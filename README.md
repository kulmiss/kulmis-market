# Kulmis Market

An online shop for handcrafted Ethiopian products, a trading-signals service
in a private Telegram channel, and a monthly membership (member prices, free
shipping, early access, signals) with a 3-level referral program and wallet.
Prices in ETB.

- **Backend:** Laravel 13 + MySQL, database queue + one cron line
- **Storefront:** Blade + Tailwind, static page cache, no tracking
- **Admin:** React (shadcn-admin / shadcn/ui) at `/admin`, 2FA required
- **API:** REST `/api/v1` (ready for a Flutter app)
- **Payments:** Chapa (Telebirr, CBE Birr, M-Pesa, cards) + bank/Telebirr
  transfer with proof

Start here:
- [handover.md](handover.md): state, PRs, merge order, decisions
- [docs/local-setup.md](docs/local-setup.md): run it on your computer
- [docs/deploy.md](docs/deploy.md): shared hosting and VPS, connecting
  Chapa, Telegram and SMS
- [QUESTIONS.md](QUESTIONS.md): every decision taken, and what's still open
- [SECURITY_REPORT.md](SECURITY_REPORT.md): the security and quality review, scores and remaining risks
- `docs/`: one page per area (ledger, payments, referrals, signals…)
