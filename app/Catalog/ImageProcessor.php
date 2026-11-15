<?php

namespace App\Catalog;

use App\Models\Product;
use App\Models\ProductImage;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Turns an upload into responsive AVIF / WebP / JPEG files (400, 800, 1600 px
 * wide). Re-encoding also strips EXIF/GPS metadata from customer-facing photos.
 */
class ImageProcessor
{
    public const WIDTHS = [400, 800, 1600];

    public function store(Product $product, UploadedFile $file, ?string $alt = null): ProductImage
    {
        $src = $this->load($file);
        $w = imagesx($src);
        $h = imagesy($src);
        if ($w > 8000 || $h > 8000) {
            throw new RuntimeException('Image is too large (max 8000 px).');
        }

        $base = "products/{$product->id}/".Str::lower(Str::random(12));
        $widths = array_values(array_unique(array_map(fn ($t) => min($t, $w), self::WIDTHS)));
        $formats = array_values(array_filter(['avif', 'webp', 'jpg'], fn ($f) => $this->supports($f)));
        $disk = Storage::disk('public');

        foreach ($widths as $tw) {
            $th = (int) round($h * $tw / $w);
            $img = imagecreatetruecolor($tw, $th);
            imagealphablending($img, false);
            imagesavealpha($img, true);
            imagecopyresampled($img, $src, 0, 0, 0, 0, $tw, $th, $w, $h);
            foreach ($formats as $f) {
                $disk->put("$base-$tw.$f", $this->encode($img, $f));
            }
            imagedestroy($img);
        }
        imagedestroy($src);

        return $product->images()->create([
            'path' => $base, 'formats' => $formats, 'widths' => $widths,
            'width' => $w, 'height' => $h, 'alt' => $alt ?? $product->name,
            'position' => (int) $product->images()->max('position') + 1,
        ]);
    }

    public function delete(ProductImage $image): void
    {
        $disk = Storage::disk('public');
        foreach ($image->widths as $w) {
            foreach ($image->formats as $f) {
                $disk->delete("{$image->path}-$w.$f");
            }
        }
        $image->delete();
    }

    private function load(UploadedFile $file): GdImage
    {
        $data = (string) file_get_contents($file->getRealPath());
        $img = @imagecreatefromstring($data);
        if (! $img instanceof GdImage) {
            throw new RuntimeException('Unsupported image.');
        }
        // Respect camera orientation for JPEGs.
        if (function_exists('exif_read_data') && str_starts_with($data, "\xFF\xD8")) {
            $exif = @exif_read_data($file->getRealPath());
            $rotated = match ((int) ($exif['Orientation'] ?? 1)) {
                3 => imagerotate($img, 180, 0), 6 => imagerotate($img, -90, 0), 8 => imagerotate($img, 90, 0), default => $img,
            };
            if ($rotated instanceof GdImage) {
                $img = $rotated;
            }
        }

        return $img;
    }

    private function supports(string $format): bool
    {
        return match ($format) {
            'avif' => function_exists('imageavif'),
            'webp' => function_exists('imagewebp'),
            default => true,
        };
    }

    private function encode(GdImage $img, string $format): string
    {
        ob_start();
        match ($format) {
            // Quality picked by eye on craft photos: AVIF 45 looks the same as 55 and is ~30% smaller
            // (the product photo is what phones wait for).
            'avif' => imageavif($img, null, 45, 6),
            'webp' => imagewebp($img, null, 75),
            default => imagejpeg($img, null, 82),
        };

        return (string) ob_get_clean();
    }
}
