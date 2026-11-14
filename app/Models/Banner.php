<?php

namespace App\Models;

use App\Events\CatalogChanged;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $placement
 * @property string $title
 * @property ?string $text
 * @property ?string $link_url
 * @property ?string $link_text
 * @property ?string $image_path
 */
class Banner extends Model
{
    /** @var array<string, mixed> */
    protected $attributes = ['is_active' => true, 'position' => 0, 'placement' => 'home'];

    protected $fillable = ['placement', 'title', 'text', 'link_url', 'link_text', 'image_path', 'starts_at', 'ends_at', 'is_active', 'position'];

    protected $dispatchesEvents = ['saved' => CatalogChanged::class, 'deleted' => CatalogChanged::class];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'is_active' => 'boolean'];
    }

    /** @param Builder<Banner> $q */
    public function scopeLive(Builder $q, string $placement): void
    {
        $now = now();
        $q->where('placement', $placement)->where('is_active', true)
            ->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            ->orderBy('position');
    }
}
