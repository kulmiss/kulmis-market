# Testing guide (by hand)

This is a short list. Test only these things. The automated tests (536 of them) already check the maths, the rules and every page in many ways. What is left for you: the things where **money, a broken shop or an angry customer** would hurt most, and where a real person's eyes help.

- **Must test**: 10 items, about 40 minutes. Do these first.
- **Should test**: 10 items. Do them when you have time this week.
- **Nice to test**: 5 items.

Tick each box `[x]` when it passes.

---

## Before you start (10 minutes, once)

### 1. Start the shop and reset the demo data

Open a terminal in the project folder and run:

```bash
docker compose up -d
docker compose exec app php artisan migrate:fresh --seed --force
docker compose exec app php artisan db:seed --class=DemoSeeder
```

This **deletes everything in your local shop** and fills it with fresh demo data (about 30 seconds). It never touches a live shop. Do this again any time you want to start clean.

- Shop: **http://localhost:8090**
- Admin: **http://localhost:8090/admin**
- Emails the shop sends (order paid, cash-out…): **http://localhost:8025** (Mailpit). No real email leaves your computer.

### 2. Demo accounts

Password for **all** of them: **`kulmis demo 2026`**

| Who | Email | Where |
|-----|-------|-------|
| Super Admin (you) | `demo-super_admin@kulmis.test` | /admin |
| Finance | `demo-finance@kulmis.test` | /admin |
| Fulfilment | `demo-fulfilment@kulmis.test` | /admin |
| Member with a big team (Selam) | `demo-member@kulmis.test` | /account |
| Brand-new member (Dawit) | `demo-new@kulmis.test` | /account |
| Shopper, not a member | `demo-shopper-0@kulmis.test` | /account |

**The first time you sign in to the admin**, it asks you to set up two-step sign-in: scan the QR code with an app on your phone (Google Authenticator, Microsoft Authenticator or Authy) and type the 6-digit code. After that, every admin sign-in asks for a code from that app. (Each staff account sets up its own.)

### 3. Testing with 2 people

Some tests need two people at the same time (for example an admin and a customer). Use:

- **Window A**: a normal Chrome window → the admin.
- **Window B**: a **private window** (Chrome: Ctrl+Shift+N, Mac: Cmd+Shift+N) → the customer.

A private window has its own sign-in, so both can be signed in at once. For a **third** person, use another browser (Edge, Firefox or Safari).

### 4. Testing as a phone

Most customers use a phone. Either:

- open **http://localhost:8090** on your computer, press **F12**, click the small phone icon (top left of the panel) and choose **iPhone 12 Pro** or **Galaxy S20**, or
- use your real phone on the same Wi-Fi: open `http://<your computer's IP>:8090` (only if your Docker allows it; otherwise use the F12 way).

### 5. If something is wrong

Send me:
1. The **test number** (for example "Must 3").
2. A **screenshot** of the whole screen.
3. The **address** in the address bar (copy it).
4. If there is an error page or a red message: copy its text.
5. For admin problems: press F12 → **Console** tab → screenshot anything in red.

---

## Must test (about 40 minutes)

### [ ] Must 1 — A guest buys and pays by bank transfer (5 min)
**Checks:** a customer without an account can buy, and the money only counts after you approve the receipt.
**Who:** Window B (private window) as a guest. Window A as Super Admin. Phone view in window B.

1. Window B: open http://localhost:8090, click **Shop**, open any product, click **Add to cart**.
2. Click the cart icon, click **Checkout**.
3. Type a name, phone `0911223344`, city `Addis Ababa`, an address. Click to place the order.
4. On the pay page choose **Telebirr or bank transfer**, upload any photo as the receipt, type reference `TEST1`, send it.
5. Write down the order number (starts with KM).
6. Window A: sign in to /admin, click **Payments**. Find the order. Click **Approve**.
7. Click **Orders**, open the order.

