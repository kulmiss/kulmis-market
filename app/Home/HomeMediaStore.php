<?php

namespace App\Home;

use App\Catalog\ImageProcessor;
use App\Models\HomeMedia;
use App\Models\HomePageVersion;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Pictures and short videos for the home page.
 * - Pictures: re-encoded to AVIF / WebP / JPEG at 3 sizes (no EXIF/GPS).
 * - Videos: MP4 / WebM / MOV, at most 2 minutes, re-encoded by ffmpeg to a
 *   small H.264 MP4 without any metadata, plus a poster picture from the video.
 *   The original file is never kept or served.
 */
final class HomeMediaStore
{
    public const MAX_VIDEO_SECONDS = 120;

    public function __construct(private readonly ImageProcessor $images) {}

    public function image(UploadedFile $file, string $alt, ?User $by, bool $demo = false): HomeMedia
    {
        $r = $this->images->responsive($file, 'home');

        return HomeMedia::query()->create([...$r, 'kind' => 'image', 'alt' => mb_substr(trim($alt), 0, 200),
            'bytes' => (int) $file->getSize(), 'uploaded_by' => $by?->id, 'is_demo' => $demo]);
    }

    public static function videoEnabled(): bool
    {
        $bin = (string) config('shop.ffmpeg');
        if ($bin === '') {
            return false;
        }
        static $ok = [];

        return $ok[$bin] ??= Process::timeout(10)->run([$bin, '-version'])->successful();
    }

    /** @return array{video:HomeMedia, poster:?HomeMedia} */
    public function video(UploadedFile $file, string $alt, ?User $by): array
    {
        if (! self::videoEnabled()) {
            throw new RuntimeException('Video upload is not set up on this server (ffmpeg is missing). Use a YouTube or Vimeo link instead.');
        }
        $head = (string) file_get_contents($file->getRealPath(), false, null, 0, 16);
        $isMp4 = substr($head, 4, 4) === 'ftyp';
        $isWebm = str_starts_with($head, "\x1A\x45\xDF\xA3");
        if (! $isMp4 && ! $isWebm) {
            throw new RuntimeException('Use an MP4, MOV or WebM video.');
        }

        $bin = (string) config('shop.ffmpeg');
        $tmp = storage_path('app/tmp/'.Str::random(16));
        File::ensureDirectoryExists($tmp);
        try {
            $out = "$tmp/v.mp4";
            $res = Process::timeout(180)->run([
                $bin, '-nostdin', '-y', '-v', 'error', '-i', $file->getRealPath(), '-t', (string) self::MAX_VIDEO_SECONDS,
                '-map', '0:v:0', '-map', '0:a:0?', '-map_metadata', '-1', '-map_chapters', '-1', '-sn', '-dn',
                '-vf', "scale='min(1280,iw)':-2", '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '28', '-pix_fmt', 'yuv420p',
                '-c:a', 'aac', '-b:a', '96k', '-movflags', '+faststart', $out,
            ]);
            if (! $res->successful() || ! is_file($out) || filesize($out) < 1000) {
                throw new RuntimeException('This video could not be read. Try another file.');
            }
            [$w, $h] = $this->size($out);
            $path = 'home/videos/'.Str::lower(Str::random(16)).'.mp4';
            Storage::disk('public')->put($path, (string) file_get_contents($out));
            $video = HomeMedia::query()->create(['kind' => 'video', 'path' => $path, 'formats' => ['mp4'], 'widths' => [],
                'width' => $w, 'height' => $h, 'bytes' => (int) filesize($out), 'alt' => mb_substr(trim($alt), 0, 200), 'uploaded_by' => $by?->id]);

            $poster = null;
            $jpg = "$tmp/poster.jpg";
            foreach (['1', '0'] as $at) {
                if (Process::timeout(60)->run([$bin, '-nostdin', '-y', '-v', 'error', '-ss', $at, '-i', $out, '-frames:v', '1', $jpg])->successful() && is_file($jpg)) {
                    $poster = $this->image(new UploadedFile($jpg, 'poster.jpg', 'image/jpeg', null, true), $alt, $by);
                    break;
                }
            }

            return ['video' => $video, 'poster' => $poster];
        } finally {
            File::deleteDirectory($tmp);
        }
    }

    /** @return array{0:int, 1:int} */
    private function size(string $file): array
    {
        $probe = Process::timeout(30)->run([(string) config('shop.ffmpeg'), '-nostdin', '-hide_banner', '-i', $file, '-f', 'null', '-t', '0', '-']);
        preg_match('/Video:.*?, (\d{2,5})x(\d{2,5})/', $probe->errorOutput().$probe->output(), $m);

        return [(int) ($m[1] ?? 0), (int) ($m[2] ?? 0)];
    }

    /**
     * Where a picture or video is used now (draft, planned or live page).
     *
     * @return list<string>
     */
    public function usedIn(HomeMedia $media): array
    {
        $out = [];
        foreach (['draft' => HomePageVersion::draft(), 'planned publish' => HomePageVersion::scheduled(), 'live page' => HomePageVersion::live()] as $label => $v) {
            if ($v && in_array($media->id, Sections::mediaIds(Sections::normalize($v->sections), Sections::seo($v->seo)), true)) {
                $out[] = $label;
            }
        }

        return $out;
    }

    public function delete(HomeMedia $media): void
    {
        $disk = Storage::disk('public');
        if ($media->kind === 'video') {
            $disk->delete($media->path);
        } else {
            foreach ($media->widths as $w) {
                foreach ($media->formats as $f) {
                    $disk->delete("{$media->path}-$w.$f");
                }
            }
        }
        $media->delete();
    }
}
