---
title: Connect Telegram (signals)
part: 1. Setup
order: 8
---
Only needed if you sell **trading signals**.

1. In Telegram, open **@BotFather** → `/newbot` → choose a name and a username. Copy the **token**.
2. Create a **private channel** for the signals.
3. Add your bot to the channel as an **admin** with the rights **Invite users** and **Ban users**.
4. Get the channel id (it starts with `-100`): forward a channel post to @userinfobot, or use the bot API.
5. In `.env`:
   ```
   TELEGRAM_BOT_TOKEN=123456:ABC…
   TELEGRAM_BOT_USERNAME=kulmis_bot
   TELEGRAM_CHANNEL_ID=-100…
   TELEGRAM_WEBHOOK_SECRET=(a long random text)
   TELEGRAM_ADMIN_CHAT_ID=(your own chat id, for alerts and cash-out reminders)
   ```
6. `php artisan optimize` and then `php artisan telegram:set-webhook` (needs HTTPS).
7. Check: Admin → **Signals → Telegram bot** shows the bot and the webhook as OK.

**How people get in**: they get a personal link by message → the bot links their Telegram account → it approves their join request. A forwarded link does not work for anyone else. When access ends they are removed, and can come back after paying again.
