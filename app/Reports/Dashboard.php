<?php

namespace App\Reports;

use App\Ledger\Account;
use App\Models\Commission;
use App\Models\Membership;
use App\Models\Order;
use App\Models\PayoutRequest;
use App\Models\Product;
use App\Models\SignalSubscription;
use App\Referral\Engagement;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Business numbers for the admin dashboard. Money comes from the ledger
 * (so refunds are already netted out); counts from the domain tables.
 */
class Dashboard
{
    /** true = the demo data set, false = real data. Never both: money totals never mix them. */
    private bool $demo = false;

    /** @return array<string, mixed> */
    public function build(Carbon $from, Carbon $to, bool $demo = false): array
    {
        $this->demo = $demo;
        $paidOrders = Order::query()->where('orders.is_demo', $demo)->whereNotNull('paid_at')->whereBetween('paid_at', [$from, $to])
            ->whereNotIn('status', [Order::CANCELLED]);

        $revenue = [
            'products' => $this->flow('income:sales', $from, $to, true),
            'memberships' => $this->flow('income:memberships', $from, $to, true),
            'signals' => $this->flow('income:signals', $from, $to, true),
        ];
        $netRevenue = array_sum($revenue);
        $productCost = (int) DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.is_demo', $demo)->whereNotNull('orders.paid_at')->whereBetween('orders.paid_at', [$from, $to])->where('orders.status', '!=', Order::REFUNDED)
            ->sum(DB::raw('order_items.unit_cost * order_items.quantity'));
        $commissions = $this->flow(Account::COMMISSIONS, $from, $to);
        $loyalty = $this->flow(Account::LOYALTY, $from, $to);
        $fees = $this->flow('expense:payment_fees', $from, $to);

        $discounts = [
            'member' => (int) (clone $paidOrders)->sum('member_discount'),
            'coupons' => (int) (clone $paidOrders)->sum('coupon_discount'),
            'free_shipping' => (int) (clone $paidOrders)->sum('shipping_discount'),
        ];

        $activeMembers = Membership::query()->where('is_demo', $demo)->whereIn('status', ['active', 'grace'])
            ->where('grace_ends_at', '>=', now())->count();
        $churned = (int) DB::table('activity_log')->where('is_demo', $demo)->where('action', 'membership.expired')->whereBetween('created_at', [$from, $to])->count();
        $newMembers = (int) DB::table('membership_purchases')->where('is_demo', $demo)->where('status', 'paid')->whereBetween('paid_at', [$from, $to])
            ->whereNotExists(fn ($q) => $q->from('membership_purchases as p2')->whereColumn('p2.user_id', 'membership_purchases.user_id')
                ->where('p2.status', 'paid')->whereColumn('p2.paid_at', '<', 'membership_purchases.paid_at'))
            ->count();

        $wallets = fn (string $bucket) => -(int) DB::table('ledger_postings')->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_postings.ledger_account_id')
            ->where('ledger_postings.is_demo', $demo)->where('ledger_accounts.code', 'like', "user:%:$bucket")->sum('ledger_postings.amount');

        $lowStock = Settings::int('catalog.low_stock');

        return [
            'dataset' => $demo ? 'demo' : 'real',
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'revenue' => $revenue + ['total' => $netRevenue],
            'gross_profit' => $netRevenue - $productCost - $commissions - $loyalty - $fees,
            'costs' => ['products' => $productCost, 'commissions' => $commissions, 'loyalty' => $loyalty, 'payment_fees' => $fees],
            'discounts' => $discounts + ['total' => array_sum($discounts)],
            'shipping' => [
                'charged' => (int) (clone $paidOrders)->sum(DB::raw('shipping - shipping_discount')),
                'free_given' => $discounts['free_shipping'],
            ],
            'orders' => ['paid' => (clone $paidOrders)->count(), 'average' => (int) round((float) (clone $paidOrders)->avg('total'))],
            'commissions' => [
                'earned_in_period' => $commissions,
                'pending' => $wallets('pending'),
                'available' => $wallets('available'),
                'requested' => $wallets('payout'),
                'paid_out' => (int) PayoutRequest::query()->where('is_demo', $demo)->where('status', 'paid')->whereBetween('processed_at', [$from, $to])->sum('amount'),
                'paid_out_all_time' => (int) PayoutRequest::query()->where('is_demo', $demo)->where('status', 'paid')->sum('amount'),
            ],
            'loyalty_rewards' => $loyalty,
            'payment_fees' => $fees,
            'membership' => [
                'active' => $activeMembers,
                'mrr' => $activeMembers * Settings::int('membership.price'),
                'new' => $newMembers,
                'churned' => $churned,
                'churn_rate' => ($activeMembers + $churned) > 0 ? round($churned * 100 / ($activeMembers + $churned), 1) : 0.0,
            ],
            'signals' => [
                'subscribers' => SignalSubscription::query()->where('is_demo', $demo)->with('user.membership')->where('status', '!=', 'ended')->get()
                    ->filter(fn (SignalSubscription $s) => $s->isEntitled())->count(),
                'guests_paying' => SignalSubscription::query()->where('is_demo', $demo)->where('source', 'purchase')->where('grace_ends_at', '>=', now())->count(),
            ],
            'top_products' => DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('orders.is_demo', $demo)->whereNotNull('orders.paid_at')->whereBetween('orders.paid_at', [$from, $to])
                ->selectRaw('order_items.product_id, MAX(order_items.name) as name, SUM(order_items.quantity) as qty, SUM(order_items.line_total) as revenue')
                ->groupBy('order_items.product_id')->orderByDesc('qty')->limit(5)->get(),
            'stock_alerts' => Product::query()->where('is_demo', $demo)->where('status', 'active')->where('stock_mode', Product::STOCK)->with('variants')->get()
                ->filter(fn (Product $p) => ($p->availableStock() ?? PHP_INT_MAX) <= $lowStock)
                ->take(10)->map(fn (Product $p) => ['id' => $p->id, 'name' => $p->name, 'stock' => $p->availableStock()])->values(),
            'top_referrers' => app(Engagement::class)->topReferrers($to, 5, $demo),
            'cash_outs' => [
                'waiting' => PayoutRequest::query()->where('is_demo', $demo)->whereIn('status', ['requested', 'on_hold'])->count(),
                'waiting_amount' => (int) PayoutRequest::query()->where('is_demo', $demo)->whereIn('status', ['requested', 'on_hold'])->sum('amount'),
                'overdue' => PayoutRequest::query()->where('is_demo', $demo)->whereIn('status', ['requested', 'on_hold'])->where('due_at', '<', now())->count(),
            ],
            'queues' => [
                'proofs_to_review' => DB::table('payments')->where('is_demo', $demo)->where('status', 'awaiting_review')->count(),
                'fraud_open' => DB::table('fraud_flags')->where('is_demo', $demo)->where('status', 'open')->count(),
                'orders_to_ship' => Order::query()->where('is_demo', $demo)->whereIn('status', [Order::PAID, Order::MAKING])->count(),
                'commissions_on_hold' => Commission::query()->where('is_demo', $demo)->where('on_hold', true)->where('status', 'pending')->count(),
            ],
            'daily' => $this->daily($from, $to),
        ];
    }

