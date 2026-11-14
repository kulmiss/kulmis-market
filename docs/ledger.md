# Money ledger

- **Integer santim everywhere** (1 ETB = 100). `App\Support\Money` handles
  rounding and formatting.
- **Rounding:** customer discounts round half-up (`bpsRound`). Commissions
  and rewards round down (`bpsFloor`).
- **Double entry:** `App\Ledger\Ledger::post($type, [[account, amount], ...])`.
  Amounts are + debit / − credit, and an entry must sum to zero. Everything
  runs inside a DB transaction with row locks on the accounts involved.
- **Balances are never stored.** They are summed from `ledger_postings`.
  Entries and postings are immutable (updates and deletes throw). To correct
  something, post a reversal (`Ledger::reverse`, which can be partial).
- **Idempotency:** `post(..., idempotencyKey: 'commission:payment:12:l1')`. A
  repeated key returns the first entry, so nothing is credited twice.
  `Idempotency::once($scope, $key, fn)` does the same for any other side effect.
- **Manual entries** (`manual.*`) need a reason and go into the activity log.
  Admin endpoint: `POST /api/v1/admin/ledger/adjustments`.
- **Overspend guard:** `post(..., nonNegative: [$walletAccount])` rolls back if
  the balance would drop below 0.

## Accounts
| Code | Type | Meaning |
|------|------|---------|
| `asset:cash` | asset | Money received (Chapa, bank, Telebirr) |
| `expense:commissions` | expense | Referral commissions |
| `expense:loyalty` | expense | Loyalty rewards |
| `income:wallet_sales` | income | Orders and memberships paid from a wallet |
| `equity:adjustments` | equity | Counterpart for manual corrections |
| `user:{id}:pending` | liability | Earnings inside the 14-day refund window |
| `user:{id}:available` | liability | Spendable / withdrawable |
| `user:{id}:payout` | liability | Cash-outs waiting for approval |
