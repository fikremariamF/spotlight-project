<?php

namespace App\Services;

use App\Models\ProductImage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Makes the smaller copies of a product photo that pages actually load: a thumbnail for cards and
 * lists, and a large copy for the product page. Copies are WebP when this PHP can write it, else JPEG.
 * Re-encoding drops all photo metadata (camera, GPS location), and phone photos are turned upright
 * from their EXIF orientation first. If anything goes wrong the photo is still usable: pages fall
 * back to the original file.
 */
class ImageVariants
{
    public const THUMB = 640;

    public const LARGE = 1600;

    /** Largest photo (in pixels) this will decode; bigger ones keep using the original. */
    private const MAX_PIXELS = 8000 * 8000;

    /**
     * Create the copies for a stored original and return their paths, or [] when they could not be made.
     *
     * @return array{thumb_path?: string, large_path?: string}
     */
    public function make(string $path): array
    {
        $disk = Storage::disk('public');
        $written = [];
        $memory = ini_get('memory_limit');

        try {
            // Read through the disk (not a local path) so this also works when photos live on S3.
            $bytes = $disk->get($path);
            $info = $bytes === null ? false : @getimagesizefromstring($bytes);
            if ($info === false || $info[0] * $info[1] > self::MAX_PIXELS) {
                return [];
            }
            [$width, $height, $type] = $info;

            $orientation = 1;
            if ($type === IMAGETYPE_JPEG) {
                // Without the exif extension a sideways phone photo would be saved sideways; the
                // original keeps its orientation tag, which browsers honor, so use that instead.
                if (! $this->canReadOrientation()) {
                    return [];
                }
                $orientation = $this->orientation($bytes);
            }

            if (! $this->reserveMemory($width * $height)) {
                return [];
            }

            $image = @imagecreatefromstring($bytes);
            unset($bytes);
            if ($image === false) {
                return [];
            }
            $image = $this->upright($image, $orientation);

            $webp = $this->canWriteWebp();
            $base = preg_replace('/\.[A-Za-z0-9]+$/', '', $path);
            foreach (['thumb_path' => self::THUMB, 'large_path' => self::LARGE] as $key => $size) {
                $target = $base.'-'.($key === 'thumb_path' ? 'thumb' : 'large').($webp ? '.webp' : '.jpg');
                if (! $disk->put($target, $this->encode($this->fit($image, $size, $webp), $webp))) {
                    throw new \RuntimeException("Could not write {$target}.");
                }
                $written[$key] = $target;
            }

            return $written;
        } catch (\Throwable $e) {
            $disk->delete(array_values($written));
            Log::warning('Could not make smaller copies of a product photo; the original is used.', ['path' => $path, 'error' => $e->getMessage()]);

            return [];
        } finally {
            // Lowering the limit fails (quietly) while the decoded photo is still in memory; that is fine.
            @ini_set('memory_limit', (string) $memory);
        }
    }

    /** Make (or remake) the copies of one stored photo and save their paths. Returns false when they could not be made. */
    public function refresh(ProductImage $image): bool
    {
        $old = array_filter([$image->thumb_path, $image->large_path]);
        $paths = $this->make($image->path);
        if ($paths === []) {
            return false;
        }
        $image->forceFill($paths)->save();
        // Same names are overwritten in place; only remove copies in another format (JPEG vs WebP).
        Storage::disk('public')->delete(array_diff($old, $paths));

        return true;
    }

    protected function canReadOrientation(): bool
    {
        return function_exists('exif_read_data');
    }

    protected function canWriteWebp(): bool
    {
        return function_exists('imagewebp') && (gd_info()['WebP Support'] ?? false);
    }

    private function orientation(string $bytes): int
    {
        try {
            $stream = fopen('php://memory', 'r+b');
            fwrite($stream, $bytes);
            rewind($stream);
            $exif = @exif_read_data($stream);
            fclose($stream);
        } catch (\Throwable) {
            return 1; // Broken EXIF block: treat as upright.
        }

        $value = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        return $value >= 1 && $value <= 8 ? $value : 1;
    }

    /** GD holds 4 bytes per pixel, plus room for the turned copy and the resized copies. */
    private function reserveMemory(int $pixels): bool
    {
        $needed = (int) ($pixels * 4 * 2.2) + 64 * 1024 * 1024;
        $limit = $this->bytes((string) ini_get('memory_limit'));
        if ($limit < 0 || $limit >= memory_get_usage() + $needed) {
            return true;
        }

        return @ini_set('memory_limit', (string) (memory_get_usage() + $needed)) !== false;
    }

    private function bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }

    /** Apply the EXIF orientation (1 to 8) so the pixels themselves are upright. */
    private function upright(\GdImage $image, int $orientation): \GdImage
    {
        // imagerotate turns counter-clockwise.
        $turned = match ($orientation) {
            3, 4 => imagerotate($image, 180, 0),
            5, 6 => imagerotate($image, 270, 0),
            7, 8 => imagerotate($image, 90, 0),
            default => $image,
        };
        if ($turned === false) {
            throw new \RuntimeException('Photo could not be turned upright.');
        }
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($turned, IMG_FLIP_HORIZONTAL);
        }

        return $turned;
    }

    /** Scale down to fit in a size x size box (never up), keeping transparency for WebP. */
    private function fit(\GdImage $image, int $size, bool $webp): \GdImage
    {
        $w = imagesx($image);
        $h = imagesy($image);
        $scale = min(1, $size / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));

        $out = imagecreatetruecolor($nw, $nh);
        if ($webp) {
            imagealphablending($out, false);
            imagesavealpha($out, true);
            imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
        } else {
            // JPEG has no transparency: put see-through parts on white instead of black.
            imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
        }
        imagecopyresampled($out, $image, 0, 0, 0, 0, $nw, $nh, $w, $h);

        return $out;
    }

    private function encode(\GdImage $image, bool $webp): string
    {
        ob_start();
        $ok = $webp ? imagewebp($image, null, 80) : imagejpeg($image, null, 82);
        $data = (string) ob_get_clean();
        if (! $ok || $data === '') {
            throw new \RuntimeException('Photo could not be encoded.');
        }

        return $data;
    }
}
