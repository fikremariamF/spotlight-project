<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One of a store's expense categories. Hidden rather than deleted once used. */
class ExpenseCategory extends Model
{
    /** What every store starts with. */
    public const DEFAULTS = [
        'Furniture transportation and delivery',
        'Warehouse rent',
        'Packaging',
        'Repairs and maintenance',
        'Marketing',
        'Staff expenses',
        'Other operating costs',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /**
     * The store's categories, with the usual ones added the first time.
     *
     * @return Collection<int, self>
     */
    public static function forStore(int $sellerProfileId): Collection
    {
        if (! self::where('seller_profile_id', $sellerProfileId)->exists()) {
            $now = now();
            // insertOrIgnore: two first visits at once must not fail on the unique name.
            self::insertOrIgnore(array_map(fn ($name, $i) => [
                'seller_profile_id' => $sellerProfileId, 'name' => $name, 'is_active' => true, 'sort_order' => $i,
                'created_at' => $now, 'updated_at' => $now,
            ], self::DEFAULTS, array_keys(self::DEFAULTS)));
        }

        return self::where('seller_profile_id', $sellerProfileId)->orderBy('sort_order')->orderBy('name')->get();
    }
}
