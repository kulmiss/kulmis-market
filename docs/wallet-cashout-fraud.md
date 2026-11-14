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
