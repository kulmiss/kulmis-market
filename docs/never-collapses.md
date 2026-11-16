# "The shop never collapses": how it is proven

Run on every CI build (the top PR), with a short summary in the log.

## 1. Rules checked after every money test
`App\Ledger\MoneyChecks` runs after every test of the team program
(`ReferralTestCase::tearDown`, over 200 tests: every level, memberships,
signals, products, refunds, cash-outs, badge bonus, contests, challenges,
rewards, gift cards, coupons, wallet payments). It fails the test when:
1. the books don't add up (an entry does not balance);
2. a member earned from a payment that was never paid;
3. one purchase paid members more than what stayed paid, or more than the
   guard limit (35%) of it;
4. members got more than 35% of all the new money paid in.

`EveryMoneyPathTest` walks each path with exact ETB amounts, and
`AbdiStoryTest` runs the testing guide's story number by number.

## 2. 10,000 members over 24 months
`MoneyNeverCollapsesTest` uses a fast copy of the money rules
(`tests/Support/MoneyModel.php`): people join on their own and through
invites, pay 5,000 ETB memberships, renew or stop, buy products, ask for
refunds and cash out. Team earnings (3 levels, active members only),
first-payment bonus, loyalty reward, monthly badge bonus (with its limits),
and contest and challenge prizes **at their full budget every month** (the
worst case) are paid. Every day it checks that the books add up and that the
shop holds the money for everything it owes members (so every cash-out can
be paid). Every month: members got at most 35% of that month's money and the
shop made a profit after product costs.

Three runs:
- steady growth to 10,000 members;
- very fast growth (everyone brings ~1.5 people a month), then **nobody new
  joins, most stop paying, and then everyone leaves** — the classic way such
  programs fall over;
- growth, then a month where 40% of payments are refunded.

**Why the fast copy can be trusted:** the first test runs the same people,
days and refunds through the real code (real payments, team earnings,
first-payment bonus, refunds) and checks every member got exactly the same
amount, to the santim.

Why it can't collapse: members are only ever paid a share (at most 35%) of
money that was actually paid in, after the 14-day refund window, and the
monthly bonuses and prizes have budgets that are a share of that month's
money. Nothing is paid from future members' money.
