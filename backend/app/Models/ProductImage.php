<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable(['path', 'thumb_path', 'large_path', 'alt', 'sort_order'])]
class ProductImage extends Model
{
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** The photo for the product page: the large copy, or the original when no copy was made. */
    public function url(): string
    {
        return Storage::disk('public')->url($this->large_path ?? $this->path);
    }

    /** The photo for cards and lists. */
    public function thumbUrl(): string
    {
        return Storage::disk('public')->url($this->thumb_path ?? $this->large_path ?? $this->path);
    }

    /** @return list<string> the original and every copy on the public disk */
    public function files(): array
    {
        return array_values(array_filter([$this->path, $this->thumb_path, $this->large_path]));
    }
}
