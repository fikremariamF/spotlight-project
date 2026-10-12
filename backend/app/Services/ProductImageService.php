<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ProductImageService
{
    public function __construct(private ImageVariants $variants) {}

    /**
     * Store uploaded images under a random file name (never the client's name).
     *
     * @param  array<UploadedFile>  $files
     */
    public function storeMany(Product $product, array $files): void
    {
        if ($files === []) {
            return;
        }

        $max = (int) config('marketplace.products.max_images');

        DB::transaction(function () use ($product, $files, $max) {
            // Lock the product row so two concurrent uploads cannot exceed the limit.
            Product::whereKey($product->id)->lockForUpdate()->first();

            $existing = $product->images()->count();
            if ($existing + count($files) > $max) {
                throw ValidationException::withMessages([
                    'images' => "A listing can have at most {$max} photos. It has {$existing} already.",
                ]);
            }

            $next = (int) $product->images()->max('sort_order') + ($existing > 0 ? 1 : 0);
            $stored = [];

            try {
                foreach ($files as $file) {
                    $path = $file->store("products/{$product->id}", 'public');
                    if ($path === false) {
                        throw new \RuntimeException('Image could not be saved.');
                    }
                    $stored[] = $path;
                    // Without copies the original is shown, so a failure here never blocks the upload.
                    $copies = $this->variants->make($path);
                    array_push($stored, ...array_values($copies));
                    $product->images()->create(['path' => $path, ...$copies, 'sort_order' => $next++, 'alt' => $product->name]);
                }
            } catch (\Throwable $e) {
                // Do not leave orphaned files when the database write fails.
                Storage::disk('public')->delete($stored);
                throw $e;
            }
        });
    }

    public function delete(ProductImage $image): void
    {
        $files = $image->files();
        $image->delete();
        Storage::disk('public')->delete($files);
    }
}
