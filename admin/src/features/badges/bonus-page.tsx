import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from '@tanstack/react-router'
import { CalendarClock, CircleCheck, PiggyBank, ShieldCheck, SkipForward } from 'lucide-react'
import { useMutate } from '@/hooks/use-mutate'
import { api } from '@/lib/api'
import { formatBps, formatMoney } from '@/lib/money'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { ConfirmDialog } from '@/components/confirm-dialog'
import { Page } from '@/components/km/page'
import { PageSkeleton } from '@/components/km/page-skeleton'

type Line = { user_id: number; name: string; badge: string; kind: string; wanted: number; amount: number; limited_by: string | null; team_active: number; team_money: number; status?: string }
type Pool = { revenue: number; budget: number; wanted: number; paid: number; adjusted: boolean; members: number; lines: Line[] }
type Run = { month: string; label: string; status: 'paid' | 'skipped'; revenue: number; budget: number; wanted: number; paid: number; members: number; adjusted: boolean; note: string | null; at: string | null }
type Data = {
  guard: { price: number; commissions: number; bonus: number; loyalty: number; total: number; keep: number; max: number; keep_line: string }
  settings: { budget: number; team_limit: number; pending_days: number }
  ladder: { id: number; name: string; color: string; threshold: number; monthly_bonus: number }[]
  first_payout_done: boolean
  last_month: { month: string; label: string; run: Run | null; preview: Pool | null }
  forecast: { month: string; label: string; members: number; wanted: number; money_so_far: number; budget_so_far: number; budget_estimate: number; skipped: boolean }
  history: Run[]
}

const limitText: Record<string, string> = { team: 'Team limit', budget: 'Budget' }

