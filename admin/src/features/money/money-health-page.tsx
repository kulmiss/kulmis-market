import { useEffect, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { CircleCheck, CircleAlert, TriangleAlert } from 'lucide-react'
import { api } from '@/lib/api'
import { formatMoney } from '@/lib/money'
import { cn } from '@/lib/utils'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Segmented } from '@/components/km/segmented'
import { Page } from '@/components/km/page'
import { PageSkeleton } from '@/components/km/page-skeleton'

type Health = {
  money_in: number
  paid_to_members: number
  parts: { team_earnings: number; badge_bonus: number; rewards_and_prizes: number; loyalty: number }
  kept: number
  kept_percent: number
  on_the_way: number
  ready_to_use: number
  cash_outs_waiting: number
  cash_outs_waiting_count: number
  owed: number
  shop_has: number
  light: 'green' | 'yellow' | 'red'
  why: string[]
  months: { month: string; money_in: number; paid_to_members: number; kept_percent: number }[]
}
type WhatIf = { members: number; money_in: number; team_earnings: number; badge_bonus: number; paid_to_members: number; kept: number; kept_percent: number; one_member_earns: number }

const LIGHT = {
  green: { text: 'Healthy', note: 'The shop keeps most of the money and has more than it owes members.', icon: CircleCheck, cls: 'border-emerald-500/40 bg-emerald-500/10 text-emerald-800 dark:text-emerald-200' },
  yellow: { text: 'Watch', note: 'Still safe, but look at the reason below.', icon: TriangleAlert, cls: 'border-amber-500/40 bg-amber-500/10 text-amber-900 dark:text-amber-200' },
  red: { text: 'Act now', note: 'Something is not safe. Read the reason below.', icon: CircleAlert, cls: 'border-red-500/40 bg-red-500/10 text-red-800 dark:text-red-200' },
}

/** Is the shop safe? Money in, paid to members, kept, owed — and a "what if" calculator. */
export function MoneyHealthPage() {
  const [period, setPeriod] = useState('month')
  const [demo, setDemo] = useState(false)
  const q = useQuery({ queryKey: ['money-health', period, demo], queryFn: async () => (await api.get<{ data: Health }>('/money-health', { params: { period, demo: demo ? 1 : 0 } })).data.data })
  const h = q.data
  if (!h) return <PageSkeleton cards={4} rows={4} />
  const light = LIGHT[h.light]
  const Icon = light.icon

  return (
    <Page title='Money health' description='Is the shop safe? What came in, what members got, what the shop kept, and what it owes members. Real data and demo data are never added together.'>
      <div className='mb-4 flex flex-wrap gap-3'>
      <Segmented label='Which data' value={demo ? 'demo' : 'real'} onChange={(v) => setDemo(v === 'demo')}
        options={[{ value: 'real', label: 'Real data' }, { value: 'demo', label: 'Demo data' }]} />
      <Segmented label='Period' value={period} onChange={setPeriod}
        options={[{ value: 'month', label: 'This month' }, { value: 'last_month', label: 'Last month' }, { value: 'year', label: 'This year' }, { value: 'all', label: 'All time' }]} />
      </div>

      <div role='status' className={cn('mb-4 flex items-start gap-3 rounded-lg border p-4', light.cls)} data-health-light={h.light}>
        <Icon className='mt-0.5 size-7 shrink-0' />
        <div>
          <p className='text-lg font-semibold'>{light.text}</p>
          <p className='text-sm'>{light.note}</p>
          {h.why.length > 0 && <ul className='mt-2 list-disc ps-5 text-sm'>{h.why.map((w) => <li key={w}>{w}</li>)}</ul>}
        </div>
      </div>

      <div className='grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4'>
        <Stat label='Money in' value={h.money_in} sub='Paid by customers, after refunds' />
        <Stat label='Paid to members' value={h.paid_to_members} sub={`${pct(h.money_in ? (100 * h.paid_to_members) / h.money_in : 0)} of the money in`} />
        <Stat label='Kept by the shop' value={h.kept} sub={`${pct(h.kept_percent)} (before product costs)`} strong />
        <Stat label='Members are owed' value={h.owed} sub={`The shop has ${formatMoney(h.shop_has)} from customers`} />
      </div>

      <div className='mt-3 grid grid-cols-1 gap-3 lg:grid-cols-2'>
        <Card>
          <CardHeader className='pb-2'><CardTitle className='text-base'>What members got</CardTitle></CardHeader>
          <CardContent className='grid gap-2 text-sm'>
            <Row label='Team earnings' value={h.parts.team_earnings} />
            <Row label='Badge bonus' value={h.parts.badge_bonus} />
            <Row label='First-payment bonuses, prizes, rewards' value={h.parts.rewards_and_prizes} />
            <Row label='Loyalty reward' value={h.parts.loyalty} />
          </CardContent>
        </Card>
        <Card>
          <CardHeader className='pb-2'><CardTitle className='text-base'>What members are owed now</CardTitle></CardHeader>
          <CardContent className='grid gap-2 text-sm'>
            <Row label='On the way (ready in 14 days)' value={h.on_the_way} />
            <Row label='Ready to use (in wallets)' value={h.ready_to_use} />
            <Row label={`Cash-outs waiting (${h.cash_outs_waiting_count})`} value={h.cash_outs_waiting} />
          </CardContent>
        </Card>
      </div>

      <Card className='mt-3'>
        <CardHeader className='pb-2'><CardTitle className='text-base'>Last 6 months: share the shop kept</CardTitle></CardHeader>
        <CardContent className='grid gap-2'>
          {h.months.map((m) => (
            <div key={m.month} className='grid grid-cols-[5.5rem_1fr_4rem] items-center gap-3 text-sm'>
              <span className='text-muted-foreground'>{m.month}</span>
              <div className='h-3 rounded-full bg-muted' aria-hidden>
                <div className={cn('h-3 rounded-full', m.kept_percent < 65 ? 'bg-red-500' : m.kept_percent < 75 ? 'bg-amber-500' : 'bg-emerald-600')} style={{ width: `${m.money_in ? Math.max(2, m.kept_percent) : 0}%` }} />
              </div>
              <span className='text-end tabular-nums'>{m.money_in ? pct(m.kept_percent) : '—'}</span>
            </div>
          ))}
          <p className='text-xs text-muted-foreground'>Green: 75% or more. Yellow: 65–75%. Red: below 65% (the settings never allow it).</p>
        </CardContent>
      </Card>

      <WhatIfCard />
    </Page>
  )
}

