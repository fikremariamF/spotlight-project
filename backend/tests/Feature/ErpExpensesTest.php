<?php

namespace Tests\Feature;

use App\Enums\SellerStatus;
use App\Models\AuditLog;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\PlacesOrders;
use Tests\TestCase;

class ErpExpensesTest extends TestCase
{
    use PlacesOrders, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        $this->setUpOrders();
    }

    private function categoryId(string $name = 'Warehouse rent'): int
    {
        return $this->as($this->seller->user)->getJson('/api/seller/erp/expense-categories')->assertOk()
            ->collect('data')->firstWhere('name', $name)['id'];
    }

    private function spend(array $attrs = []): TestResponse
    {
        return $this->post('/api/seller/erp/expenses', $attrs + [
            'expense_category_id' => $this->categoryId(), 'amount' => '15000', 'description' => 'October rent, Bole warehouse',
            'payment_method' => 'bank_transfer', 'paid_to' => 'Ato Girma',
        ], ['Accept' => 'application/json']);
    }

    /** A file whose content, not its name, says what it is. */
    private function realFile(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'exp');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    public function test_every_store_starts_with_the_usual_categories_once(): void
    {
        $list = $this->as($this->seller->user)->getJson('/api/seller/erp/expense-categories')->assertOk()->json('data');
        $this->assertSame(ExpenseCategory::DEFAULTS, array_column($list, 'name'));
        $this->getJson('/api/seller/erp/expense-categories')->assertOk()->assertJsonCount(7, 'data');
        $this->assertSame(7, ExpenseCategory::count());

        $this->postJson('/api/seller/erp/expense-categories', ['name' => 'packaging'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $fuel = $this->postJson('/api/seller/erp/expense-categories', ['name' => '  Fuel  '])->assertCreated()->assertJsonPath('data.name', 'Fuel')->json('data.id');
        $this->putJson("/api/seller/erp/expense-categories/{$fuel}", ['name' => 'Marketing'])->assertUnprocessable();
        $this->putJson("/api/seller/erp/expense-categories/{$fuel}", ['name' => 'Fuel and taxis', 'is_active' => false])->assertOk()
            ->assertJsonPath('data.is_active', false);
        $this->spend(['expense_category_id' => $fuel])->assertUnprocessable()->assertJsonValidationErrors('expense_category_id');
        $this->assertSame(1, AuditLog::where('action', 'erp.expense_category_updated')->count());

        // Another store gets its own list and cannot touch this one.
        $other = $this->approvedSeller();
        $this->as($other->user)->getJson('/api/seller/erp/expense-categories')->assertOk()->assertJsonCount(7, 'data');
        $this->putJson("/api/seller/erp/expense-categories/{$fuel}", ['name' => 'Mine'])->assertNotFound();
        $this->spend(['expense_category_id' => $fuel])->assertUnprocessable()->assertJsonValidationErrors('expense_category_id');
    }

    public function test_an_expense_is_recorded_once_with_its_receipt_and_counted_by_category(): void
    {
        $photo = UploadedFile::fake()->image('Rent receipt (Oct).jpg', 600, 800)->size(300);
        $first = $this->spend(['attachment' => $photo, 'request_id' => 'exp-1'])->assertCreated()->json('data');
        $this->assertSame(['EX-00001', 1_500_000, 'recorded', 'Warehouse rent', 'Rent receipt Oct.jpg', 'image/jpeg'],
            [$first['number'], $first['amount_santim'], $first['status'], $first['category']['name'], $first['attachment']['name'], $first['attachment']['mime']]);
        $path = Expense::sole()->attachment_path;
        $this->assertMatchesRegularExpression('#^erp/expenses/'.$this->seller->id.'/[0-9a-f-]{36}\.jpg$#', $path);
        Storage::disk('local')->assertExists($path);

        // The same form again records nothing more.
        $this->spend(['attachment' => UploadedFile::fake()->image('again.jpg'), 'request_id' => 'exp-1'])->assertCreated()->assertJsonPath('data.id', $first['id']);
        $this->assertSame(1, Expense::count());
        $this->assertCount(1, Storage::disk('local')->allFiles('erp/expenses'));

        $res = $this->get("/api/seller/erp/expenses/{$first['id']}/attachment")->assertOk();
        $this->assertSame('image/jpeg', $res->headers->get('Content-Type'));
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('sandbox', $res->headers->get('Content-Security-Policy'));

        $pdf = $this->realFile('bill.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
        $this->spend(['expense_category_id' => $this->categoryId('Packaging'), 'amount' => '850.50', 'description' => 'Bubble wrap', 'payment_method' => 'cash',
            'spent_on' => now(config('marketplace.timezone'))->subDays(3)->toDateString(), 'attachment' => $pdf])->assertCreated()
            ->assertJsonPath('data.attachment.mime', 'application/pdf');
        $this->spend(['amount' => '100', 'description' => 'Old one', 'spent_on' => now(config('marketplace.timezone'))->subDays(40)->toDateString()])->assertCreated();

        $list = $this->getJson('/api/seller/erp/expenses')->assertOk();
        // The old one is outside the last 30 days.
        $this->assertSame([2, 1_585_050, 0, 2], [$list->json('meta.count'), $list->json('meta.total_santim'), $list->json('meta.without_receipt'), count($list->json('data'))]);
        $this->assertSame([['Warehouse rent', 1_500_000], ['Packaging', 85_050]], array_map(fn ($c) => [$c['name'], $c['total_santim']], $list->json('meta.by_category')));
        $this->getJson('/api/seller/erp/expenses?q=bubble')->assertJsonCount(1, 'data');
        $this->getJson('/api/seller/erp/expenses?method=cash')->assertJsonCount(1, 'data');
        $this->getJson('/api/seller/erp/expenses?receipt=without&period=custom&from='.now(config('marketplace.timezone'))->subDays(60)->toDateString())->assertJsonCount(1, 'data');

        $csv = $this->get('/api/seller/erp/expenses.csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('EX-00001,"Warehouse rent","October rent, Bole warehouse","Ato Girma",bank_transfer,,15000.00,yes', $csv);
        $this->assertStringContainsString('850.50', $csv);
        // Text people typed cannot run as a spreadsheet formula.
        $this->spend(['description' => '=HYPERLINK("http://x.test","click")', 'paid_to' => '@cmd', 'reference' => '-2+3'])->assertCreated();
        $csv = $this->get('/api/seller/erp/expenses.csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('"\'=HYPERLINK(""http://x.test"",""click"")",\'@cmd,bank_transfer,\'-2+3,', $csv);
    }

    public function test_receipt_files_are_checked_by_content_and_kept_private(): void
    {
        $page = $this->realFile('receipt.jpg', '<html><body><script>alert(1)</script></body></html>');
        $this->spend(['attachment' => $page])->assertUnprocessable()->assertJsonValidationErrors('attachment');
        $this->spend(['attachment' => UploadedFile::fake()->image('huge.jpg')->size(5121)])->assertUnprocessable()->assertJsonValidationErrors('attachment');
        $this->spend(['attachment' => UploadedFile::fake()->create('notes.txt', 3, 'text/plain')])->assertUnprocessable()->assertJsonValidationErrors('attachment');
        $this->assertSame(0, Expense::count());

        // A form that fails after the file was checked leaves no file behind.
        $this->spend(['attachment' => UploadedFile::fake()->image('r.jpg'), 'spent_on' => now(config('marketplace.timezone'))->addDays(2)->toDateString()])
            ->assertUnprocessable()->assertJsonValidationErrors('spent_on');
        $this->assertSame([], Storage::disk('local')->allFiles());

        $expense = $this->spend()->assertCreated()->json('data');
        $this->assertNull($expense['attachment']);
        $this->getJson("/api/seller/erp/expenses/{$expense['id']}/attachment")->assertNotFound();
        $this->post("/api/seller/erp/expenses/{$expense['id']}/attachment", ['attachment' => UploadedFile::fake()->image('later.png')], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.attachment.mime', 'image/png');
        $this->post("/api/seller/erp/expenses/{$expense['id']}/attachment", ['attachment' => UploadedFile::fake()->image('other.png')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('attachment');
        $this->assertCount(1, Storage::disk('local')->allFiles());

        $other = $this->approvedSeller();
        $this->as($other->user)->get("/api/seller/erp/expenses/{$expense['id']}/attachment")->assertNotFound();
        $this->getJson("/api/seller/erp/expenses/{$expense['id']}")->assertNotFound();
        $this->getJson('/api/seller/erp/expenses')->assertOk()->assertJsonCount(0, 'data');
        $this->post("/api/seller/erp/expenses/{$expense['id']}/attachment", ['attachment' => UploadedFile::fake()->image('x.png')], ['Accept' => 'application/json'])->assertNotFound();
        $this->as($this->buyer)->get("/api/seller/erp/expenses/{$expense['id']}/attachment")->assertForbidden();
    }

    public function test_amounts_are_fixed_once_recorded_and_mistakes_are_voided(): void
    {
        $expense = $this->spend()->assertCreated()->json('data');
        $this->spend(['amount' => '0'])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->spend(['amount' => '12.345'])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->spend(['payment_method' => 'iou'])->assertUnprocessable()->assertJsonValidationErrors('payment_method');
        $this->spend(['description' => ''])->assertUnprocessable()->assertJsonValidationErrors('description');

        $marketing = $this->categoryId('Marketing');
        $this->putJson("/api/seller/erp/expenses/{$expense['id']}", ['expense_category_id' => $marketing, 'description' => 'Flyers', 'amount' => '1', 'spent_on' => '2001-01-01'])
            ->assertOk()->assertJsonPath('data.category.name', 'Marketing')->assertJsonPath('data.description', 'Flyers')
            ->assertJsonPath('data.amount_santim', 1_500_000)->assertJsonPath('data.spent_on', $expense['spent_on']);
        $this->assertSame('October rent, Bole warehouse', AuditLog::where('action', 'erp.expense_updated')->sole()->meta['before']['description']);

        $this->postJson("/api/seller/erp/expenses/{$expense['id']}/void", ['reason' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/seller/erp/expenses/{$expense['id']}/void", ['reason' => 'Paid twice'])->assertOk()->assertJsonPath('data.status', 'voided');
        $this->postJson("/api/seller/erp/expenses/{$expense['id']}/void", ['reason' => 'Again'])->assertUnprocessable();
        $this->putJson("/api/seller/erp/expenses/{$expense['id']}", ['description' => 'Back'])->assertUnprocessable();
        $this->getJson('/api/seller/erp/expenses')->assertOk()->assertJsonPath('meta.total_santim', 0)->assertJsonPath('data.0.status', 'voided');
        $this->getJson('/api/seller/erp/expenses?status=recorded')->assertJsonCount(0, 'data');
        $this->assertStringNotContainsString('EX-00001', $this->get('/api/seller/erp/expenses.csv')->streamedContent());
        $this->assertSame(1, Expense::count());

        // Used categories stay; another store cannot void or change this store's expenses.
        $other = $this->approvedSeller();
        $this->as($other->user)->postJson("/api/seller/erp/expenses/{$expense['id']}/void", ['reason' => 'Mine'])->assertNotFound();
        $this->putJson("/api/seller/erp/expenses/{$expense['id']}", ['description' => 'Mine'])->assertNotFound();

        $this->seller->forceFill(['status' => SellerStatus::Suspended])->save();
        $this->as($this->seller->user)->getJson('/api/seller/erp/expenses')->assertOk();
        $this->spend()->assertForbidden();
        $this->postJson('/api/seller/erp/expense-categories', ['name' => 'New'])->assertForbidden();
    }
}
