# Handover — Kulmis Market

_Last updated: PR 2 (platform core)._

## What this is
Kulmis Market is an online shop for handcrafted products, plus a trading-signals
service and a paid monthly membership with a 3-level referral program.
Customers are in Ethiopia, the only currency is ETB, and the UI is English
(ready for Amharic later).

## Current state
| PR | Branch | Status |
|----|--------|--------|
| 1 | `pr/01-scaffold` | Draft. Laravel 13, Docker, CI, docs |

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
PR 3: money ledger.
