# Membership

- **Price:** `membership.price` (default 5,000 ETB) per month. Monthly only.
- **Accounts:** `POST /api/v1/auth/register` (first/last name, Ethiopian
  phone, optional email, password) and `POST /api/v1/auth/login` (phone or
  email). Both return a Sanctum token. Staff can't get API tokens; they use
  the admin login with 2FA.
- **Buying:** `POST /api/v1/membership/pay {method: wallet}` when the wallet
  covers the price, or `POST /api/v1/membership/payment-proof` for a transfer
  (add `use_wallet: true` to pay part from the wallet and transfer the rest).
  Each payment adds 1 month:
  - renewing **before access ends** (including the grace period) adds the
    month after the current end and keeps the membership *continuous*
  - renewing after expiry starts a new period today and resets
    `continuous_since`
- **Grace period:** `membership.grace_days` (default 3). All benefits
  continue during grace (QUESTIONS.md #10).
- **Expiry:** `memberships:sweep` runs hourly: active → grace → expired.
  When it expires, discounts, signals and earnings stop. The wallet is
  kept, and `MembershipEnded` fires (the signals channel removal listens to
  it).
- **Reminders:** 3 days before, on the day and 1 day after (all editable),
  each sent once per period by SMS, email and Telegram. The texts are
  editable (`notify.membership_*`).
- **Loyalty reward:** `loyalty.reward` (300 ETB) is credited to the wallet
  as *available* after every `loyalty.every_months` (3) months of continuous
  membership. It can't be granted twice (unique key + ledger idempotency)
  and is switchable (`loyalty_reward`).
- **Refunds:** a fully refunded month is taken back. If that ends access,
  the membership ends now.
- **Admin:** `/api/v1/admin/members` (filters), member detail (membership,
  purchases, wallet), extend or end with a reason, suspend.
