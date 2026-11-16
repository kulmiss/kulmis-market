# Testing guide (by hand)

The two things that matter most:
1. **Members earn money by inviting people**, and every ETB is right.
2. **The shop makes money and never collapses.**

So the **Must test** part is one real-life story about money: Abdi invites Sara, Sara invites Hana, Hana invites Omar, Omar invites Ali. You check every number in ETB.

The same story also runs as an automatic test (`AbdiStoryTest`) on every build, so if any number in this guide ever changes, the build fails. Your job is to check that **the screens** show the same thing.

- **Must test**: 10 steps of the story, about 45 minutes.
- **Should test**: 10 items for the rest of the shop.
- **Nice to test**: 5 items.

Tick each box `[x]` when it passes.

---

## Before you start (10 minutes)

### 1. Start clean and create the story people

Open a terminal in the project folder:

```bash
docker compose up -d
docker compose exec app php artisan km:test-clock --reset
docker compose exec app php artisan migrate:fresh --seed --force
docker compose exec app php artisan km:story
```

This **deletes everything in your local shop** (never a live shop) and creates the people of the story. The last command prints a table and a line like `Last month for the badge bonus: 2026-10`. **Write that month down.**

**From now on, this moment is "day 0".**

- Shop: **http://localhost:8090**
- Admin: **http://localhost:8090/admin**
- Password for every story person: **`kulmis story 2026`**

| Who | Email | Notes |
|-----|-------|-------|
| Owner (you, Super Admin) | `owner@story.test` | approves receipts and cash-outs in /admin |
| Abdi | `abdi@story.test` | member for 2 months, code **ABDI**, already has **2,250 ETB ready to use** (from 3 people he invited last month: Bekele, Chaltu, Dawit) |
| Sara, Hana, Omar, Ali | you create them | `sara@story.test`, `hana@story.test`, `omar@story.test`, `ali@story.test` |

The first time you sign in to the admin, scan the QR code with an authenticator app on your phone (Google Authenticator, Microsoft Authenticator or Authy) and type the 6-digit code.

### 2. Moving time

Earnings are "on the way" for 14 days, memberships last a month, and links are remembered for 30 days. To test this without waiting, move the shop's clock:

```bash
docker compose exec app php artisan km:test-clock +15days
```

The shop now thinks it is 15 days later. The daily jobs (memberships, earnings "on the way" → "ready to use", badges) run at once. **After each clock move, reload the pages. You may have to sign in again.** This only works on your computer, never on a live shop.

### 3. Which browser for whom

| Browser | Who |
|---------|-----|
| **Chrome, normal window** | Abdi (and later Sara, Hana… when you check their numbers) |
| **Chrome, private window** (Ctrl+Shift+N / Cmd+Shift+N) | guests and new people signing up |
| **Edge (or Firefox), normal window** | the owner in /admin |
| **Edge (or Firefox), private window** | only for Step 9 |

**Important:** before each new person signs up or each new guest buys, **close all Chrome private windows** and open a new one. Otherwise the shop thinks it's the same person (that's a fraud check, see Step 6).

### 4. Where to read the numbers

- A member's money: **/account/wallet** shows **On the way**, **Ready to use** and **Cash-out asked**. **/account/earnings** lists every earning: date, **from whom**, **which payment**, level, amount, status, **ready on**.
- The shop's money: **/admin → Money health**.

### 5. If something is wrong

Send me:
1. The **step number** (for example "Must 4").
2. A **screenshot** of the whole screen.
3. The **address** from the address bar.
4. The number you expected and the number you see.
5. For an error page or red message, copy its text.

### The rates (from your settings, unchanged)

| Payment | Level 1 (I invited them) | Level 2 | Level 3 |
|---|---:|---:|---:|
| Membership 5,000 ETB | 10% = **500** | 5% = **250** | 2% = **100** |
| Shop order (goods, without delivery) | 6% | 3% | 1% |
| Signals bought without membership (4,000 ETB) | 10% = **400** | — | — |
| First payment of someone I invited | **+250 once** | — | — |

Only **active members** earn. A level that is not active is skipped and **the shop keeps it** (it is not passed up). Level 4 and deeper: nobody earns.

The **Story basket** costs **2,000 ETB**, plus **150 ETB** delivery in Addis Ababa = **2,150 ETB**. For members it is **1,700 ETB** (15% member price).

