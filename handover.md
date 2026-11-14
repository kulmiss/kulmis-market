# Handover — Kulmis Market

_Last updated: PR 9._

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
PR 10: signals + Telegram.
