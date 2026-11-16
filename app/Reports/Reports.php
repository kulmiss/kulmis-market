<?php

namespace App\Reports;

use App\Ledger\Account;
use App\Models\GiftCard;
use App\Models\MembershipGift;
use App\Models\Order;
use App\Models\Reward;
use App\Rewards\Rewards;
use App\Wallet\CashOuts;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Super Admin reports. Every report: a title, easy column names, rows,
 * totals. Money in santim; real data only (demo rows never count).
 */
class Reports
{
    public const TYPES = [
        'revenue' => 'Money in and out (by source)',
        'features' => 'What each new feature brought in',
        'products' => 'Sales and profit per product',
        'categories' => 'Sales per category',
        'days' => 'Sales per day',
        'customers' => 'Top customers',
        'membership' => 'Membership: new, renewals, refunds per month',
        'referrals' => 'Team earnings by level',
        'payouts' => 'Bonuses, prizes and rewards',
        'coupons' => 'Coupons used',
        'shipping' => 'Shipping by city',
    ];

    /** @return array{title:string, columns:array<string, array{0:string,1:string}>, rows:list<array<string, mixed>>, totals:array<string, int>} */
    public function run(string $type, Carbon $from, Carbon $to): array
    {
        [$columns, $rows] = match ($type) {
            'revenue' => $this->revenue($from, $to),
            'features' => $this->features($from, $to),
            'products' => $this->products($from, $to),
            'categories' => $this->categories($from, $to),
            'days' => $this->days($from, $to),
            'customers' => $this->customers($from, $to),
            'membership' => $this->membership($from, $to),
            'referrals' => $this->referrals($from, $to),
            'payouts' => $this->payouts($from, $to),
            'coupons' => $this->coupons($from, $to),
            'shipping' => $this->shipping($from, $to),
            default => throw new \InvalidArgumentException("Unknown report [$type]."),
        };
        $totals = [];
        foreach ($columns as $key => [, $kind]) {
            if (in_array($kind, ['money', 'int'], true)) {
                $totals[$key] = (int) array_sum(array_column($rows, $key));
            }
        }

        return ['title' => self::TYPES[$type], 'columns' => $columns, 'rows' => $rows, 'totals' => $totals];
    }

    /** Paid, not cancelled/refunded, real orders in the period. */
    private function paidOrders(Carbon $from, Carbon $to): Builder
    {
        return DB::table('orders')->where('orders.is_demo', false)->whereNotNull('orders.paid_at')
            ->whereBetween('orders.paid_at', [$from, $to])->whereNotIn('orders.status', [Order::CANCELLED, Order::REFUNDED]);
    }

    private function flow(string $code, Carbon $from, Carbon $to, bool $credit = false): int
    {
        $sum = (int) DB::table('ledger_postings')->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_postings.ledger_account_id')
            ->join('journal_entries', 'journal_entries.id', '=', 'ledger_postings.journal_entry_id')
            ->where('ledger_accounts.code', $code)->where('ledger_postings.is_demo', false)
            ->whereBetween('journal_entries.created_at', [$from, $to])->sum('ledger_postings.amount');

        return $credit ? -$sum : $sum;
    }

    /** @return array{0: array<string, array{0:string,1:string}>, 1: list<array<string, mixed>>} */
    private function revenue(Carbon $from, Carbon $to): array
    {
        $in = ['Shop sales' => 'income:sales', 'Memberships' => 'income:memberships', 'Signals' => 'income:signals', 'Cash-out fees' => CashOuts::FEE_ACCOUNT];
        $out = ['Team earnings' => Account::COMMISSIONS, 'Badge bonuses' => Account::BADGE_BONUSES, 'Loyalty rewards' => Account::LOYALTY, 'Payment fees' => 'expense:payment_fees'];
        foreach (Reward::KINDS as $k => $label) {
            $out[$label] = Rewards::account($k);
        }
        $rows = [];
        foreach ($in as $label => $code) {
            $rows[] = ['source' => $label, 'kind' => 'Money in', 'amount' => $this->flow($code, $from, $to, true)];
        }
        foreach ($out as $label => $code) {
            $rows[] = ['source' => $label, 'kind' => 'Paid out', 'amount' => -$this->flow($code, $from, $to)];
        }
        $rows[] = ['source' => 'Gift cards sold (not spent yet = owed)', 'kind' => 'Held', 'amount' => $this->flow(GiftCard::ACCOUNT, $from, $to, true)];
        $rows[] = ['source' => 'Gift memberships bought (not started yet = owed)', 'kind' => 'Held', 'amount' => $this->flow(MembershipGift::ACCOUNT, $from, $to, true)];

        return [['source' => ['Source', 'text'], 'kind' => ['Type', 'text'], 'amount' => ['Amount (− = paid out)', 'money']], $rows];
    }

