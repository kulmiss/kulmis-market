<?php

namespace App\Models;

use App\Help\HelpArticles;
use App\Storefront\PageCache;
use Illuminate\Database\Eloquent\Model;

/**
 * The Super Admin's own version of a help article (or a new one). Defaults: resources/help/*.md.
 *
 * @property int $id
 * @property string $slug
 * @property string $topic
 * @property string $title
 * @property string $body
 * @property ?string $feature
 * @property int $position
 * @property bool $is_published
 */
class HelpArticle extends Model
{
    protected $fillable = ['slug', 'topic', 'title', 'body', 'feature', 'position', 'is_published', 'updated_by'];

    protected static function booted(): void
    {
        $changed = function (): void {
            PageCache::clear();
            HelpArticles::forget();
        };
        static::saved($changed);
        static::deleted($changed);
    }

    protected function casts(): array
    {
        return ['position' => 'integer', 'is_published' => 'boolean'];
    }
}
