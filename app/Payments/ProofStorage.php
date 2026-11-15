<?php

namespace App\Payments;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Payment proofs (receipt photos / PDFs). Kept on the private disk
 * (storage/app/private, never under public/). Photos are re-drawn as a new
 * JPEG, which drops hidden data such as GPS location and anything smuggled
 * inside the file. PDFs must really be PDFs. Admins view them through an
 * authorised endpoint that sends them as inert files.
 */
class ProofStorage
{
    public const MAX_PIXELS = 40_000_000; // ~6300 × 6300

    public const MAX_SIDE = 2400;

    public function delete(string $path): void
    {
        Storage::disk('local')->delete($path);
    }

    public function store(UploadedFile $file): string
    {
        $data = (string) file_get_contents($file->getRealPath());
        $dir = 'payment-proofs/'.now()->format('Y/m');
        $name = Str::lower(Str::random(32));

        if (str_starts_with($data, '%PDF-')) {
            if (strlen($data) > 5 * 1024 * 1024) {
                throw ValidationException::withMessages(['proof' => 'The PDF is too large (max 5 MB).']);
            }
            Storage::disk('local')->put("$dir/$name.pdf", $data);

            return "$dir/$name.pdf";
        }

        $info = @getimagesizefromstring($data);
        if (! $info || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true) || $info[0] * $info[1] > self::MAX_PIXELS) {
            throw ValidationException::withMessages(['proof' => 'Upload a photo (JPG, PNG, WebP) or a PDF of your receipt.']);
        }
        $src = @imagecreatefromstring($data);
        if (! $src instanceof GdImage) {
            throw ValidationException::withMessages(['proof' => 'We could not read that image. Try another photo.']);
        }
        [$w, $h] = [imagesx($src), imagesy($src)];
        $scale = min(1, self::MAX_SIDE / max($w, $h));
        $tw = max(1, (int) round($w * $scale));
        $th = max(1, (int) round($h * $scale));
        $out = imagecreatetruecolor($tw, $th);
        imagefill($out, 0, 0, (int) imagecolorallocate($out, 255, 255, 255)); // flatten transparency
        imagecopyresampled($out, $src, 0, 0, 0, 0, $tw, $th, $w, $h);
        ob_start();
        imagejpeg($out, null, 85);
        $jpeg = (string) ob_get_clean();
        imagedestroy($src);
        imagedestroy($out);
        Storage::disk('local')->put("$dir/$name.jpg", $jpeg);

        return "$dir/$name.jpg";
    }
}
