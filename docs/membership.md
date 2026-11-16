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

## When membership (or signals) is switched off
- Nobody can buy or renew (pages and API answer 404; visitors see nothing of it).
- People who already paid keep it until their paid month ends — member
  status, team earnings and the signals channel. There is no grace time while
  it is off (nobody can pay), then they leave as normal (hourly sweep), also
  from the Telegram channel. Signals bought without membership work the same.
- Before switching off, the Super Admin sees how many people keep it and until
  when (`GET /api/v1/admin/features/paid-access/{membership|signals}`).
- **End access now** (`POST …/paid-access/{feature}/end`, `credit: true|false`,
  only while the feature is off) ends it for everyone at once. With `credit`,
  the days not used go to their wallet: each paid month counts at what it
  cost (half a month left of 5,000 ETB = 2,500 ETB), ledger type
  `access.unused_credit` ("Money back for days not used"). Guest signal
  buyers without an account can't get a wallet credit; the list shows their
  amount so you can refund by hand.
