import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from '@tanstack/react-router'
import { AlertTriangle, Banknote, Crown, Package, Radio, ShieldAlert, TrendingUp, Users } from 'lucide-react'
import { Area, AreaChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { api } from '@/lib/api'
import { formatMoney } from '@/lib/money'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Page } from '@/components/km/page'

type D = {
  revenue: { products: number; memberships: number; signals: number; total: number }
  gross_profit: number
  costs: { products: number; commissions: number; loyalty: number; payment_fees: number }
  discounts: { member: number; coupons: number; free_shipping: number; total: number }
  shipping: { charged: number; free_given: number }
  orders: { paid: number; average: number }
  commissions: { earned_in_period: number; pending: number; available: number; requested: number; paid_out: number; paid_out_all_time: number }
  loyalty_rewards: number; payment_fees: number
  membership: { active: number; mrr: number; new: number; churned: number; churn_rate: number }
  signals: { subscribers: number; guests_paying: number }
  top_products: { product_id: number; name: string; qty: number; revenue: number }[]
  stock_alerts: { id: number; name: string; stock: number }[]
  top_referrers: { rank: number; name: string; badge: { name: string; icon: string; color: string } | null; count: number }[]
  dataset: 'real' | 'demo'
  demo: { visible: boolean; exists: boolean }
  cash_outs: { waiting: number; waiting_amount: number; overdue: number }
  queues: { proofs_to_review: number; fraud_open: number; orders_to_ship: number; commissions_on_hold: number }
  daily: { date: string; revenue: number; orders: number }[]
}

const ranges = [{ label: '7 days', days: 7 }, { label: '30 days', days: 30 }, { label: '90 days', days: 90 }, { label: '12 months', days: 365 }]

export function Dashboard() {
  const [days, setDays] = useState(30)
  const [data, setData] = useState<'demo' | 'real'>('demo')
  const q = useQuery({
    queryKey: ['dashboard', days, data],
    queryFn: async () => {
      const to = new Date()
      const from = new Date(Date.now() - (days - 1) * 86400_000)
      const iso = (x: Date) => x.toISOString().slice(0, 10)
      return (await api.get<{ data: D }>('/dashboard', { params: { from: iso(from), to: iso(to), data } })).data.data
    },
  })
  const d = q.data

  return (
    <Page title='Dashboard' description='Money from the ledger, so refunds are already taken off.'
      actions={ranges.map((r) => <Button key={r.days} size='sm' variant={days === r.days ? 'default' : 'outline'} onClick={() => setDays(r.days)}>{r.label}</Button>)}>
      {!d ? <p className='text-muted-foreground'>Loading…</p> : (
        <div className='grid gap-4'>
          {d.demo.visible && d.demo.exists && (
            <div className='flex flex-wrap items-center gap-2 rounded-md border border-dashed p-3 text-sm' data-testid='dataset-switch'>
              <span>{d.dataset === 'demo' ? 'Showing demo numbers (not real money).' : 'Showing real numbers only.'}</span>
              <Button size='sm' variant={data === 'demo' ? 'default' : 'outline'} onClick={() => setData('demo')}>Demo data</Button>
              <Button size='sm' variant={data === 'real' ? 'default' : 'outline'} onClick={() => setData('real')}>Real data</Button>
              <a className='ms-auto underline' href='/admin/demo'>Hide or delete demo data</a>
            </div>
          )}
          <Attention d={d} />
          <div className='grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4'>
            <Kpi icon={TrendingUp} title='Revenue' value={formatMoney(d.revenue.total)}
              sub={`Shop ${formatMoney(d.revenue.products)} · Membership ${formatMoney(d.revenue.memberships)} · Signals ${formatMoney(d.revenue.signals)}`} />
            <Kpi icon={Banknote} title='Gross profit' value={formatMoney(d.gross_profit)}
              sub={`after product cost ${formatMoney(d.costs.products)}, commissions ${formatMoney(d.costs.commissions)}, loyalty ${formatMoney(d.costs.loyalty)}, fees ${formatMoney(d.costs.payment_fees)}`} />
            <Kpi icon={Crown} title='Membership MRR' value={formatMoney(d.membership.mrr)}
              sub={`${d.membership.active} active · ${d.membership.new} new · ${d.membership.churned} churned (${d.membership.churn_rate}%)`} />
            <Kpi icon={Radio} title='Signals subscribers' value={String(d.signals.subscribers)} sub={`${d.signals.guests_paying} paying guests, the rest are members`} />
          </div>

          <Card>
            <CardHeader><CardTitle className='text-base'>Revenue per day</CardTitle><CardDescription>{d.orders.paid} paid orders · average {formatMoney(d.orders.average)}</CardDescription></CardHeader>
            <CardContent className='h-64'>
              <ResponsiveContainer width='100%' height='100%'>
                <AreaChart data={d.daily}>
                  <CartesianGrid strokeDasharray='3 3' className='stroke-muted' />
                  <XAxis dataKey='date' tickFormatter={(v: string) => v.slice(5)} fontSize={12} />
                  <YAxis allowDecimals={false} domain={[0, (max: number) => Math.max(max, 100_000)]} tickFormatter={(v: number) => `${Math.round(v / 100).toLocaleString()}`} fontSize={12} width={60} />
                  <Tooltip formatter={(v) => formatMoney(Number(v))} labelFormatter={(l) => String(l)} />
                  <Area type='monotone' dataKey='revenue' stroke='var(--color-primary)' fill='var(--color-primary)' fillOpacity={0.15} />
                </AreaChart>
              </ResponsiveContainer>
            </CardContent>
          </Card>

          <div className='grid grid-cols-1 gap-4 lg:grid-cols-3'>
            <Card>
              <CardHeader><CardTitle className='text-base'>Commissions</CardTitle></CardHeader>
              <CardContent className='grid gap-1 text-sm'>
                <Line label='Earned in period' value={formatMoney(d.commissions.earned_in_period)} />
                <Line label='Pending (all members)' value={formatMoney(d.commissions.pending)} />
                <Line label='Available (all members)' value={formatMoney(d.commissions.available)} />
                <Line label='Cash-outs requested' value={formatMoney(d.commissions.requested)} />
                <Line label='Paid out in period' value={formatMoney(d.commissions.paid_out)} />
                <Line label='Paid out all time' value={formatMoney(d.commissions.paid_out_all_time)} />
                <Line label='Loyalty rewards in period' value={formatMoney(d.loyalty_rewards)} />
              </CardContent>
            </Card>
            <Card>
              <CardHeader><CardTitle className='text-base'>Discounts & shipping</CardTitle></CardHeader>
              <CardContent className='grid gap-1 text-sm'>
                <Line label='Member discounts' value={formatMoney(d.discounts.member)} />
                <Line label='Coupons' value={formatMoney(d.discounts.coupons)} />
                <Line label='Free shipping given' value={formatMoney(d.discounts.free_shipping)} />
                <Line label='Shipping charged' value={formatMoney(d.shipping.charged)} />
                <Line label='Payment fees' value={formatMoney(d.payment_fees)} />
              </CardContent>
            </Card>
            <Card>
              <CardHeader><CardTitle className='text-base'>Top referrers this month</CardTitle></CardHeader>
              <CardContent className='grid gap-1 text-sm'>
                {d.top_referrers.length === 0 && <span className='text-muted-foreground'>No referrals yet this month.</span>}
                {d.top_referrers.map((r) => <Line key={r.rank} label={`${r.rank}. ${r.name}${r.badge ? ` · ${r.badge.name}` : ''}`} value={`${r.count} new`} />)}
              </CardContent>
            </Card>
            <Card>
              <CardHeader><CardTitle className='text-base'>Top products</CardTitle></CardHeader>
              <CardContent className='grid gap-1 text-sm'>
                {d.top_products.map((p) => <Line key={p.product_id} label={`${p.qty} × ${p.name}`} value={formatMoney(Number(p.revenue))} />)}
                {d.top_products.length === 0 && <span className='text-muted-foreground'>No sales in this period.</span>}
              </CardContent>
            </Card>
            <Card>
              <CardHeader><CardTitle className='flex items-center gap-2 text-base'><Package className='size-4' /> Stock alerts</CardTitle></CardHeader>
              <CardContent className='grid gap-1 text-sm'>
                {d.stock_alerts.map((p) => <Link key={p.id} to='/products/$id' params={{ id: String(p.id) }} className='flex justify-between hover:underline'><span>{p.name}</span><span>{p.stock} left</span></Link>)}
                {d.stock_alerts.length === 0 && <span className='text-muted-foreground'>All good.</span>}
              </CardContent>
            </Card>
            <Card>
              <CardHeader><CardTitle className='flex items-center gap-2 text-base'><Users className='size-4' /> Cash-out queue</CardTitle></CardHeader>
              <CardContent className='grid gap-1 text-sm'>
                <Line label='Waiting' value={`${d.cash_outs.waiting} · ${formatMoney(d.cash_outs.waiting_amount)}`} />
                <Line label='Overdue (> 3 days)' value={String(d.cash_outs.overdue)} />
                <Button size='sm' variant='outline' className='mt-2' asChild><Link to='/cash-outs'>Open queue</Link></Button>
              </CardContent>
            </Card>
          </div>
        </div>
      )}
    </Page>
  )
}

