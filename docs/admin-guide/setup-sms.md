---
title: Connect SMS (later)
part: 1. Setup
order: 9
---
Until you connect a provider, SMS only go to the log file. **Email and Telegram still work.**

Any provider with a simple web API works: it must accept `POST {to, message, sender}` with a token.

1. Choose a provider and get the API address and token.
2. In `.env`:
   ```
   SMS_DRIVER=http
   SMS_URL=https://…
   SMS_TOKEN=…
   SMS_SENDER=KulmisMkt
   ```
3. `php artisan optimize`
4. Test: Settings → **Run setup again** → page 4 → SMS → **Test** (it sends to the phone on your admin account).

If your provider expects a different format, a developer changes one small file: `app/Notify/Sms/HttpSmsGateway.php`.
