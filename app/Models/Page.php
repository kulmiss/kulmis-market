<?php

namespace App\Models;

use App\Events\CatalogChanged;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $slug
 * @property string $title
 * @property string $body
 * @property ?string $seo_description
 * @property bool $is_published
 * @property bool $in_footer
 */
class Page extends Model
{
    /** @var array<string, mixed> */
    protected $attributes = ['is_published' => true, 'in_footer' => true, 'position' => 0];

    protected $fillable = ['slug', 'title', 'body', 'seo_description', 'is_published', 'in_footer', 'position'];

    protected $dispatchesEvents = ['saved' => CatalogChanged::class, 'deleted' => CatalogChanged::class];

    protected function casts(): array
    {
        return ['is_published' => 'boolean', 'in_footer' => 'boolean'];
    }

    /** Markdown → safe HTML (raw HTML in the text is escaped). */
    public function html(): string
    {
        return Str::markdown($this->body, ['html_input' => 'escape', 'allow_unsafe_links' => false]);
    }
}