    /** @return array{0: array<string, array{0:string,1:string}>, 1: list<array<string, mixed>>} */
    private function features(Carbon $from, Carbon $to): array
    {
        $items = fn () => DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')->where('orders.is_demo', false)
            ->whereNotNull('orders.paid_at')->whereBetween('orders.paid_at', [$from, $to])->whereNotIn('orders.status', [Order::CANCELLED, Order::REFUNDED]);
        $byKind = fn (string $kind) => (int) $items()->join('products', 'products.id', '=', 'order_items.product_id')->where('products.kind', $kind)->sum('order_items.line_total');
        $orders = $this->paidOrders($from, $to);
        $rows = [
            ['feature' => 'Flash sales', 'what' => 'Sales at a flash price', 'amount' => (int) $items()->whereNotNull('order_items.flash_sale_id')->sum('order_items.line_total')],
            ['feature' => 'Bundles', 'what' => 'Saving given on sets (cost to you)', 'amount' => -(int) (clone $orders)->sum('bundle_discount')],
            ['feature' => 'Checkout extras', 'what' => 'Gift wrap, express, insurance', 'amount' => (int) (clone $orders)->sum('extras_total')],
            ['feature' => 'Digital products', 'what' => 'Sales', 'amount' => $byKind('digital')],
            ['feature' => 'Paid add-ons', 'what' => 'Sales', 'amount' => $byKind('addon')],
            ['feature' => 'Pre-orders and custom orders', 'what' => 'Orders paid with a deposit first', 'amount' => (int) (clone $orders)->where('deposit_amount', '>', 0)->sum('merchandise_total')],
            ['feature' => 'Gift cards', 'what' => 'Spent in the shop', 'amount' => (int) (clone $orders)->sum('gift_card_used')],
            ['feature' => 'Gift membership', 'what' => 'Gift months started', 'amount' => (int) DB::table('membership_gifts')->where('is_demo', false)->where('status', 'redeemed')->whereBetween('redeemed_at', [$from, $to])->sum('amount')],
            ['feature' => 'Win-back offers', 'what' => 'Orders with a win-back code', 'amount' => (int) (clone $orders)->where('coupon_code', 'like', 'BACK%')->sum('merchandise_total')],
            ['feature' => 'Cash-out fee', 'what' => 'Fees kept', 'amount' => $this->flow(CashOuts::FEE_ACCOUNT, $from, $to, true)],
        ];
        foreach (Reward::KINDS as $k => $label) {
            $rows[] = ['feature' => $label, 'what' => 'Paid out (cost to you)', 'amount' => -$this->flow(Rewards::account($k), $from, $to)];
        }

        return [['feature' => ['Feature', 'text'], 'what' => ['What', 'text'], 'amount' => ['Amount (− = cost)', 'money']], $rows];
    }

