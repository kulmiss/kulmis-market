---
title: Problems and answers
part: 3. Problems and answers
order: 1
---
### "A member says they didn't get their earning"
**Why**: (1) the payment is still waiting for approval; (2) the earning is **on the way** for {pending_days} days; (3) their own membership was not active when the payment came (then that level is skipped); (4) the payment was refunded (taken back); (5) a fraud flag holds it; (6) they bought from themselves.
**Fix**: Members → open the member → **Referral** and **Team earnings**: each line shows its status. *Skipped* and *taken back* are correct. *On hold* → **Fraud checks** → **Review** → *It's fine — clear and release holds* if it is fine.

### "Payment paid but the order is not marked paid"
**Why**: the receipt was sent but nobody approved it yet. Orders become **Paid** only from an approved payment.
**Fix**: **Payments** → *Receipt to check* → find the name or order → **Approve**. Not in the list? Filters → Status: **all** and search: it may have been rejected (the reason is shown).

### "Telegram didn't add someone"
**Why**: (1) their access is not active (unpaid or ended); (2) they opened the link with a different Telegram account than the first time; (3) the bot is not admin of the channel, or lost the *Invite users* right; (4) the webhook is not set.
**Fix**: **Signals → Telegram bot** must show OK; if not, run `php artisan telegram:set-webhook`. **Subscribers** → check their access. To start again: **Remove now**, then add them again: they get a new personal link.

### "Cash-out stuck"
**Why**: (1) the first cash-out of a new account waits for a check; (2) a fraud flag is open; (3) the cash-out account was changed less than {account_hold_hours} hours ago; (4) nobody pressed **I sent it — mark paid**.
**Fix**: **Cash-outs** → open it: the reason is shown. Check the account name, pay, type the reference, mark paid. Or **Do not pay, put money back** with a reason.

### "The site is slow"
**Fix**: (1) Admin → **Status**: jobs waiting or failed? Check the cron line. (2) Run `php artisan optimize`. (3) Turn on Cloudflare. (4) Big product photos are made small by the shop; don't upload 20 MB files. (5) On shared hosting, ask your host for more memory, or move to a VPS.

### "Emails are not arriving"
**Fix**: Admin → **Status** → Mail. `MAIL_MAILER=log` means emails only go to the log: add your SMTP details ([Connect email](setup-email.md)). Test from the setup wizard page 4. In spam? Ask your host for SPF, DKIM and DMARC. No cron line? Messages wait in the queue: add the cron line.

### "I lost my 2FA phone"
**Fix**: (1) Sign in with a **recovery code** (Use a recovery code instead) and set up 2FA again in My account. (2) No codes left: on the server run `php artisan km:reset-2fa you@example.com`. Then sign in and set up 2FA again. Save the new recovery codes!

### "A member forgot their password"
They tap **Forgot password?** on the sign-in page and get a code by SMS or email. Staff never see or set passwords.

### "Two people bought the last piece"
They can't: stock is taken when the order is placed. The second person sees *sold out*.

### "I changed a price but the shop still shows the old one"
The page cache updates by itself. If not: `php artisan optimize`.

### "Money health is yellow or red"
Read the reasons under the light. Late cash-outs → pay them. Members got too much → lower rates or skip the badge bonus this month. See [Dashboard and Money health](daily-dashboard-money-health.md).

### "km:doctor shows red"
Each red line says how to fix it (for example `APP_DEBUG=false`). Fix them all before going live.
