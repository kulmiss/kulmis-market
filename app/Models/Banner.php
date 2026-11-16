<?php

namespace App\Models;

use App\Events\CatalogChanged;
use App\Support\FeatureText;
use App\Support\FeatureUrls;
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

    protected $fillable = ['placement', 'title', 'text', 'link_url', 'link_text', 'image_path', 'starts_at', 'ends_at', 'is_active', 'position', 'color'];

    public const COLORS = ['green' => 'bg-brand-600 text-white', 'gold' => 'bg-gold-500 text-white', 'dark' => 'bg-ink-900 text-mist-100', 'red' => 'bg-danger-600 text-white'];

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

    /** Shown? Not while it talks about, or links to, a switched-off feature (the admin is warned). */
    public function shownNow(): bool
    {
        return FeatureText::mentionsOff($this->title.' '.$this->text.' '.$this->link_text) === []
            && ($this->link_url === null || $this->link_url === '' || FeatureUrls::available($this->link_url));
    }
}
