<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A saved delivery address (members can keep several, one is the default).
 *
 * @property int $id
 * @property int $user_id
 * @property string $label
 * @property string $first_name
 * @property string $last_name
 * @property string $phone
 * @property string $city
 * @property string $address
 * @property bool $is_default
 */
class Address extends Model
{
    public const MAX_PER_USER = 10;

    protected $fillable = ['label', 'first_name', 'last_name', 'phone', 'city', 'address', 'is_default'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }
}
