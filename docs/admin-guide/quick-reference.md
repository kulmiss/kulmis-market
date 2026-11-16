---
title: Quick reference (cheat sheet)
part: 4. Quick reference
order: 1
pages: /guide
---
**Every day** (10 minutes)
- [ ] Dashboard: the yellow boxes (receipts, orders, fraud)
- [ ] **Payments** → approve or reject receipts
- [ ] **Orders** → make, ship, deliver
- [ ] **Cash-outs** → pay the ones due (within {payout_days} days)

**Every week**
- [ ] **Money health**: green?
- [ ] **Fraud checks**: clear or confirm
- [ ] Download the newest backup to your computer
- [ ] **Help centre**: answer messages; look at articles with many *No*

**Every month**
- [ ] On the 1st: **Badge bonus** → Pay now (or Skip)
- [ ] `php artisan km:backup-verify` → *Backup OK*
- [ ] `php artisan km:doctor` → all green
- [ ] Money health → Last month

**Important commands** (on the server)
| Command | What it does |
|---|---|
| `php artisan km:doctor` | Safety and go-live check |
| `php artisan km:backup` | Make a backup now |
| `php artisan km:backup-verify` | Check the newest backup opens |
| `php artisan km:create-admin email --role=finance` | Add a staff account |
| `php artisan km:reset-2fa email` | Two-step sign-in off for one account (lost phone) |
| `php artisan telegram:set-webhook` | Connect the Telegram bot |
| `php artisan optimize` | After every update or `.env` change |
| `php artisan down` / `up` | Maintenance mode on / off |

**Where to find things**
| I want to… | Go to |
|---|---|
| approve a receipt | Payments |
| refund | Payments → Filters → Status: all → open → Refund… |
| ship an order | Orders → open → Mark making → Courier → Mark shipped |
| pay a cash-out | Cash-outs → Pay |
| change a price or a rate | Settings |
| switch something off | Feature switches |
| change a referrer | Members → member → Referral → Change referrer |
| fix a membership | Members → member → Membership |
| change a help text | Help centre → Articles |
| run the setup again | Settings → Run setup again |

**The money rules right now** (read live from your settings)

{money_rules}
