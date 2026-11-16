---
title: Connect email
part: 1. Setup
order: 6
---
The shop sends order messages, membership reminders and **"Was this you?"** safety messages by email.

1. Get the SMTP details from your host (cPanel → Email Accounts → Connect Devices) or from a mail service.
2. In `.env`:
   ```
   MAIL_MAILER=smtp
   MAIL_HOST=mail.your-domain
   MAIL_PORT=587
   MAIL_USERNAME=hello@your-domain
   MAIL_PASSWORD=…
   MAIL_FROM_ADDRESS=hello@your-domain
   MAIL_FROM_NAME="Kulmis Market"
   ```
3. `php artisan optimize`
4. Test: Settings → **Run setup again** → page 4 → Email → **Test**. Or Admin → Status → Mail.

**Emails go to spam?** Ask your host to set up SPF, DKIM and DMARC for your domain. Use a `MAIL_FROM_ADDRESS` on your own domain.
