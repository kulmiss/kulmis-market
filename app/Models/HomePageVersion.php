<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One version of the home page (see the home_page_versions migration).
 *
 * @property int $id
 * @property string $status
 * @property list<array{id:string, type:string, on:bool, data:array<string, mixed>}> $sections
 * @property ?array<string, mixed> $seo
 * @property ?string $note
 * @property ?string $template
 * @property ?int $restored_from_id
 * @property int $revision
 * @property ?Carbon $publish_at
 * @property ?Carbon $published_at
 * @property ?int $created_by
 * @property ?int $published_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class HomePageVersion extends Model
{
    public const DRAFT = 'draft';

    public const SCHEDULED = 'scheduled';

    public const PUBLISHED = 'published';

    protected $fillable = ['status', 'sections', 'seo', 'note', 'template', 'restored_from_id', 'revision', 'publish_at', 'published_at', 'created_by', 'published_by'];

    /** @var array<string, ?self> once per request */
    private static array $memo = [];

    protected static function booted(): void
    {
        static::saved(fn () => self::$memo = []);
        static::deleted(fn () => self::$memo = []);
    }

    protected function casts(): array
    {
        return [
            'sections' => 'array', 'seo' => 'array', 'revision' => 'integer',
            'publish_at' => 'datetime', 'published_at' => 'datetime',
        ];
    }

    /** The live home page: the newest publish (null = never published, the fixed home page is used). */
    public static function live(): ?self
    {
        // Kept per app instance (one request, one test), never across them.
        $key = 'live'.spl_object_id(app());

        return array_key_exists($key, self::$memo) ? self::$memo[$key]
            : self::$memo[$key] = self::query()->where('status', self::PUBLISHED)->orderByDesc('published_at')->orderByDesc('id')->first();
    }

    public static function draft(): ?self
    {
        return self::query()->where('status', self::DRAFT)->latest('id')->first();
    }

    public static function scheduled(): ?self
    {
        return self::query()->where('status', self::SCHEDULED)->latest('id')->first();
    }

    public static function forgetMemo(): void
    {
        self::$memo = [];
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}
