---
title: How earning works
topic: How earning works
order: 1
feature: referral
keywords: earn, levels, link, code, commission, referral, money, team
---
You get your own **link** and **code** (Home → *Your referral link*). When someone opens your link or types your code and then pays, you earn.

**The levels**

```
You
{if referral.level1} └─ Level 1: people you invited
{/if}{if referral.level2}     └─ Level 2: people they invited
{/if}{if referral.level3}         └─ Level 3: people they invited
{/if}```

**On each {membership_price} membership:**

| Level | Share | You earn |
|---|---|---|
{if referral.level1}| Level 1 | {l1_rate} | {l1_amount} |
{/if}{if referral.level2}| Level 2 | {l2_rate} | {l2_amount} |
{/if}{if referral.level3}| Level 3 | {l3_rate} | {l3_amount} |
{/if}
{if referral.level1}**Example:** you invite 3 friends and they each pay for a membership. You earn **{three_friends} every month** they stay.{/if}

{if referral.commission.products}On shop orders you earn{if referral.level1} {shop_l1_rate} of the goods (level 1){/if}{if referral.level2}, {shop_l2_rate} (level 2){/if}{if referral.level3}, {shop_l3_rate} (level 3){/if}.{/if}

{if referral.first_payment_bonus}You also get a **first-payment bonus** of {first_payment_bonus} when someone you invited pays for the first time.{/if}

**Good to know**
- You earn only while **your own membership is paid**.
- Buying from yourself never pays.
- A link you shared is remembered for {link_days} days.
- Earnings are never a promise: you earn only when real people really pay.

![Earnings on a phone](/help-screens/member-earnings-phone.jpg)
