# Trading signals + Telegram bot

**One plan, all signals:** guests pay `signals.guest_price` (4,000 ETB) per
month. Signals are **included** for active members.

## Access flow
1. A guest pays by transfer and sends the receipt
   (`POST /api/v1/signals/payment-proof`); staff approve it. A member gets access automatically when the
   membership activates (`GET /api/v1/signals/member-access` returns the link).
2. The person gets a **personal deep link** by SMS/email:
   `https://t.me/<bot>?start=<token>`.
3. In Telegram, `/start <token>` binds *their* Telegram account to the
   subscription (once; a second account is refused). The bot then sends a
   channel link that creates a **join request**.
4. The bot approves the join request only for bound, entitled accounts. If
   the link is forwarded, anyone else's request is declined.
5. When access ends (after the grace period, a refund, membership expiry,
   or by admin), `signals:sweep` (hourly) and the `MembershipEnded` listener
   remove the person: ban, then immediately unban, so they can rejoin after
   paying again.

## Posting signals (admin)
`POST /api/v1/admin/signals` takes pair, direction, entry, targets, stop loss
and notes, and posts to the channel. A signal can be edited while open.
`POST /signals/{id}/close` sets the result (win / loss / breakeven /
cancelled, plus a value and note), posts the result as a reply, and
**locks** the signal: it can never be edited or deleted after that.

## Public performance page
`GET /api/v1/signals/performance` shows the win rate, totals and the last 50
closed signals. It's switchable (`signals.performance_page`). The risk
notice text is editable (`signals.risk_notice`), so you can put your own
wording there.

## Admin subscribers
List with filters, manual add for N days (with a reason; they get a link),
remove now. `GET /api/v1/admin/signals/telegram` shows the bot and webhook
status.

## Setup
1. Create a bot with @BotFather and set `TELEGRAM_BOT_TOKEN` and
   `TELEGRAM_BOT_USERNAME`.
2. Create a **private channel** and add the bot as an **admin** with "Invite
   users" and "Ban users" rights.
3. Put the channel id (e.g. `-100…`) in `TELEGRAM_CHANNEL_ID` and a long
   random `TELEGRAM_WEBHOOK_SECRET`.
4. Run `php artisan telegram:set-webhook`.
