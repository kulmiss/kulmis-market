# Wallet, cash-out and anti-fraud

## Paying from the wallet (`wallet.spend`)
- Checkout: `use_wallet: true` (signed-in members). The wallet pays up to
  the full total. Membership: `POST /api/v1/membership/pay {method: "wallet"}`
  or `{method: "chapa", use_wallet: true}` for wallet + Chapa.
- The wallet part is its own `Payment` (provider `wallet`). The ledger moves
  `user:{id}:available` → the sale's income account, with an overspend guard.
- If the wallet covers everything, the order or membership is paid at
  once and commissions fire. Otherwise Chapa (or a proof) pays the rest.
- An unpaid order that gets cancelled puts the wallet part back. A refund of
  the wallet part goes back to *available*.
- Commissions count the wallet part as "paid" (QUESTIONS.md #17).

## Cash-out (`wallet.cashout`)
`POST /api/v1/wallet/cash-out {amount, method: telebirr|bank, account_name, account_number, bank_name?}`
- Minimum `wallet.cashout_min` (1,000 ETB), any time, at most the
  available balance.
- **The account name must match the member's name** (first and last name,
  any order or case).
- The amount moves to the `payout` hold straight away. The account number is
  stored encrypted, and members only ever see it masked.
- Admin (`approve-cashouts`): `GET /api/v1/admin/cash-outs` (due-first,
  `overdue` filter). Opening a request shows the full account number; you
  send the money, then `POST …/pay {reference}`, or `…/reject {reason}`,
  which returns the money to the wallet. The member is messaged either way.
- **Paid within 3 days:** `due_at` = request + `wallet.payout_days`. The
  daily `payouts:remind` job sends you the list (Telegram
  `TELEGRAM_ADMIN_CHAT_ID` + email) for anything due within a day or overdue.

## Anti-fraud
| Check | When | Effect |
|-------|------|--------|
| Same phone / email | sign-up, every commission | not linked / level skipped |
| Same device (hashed cart token, no tracking cookie) | sign-up, guest orders | not linked / skipped + flag |
| Paid with the recipient's own phone or payout account | every commission | skipped + flag |
| More than `referral.daily_signup_limit` sign-ups in a day | sign-up | flag (still linked) |
| Payout account used by another member | cash-out | both flagged |

**An open flag puts the member on hold:** earnings stay pending and cash-outs
wait as `on_hold`. Review queue: `GET /api/v1/admin/fraud`, then
`POST /api/v1/admin/fraud/{id}/review {decision: cleared|confirmed, suspend?}`.
Clearing releases the holds. You can also flag someone by hand.

## Money safety (security review)
- **Saved cash-out account.** Members save one Telebirr/bank account
  (Account → Wallet, or `PUT /api/v1/wallet/payout-account`). Saving needs
  the password, plus a 2FA code when two-step sign-in is on. The name must
  match theirs.
- **48-hour wait.** A new or changed account can be used after
  `wallet.payout_account_hold_hours` (48). The member is told on every
  channel ("Was this you?"), and open requests made before the change go on
  hold.
- **Verified accounts only.** The first payout to an account needs Super
  Admin to tick "I checked the account" (the name shown by Telebirr/the
  bank). After that the account is verified.
- **Daily limits.** At most `wallet.cashout_daily_count` (2) requests and
  `wallet.cashout_daily_max` (50,000 ETB) per member in 24 hours.
- **New accounts.** The first cash-out of a member who joined less than
  `wallet.new_account_review_days` (30) ago is held for review.
- **Idempotent.** The app sends an `Idempotency-Key` header and the website
  form a hidden `request_id`, so a retry or double click never creates two
  requests.
- **Chapa double click.** Starting a payment twice within 30 minutes reuses
  the same checkout, so a customer can't pay twice by accident.

## Referral abuse
- Self-referral: same phone, email, browser (cart token hash) or payment
  account. The link is refused, or the commission is skipped and flagged.
- Fake accounts: 2+ of a referrer's sign-ups from the same browser, or 3+
  from the same network within 24 h (`referral.same_network_signups`), flag
  the referrer. Their earnings and cash-outs wait for review. The network is
  kept only as a keyed hash in the cache for 24 h, never in the database.
- Sign-up rate limit: 5 per hour and 20 per day per network.
- Shared payout account between members: both are flagged.