function WhatIfCard() {
  const [members, setMembers] = useState(100)
  const [invites, setInvites] = useState(3)
  const [debounced, setDebounced] = useState({ members: 100, invites: 3 })
  useEffect(() => {
    const t = setTimeout(() => setDebounced({ members, invites }), 300)
    return () => clearTimeout(t)
  }, [members, invites])
  const q = useQuery({
    queryKey: ['what-if', debounced],
    queryFn: async () => (await api.get<{ data: WhatIf }>('/money-health/what-if', { params: debounced })).data.data,
  })
  const w = q.data
  return (
    <Card className='mt-3'>
      <CardHeader className='pb-2'><CardTitle className='text-base'>What if…</CardTitle></CardHeader>
      <CardContent className='grid gap-4'>
        <div className='grid gap-3 sm:grid-cols-2'>
          <label className='grid gap-1.5 text-sm font-medium'>Members to start with
            <Input type='number' min={0} max={1000000} value={members} onChange={(e) => setMembers(Math.max(0, Number(e.target.value)))} className='h-12' />
          </label>
          <label className='grid gap-1.5 text-sm font-medium'>Paying people each member brings
            <Input type='number' min={0} max={50} step={0.5} value={invites} onChange={(e) => setInvites(Math.max(0, Number(e.target.value)))} className='h-12' />
          </label>
        </div>
        {w && (
          <div className='grid gap-2 text-sm' data-what-if>
            <p>With <b>{w.members.toLocaleString()}</b> paying members (3 levels down), one month:</p>
            <Row label='Money in' value={w.money_in} />
            <Row label='Team earnings' value={w.team_earnings} />
            <Row label='Badge bonus' value={w.badge_bonus} />
            <Row label='Members get' value={w.paid_to_members} />
            <Row label={`The shop keeps (${pct(w.kept_percent)})`} value={w.kept} strong />
            <p className='text-muted-foreground'>A member who brings {invites} people (who each bring {invites}) earns about <b>{formatMoney(w.one_member_earns)}</b> a month.</p>
          </div>
        )}
      </CardContent>
    </Card>
  )
}

function Stat({ label, value, sub, strong }: { label: string; value: number; sub: string; strong?: boolean }) {
  return (
    <Card>
      <CardContent className='grid gap-1 pt-6'>
        <p className='text-xs font-medium tracking-wide text-muted-foreground uppercase'>{label}</p>
        <p className={cn('text-2xl tabular-nums', strong ? 'font-bold' : 'font-semibold')}>{formatMoney(value)}</p>
        <p className='text-xs text-muted-foreground'>{sub}</p>
      </CardContent>
    </Card>
  )
}

function Row({ label, value, strong }: { label: string; value: number; strong?: boolean }) {
  return (
    <div className='flex items-baseline justify-between gap-3 border-b pb-1 last:border-0'>
      <span>{label}</span>
      <span className={cn('tabular-nums', strong && 'font-bold')}>{formatMoney(value)}</span>
    </div>
  )
}

const pct = (n: number) => `${Math.round(n * 10) / 10}%`