---

## Must test: the Abdi story (about 45 minutes)

### [ ] Must 1 — Day 0: Abdi's starting money and last month's badge bonus (3 min)
**Checks:** a member's ready money and the monthly badge bonus are right.
**Who:** Chrome normal: `abdi@story.test`. Terminal.

1. Sign in as Abdi. Open **/account/wallet**.
2. Open **/account/badges**.
3. In the terminal run (use the month you wrote down):
   `docker compose exec app php artisan badges:bonus --month=2026-10`
4. Reload **/account/wallet**.

**You should see:**
- Step 1: On the way **0 ETB**, Ready to use **2,250 ETB** (3 × 500 team earnings + 3 × 250 first-payment bonus, from Bekele, Chaltu and Dawit).
- Step 2: the **Bronze** badge is unlocked (3 active people he invited).
- Step 4: On the way **200 ETB** (Bronze bonus for last month), Ready to use **2,250 ETB**.
- The shop paid 200 ETB from last month's bonus budget (5% of last month's 15,000 ETB membership money = 750 ETB, so 200 fits).

**If wrong:** screenshot of the wallet and the terminal output.

### [ ] Must 2 — A guest buys through Abdi's link → Abdi earns level 1 (4 min)
**Checks:** a share link brings Abdi 6% of what a guest pays for goods.
**Who:** Chrome private (guest). Edge normal: `owner@story.test` in /admin.