    /** @return array{0: array<string, array{0:string,1:string}>, 1: list<array<string, mixed>>} */
    private function products(Carbon $from, Carbon $to): array
    {
        $rows = DB::table('order_items')->joinSub($this->paidOrders($from, $to)->select('orders.id'), 'o', 'o.id', '=', 'order_items.order_id')
            ->groupBy('order_items.product_id')->selectRaw('order_items.product_id, MAX(order_items.name) as name, SUM(order_items.quantity) as qty,
                SUM(order_items.line_total) as revenue, SUM(order_items.unit_cost * order_items.quantity) as cost')
            ->orderByDesc('revenue')->limit(500)->get()
            ->map(fn ($r) => ['product' => (string) $r->name, 'qty' => (int) $r->qty, 'revenue' => (int) $r->revenue, 'cost' => (int) $r->cost,
                'profit' => (int) $r->revenue - (int) $r->cost])->all();

        return [['product' => ['Product', 'text'], 'qty' => ['Sold', 'int'], 'revenue' => ['Sales', 'money'], 'cost' => ['Cost', 'money'],
            'profit' => ['Profit before team earnings and fees', 'money']], $rows];
    }

    /** @return array{0: array<string, array{0:string,1:string}>, 1: list<array<string, mixed>>} */
    private function categories(Carbon $from, Carbon $to): array
    {
        $rows = DB::table('order_items')->joinSub($this->paidOrders($from, $to)->select('orders.id'), 'o', 'o.id', '=', 'order_items.order_id')
            ->leftJoin('products', 'products.id', '=', 'order_items.product_id')->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->groupBy('categories.name')->selectRaw('COALESCE(categories.name, ?) as cat, SUM(order_items.quantity) as qty, SUM(order_items.line_total) as revenue,
                SUM(order_items.unit_cost * order_items.quantity) as cost', ['No category'])
            ->orderByDesc('revenue')->get()
            ->map(fn ($r) => ['category' => (string) $r->cat, 'qty' => (int) $r->qty, 'revenue' => (int) $r->revenue, 'profit' => (int) $r->revenue - (int) $r->cost])->all();

        return [['category' => ['Category', 'text'], 'qty' => ['Sold', 'int'], 'revenue' => ['Sales', 'money'], 'profit' => ['Profit before team earnings and fees', 'money']], $rows];
    }

    /** @return array{0: array<string, array{0:string,1:string}>, 1: list<array<string, mixed>>} */
    private function days(Carbon $from, Carbon $to): array
    {
        $tz = (string) config('app.timezone');
        $byDay = $this->paidOrders($from, $to)->get(['paid_at', 'total', 'merchandise_total'])
            ->groupBy(fn ($o) => Carbon::parse($o->paid_at)->timezone($tz)->toDateString());
        $rows = [];
        for ($d = $from->copy()->timezone($tz)->startOfDay(); $d->lte($to); $d->addDay()) {
            $g = $byDay->get($d->toDateString(), collect());
            $rows[] = ['day' => $d->toDateString(), 'orders' => $g->count(), 'goods' => (int) $g->sum('merchandise_total'), 'total' => (int) $g->sum('total')];
            if (count($rows) >= 400) {
                break;
            }
        }

        return [['day' => ['Day', 'text'], 'orders' => ['Orders', 'int'], 'goods' => ['Goods', 'money'], 'total' => ['Paid (with delivery and extras)', 'money']], $rows];
    }

    /** @return array{0: array<string, array{0:string,1:string}>, 1: list<array<string, mixed>>} */
    private function customers(Carbon $from, Carbon $to): array
    {
        $rows = $this->paidOrders($from, $to)->groupBy('orders.phone')
            ->selectRaw('orders.phone, MAX(orders.first_name) as name, MAX(orders.city) as city, COUNT(*) as orders, SUM(orders.total) as spent')
            ->orderByDesc('spent')->limit(100)->get()
            ->map(fn ($r) => ['name' => (string) $r->name, 'phone' => (string) $r->phone, 'city' => (string) $r->city, 'orders' => (int) $r->orders, 'spent' => (int) $r->spent])->all();

        return [['name' => ['Name', 'text'], 'phone' => ['Phone', 'text'], 'city' => ['City', 'text'], 'orders' => ['Orders', 'int'], 'spent' => ['Spent', 'money']], $rows];
    }

    /** @return array{0: array<string, array{0:string,1:string}>, 1: list<array<string, mixed>>} */
    private function membership(Carbon $from, Carbon $to): array
    {
        $tz = (string) config('app.timezone');
        $all = DB::table('membership_purchases as mp')->where('mp.is_demo', false)->whereNotNull('mp.paid_at')->whereBetween('mp.paid_at', [$from, $to])
            ->get(['mp.user_id', 'mp.paid_at', 'mp.status', 'mp.amount', DB::raw('(SELECT COUNT(*) FROM membership_purchases p2 WHERE p2.user_id = mp.user_id AND p2.paid_at < mp.paid_at AND p2.amount > 0) as earlier')]);
        $ended = DB::table('activity_log')->where('is_demo', false)->where('action', 'membership.expired')->whereBetween('created_at', [$from, $to])->get(['created_at']);
        $months = [];
        foreach ($all as $p) {
            $m = Carbon::parse($p->paid_at)->timezone($tz)->format('Y-m');
            $months[$m] ??= ['month' => $m, 'new' => 0, 'renewals' => 0, 'refunded' => 0, 'ended' => 0, 'money' => 0];
            $p->status === 'refunded' ? $months[$m]['refunded']++ : ((int) $p->earlier === 0 ? $months[$m]['new']++ : $months[$m]['renewals']++);
            $months[$m]['money'] += $p->status === 'paid' ? (int) $p->amount : 0;
        }
        foreach ($ended as $e) {
            $m = Carbon::parse($e->created_at)->timezone($tz)->format('Y-m');
            $months[$m] ??= ['month' => $m, 'new' => 0, 'renewals' => 0, 'refunded' => 0, 'ended' => 0, 'money' => 0];
            $months[$m]['ended']++;
        }
        ksort($months);

        return [['month' => ['Month', 'text'], 'new' => ['New members', 'int'], 'renewals' => ['Renewals', 'int'], 'refunded' => ['Refunded', 'int'],
            'ended' => ['Ended (churn)', 'int'], 'money' => ['Money', 'money']], array_values($months)];
    }

    /** @return array{0: array<string, array{0:string,1:string}>, 1: list<array<string, mixed>>} */
    private function referrals(Carbon $from, Carbon $to): array
    {
        $rows = DB::table('commissions')->where('is_demo', false)->whereIn('status', ['pending', 'available', 'reversed'])->whereBetween('created_at', [$from, $to])
            ->groupBy('level', 'type')->selectRaw('level, type, COUNT(*) as n, SUM(amount - reversed_amount) as amount')->orderBy('level')->orderBy('type')->get()
            ->map(fn ($r) => ['level' => 'Level '.$r->level, 'type' => match ($r->type) {
                'membership' => 'Memberships', 'products' => 'Shop orders', 'signals' => 'Signals', default => (string) $r->type
            },
                'count' => (int) $r->n, 'amount' => (int) $r->amount])->all();

        return [['level' => ['Level', 'text'], 'type' => ['From', 'text'], 'count' => ['Times', 'int'], 'amount' => ['Paid (after refunds)', 'money']], $rows];
    }

    /** @return array{0: array<string, array{0:string,1:string}>, 1: list<array<string, mixed>>} */
    private function payouts(Carbon $from, Carbon $to): array
    {
        $rows = [['what' => 'Badge bonuses', 'count' => (int) DB::table('badge_bonuses')->where('is_demo', false)->whereBetween('created_at', [$from, $to])->count(),
            'amount' => $this->flow(Account::BADGE_BONUSES, $from, $to)]];
        $rows[] = ['what' => 'Loyalty rewards', 'count' => (int) DB::table('loyalty_rewards')->where('is_demo', false)->whereBetween('created_at', [$from, $to])->count(),
            'amount' => $this->flow(Account::LOYALTY, $from, $to)];
        foreach (Reward::KINDS as $k => $label) {
            $rows[] = ['what' => $label, 'count' => Reward::query()->where('kind', $k)->whereBetween('created_at', [$from, $to])->count(), 'amount' => $this->flow(Rewards::account($k), $from, $to)];
        }

        return [['what' => ['What', 'text'], 'count' => ['Times', 'int'], 'amount' => ['Paid (after refunds)', 'money']], $rows];
    }

    /** @return array{0: array<string, array{0:string,1:string}>, 1: list<array<string, mixed>>} */
    private function coupons(Carbon $from, Carbon $to): array
    {
        $rows = $this->paidOrders($from, $to)->whereNotNull('coupon_code')->groupBy('coupon_code')
            ->selectRaw('coupon_code, COUNT(*) as uses, SUM(coupon_discount) as discount, SUM(merchandise_total) as goods')->orderByDesc('uses')->get()
            ->map(fn ($r) => ['code' => (string) $r->coupon_code, 'uses' => (int) $r->uses, 'discount' => (int) $r->discount, 'goods' => (int) $r->goods])->all();

        return [['code' => ['Code', 'text'], 'uses' => ['Used', 'int'], 'discount' => ['Discount given', 'money'], 'goods' => ['Goods sold with it', 'money']], $rows];
    }

    /** @return array{0: array<string, array{0:string,1:string}>, 1: list<array<string, mixed>>} */
    private function shipping(Carbon $from, Carbon $to): array
    {
        $rows = $this->paidOrders($from, $to)->groupBy('city')
            ->selectRaw('city, COUNT(*) as orders, SUM(shipping - shipping_discount) as charged, SUM(shipping_discount) as free')->orderByDesc('orders')->get()
            ->map(fn ($r) => ['city' => (string) $r->city, 'orders' => (int) $r->orders, 'charged' => (int) $r->charged, 'free' => (int) $r->free])->all();

        return [['city' => ['City', 'text'], 'orders' => ['Orders', 'int'], 'charged' => ['Delivery paid', 'money'], 'free' => ['Free delivery given', 'money']], $rows];
    }
}
