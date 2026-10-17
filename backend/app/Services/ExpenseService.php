<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\SellerProfile;
use App\Models\User;
use App\Support\Erp\StoreLock;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A store's running costs. The amount, date and payment method never change once recorded: a
 * mistake is voided with a reason and entered again, so the history stays complete. Receipt files
 * live on the private disk and are only ever sent to the store that owns them.
 */
class ExpenseService
{
    public const DISK = 'local';

    public const MAX_CATEGORIES = 50;

    /** Content types a receipt may be, with the extension it is stored under. */
    private const FILES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];

    public function __construct(private readonly AuditLogger $audit) {}

    /** @param  array<string, mixed>  $data  validated by the controller */
    public function record(SellerProfile $store, array $data, ?UploadedFile $file, User $actor): Expense
    {
        $stored = null;
        try {
            return DB::transaction(function () use ($store, $data, $file, $actor, &$stored) {
                StoreLock::take($store->id);
                if (! empty($data['request_id'])) {
                    $earlier = Expense::where('seller_profile_id', $store->id)->where('request_id', $data['request_id'])->first();
                    if ($earlier) {
                        return $earlier;
                    }
                }
                $category = $this->category($store->id, (int) $data['expense_category_id']);
                if (! $category->is_active) {
                    throw ValidationException::withMessages(['expense_category_id' => "{$category->name} is hidden. Show it again in the category list, or choose another."]);
                }
                $amount = Money::toSantim($data['amount']);
                if ($amount < 1) {
                    throw ValidationException::withMessages(['amount' => 'Enter the amount spent.']);
                }
                $today = CarbonImmutable::now(config('marketplace.timezone'))->toDateString();
                $spentOn = $data['spent_on'] ?? $today;
                if ($spentOn > $today) {
                    throw ValidationException::withMessages(['spent_on' => 'The date cannot be in the future.']);
                }

                $expense = new Expense;
                $expense->seller_profile_id = $store->id;
                $expense->sequence = StoreLock::next(Expense::class, $store->id);
                $expense->number = StoreLock::number('EX', $expense->sequence);
                $expense->expense_category_id = $category->id;
                $expense->spent_on = $spentOn;
                $expense->amount_santim = $amount;
                $expense->description = trim($data['description']);
                $expense->paid_to = $data['paid_to'] ?? null;
                $expense->payment_method = $data['payment_method'];
                $expense->reference = $data['reference'] ?? null;
                $expense->status = 'recorded';
                $expense->request_id = $data['request_id'] ?? null;
                $expense->created_by = $actor->id;
                if ($file) {
                    $stored = $this->storeFile($store->id, $file);
                    $expense->forceFill($stored);
                }
                $expense->save();
                $this->audit->log('erp.expense_recorded', $expense, [
                    'number' => $expense->number, 'category_id' => $category->id, 'amount_santim' => $amount, 'attachment' => $file !== null,
                ]);

                return $expense;
            });
        } catch (\Throwable $e) {
            // Nothing was saved, so the file must not stay behind either.
            if ($stored) {
                Storage::disk(self::DISK)->delete($stored['attachment_path']);
            }
            throw $e;
        }
    }

    /** Category, description, who was paid and the reference. Not the amount, date or method. */
    public function updateDetails(SellerProfile $store, int $id, array $data, User $actor): Expense
    {
        return DB::transaction(function () use ($store, $id, $data) {
            $expense = $this->find($store->id, $id);
            if ($expense->isVoided()) {
                throw ValidationException::withMessages(['status' => 'This expense was voided.']);
            }
            if (array_key_exists('expense_category_id', $data) && (int) $data['expense_category_id'] !== $expense->expense_category_id) {
                $category = $this->category($store->id, (int) $data['expense_category_id']);
                if (! $category->is_active) {
                    throw ValidationException::withMessages(['expense_category_id' => "{$category->name} is hidden. Choose another category."]);
                }
                $expense->expense_category_id = $category->id;
            }
            foreach (['description', 'paid_to', 'reference'] as $field) {
                if (array_key_exists($field, $data)) {
                    $expense->{$field} = $field === 'description' ? trim($data[$field]) : $data[$field];
                }
            }
            if ($expense->isDirty()) {
                $before = array_intersect_key($expense->getOriginal(), $expense->getDirty());
                $expense->save();
                $this->audit->log('erp.expense_updated', $expense, ['number' => $expense->number, 'before' => $before]);
            }

            return $expense;
        });
    }

    /** A receipt added afterwards. One per expense: a wrong file means voiding and entering again. */
    public function attach(SellerProfile $store, int $id, UploadedFile $file, User $actor): Expense
    {
        $stored = null;
        try {
            return DB::transaction(function () use ($store, $id, $file, &$stored) {
                $expense = $this->find($store->id, $id);
                if ($expense->isVoided()) {
                    throw ValidationException::withMessages(['attachment' => 'This expense was voided.']);
                }
                if ($expense->attachment_path) {
                    throw ValidationException::withMessages(['attachment' => 'A receipt is already attached. To use another one, void this expense and enter it again.']);
                }
                $stored = $this->storeFile($store->id, $file);
                $expense->forceFill($stored)->save();
                $this->audit->log('erp.expense_attached', $expense, ['number' => $expense->number, 'size' => $stored['attachment_size']]);

                return $expense;
            });
        } catch (\Throwable $e) {
            if ($stored) {
                Storage::disk(self::DISK)->delete($stored['attachment_path']);
            }
            throw $e;
        }
    }

    public function void(SellerProfile $store, int $id, string $reason, User $actor): Expense
    {
        return DB::transaction(function () use ($store, $id, $reason, $actor) {
            $expense = $this->find($store->id, $id);
            if ($expense->isVoided()) {
                throw ValidationException::withMessages(['reason' => 'This expense was already voided.']);
            }
            $expense->status = 'voided';
            $expense->voided_at = now();
            $expense->void_reason = $reason;
            $expense->voided_by = $actor->id;
            $expense->save();
            $this->audit->log('erp.expense_voided', $expense, ['number' => $expense->number, 'amount_santim' => $expense->amount_santim]);

            return $expense;
        });
    }

    public function addCategory(SellerProfile $store, string $name, User $actor): ExpenseCategory
    {
        return DB::transaction(function () use ($store, $name) {
            StoreLock::take($store->id);
            $existing = ExpenseCategory::forStore($store->id);
            if ($existing->count() >= self::MAX_CATEGORIES) {
                throw ValidationException::withMessages(['name' => 'A store can have up to '.self::MAX_CATEGORIES.' categories. Hide or rename one instead.']);
            }
            $this->assertNameFree($store->id, $name);
            $category = new ExpenseCategory;
            $category->seller_profile_id = $store->id;
            $category->name = $name;
            $category->is_active = true;
            $category->sort_order = (int) $existing->max('sort_order') + 1;
            $category->save();
            $this->audit->log('erp.expense_category_created', $category, ['name' => $name]);

            return $category;
        });
    }

    public function updateCategory(SellerProfile $store, int $id, array $data, User $actor): ExpenseCategory
    {
        return DB::transaction(function () use ($store, $id, $data) {
            StoreLock::take($store->id);
            $category = ExpenseCategory::where('seller_profile_id', $store->id)->lockForUpdate()->findOrFail($id);
            if (isset($data['name'])) {
                $this->assertNameFree($store->id, $data['name'], $category->id);
                $category->name = $data['name'];
            }
            if (array_key_exists('is_active', $data)) {
                $category->is_active = (bool) $data['is_active'];
            }
            if ($category->isDirty()) {
                $before = array_intersect_key($category->getOriginal(), $category->getDirty());
                $category->save();
                $this->audit->log('erp.expense_category_updated', $category, ['before' => $before]);
            }

            return $category;
        });
    }

    /** One of the store's expenses, locked. Another store's id answers 404. */
    private function find(int $storeId, int $id): Expense
    {
        return Expense::where('seller_profile_id', $storeId)->lockForUpdate()->findOrFail($id);
    }

    private function category(int $storeId, int $id): ExpenseCategory
    {
        return ExpenseCategory::where('seller_profile_id', $storeId)->find($id)
            ?? throw ValidationException::withMessages(['expense_category_id' => 'Choose one of your categories.']);
    }

    private function assertNameFree(int $storeId, string $name, ?int $except = null): void
    {
        $taken = ExpenseCategory::where('seller_profile_id', $storeId)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->when($except, fn ($q) => $q->whereKeyNot($except))->exists();
        if ($taken) {
            throw ValidationException::withMessages(['name' => 'You already have a category with this name.']);
        }
    }

    /**
     * Keep the file under a random name; its type comes from its content, not the name it was sent with.
     *
     * @return array{attachment_path: string, attachment_name: string, attachment_mime: string, attachment_size: int}
     */
    private function storeFile(int $storeId, UploadedFile $file): array
    {
        $mime = (string) $file->getMimeType();
        $ext = self::FILES[$mime] ?? throw ValidationException::withMessages(['attachment' => 'Attach a photo (JPG, PNG or WebP) or a PDF.']);
        $path = $file->storeAs("erp/expenses/{$storeId}", Str::uuid()->toString().'.'.$ext, self::DISK);
        if (! $path) {
            throw new \RuntimeException('Could not save the receipt file.');
        }
        // Only a readable name is kept from the sender's file name, for the download.
        $base = trim(preg_replace('/[^\pL\pN ._-]+/u', '', pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?? '', ' ._-');

        return [
            'attachment_path' => $path,
            'attachment_name' => mb_substr($base !== '' ? $base : 'receipt', 0, 140).'.'.$ext,
            'attachment_mime' => $mime,
            'attachment_size' => (int) $file->getSize(),
        ];
    }
}