**You should see:** the customer sees "we received your receipt"; after you approve, the order says **Paid**; an email "we received your payment" is in Mailpit (http://localhost:8025) only if you typed an email. The total on the order is the same as the customer saw.
**If wrong:** screenshot of the pay page, the order page in the admin, and the order number.

### [ ] Must 2 — Member prices and free delivery are right (4 min)
**Checks:** members pay less and get free delivery above 3,000 ETB; others don't.
**Who:** Window A: `demo-member@kulmis.test` (Selam, a member). Window B (private): guest.

1. Both windows: open the same product, price around 1,500–3,000 ETB.
2. Both windows: add **2 pieces** to the cart and open the cart.

**You should see:** Selam's cart shows the **member price** (15% less) and **free delivery** when the goods are 3,000 ETB or more. The guest sees the normal price, a "member price" hint and pays delivery. Totals add up (goods − discount + delivery).
**If wrong:** screenshot of both carts side by side.

### [ ] Must 3 — Someone joins through a member's link, and the member earns (6 min)
**Checks:** the referral link connects the new person to the right member, and the earning appears.
**Who:** Window A: Selam `demo-member@kulmis.test`. Window B (private): a new person. Window C (other browser): Super Admin.

1. Window A: open /account. In **Your referral link**, click **Copy**.
2. Window B: paste the link and open it. Click **Sign up** / **Create account**. Use a new email like `test1@example.com` and a strong password.
3. Window B: on the dashboard, click **Join** (membership), pay by **Telebirr or bank transfer**, upload any photo, send.
4. Window C: /admin → **Payments** → **Approve** the membership payment.
5. Window A: reload /account, click **Earnings**.

**You should see:** the new person is in Selam's **Team** (level 1). A new earning for a membership is there, status **On the way** (ready in 14 days). In admin **Team tree**, the new person is under Selam.
**If wrong:** screenshots of Selam's Team and Earnings pages and the new person's email.

### [ ] Must 4 — A refund takes the earning back (4 min)
**Checks:** if you give money back, the team earning for it is taken back too (no money lost).
**Who:** Window C: Super Admin. Window A: Selam. (Continue from Must 3.)

1. Window C: /admin → **Payments**, open the membership payment from Must 3.
2. Click **Refund**, keep the full amount, type a reason, click **Refund … ETB**.
3. Window A: reload Selam's **Earnings**.

**You should see:** the earning shows **Taken back**. The new person's membership is ended. Selam's "On the way" total went down by the same amount.
**If wrong:** screenshot of the payment in the admin and Selam's Earnings page.

### [ ] Must 5 — Cash-out: ask, pay, and the wallet is right (5 min)
**Checks:** a member can only take out money they really have, and the wallet updates once.
**Who:** Window A: Selam. Window C: Super Admin (or Finance `demo-finance@kulmis.test`).

1. Window A: /account → **Wallet**. Write down **Ready to use**.
2. Try to cash out **more** than Ready to use. → You should see a clear "not enough" message.
3. Cash out **1,000 ETB** (or the smallest allowed). Confirm.
4. Window C: /admin → **Cash-outs**. Find Selam. Click **Pay**, type a reference, click **I sent it — mark paid**.
5. Window A: reload the Wallet.

**You should see:** after step 3, Ready to use went down by 1,000 and "Cash-out asked" went up by 1,000. After step 4, the cash-out shows **Paid** with your reference, and an email "we sent your cash-out" is in Mailpit. Clicking the button twice never pays twice.
**If wrong:** screenshot of the Wallet page before and after, and the Cash-outs page.

### [ ] Must 6 — A brand-new person can buy a membership on the phone (4 min)
**Checks:** joining works on a phone and benefits start at once.
**Who:** Window B (private), **phone view**, `demo-shopper-0@kulmis.test`. Window A: Super Admin.

1. Window B: sign in, open **Membership**, click **Join now**.
2. Pay by **Telebirr or bank transfer**, upload any photo, send.
3. Window A: /admin → **Payments** → **Approve**.
4. Window B: reload /account, then open any product.

**You should see:** the dashboard says **Active member · renews …** (one month from today). Products now show your member price. Nothing on the phone screen is cut off or needs sideways scrolling.
**If wrong:** phone screenshots of each step.

### [ ] Must 7 — Switching a feature off hides it completely (5 min)
**Checks:** when you switch Signals off, customers see nothing about signals.
**Who:** Window A: Super Admin. Window B (private): guest, then Selam.

1. Window A: /admin → **Feature switches**. Search `signals`. Switch **Trading signals** off. Read the box, click **Switch off**.
2. Window B: open http://localhost:8090. Look at the menu, home page, footer, **Membership** page.
3. Window B: open http://localhost:8090/signals.
4. Window B: sign in as Selam. Look at the dashboard and **More → Notifications** (settings at the bottom).
5. Window A: switch **Trading signals** on again. Window B: reload.

**You should see:** in steps 2–4 no word "signal" or "trading" anywhere; /signals shows **"We can't find that page"**; no "New trading signals" option in notification settings. After step 5 everything is back.
**If wrong:** screenshot of the page where you still see it, plus its address.

### [ ] Must 8 — Two people try to buy the last piece (3 min)
**Checks:** you never sell more pieces than you have.
**Who:** Window A: Super Admin. Window B (private): guest 1. Window C (other browser): guest 2.

1. Window A: /admin → **Products**, open a product, set **Stock** to `1`, save.
2. Window B and C: both add that product to the cart and go to checkout.
3. Window B: place the order. Then window C: place the order.

**You should see:** only the first order goes through. The second gets a clear message like "only 0 left / sold out". The product page now says **Sold out**.
**If wrong:** screenshots of both checkout screens.

### [ ] Must 9 — The admin is safe (3 min)
**Checks:** staff can only see what their job needs, and nobody gets in without two-step sign-in.
**Who:** Window B (private).

1. Sign in to /admin as `demo-fulfilment@kulmis.test`. Set up two-step sign-in if asked.
2. Look at the menu. Try to open http://localhost:8090/admin/cash-outs and http://localhost:8090/admin/features.
3. Sign out. Sign in to /admin with a **wrong** 6-digit code.

**You should see:** Fulfilment sees orders and shipping but **not** cash-outs, money or feature switches (opening them shows "not allowed"). A wrong code is refused.
**If wrong:** screenshot of the menu and the page that opened.

### [ ] Must 10 — Sign out (1 min)
**Checks:** signing out asks first and really signs you out.
**Who:** Phone view, Selam.

1. Sign in as Selam. Tap **More** → **Sign out**.
2. Tap **Cancel**. Then **Sign out** again and tap **Sign out**.
3. Press the browser's back button.

**You should see:** a box "Do you want to sign out?"; Cancel keeps you signed in; then the home page says **"You are signed out."**; going back does not show Selam's account.
**If wrong:** screenshots.

---

## Should test (when you have time this week)

### [ ] Should 1 — A wrong receipt is rejected
**Who:** guest (private window) + Super Admin.
1. Buy something, upload a receipt (Must 1 steps 1–4).
2. Admin → **Payments** → type a reason (for example "Amount is wrong") → **Reject**.

**You should see:** the order is not paid; the customer can send a new receipt; no earnings were made.
**If wrong:** screenshot of the payment and the order.

### [ ] Should 2 — A coupon
**Who:** Super Admin + guest.
1. Admin → **Coupons** → make a 10% coupon `TEST10`.
2. Guest: add a product, type `TEST10` in the cart.

**You should see:** 10% less on the goods (not on delivery). A wrong code says it is not valid.
**If wrong:** screenshot of the cart.

### [ ] Should 3 — Order status messages
**Who:** guest with an email + Super Admin.
1. Buy and get the order approved (Must 1, type an email this time).
2. Admin → the order → **Mark making**, then **Mark shipped** (choose a courier, tracking number `123`), then **Mark delivered**.
3. Open Mailpit http://localhost:8025.

**You should see:** one clear email for each step, with the right order number. On http://localhost:8090/track the customer sees the same status.
**If wrong:** screenshot of Mailpit and the order page.

### [ ] Should 4 — Membership switched off: paid members keep their benefits
**Who:** Super Admin + Selam + guest.
1. Admin → **Feature switches** → switch **Membership** off. Read the box (how many people keep it), click **Switch off**.
2. Selam: open a product and the cart.
3. Guest (private window): open /membership and a product.
4. Switch **Membership** on again.

**You should see:** Selam still gets member prices and free delivery. The guest sees no membership anywhere and /membership shows "We can't find that page". Nobody can join.
**If wrong:** screenshots.

### [ ] Should 5 — "End access now" with money back
**Who:** Super Admin.
1. Switch **Membership** off (Should 4).
2. Under the Membership switch, click **End access now**, tick **Add the days they did not use to their wallet**, confirm.
3. Sign in as Selam, open **Wallet**.
4. Switch Membership on again. (Reset the demo data afterwards.)

**You should see:** Selam's membership has ended and a line **"Money back for days not used"** is in the wallet, about the right amount for the days left.
**If wrong:** screenshot of the admin message and Selam's wallet.

### [ ] Should 6 — The money numbers in the admin make sense
**Who:** Super Admin.
1. Open the admin **Dashboard** and **Reports**.
2. Compare with what you did in Must 1–6.

**You should see:** the payments, refunds, cash-outs and team earnings you made are counted, once each.
**If wrong:** screenshot of the number and tell me what you expected.

### [ ] Should 7 — Phone: the member area
**Who:** phone view, Selam and Dawit (`demo-new@kulmis.test`).
1. Open every tab in the bottom bar (Home, Team, Earnings, Wallet, More) and every item under More.

**You should see:** everything readable, buttons big enough, nothing cut off, no sideways scrolling. Dawit (new member) sees a friendly "how to start".
**If wrong:** screenshot.

### [ ] Should 8 — Editing texts
**Who:** Super Admin.
1. Admin → **Settings** → change the shop name or a message text, save.
2. Admin → **Content** → edit the Terms page, save.
3. Open the shop in a private window.

**You should see:** your new text, right away.
**If wrong:** screenshot of the setting and the page.

### [ ] Should 9 — Demo data on and off
**Who:** Super Admin.
1. Admin → **Demo data** → switch **Show demo data** off.
2. Look at the shop and the admin dashboard. Switch it on again.

**You should see:** demo products, members and orders disappear and come back. (Do **not** click "Delete all demo data" unless you want to.)
**If wrong:** screenshot of what still shows.

### [ ] Should 10 — Help centre
**Who:** guest + Support (`demo-support@kulmis.test`).
1. Guest: open /help, search a word (for example `delivery`), then send a message with the contact form.
2. Support: /admin → **Help centre** → **Messages**.

**You should see:** answers appear when you search; the message arrives in the admin; mark it **Answered**.
**If wrong:** screenshot.

---

## Nice to test

### [ ] Nice 1 — Dark mode
Click the moon icon in the shop, the member area and the admin. **You should see:** everything readable in dark mode.

### [ ] Nice 2 — Gift card
Guest: buy a gift card at /gift-cards (pay by receipt), admin approves. Use the code in another cart. **You should see:** the code takes the amount off once; using it again shows the money left.

### [ ] Nice 3 — Badges
Selam: **More → Badges**. **You should see:** unlocked badges and the progress to the next one look right.

### [ ] Nice 4 — Share kit
Selam: **More → Share kit** → copy a post, make a picture card. **You should see:** the post has Selam's link and code.

### [ ] Nice 5 — Install the member app
On a real phone: open /account in Chrome (Android) or Safari (iPhone) → **Add to home screen**. **You should see:** an app icon that opens the member area.

---

## What you can't test on your computer

These need real keys, a real domain, or real phones. Test them **on the live server, before you announce the shop**, with small amounts:

| What | Why not locally | When to test |
|------|-----------------|--------------|
| **Chapa card / mobile payments** | needs your Chapa keys and a public web address for Chapa's "payment done" message | after you put the Chapa keys in `.env` on the live server: pay 10 ETB with a real Telebirr/card, check the order turns **Paid** by itself, then refund it |
| **Telegram bot and the signals channel** | needs the bot token and a public web address | after the bot is set up: buy signals with a test account, open the personal link, check you join the channel; end that access and check you are removed |
| **SMS** | no SMS provider yet (question A in QUESTIONS.md) | after you choose a provider: reset a password by SMS and get an order message on your phone |
| **Real emails** | locally they stop in Mailpit | after the domain and mail settings: sign up with your own email and check it arrives (and is not in spam) |
| **Phone notifications (push)** | need the real web address (https) | on the live site: allow notifications in the member app, then earn or get an order update |
| **Speed on real Ethiopian mobile internet** | your computer is too fast | on the live site, from a phone on mobile data |

---

## When all these pass, you're ready for the next step:

1. **All 10 "Must test" items pass** → the shop is safe to show to a few real people (friends and family) on the live server.
2. **Choose and set up** hosting + domain, Chapa keys, the Telegram bot and an SMS provider (QUESTIONS.md, "Still open": A, B, D).
3. **Do the "can't test locally" checks** on the live server with small real amounts.
4. **Delete the demo data** on the live server (Admin → Demo data → Delete all demo data), add your real products and set your prices.
5. **Open the shop**, and keep the "Should test" list for after each big change.
