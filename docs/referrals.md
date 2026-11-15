# Referral program

## Rates (all editable in settings, each level and type switchable)
| Sale | Base | L1 | L2 | L3 |
|------|------|----|----|----|
| Membership | month price | 10% | 5% | 2% |
| Signals (guests) | month price | 10% | — | — |
| Products | goods after member discount and coupon, **no shipping** | 6% | 3% | 1% |

Commissions round down to the santim.

## Rules
- **Only active members earn** (active or in grace). If a level's
  recipient isn't active, that level is recorded as `skipped` and **not passed
  up**: the shop keeps it.
- **Anyone can be referred**, guests included. Level 1 is unlimited
  (unilevel), and the program goes 3 levels deep.
- **Link + code:** every customer gets a short code (`ABDI25`) and a link
  `/r/ABDI25`. A link (or `?ref=CODE` on any page) sets the essential cookie
  `km_ref`: just the code, for 30 days, and the latest click wins. The code
  field at sign-up and checkout is the backup and wins over the cookie.
- **Locked referrer:** set at sign-up and never changed by later links. Only
  Super Admin can change it (with a reason; it's logged, and loops are
  refused): `POST /api/v1/admin/referrals/members/{id}/referrer`.
- **Guests:** the referrer is stored per order, and on the signals
  subscription at the first purchase.
- **Code change:** a member can change their code **once** (4–12 letters or
  numbers, unique, no offensive or reserved words, l33t spellings included).
- **Self-referral:** the same account, phone or email is skipped. A sign-up
  in the referrer's own browser (shared family phone) **is linked** but the
  referrer's earnings wait for review; see
  [wallet-cashout-fraud.md](wallet-cashout-fraud.md#referral-abuse).
- **Team view:** a new sign-up shows in the referrer's level 1 straight away
  as "not active" and turns active once they pay. The admin tree
  (`/api/v1/admin/referrals/tree/{id}`) and the member team
  (`/api/v1/referral`) read the same `referrer_id` chain, and a test checks
  they match.
- **Tested end to end** (`tests/Feature/Referral/ReferralEndToEndTest.php`):
  A → B (`/r/` link) → C (`?ref=`) → D (typed code), every level's
  commission pending 14 days then available, and a guest buying through A's
  link.

## Money flow
1. `PaymentSucceeded` creates one commission row per level
   (`unique(source, level)`, so never twice) and posts to the ledger:
   `expense:commissions` → `user:{id}:pending`.
2. `referrals:release` (hourly) moves pending → available after
   `referral.pending_days` (14), unless the commission is on hold
   (fraud queue).
3. `PaymentRefunded` reverses commissions: all of them on a full refund, or
   proportionally to the refund over the amount paid. Pending earnings come
   off pending; already-available earnings come off available.

## Member view (privacy)
`GET /api/v1/referral`: code, link, team level 1 (first name + last
initial, joined date, active), level 2/3 counts, earnings per level, wallet.
`GET /api/v1/referral/history`. No phone, email, address, purchases or other
members' wallets, ever.

## Admin
`GET /api/v1/admin/referrals/tree/{user}` (3 levels down plus the upline,
with wallets) and `GET /api/v1/admin/referrals/commissions` (filters).

## Engagement (PR 13)
- **Badges** go by the number of *active* level-1 members: Bronze 3, Silver 10,
  Gold 25 (editable, `badges` switch). `progress` also says how many more
  are needed for the next badge.
- **Top referrers** (`GET /api/v1/referral/top?month=YYYY-MM`, public,
  `top_referrers` switch): ranked by new level-1 people this month who are
  active members. Shows **first name + badge only**. Cached for 1 hour.
- **Dashboard data** (`GET /api/v1/referral`): link, code, team, earnings per
  level, pending/available balances, badge progress, Telegram and WhatsApp
  share links, renewal date. `GET /api/v1/referral/qr.svg` returns the QR
  code for the referral link. Cash-outs and history: `GET /api/v1/wallet`.
- Products API filters: `members_only=1` ("Members-only product of the
  month") and `early_access=1`.
