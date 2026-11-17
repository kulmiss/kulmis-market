<?php

namespace App\Models;

use App\Demo\HidesDemo;
use App\Storefront\PageCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property string $name
 * @property ?string $city
 * @property string $text
 * @property ?string $photo_path
 * @property bool $photo_ok
 * @property bool $confirmed_real
 * @property bool $is_active
 * @property int $position
 * @property bool $is_demo
 * @property ?int $created_by
 * @property Carbon $created_at
 */
class Testimonial extends Model
{
    use HidesDemo;

    protected $fillable = ['name', 'city', 'text', 'photo_path', 'photo_ok', 'confirmed_real', 'is_active', 'position', 'is_demo', 'created_by'];

    /** @var array<string, mixed> */
    protected $attributes = ['is_active' => true, 'confirmed_real' => false, 'photo_ok' => false, 'is_demo' => false, 'position' => 0];

    protected static function booted(): void
    {
        static::saved(fn () => PageCache::clear());
        static::deleted(fn () => PageCache::clear());
    }

    protected function casts(): array
    {
        return ['photo_ok' => 'boolean', 'confirmed_real' => 'boolean', 'is_active' => 'boolean', 'position' => 'integer', 'is_demo' => 'boolean'];
    }

    /**
     * What may be shown on the home page: real ones with the tick, and demo
     * ones while demo data is shown (the global scope hides them otherwise).
     *
     * @param  Builder<Testimonial>  $q
     */
    public function scopeShowable(Builder $q): void
    {
        $q->where('is_active', true)->where(fn ($w) => $w->where('confirmed_real', true)->orWhere('is_demo', true));
    }

    public function photoUrl(): ?string
    {
        return $this->photo_ok && $this->photo_path ? Storage::disk('public')->url($this->photo_path) : null;
    }

    /** @return list<string> why it is not on the page (empty = it can show) */
    public function notShownBecause(): array
    {
        return array_values(array_filter([
            ! $this->is_active ? 'Switched off' : null,
            ! $this->confirmed_real && ! $this->is_demo ? 'Tick "A real customer said this" to show it' : null,
            $this->photo_path && ! $this->photo_ok ? 'Photo hidden: no permission ticked' : null,
        ]));
    }
}