    /** Net movement on an account in the period (natural sign). */
    private function flow(string $code, Carbon $from, Carbon $to, bool $income = false): int
    {
        $sum = (int) DB::table('ledger_postings')->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_postings.ledger_account_id')
            ->where('ledger_postings.is_demo', $this->demo)->where('ledger_accounts.code', $code)->whereBetween('ledger_postings.created_at', [$from, $to])->sum('ledger_postings.amount');

        return $income ? -$sum : $sum;
    }

    /** @return list<array{date:string, revenue:int, orders:int}> */
    private function daily(Carbon $from, Carbon $to): array
    {
        $rows = DB::table('ledger_postings')->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_postings.ledger_account_id')
            ->where('ledger_postings.is_demo', $this->demo)->where('ledger_accounts.code', 'like', 'income:%')->whereBetween('ledger_postings.created_at', [$from, $to])
            ->selectRaw('DATE(ledger_postings.created_at) as d, -SUM(ledger_postings.amount) as revenue')->groupBy('d')->pluck('revenue', 'd');
        $orders = Order::query()->where('is_demo', $this->demo)->whereNotNull('paid_at')->whereBetween('paid_at', [$from, $to])
            ->selectRaw('DATE(paid_at) as d, COUNT(*) as c')->groupBy('d')->pluck('c', 'd');

        $out = [];
        for ($day = $from->copy()->startOfDay(); $day->lte($to); $day->addDay()) {
            $k = $day->toDateString();
            $out[] = ['date' => $k, 'revenue' => (int) ($rows[$k] ?? 0), 'orders' => (int) ($orders[$k] ?? 0)];
        }

        return $out;
    }
}
