<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property ?string $phone
 * @property ?string $tracking_url
 * @property bool $is_active
 */
class Courier extends Model
{
    /** @var array<string, mixed> */
    protected $attributes = ['is_active' => true];

    protected $fillable = ['name', 'phone', 'tracking_url', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function trackingLink(?string $number): ?string
    {
        if (! $this->tracking_url || ! $number) {
            return null;
        }

        return str_replace('{number}', rawurlencode($number), $this->tracking_url);
    }
}
