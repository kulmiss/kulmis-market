<?php

namespace App\Models;

use App\Storefront\PageCache;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property ?string $category
 * @property string $question
 * @property string $answer
 * @property int $position
 * @property bool $is_published
 */
class Faq extends Model
{
    protected $fillable = ['category', 'question', 'answer', 'position', 'is_published'];

    protected static function booted(): void
    {
        static::saved(fn () => PageCache::clear());
        static::deleted(fn () => PageCache::clear());
    }

    protected function casts(): array
    {
        return ['position' => 'integer', 'is_published' => 'boolean'];
    }
}