/** Super Admin: is the shop safe, what goes out this month, pay now or skip, and every month so far. */
export function BonusPage() {
  const q = useQuery({ queryKey: ['badge-bonus'], queryFn: async () => (await api.get<{ data: Data }>('/badge-bonus')).data.data })
  const pay = useMutate('post', ['badge-bonus'], 'Bonuses paid. Members see them as "on the way".')
  const skip = useMutate('post', ['badge-bonus'], 'Month skipped. Nothing will be paid for it.')
  const [ask, setAsk] = useState<null | { kind: 'pay' | 'skip'; month: string; label: string }>(null)
  const last = useQuery({
    queryKey: ['badge-bonus', 'month', q.data?.last_month.month],
    enabled: !!q.data?.last_month.run,
    queryFn: async () => (await api.get<{ data: { lines: Line[] } }>(`/badge-bonus/months/${q.data?.last_month.month}`)).data.data.lines,
  })

  if (q.isLoading || !q.data) return <PageSkeleton cards={3} rows={6} />
  const d = q.data
  const lm = d.last_month
  const lines = lm.run ? (last.data ?? []) : (lm.preview?.lines ?? [])

  return (
    <Page title='Badge bonus' description='On the 1st of each month, members with a team badge (Bronze to Diamond) get a bonus for the month that ended. Safety limits keep you in profit.'>
      <div className='grid grid-cols-1 gap-4 lg:grid-cols-3'>
        {/* Safety */}
        <Card>
          <CardHeader className='pb-2'>
            <CardTitle className='flex items-center gap-2 text-base'><ShieldCheck className='size-5 text-emerald-600' /> You stay in profit</CardTitle>
            <CardDescription>{d.guard.keep_line}</CardDescription>
          </CardHeader>
          <CardContent className='grid gap-2 text-sm'>
            <Row label='Team earnings (3 levels)' value={formatBps(d.guard.commissions)} />
            <Row label='Badge bonus, at most' value={formatBps(d.guard.bonus)} />
            <Row label='Loyalty reward, at most' value={formatBps(d.guard.loyalty)} />
            <Row label='Most that can go out' value={`${formatBps(d.guard.total)} of ${formatBps(d.guard.max)} allowed`} strong />
            <ul className='mt-2 grid gap-1 text-xs text-muted-foreground'>
              <li>• Each member: at most {formatBps(d.settings.team_limit)} of what their own invited people paid that month.</li>
              <li>• All members together: at most {formatBps(d.settings.budget)} of the month's membership money. Over it, everyone gets the same smaller share.</li>
              <li>• Paid only for a finished month, from money really paid. On the way for {d.settings.pending_days} days first.</li>
            </ul>
          </CardContent>
        </Card>

        {/* Last month */}
        <Card>
          <CardHeader className='pb-2'>
            <CardTitle className='flex items-center gap-2 text-base'><CircleCheck className='size-5' /> {lm.label}</CardTitle>
            <CardDescription>
              {lm.run?.status === 'paid' && 'Paid.'}
              {lm.run?.status === 'skipped' && 'Skipped: nothing paid for this month.'}
              {!lm.run && 'Not paid yet. This is the list it would pay (nothing is paid until you press the button).'}
            </CardDescription>
          </CardHeader>
          <CardContent className='grid gap-2 text-sm'>
            {lm.run ? (
              <>
                <Row label='Paid' value={formatMoney(lm.run.paid)} strong />
                <Row label='Members' value={String(lm.run.members)} />
                <Row label='Budget' value={formatMoney(lm.run.budget)} />
                <Row label='Membership money' value={formatMoney(lm.run.revenue)} />
                {lm.run.adjusted && <Badge variant='outline' className='w-fit'>Bonus adjusted this month</Badge>}
              </>
            ) : lm.preview ? (
              <>
                <Row label='Would pay' value={formatMoney(lm.preview.paid)} strong />
                <Row label='Members' value={String(lm.preview.members)} />
                <Row label='Budget' value={formatMoney(lm.preview.budget)} />
                <Row label='Membership money' value={formatMoney(lm.preview.revenue)} />
                {lm.preview.adjusted && <Badge variant='outline' className='w-fit'>Over budget: everyone gets the same smaller share</Badge>}
                {!d.first_payout_done && <p className='rounded-md bg-amber-500/10 p-2 text-xs'>First payout: check the list below, then press "Pay now". After that it pays by itself on the 1st of every month.</p>}
                <div className='mt-2 flex flex-wrap gap-2'>
                  <Button className='min-h-12' onClick={() => setAsk({ kind: 'pay', month: lm.month, label: lm.label })}><PiggyBank /> Pay {formatMoney(lm.preview.paid)} now</Button>
                  <Button className='min-h-12' variant='outline' onClick={() => setAsk({ kind: 'skip', month: lm.month, label: lm.label })}><SkipForward /> Skip this month</Button>
                </div>
              </>
            ) : <p className='text-muted-foreground'>No membership money that month.</p>}
          </CardContent>
        </Card>

        {/* This month */}
        <Card>
          <CardHeader className='pb-2'>
            <CardTitle className='flex items-center gap-2 text-base'><CalendarClock className='size-5' /> {d.forecast.label} so far</CardTitle>
            <CardDescription>A guess for the next payout, if the month ended now.</CardDescription>
          </CardHeader>
          <CardContent className='grid gap-2 text-sm'>
            <Row label='Members with a badge' value={String(d.forecast.members)} />
            <Row label='Their bonuses' value={formatMoney(d.forecast.wanted)} strong />
            <Row label='Budget (from last month)' value={formatMoney(d.forecast.budget_estimate)} />
            <Row label='Membership money so far' value={formatMoney(d.forecast.money_so_far)} />
            {d.forecast.skipped
              ? <Badge variant='outline' className='w-fit'>This month is skipped</Badge>
              : <Button className='mt-2 min-h-12 w-fit' variant='outline' onClick={() => setAsk({ kind: 'skip', month: d.forecast.month, label: d.forecast.label })}><SkipForward /> Skip {d.forecast.label}</Button>}
          </CardContent>
        </Card>
      </div>

      {/* Bonus table */}
      <Card className='mt-4'>
        <CardHeader className='pb-2'>
          <CardTitle className='text-base'>Bonus per badge</CardTitle>
          <CardDescription>Only the highest badge pays. Change amounts on the <Link to='/badges' className='underline'>Badges</Link> page.</CardDescription>
        </CardHeader>
        <CardContent className='grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5'>
          {d.ladder.map((b) => (
            <div key={b.id} className='rounded-md border p-3'>
              <div className='font-semibold' style={{ color: b.color }}>{b.name}</div>
              <div className='text-xs text-muted-foreground'>{b.threshold} active members</div>
              <div className='mt-1 font-semibold tabular-nums'>{formatMoney(b.monthly_bonus)} / month</div>
            </div>
          ))}
        </CardContent>
      </Card>

      {/* Members of last month */}
      <Card className='mt-4'>
        <CardHeader className='pb-2'><CardTitle className='text-base'>{lm.run ? `Paid for ${lm.label}` : `Would be paid for ${lm.label}`} ({lines.length})</CardTitle></CardHeader>
        <CardContent className='overflow-x-auto'>
          <Table>
            <TableHeader><TableRow><TableHead>Member</TableHead><TableHead>Badge</TableHead><TableHead className='text-end'>Bonus</TableHead><TableHead>Limited by</TableHead><TableHead className='text-end'>Active team</TableHead><TableHead className='text-end'>Team paid</TableHead></TableRow></TableHeader>
            <TableBody>
              {lines.length === 0 && <TableRow><TableCell colSpan={6} className='py-6 text-center text-muted-foreground'>Nobody this month.</TableCell></TableRow>}
              {lines.map((l) => (
                <TableRow key={`${l.user_id}-${l.badge}-${l.kind}`}>
                  <TableCell><Link to='/members/$id' params={{ id: String(l.user_id) }} className='hover:underline'>{l.name}</Link></TableCell>
                  <TableCell>{l.badge}{l.kind === 'one_time' && <span className='text-xs text-muted-foreground'> (one time)</span>}</TableCell>
                  <TableCell className='text-end tabular-nums'>{formatMoney(l.amount)}{l.amount < l.wanted && <div className='text-xs text-muted-foreground line-through'>{formatMoney(l.wanted)}</div>}</TableCell>
                  <TableCell>{l.limited_by ? limitText[l.limited_by] : '—'}</TableCell>
                  <TableCell className='text-end tabular-nums'>{l.team_active}</TableCell>
                  <TableCell className='text-end tabular-nums'>{formatMoney(l.team_money)}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </CardContent>
      </Card>

      {/* History */}
      <Card className='mt-4'>
        <CardHeader className='pb-2'><CardTitle className='text-base'>Every month</CardTitle></CardHeader>
        <CardContent className='overflow-x-auto'>
          <Table>
            <TableHeader><TableRow><TableHead>Month</TableHead><TableHead>What happened</TableHead><TableHead className='text-end'>Membership money</TableHead><TableHead className='text-end'>Budget</TableHead><TableHead className='text-end'>Paid</TableHead><TableHead className='text-end'>Members</TableHead></TableRow></TableHeader>
            <TableBody>
              {d.history.length === 0 && <TableRow><TableCell colSpan={6} className='py-6 text-center text-muted-foreground'>No month paid yet.</TableCell></TableRow>}
              {d.history.map((r) => (
                <TableRow key={r.month}>
                  <TableCell>{r.label}</TableCell>
                  <TableCell>{r.status === 'paid' ? (r.adjusted ? 'Paid (adjusted to the budget)' : 'Paid') : `Skipped${r.note ? `: ${r.note}` : ''}`}</TableCell>
                  <TableCell className='text-end tabular-nums'>{formatMoney(r.revenue)}</TableCell>
                  <TableCell className='text-end tabular-nums'>{formatMoney(r.budget)}</TableCell>
                  <TableCell className='text-end tabular-nums'>{formatMoney(r.paid)}</TableCell>
                  <TableCell className='text-end tabular-nums'>{r.members}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </CardContent>
      </Card>

      <ConfirmDialog
        open={ask !== null}
        onOpenChange={(o) => !o && setAsk(null)}
        title={ask?.kind === 'pay' ? `Pay the ${ask.label} bonuses?` : `Skip ${ask?.label ?? ''}?`}
        desc={ask?.kind === 'pay'
          ? `Members get their bonus as "on the way" for ${d.settings.pending_days} days, then they can use it. This can only happen once for ${ask.label}.`
          : 'Nobody gets a badge bonus for this month, now or later.'}
        confirmText={ask?.kind === 'pay' ? 'Pay now' : 'Skip this month'}
        destructive={ask?.kind === 'skip'}
        isLoading={pay.isPending || skip.isPending}
        handleConfirm={async () => {
          if (!ask) return
          await (ask.kind === 'pay' ? pay : skip).mutateAsync({ path: `/badge-bonus/${ask.kind}`, body: { month: ask.month } }).catch(() => {})
          setAsk(null)
        }}
      />
    </Page>
  )
}

function Row({ label, value, strong }: { label: string; value: string; strong?: boolean }) {
  return <div className='flex justify-between gap-3'><span className='text-muted-foreground'>{label}</span><span className={strong ? 'font-semibold tabular-nums' : 'tabular-nums'}>{value}</span></div>
}