1. Chrome private: open **http://localhost:8090/r/ABDI**.
2. Open the shop, find **Story basket**, click **Add to cart**, go to checkout.
3. Type a name, phone `0911555001`, city `Addis Ababa`, an address. Tick **I agree to the terms**. Place the order.
4. Pay by **Telebirr or bank transfer**: upload any photo, click **Send receipt**.
5. Edge: /admin → **Payments** → the order is in the list (column **For** shows the order number and the buyer's name) → open it → **Approve**.
6. Chrome normal (Abdi): reload **/account/wallet** and **/account/earnings**.

**You should see:**
- The order total is **2,150 ETB** (2,000 + 150 delivery).
- Abdi: On the way **320 ETB** (200 + **120**). Ready to use **2,250 ETB**.
- In Earnings: **A customer who used your link · Shop order · 2,000 ETB · level 1 · 120 ETB · On the way · Ready on** (day 14).
- **The shop keeps 2,030 ETB** of this order (2,150 − 120), before the basket's own cost.

**If wrong:** screenshot of the order in the admin and Abdi's earnings.

### [ ] Must 3 — Sara joins through Abdi's link and pays → Abdi earns (5 min)
**Checks:** a new member is linked to the person who invited her, and the inviter earns 500 + 250.
**Who:** a new Chrome private window (Sara). Edge (owner). Chrome normal (Abdi).

1. Close all Chrome private windows. Open a new one: **http://localhost:8090/r/ABDI**.
2. Click **Sign up**. First name `Sara`, last name `Tesfaye`, phone `0900200002`, email `sara@story.test`, password `kulmis story 2026`. Tick **I agree to the terms**. Create the account.
3. On her dashboard tap **Join** (top) or scroll to **Join by Telebirr or bank transfer · 5,000 ETB** → upload any photo → **Send receipt**. Her dashboard now says **Receipt sent · we are checking it**.
4. Edge: /admin → **Payments** → the line **Membership · Sara Tesfaye** → **Approve**.
5. Chrome private (Sara): reload /account. Copy **Your referral link** (you need it in Must 4).
6. Chrome normal (Abdi): reload /account/wallet and /account/earnings and **/account/team**.

**You should see:**
- Sara's dashboard: **Active member**.
- Abdi: On the way **1,070 ETB** (320 + **500** + **250** first-payment bonus).
- Earnings: **Sara · Membership · 5,000 ETB · level 1 · 500 ETB**.
- Team: Sara is there.
- **The shop keeps 4,250 ETB** of Sara's 5,000 (5,000 − 500 − 250).

**If wrong:** screenshots of Abdi's earnings and team pages.

### [ ] Must 4 — Hana (Sara's invite) pays → Sara level 1, Abdi level 2 (5 min)
**Checks:** level 2 works, and Abdi only sees "someone Sara invited", not Hana's name.
**Who:** new Chrome private (Hana). Edge (owner). Chrome normal: Abdi, then Sara.

1. Close all Chrome private windows. New one: open **Sara's link** from Must 3.
2. Sign up: `Hana`, `Girma`, phone `0900200003`, `hana@story.test`, password `kulmis story 2026`. Pay the membership by receipt.
3. Edge: **Approve** Hana's payment. On her dashboard (Chrome private), copy Hana's referral link.
4. Chrome normal: Abdi's /account/wallet and /account/earnings.
5. Sign out, sign in as `sara@story.test`, open /account/wallet.

**You should see:**
- Sara: On the way **750 ETB** (500 + 250 first-payment bonus).
- Abdi: On the way **1,320 ETB** (+ **250** level 2). Earnings: **Someone Sara invited · Membership · 5,000 ETB · level 2 · 250 ETB**.
- **The shop keeps 4,000 ETB** of Hana's 5,000 (5,000 − 750 − 250).

**If wrong:** screenshots of both wallets and Abdi's earnings.

### [ ] Must 5 — Omar pays (Abdi level 3), then Ali pays (Abdi gets nothing: level 4) (8 min)
**Checks:** level 3 works and the program stops at 3 levels.
**Who:** new Chrome private windows (Omar, then Ali). Edge (owner). Chrome normal.

1. Close Chrome private windows. New one: open **Hana's link**. Sign up `Omar`, `Yusuf`, `0900200004`, `omar@story.test`. Pay by receipt. Owner **approves**. Copy Omar's link.
2. Check Abdi's wallet. → On the way **1,420 ETB** (+ **100** level 3).
3. Close Chrome private windows. New one: open **Omar's link**. Sign up `Ali`, `Hassan`, `0900200005`, `ali@story.test`. Pay by receipt. Owner **approves**.
4. Check the wallets of Abdi, Sara, Hana and Omar (sign in as each in Chrome normal).

**You should see (On the way):**

| Who | On the way | Why |
|---|---:|---|
| Abdi | **1,420** | nothing from Ali (level 4) |
| Sara | **1,100** | 750 + 250 (Omar, level 2) + 100 (Ali, level 3) |
| Hana | **1,000** | 500 + 250 bonus (Omar) + 250 (Ali, level 2) |
| Omar | **750** | 500 + 250 bonus (Ali) |

- Omar's 5,000: Hana 750, Sara 250, Abdi 100 → **the shop keeps 3,900 ETB**.
- Ali's 5,000: Omar 750, Hana 250, Sara 100, Abdi 0 → **the shop keeps 3,900 ETB**.

**If wrong:** screenshots of the wallets that differ.

### [ ] Must 6 — The code typed by hand, and tricks that must not pay (4 min)
**Checks:** typing ABDI at checkout works like the link; using Abdi's own phone pays nobody; a second account with his phone is refused.
**Who:** Chrome private (guests). Edge (owner). Chrome normal (Abdi).

1. Close Chrome private windows. New one: go **straight to the shop** (no link). Buy the Story basket. At checkout type **ABDI** in **Referral code**, phone `0911555002`. Pay by receipt. Owner **approves**.
2. Check Abdi's wallet. → On the way **1,540 ETB** (+ **120**).
3. Close Chrome private windows. New one: open **/r/ABDI**, buy the basket, but use **Abdi's own phone `0900200001`**. Pay by receipt. Owner **approves**.
4. Check Abdi's wallet again.
5. Close Chrome private windows. New one: try to **Sign up** with phone `0900200001`.

**You should see:**
- Step 4: still **1,540 ETB** (buying from yourself never pays). The shop keeps all **2,150 ETB**.
- Step 5: **This phone number already has an account. Please sign in instead.** No account is made.

**If wrong:** screenshots.

### [ ] Must 7 — A refund takes the earnings back from the whole chain (4 min)
**Checks:** when Omar gets his money back, everybody above him gives back what they earned from him.
**Who:** Edge (owner). Chrome normal (each person).

1. Edge: /admin → **Payments** → **Filters** → Status **all** (paid payments are hidden by default) → type `Omar` in the search → open **Membership · Omar …** → **Refund…** → type a reason → **Refund 5,000 ETB**.
2. Check the wallets of Abdi, Sara, Hana, Omar.
3. Abdi's /account/earnings: the line from Omar's payment.

**You should see (On the way):**

| Who | Before | After | Why |
|---|---:|---:|---|
| Abdi | 1,540 | **1,440** | − 100 (level 3) |
| Sara | 1,100 | **850** | − 250 (level 2) |
| Hana | 1,000 | **250** | − 500 − 250 bonus; only the 250 from Ali stays |
| Omar | 750 | **750** | he keeps what he earned from Ali (Ali really paid) |

- Abdi's earning from Omar shows **Taken back**. Omar is no longer a member.
- The shop gave back 5,000 and took back 1,100, so it lost nothing.

**If wrong:** screenshot of the payment and the wallets.

> **Before you move the clock (for Must 9):** close all private windows. Open **Chrome private** → **http://localhost:8090/r/ABDI** and leave it open (call it **window L**). Open **Edge private** → **http://localhost:8090/r/ABDI** and leave it open (**window M**). Don't use private windows for anything else until Must 9 is done.

### [ ] Must 8 — Day 15: money is ready; Abdi spends some and cashes out (6 min)
**Checks:** after 14 days "on the way" becomes "ready to use"; a member can spend it in the shop and cash out, never more than he has.
**Who:** terminal. Chrome normal (Abdi). Edge normal (owner).

1. Terminal: `docker compose exec app php artisan km:test-clock +15days`
2. Abdi: /account/wallet. (Sign in again if asked.)
3. Abdi: buy the **Story basket**; at checkout fill the city and address, tick **Use my wallet** and **I agree to the terms**. Place the order.
4. Abdi: /account/wallet → cash out **2,000 ETB**.
5. Abdi: cash out **1,000 ETB**.
6. Owner: /admin → **Cash-outs** → Abdi → **Pay** → tick "I checked the account", type reference `TB-STORY-1` → **I sent it — mark paid**.

**You should see:**
- Step 2: On the way **0**, Ready to use **3,690 ETB** (2,250 + 200 + 120 + 500 + 250 + 250 + 120; the 100 from Omar was taken back).
- Step 3: you land on **My orders** with the message **Order KM… is placed and paid (1,850 ETB)** (member price 1,700 + 150 delivery). Ready to use **1,840 ETB**.
- Step 4: refused — not enough ready money.
- Step 5: Ready to use **840 ETB**, Cash-out asked **1,000 ETB**.
- Step 6: Cash-out asked **0**, the cash-out shows **Paid** with your reference. Sara: Ready to use **850**.

**If wrong:** screenshots of the wallet after each step.

### [ ] Must 9 — The 30-day link memory: day 29 counts, day 31 doesn't (4 min)
**Checks:** someone who opened Abdi's link and buys within 30 days still counts for Abdi; after 30 days not.
**Who:** terminal, window L (Chrome private), window M (Edge private), Edge normal (owner), Chrome normal (Abdi).

1. Terminal: `docker compose exec app php artisan km:test-clock +14days` (now day 29).
2. **Window L**: buy the Story basket (phone `0911555004`), pay by receipt. Owner **approves**.
3. Abdi's wallet. → On the way **120 ETB**, Ready to use **840 ETB**.
4. Terminal: `docker compose exec app php artisan km:test-clock +2days` (now day 31).
5. **Window M**: buy the Story basket (phone `0911555005`), pay by receipt. Owner **approves**.
6. Abdi's wallet again.

**You should see:**
- Step 3: the day-29 order counts: **+120** for Abdi.
- Step 6: unchanged, **120 / 840**. In the admin, the day-31 order has **no referrer**. The shop keeps all 2,150 ETB.

**If wrong:** screenshots of both orders in the admin.

### [ ] Must 10 — Day 35: Abdi's membership ended → he earns nothing; he renews → he earns again. Then the shop's money (6 min)
**Checks:** only active members earn (the shop keeps the rest), and the Money health page adds everything up.
**Who:** terminal, Chrome private (guest), Chrome normal (Abdi), Edge normal (owner).

1. Terminal: `docker compose exec app php artisan km:test-clock +4days` (day 35: his month ended on day 30, 3 grace days are over).
2. Abdi: /account → it says **Membership ended**.
3. Close private windows. New Chrome private: **/r/ABDI** → buy the basket (phone `0911555006`) → receipt → owner **approves**.
4. Abdi's wallet. → still **120 / 840**: nothing earned. The shop keeps the 120.
5. Abdi renews: /account → **Join by Telebirr or bank transfer · 5,000 ETB** → receipt → owner **approves**. His dashboard says **Active member** again.
6. Close private windows. New Chrome private: **/r/ABDI** → buy the basket (phone `0911555007`) → owner **approves**.
7. Abdi's wallet. → On the way **240 ETB**, Ready to use **840 ETB**.
8. Owner: /admin → **Money health** → **All time**.

**You should see on Money health (step 8):**

| | ETB |
|---|---:|
| Money in | **60,050** |
| Paid to members | **5,780** (9.6%) |
| Kept by the shop | **54,270** (**90.4%**) |
| Team earnings / Badge bonus / First-payment bonuses… / Loyalty | **4,080 / 200 / 1,500 / 0** |
| On the way | **240** |
| Ready to use | **2,690** (Abdi 840 + Sara 850 + Hana 250 + Omar 750) |
| Cash-outs waiting | **0** |
| Light | **green — Healthy** |

Where the 60,050 comes from: Abdi's 3 memberships 15,000 + Bekele, Chaltu, Dawit 15,000 + Sara, Hana, Omar, Ali 20,000 − Omar's refund 5,000 + 7 basket orders × 2,150 = 15,050. (Abdi's wallet order is not new money: it was his earnings.)

Who got the 5,780: Abdi **3,930** (2,250 + 200 + 120 + 500 + 250 + 250 + 120 + 120 + 120), Sara **850**, Hana **250**, Omar **750**, Ali 0.

**If wrong:** screenshot of the Money health page.

**When you're done:** `docker compose exec app php artisan km:test-clock --reset`

---

## Should test (the rest of the shop)

For these, reset to the demo data first (it has many more people and products):

```bash
docker compose exec app php artisan km:test-clock --reset
docker compose exec app php artisan migrate:fresh --seed --force
docker compose exec app php artisan db:seed --class=DemoSeeder
```

Demo password for everyone: **`kulmis demo 2026`**. Super Admin: `demo-super_admin@kulmis.test`, member with a big team: `demo-member@kulmis.test` (Selam), shopper: `demo-shopper-0@kulmis.test`, Fulfilment: `demo-fulfilment@kulmis.test`. Emails the shop sends appear at **http://localhost:8025** (Mailpit).

### [ ] Should 1 — "What if" and "What you could earn"
**Who:** Super Admin; Selam.
1. Admin → **Money health** → **What if…**: type 100 members and 3 people each.
2. Selam → /account → **How you earn** → **What you could earn**: 3 and 3.

**You should see:** What if: **4,000** members, money in **20,000,000 ETB**, team earnings **3,120,000**, badge bonus **260,000**, the shop keeps **16,620,000 ETB (83.1%)**, one member earns about **6,650 ETB**. Selam's calculator: level 1 **1,500**, level 2 **2,250**, level 3 **2,700**, Bronze **200**, every month **6,650 ETB**, once **750 ETB**.
**If wrong:** screenshots.

### [ ] Should 2 — Switching a feature off hides it completely
**Who:** Super Admin + private window.
1. Admin → **Feature switches** → search `signals` → switch **Trading signals** off → read the box → **Switch off**.
2. Private window: look at the home page, menu, footer, **Membership** page, and open /signals.
3. Switch it on again.

**You should see:** no word "signal" or "trading" anywhere; /signals shows **"We can't find that page"**. After step 3 everything is back.
**If wrong:** screenshot of where you still see it, plus the address.

### [ ] Should 3 — Membership switched off: paid members keep their benefits
**Who:** Super Admin, Selam, private window.
1. Switch **Membership** off (the box says how many people keep it).
2. Selam: open a product and the cart. Private window: open /membership.
3. Switch Membership on again.

**You should see:** Selam still gets member prices and free delivery; the guest sees no membership and /membership is "We can't find that page".

### [ ] Should 4 — "End access now" with money back
1. Switch **Membership** off. Under it: **End access now**, tick **Add the days they did not use to their wallet**, confirm.
2. Selam → Wallet.

**You should see:** a line **"Money back for days not used"** in Selam's wallet, about the right amount for the days left. (Reset the demo data afterwards.)

### [ ] Should 5 — Two people try to buy the last piece
1. Admin → **Products** → open **Jebena coffee pot** (a product without sizes or colours) → set stock to **1** → save.
2. Chrome private and Edge private: both add it and go to checkout. Place the order in the first, then in the second.

**You should see:** only the first goes through; the second gets a clear "sold out" message.

### [ ] Should 6 — Staff see only their part
1. Sign in to /admin as `demo-fulfilment@kulmis.test`. Try /admin/cash-outs, /admin/money-health, /admin/features.

**You should see:** orders and shipping only; the others say "not allowed".

### [ ] Should 7 — Order emails
1. Buy a product you can hold (e.g. **Woven bread basket**) as a guest with an email, admin approves, then **Mark making**, choose a **Courier** and type tracking `123` → **Mark shipped**, then **Mark delivered**.
2. Open Mailpit http://localhost:8025 and http://localhost:8090/track.

**You should see:** one clear email per step with the right order number; /track shows the same.

### [ ] Should 8 — A wrong receipt is rejected
1. Buy as a guest, send a receipt. Admin → Payments → type a reason → **Reject**.

**You should see:** the order stays unpaid; the customer's payment page says **We could not accept your last receipt. Reason: …** and shows the upload form again; the customer also gets a message (Mailpit, if they gave an email); nobody earned anything.

### [ ] Should 9 — Coupon
1. Admin → **Coupons** → 10% coupon `TEST10`. Guest: add a product, type `TEST10` in the cart.

**You should see:** 10% less on the goods (not on delivery); a wrong code says it is not valid.

### [ ] Should 10 — Phone check and sign out
On a phone (or F12 → phone icon, iPhone 12 Pro): sign in as Selam (if a **New badge unlocked!** box shows, tap **Yay!**), open every tab of the bottom bar and everything under **More**, then **More → Sign out** → **Sign out**.

**You should see:** nothing cut off, no sideways scrolling; "Do you want to sign out?", then "You are signed out."

---

## Nice to test

### [ ] Nice 1 — Dark mode
Click the moon icon in the shop, the member area and the admin. Everything stays readable.

### [ ] Nice 2 — Gift card
Guest: buy a gift card at /gift-cards (receipt), admin approves. Use the code in another cart: the amount comes off once; the rest stays on the card.

### [ ] Nice 3 — Badges page
Selam: **More → Badges**: unlocked badges and progress look right.

### [ ] Nice 4 — Share kit
Selam: **More → Share kit**: each post shows its whole text with Selam's link and code; a picture card can be made.

### [ ] Nice 5 — Install the member app
Real phone: open /account in Chrome (Android) or Safari (iPhone) → **Add to home screen**.

---

## What you can't test on your computer

Test these **on the live server before you announce the shop**, with small amounts:

| What | Why not locally | When |
|------|-----------------|------|
| **A real Telebirr payment** | money moves for real | buy something cheap with your own phone, send the real receipt, approve it, check the order turns **Paid** and the messages arrive, then refund it |
| **Telegram bot and signals channel** | needs the bot token and a public address | after the bot is set up: buy signals, join with the personal link; end the access and check you are removed |
| **SMS** | no SMS provider yet (QUESTIONS.md, A) | after you choose one: password reset by SMS, an order message on your phone |
| **Real emails** | locally they stop in Mailpit | after domain and mail settings: sign up with your email (check spam) |
| **Phone notifications** | need https | on the live site: allow notifications, then earn something |
| **Speed on Ethiopian mobile internet** | your computer is too fast | on the live site, on mobile data |
| **Real cash-outs** | money leaves for real | first one with a small amount to your own Telebirr |

---

## When all these pass, you're ready for the next step:

1. **All 10 "Must test" steps match** → the money of the team program is right on screen too (the automatic tests already prove the maths and that the shop never collapses: see `docs/never-collapses.md`).
2. **Set up** hosting + domain, the Telegram bot and an SMS provider (QUESTIONS.md, "Still open"). The step-by-step is in the **Super Admin guide** (Admin → Guide, or docs/admin-guide.pdf).
3. **Do the "can't test locally" checks** on the live server with small real amounts.
4. **Delete the demo data** on the live server (Admin → Demo data → Delete all demo data), add your real products.
5. **Invite your first real members**, and watch **Money health** every week: it should stay green.
