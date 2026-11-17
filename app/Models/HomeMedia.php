<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * A picture or short video for the home page builder. Pictures are stored
 * like product photos (AVIF / WebP / JPEG at 400, 800, 1600 px, re-encoded,
 * no metadata), so the same <picture> partial shows them.
 *
 * @property int $id
 * @property string $kind
 * @property string $path
 * @property list<string> $formats
 * @property list<int> $widths
 * @property int $width
 * @property int $height
 * @property int $bytes
 * @property ?string $alt
 * @property ?int $uploaded_by
 * @property Carbon $created_at
 */
class HomeMedia extends Model
{
    protected $table = 'home_media';

    protected $fillable = ['kind', 'path', 'formats', 'widths', 'width', 'height', 'bytes', 'alt', 'uploaded_by'];

    protected function casts(): array
    {
        return ['formats' => 'array', 'widths' => 'array', 'width' => 'integer', 'height' => 'integer', 'bytes' => 'integer'];
    }

    public function url(int $width = 800, string $format = 'webp'): string
    {
        if ($this->kind === 'video') {
            return Storage::disk('public')->url($this->path);
        }
        $width = in_array($width, $this->widths, true) ? $width : max($this->widths);
        $format = in_array($format, $this->formats, true) ? $format : $this->formats[0];

        return Storage::disk('public')->url("{$this->path}-{$width}.{$format}");
    }

    /** @return array<string, string> format => srcset */
    public function srcsets(): array
    {
        $out = [];
        foreach ($this->formats as $f) {
            $out[$f] = collect($this->widths)->map(fn ($w) => $this->url($w, $f)." {$w}w")->implode(', ');
        }

        return $out;
    }

    /** For link previews (Telegram, WhatsApp): a JPEG if there is one. */
    public function shareUrl(): string
    {
        return $this->url(1600, in_array('jpg', $this->formats, true) ? 'jpg' : 'webp');
    }

    /** @return array<string, mixed> */
    public function toEditor(): array
    {
        return [
            'id' => $this->id, 'kind' => $this->kind, 'alt' => $this->alt, 'width' => $this->width, 'height' => $this->height,
            'bytes' => $this->bytes, 'thumb' => $this->kind === 'image' ? $this->url(400) : null,
            'url' => $this->kind === 'video' ? $this->url() : $this->url(800),
        ];
    }
}
