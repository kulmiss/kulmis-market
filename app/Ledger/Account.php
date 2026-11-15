<?php

namespace App\Ledger;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $code
 * @property string $type
 * @property ?int $user_id
 * @property string $name
 */
class Account extends Model
{
    public const ASSET = 'asset';

    public const LIABILITY = 'liability';

    public const INCOME = 'income';

    public const EXPENSE = 'expense';

    public const EQUITY = 'equity';

    // System accounts.
    public const CASH = 'asset:cash';                       // money in bank/Telebirr

    public const COMMISSIONS = 'expense:commissions';

    public const LOYALTY = 'expense:loyalty';

    public const BADGE_BONUSES = 'expense:badge_bonuses';

    public const WALLET_SALES = 'income:wallet_sales';      // orders/memberships paid with wallet

    public const ADJUSTMENTS = 'equity:adjustments';        // counterpart for manual corrections

    protected $table = 'ledger_accounts';

    protected $fillable = ['code', 'type', 'user_id', 'name'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Posting, $this> */
    public function postings(): HasMany
    {
        return $this->hasMany(Posting::class, 'ledger_account_id');
    }

    /** Debit-positive accounts: asset, expense. */
    public function isDebitNormal(): bool
    {
        return in_array($this->type, [self::ASSET, self::EXPENSE], true);
    }
}
