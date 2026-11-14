# Handover — Kulmis Market

_Last updated: PR 14._

## What this is
Kulmis Market is an online shop for handcrafted products, plus a trading-signals
service and a paid monthly membership with a 3-level referral program.
Customers are in Ethiopia, the only currency is ETB, and the UI is English
(ready for Amharic later).

## Current state
| PR | Branch | Status |
|----|--------|--------|
| 1 | `pr/01-scaffold` | Draft. Laravel 13, Docker, CI, docs |
| 2 | `pr/02-platform-core` | Draft. Feature switches, settings, roles, activity log, admin 2FA ([docs/platform.md](docs/platform.md)) |
| 3 | `pr/03-money-ledger` | Draft. Double-entry ledger, wallet buckets, idempotency ([docs/ledger.md](docs/ledger.md)) |
| 4 | `pr/04-catalog` | Draft. Products, variants, images, early access, members-only, cost & 2.5× warning ([docs/catalog.md](docs/catalog.md)) |
| 5 | `pr/05-pricing` | Draft. Member price, coupons, free shipping, Join & save, profit check ([docs/pricing.md](docs/pricing.md)) |
| 6 | `pr/06-cart-checkout` | Draft. Cart, guest checkout (idempotent, no oversell), tracking, unpaid auto-cancel ([docs/checkout.md](docs/checkout.md)) |
| 7 | `pr/07-shipping-notify` | Draft. Zones by city + weight, couriers, status flow, SMS/email/Telegram messages ([docs/shipping-notifications.md](docs/shipping-notifications.md)) |
| 8 | `pr/08-payments` | Draft. Chapa (signature + re-verify), manual proof, exactly-once, refunds, PDF receipts ([docs/payments.md](docs/payments.md)) |
| 9 | `pr/09-membership` | Draft. Customer accounts, monthly membership, grace, reminders, loyalty reward ([docs/membership.md](docs/membership.md)) |
| 10 | `pr/10-signals` | Draft. Signals plan, Telegram bot (personal links, join approval, auto-removal), locked results, performance page ([docs/signals.md](docs/signals.md)) |
| 11 | `pr/11-referrals` | Draft. 3-level commissions, link/cookie/code, locked referrer, skip inactive, 14-day pending, refund reversal ([docs/referrals.md](docs/referrals.md)) |
| 12 | `pr/12-wallet-cashout` | Draft. Wallet spending, cash-out (min, name match, approval, reminders), fraud queue + holds ([docs/wallet-cashout-fraud.md](docs/wallet-cashout-fraud.md)) |
| 13 | `pr/13-engagement` | Draft. Badges, monthly top referrers, QR + share links, dashboard data |
| 14 | `pr/14-api-v1` | Draft. Order history, profile, SMS password reset, JSON 401s, full endpoint list ([docs/api.md](docs/api.md)) |

The full plan is in [docs/pr-plan.md](docs/pr-plan.md).

## Key decisions so far
- **Laravel at the repo root.** Shared hosting can point the document root at
  `public/` directly. The React admin will live in `admin/` (PR 15) and build
  into `public/admin`.
- **Money is integer santim** (1 ETB = 100). No floats anywhere. The ledger
  arrives in PR 3.
- **Queue:** database driver. On shared hosting, one cron line runs
  `schedule:run`, which drains the queue every minute
  (`QUEUE_VIA_SCHEDULER=true`). On a VPS, run a real `queue:work` and set it
  to false.
- **API** is versioned under `/api/v1`.
- **Tests:** in-memory SQLite locally for speed, MySQL 8 in CI.
- **Admin UI:** built on satnaing/shadcn-admin with shadcn/ui components. The
  user asked not to use `saas-kit`.
- **CI:** skips draft PRs. Only the top PR of the stack is marked ready.

## How to run
See [docs/local-setup.md](docs/local-setup.md).

## Next up
PR 15: admin app shell.