function Attention({ d }: { d: D }) {
  const items = [
    d.queues.proofs_to_review > 0 && { to: '/payments' as const, text: `${d.queues.proofs_to_review} payment proof(s) to review` },
    d.queues.orders_to_ship > 0 && { to: '/orders' as const, text: `${d.queues.orders_to_ship} order(s) to make or ship` },
    d.cash_outs.overdue > 0 && { to: '/cash-outs' as const, text: `${d.cash_outs.overdue} overdue cash-out(s)` },
    d.queues.fraud_open > 0 && { to: '/fraud' as const, text: `${d.queues.fraud_open} fraud flag(s) open` },
  ].filter(Boolean) as { to: '/payments' | '/orders' | '/cash-outs' | '/fraud'; text: string }[]
  if (items.length === 0) return null
  return (
    <div className='flex flex-wrap gap-2'>
      {items.map((i) => (
        <Button key={i.to} variant='outline' size='sm' className='border-amber-300 bg-amber-50 text-amber-900 dark:bg-amber-950/40 dark:text-amber-200' asChild>
          <Link to={i.to}>{i.to === '/fraud' ? <ShieldAlert /> : <AlertTriangle />} {i.text}</Link>
        </Button>
      ))}
    </div>
  )
}

function Kpi({ icon: Icon, title, value, sub }: { icon: React.ElementType; title: string; value: string; sub: string }) {
  return (
    <Card className='gap-2'>
      <CardHeader className='flex flex-row items-center justify-between pb-0'>
        <CardTitle className='text-sm font-medium'>{title}</CardTitle><Icon className='size-4 text-muted-foreground' />
      </CardHeader>
      <CardContent><div className='text-2xl font-bold'>{value}</div><p className='text-xs text-muted-foreground'>{sub}</p></CardContent>
    </Card>
  )
}

function Line({ label, value }: { label: string; value: string }) {
  return <div className='flex justify-between gap-2'><span className='text-muted-foreground'>{label}</span><span className='font-medium'>{value}</span></div>
}
