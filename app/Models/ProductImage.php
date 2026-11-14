<?php

namespace App\Models;

use App\Events\CatalogChanged;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property string $path
 * @property list<string> $formats
 * @property list<int> $widths
 * @property int $width
 * @property int $height
 * @property ?string $alt
 */
class ProductImage extends Model
{
    protected $fillable = ['path', 'formats', 'widths', 'width', 'height', 'alt', 'position'];

    protected $dispatchesEvents = ['saved' => CatalogChanged::class, 'deleted' => CatalogChanged::class];

    protected function casts(): array
    {
        return ['formats' => 'array', 'widths' => 'array'];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function url(int $width, string $format = 'webp'): string
    {
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
}
