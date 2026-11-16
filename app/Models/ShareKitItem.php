<?php

namespace App\Models;

use App\Referral\ShareLinks;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * One ready-made post for members to share (text + optional picture).
 *
 * @property int $id
 * @property string $title
 * @property string $target
 * @property string $text
 * @property ?string $image_path
 * @property int $position
 * @property bool $is_active
 */
class ShareKitItem extends Model
{
    public const TARGETS = ['shop' => 'Shop (home page)', 'membership' => 'Membership page', 'signals' => 'Signals page'];

    protected $fillable = ['title', 'target', 'text', 'image_path', 'position', 'is_active'];

    protected function casts(): array
    {
        return ['position' => 'integer', 'is_active' => 'boolean'];
    }

    public function path(): string
    {
        return $this->target === 'shop' ? '' : $this->target;
    }

    public function imageUrl(): string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : asset('og-default.png');
    }

    /** The text with this member's name, code and link filled in. */
    public function textFor(User $u): string
    {
        return strtr($this->text, ['{name}' => $u->first_name, '{code}' => (string) $u->referral_code, '{link}' => ShareLinks::for($u, $this->path())]);
    }
}
