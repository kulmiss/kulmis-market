<?php

namespace App\Models;

use App\Help\HelpTokens;
use App\Storefront\PageCache;
use App\Support\FeatureText;
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

    /** Shown to customers? Not while it talks about a switched-off feature (the admin is warned). */
    public function shownNow(): bool
    {
        return FeatureText::mentionsOff($this->question.' '.$this->answer) === [];
    }

    /** The answer with {if feature} parts applied. */
    public function answerNow(): string
    {
        return HelpTokens::apply(FeatureText::apply((string) $this->answer));
    }
}
