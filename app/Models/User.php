<?php

namespace App\Models;

use App\Demo\HidesDemo;
use App\Membership\Members;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $first_name
 * @property string $last_name
 * @property ?string $phone
 * @property ?string $email
 * @property string $role
 * @property string $status
 * @property ?string $two_factor_secret
 * @property ?array<int, string> $two_factor_recovery_codes
 * @property ?Carbon $two_factor_confirmed_at
 * @property ?int $two_factor_last_step
 * @property ?int $telegram_user_id
 * @property ?string $referral_code
 * @property ?Carbon $referral_code_changed_at
 * @property ?int $referrer_id
 * @property ?Carbon $referred_at
 * @property int $failed_logins
 * @property ?Carbon $locked_until
 * @property ?Carbon $password_changed_at
 * @property ?string $pending_email
 * @property ?Carbon $pending_email_expires_at
 * @property-read string $name
 */
class User extends Authenticatable
{
    use HidesDemo;

    /** @return list<string> */
    protected static function demoOwnerColumns(): array
    {
        return [];
    }

    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const ROLE_CUSTOMER = 'customer';

    public const ROLE_SUPER_ADMIN = 'super_admin';

    /** @var array<string, mixed> */
    protected $attributes = ['role' => self::ROLE_CUSTOMER, 'status' => 'active', 'last_name' => ''];

    protected $fillable = ['first_name', 'last_name', 'phone', 'email', 'password'];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'pending_email_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'telegram_user_id' => 'integer',
            'referral_code_changed_at' => 'datetime',
            'referred_at' => 'datetime',
            'failed_logins' => 'integer',
            'locked_until' => 'datetime',
            'password_changed_at' => 'datetime',
            'pending_email_expires_at' => 'datetime',
        ];
    }

    /** @return Attribute<string, never> */
    protected function name(): Attribute
    {
        return Attribute::get(fn () => trim($this->first_name.' '.$this->last_name));
    }

    /** "Abebe K." — the only form other members ever see. */
    public function publicName(): string
    {
        $initial = mb_substr($this->last_name, 0, 1);

        return $this->first_name.($initial !== '' ? ' '.mb_strtoupper($initial).'.' : '');
    }

    public function isStaff(): bool
    {
        return $this->role !== self::ROLE_CUSTOMER && array_key_exists($this->role, config('permissions.roles'));
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === self::ROLE_SUPER_ADMIN;
    }

    public function hasAbility(string $ability): bool
    {
        if (! $this->isStaff()) {
            return false;
        }
        $abilities = config("permissions.roles.{$this->role}.abilities", []);

        return in_array('*', $abilities, true) || in_array($ability, $abilities, true);
    }

    public function hasTwoFactor(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    /** @return HasOne<Membership, $this> */
    public function membership(): HasOne
    {
        return $this->hasOne(Membership::class);
    }

    /** @return BelongsTo<User, $this> */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(self::class, 'referrer_id');
    }

    /** @return HasMany<User, $this> */
    public function referrals(): HasMany
    {
        return $this->hasMany(self::class, 'referrer_id');
    }

    public function isActiveMember(): bool
    {
        return Members::isActive($this);
    }

    public function isActiveAccount(): bool
    {
        return $this->status === 'active';
    }
}
