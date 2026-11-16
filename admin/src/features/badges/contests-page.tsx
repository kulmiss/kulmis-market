import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Pencil, Plus, ShieldCheck, Trophy, X } from 'lucide-react'
import { useMutate } from '@/hooks/use-mutate'
import { api } from '@/lib/api'
import { formatBps, formatDate, formatMoney } from '@/lib/money'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Switch } from '@/components/ui/switch'
import { Textarea } from '@/components/ui/textarea'
import { FormDialog } from '@/components/km/form-dialog'
import { MoneyInput } from '@/components/km/money-input'
import { Page } from '@/components/km/page'
import { PageSkeleton } from '@/components/km/page-skeleton'
import { StatusBadge } from '@/components/km/status-badge'

type Board = { place: number; name: string; score_text: string; badge: { name: string } | null }
type Contest = {
  id: number; name: string; description: string | null; rule: string; prizes: number[]; min_score: number; status: string; phase: string
  starts_at: string; ends_at: string; settles_at: string; pool: number; cap_now: number; cap_line: string; paid_total: number; was_cut: boolean
  result: { place: number; name: string; score: number; prize: number }[] | null; leaderboard: Board[]
}
type Challenge = { id: number; name: string; target: number; reward: number; monthly_cap: number | null; is_active: boolean; runs: { month: string; members: number; paid: number; was_cut: boolean }[] }
type Data = { contests: Contest[]; challenges: Challenge[]; rules: Record<string, string>; budgets: { contest: number; challenge: number }
  guard: { total: number; max: number; keep_line: string; contests: number; challenges: number } }

const PHASE: Record<string, string> = { scheduled: 'scheduled', running: 'live', checking: 'pending', paid: 'paid', cancelled: 'cancelled' }
const toLocal = (iso?: string) => { if (!iso) return ''; const d = new Date(iso); return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 16) }

export function ContestsPage() {
  const q = useQuery({ queryKey: ['contests'], queryFn: async () => (await api.get<{ data: Data }>('/contests')).data.data })
  if (!q.data) return <PageSkeleton />
  const d = q.data
  return (
    <Page title='Contests and challenges' description='Fixed prizes, paid only after the end and the refund check, from money really paid.' actions={<ContestDialog rules={d.rules} />}>
      <Card className='mb-4'>
        <CardHeader className='pb-2'><CardTitle className='flex items-center gap-2 text-base'><ShieldCheck className='size-5 text-emerald-600' /> You stay in profit</CardTitle>
          <CardDescription>{d.guard.keep_line} Contests at most {formatBps(d.budgets.contest)} and challenges at most {formatBps(d.budgets.challenge)} of the membership money (Settings).</CardDescription></CardHeader>
      </Card>
      <div className='grid gap-4 lg:grid-cols-2'>
        {d.contests.length === 0 && <p className='text-sm text-muted-foreground'>No contest yet. Press “New contest”.</p>}
        {d.contests.map((c) => <ContestCard key={c.id} c={c} rules={d.rules} />)}
      </div>
      <div className='mt-8 mb-2 flex items-center justify-between'><h2 className='font-semibold'>Team challenges</h2><ChallengeDialog /></div>
      <div className='grid gap-3 md:grid-cols-2 lg:grid-cols-3'>
        {d.challenges.length === 0 && <p className='text-sm text-muted-foreground'>No challenge yet. Example: “Bring 3 new paying members this month → 300 ETB”.</p>}
        {d.challenges.map((c) => (
          <Card key={c.id}>
            <CardHeader className='pb-2'><CardTitle className='flex items-center justify-between text-base'>{c.name}<ChallengeDialog c={c} /></CardTitle>
              <CardDescription>Bring {c.target} new paying → {formatMoney(c.reward)}{c.monthly_cap ? ` · at most ${formatMoney(c.monthly_cap)} a month` : ''} {!c.is_active && '(off)'}</CardDescription></CardHeader>
            <CardContent className='grid gap-1 text-sm'>
              {c.runs.length === 0 ? <span className='text-muted-foreground'>Not paid yet.</span> : c.runs.map((r) => (
                <div key={r.month} className='flex justify-between'><span>{formatDate(r.month).replace(/^\d+ /, '')}</span><span>{r.members} members · {formatMoney(r.paid)}{r.was_cut ? ' (shared)' : ''}</span></div>
              ))}
            </CardContent>
          </Card>
        ))}
      </div>
    </Page>
  )
}

function ContestCard({ c, rules }: { c: Contest; rules: Record<string, string> }) {
  const cancel = useMutate('post', ['contests'], 'Contest cancelled')
  return (
    <Card>
      <CardHeader className='pb-2'>
        <CardTitle className='flex items-center justify-between gap-2 text-base'>
          <span className='flex items-center gap-2'><Trophy className='size-4' />{c.name}</span>
          <span className='flex items-center gap-1'><StatusBadge status={PHASE[c.phase] ?? c.phase} />{c.status === 'active' && <ContestDialog c={c} rules={rules} />}</span>
        </CardTitle>
        <CardDescription>{rules[c.rule]} · {formatDate(c.starts_at, true)} → {formatDate(c.ends_at, true)} · prizes paid {formatDate(c.settles_at)}</CardDescription>
      </CardHeader>
      <CardContent className='grid gap-2 text-sm'>
        <p>Prizes: {c.prizes.map((p, i) => `${i + 1}. ${formatMoney(p)}`).join(' · ')} (pool {formatMoney(c.pool)})</p>
        {c.status === 'active' && <p className={c.pool > c.cap_now ? 'text-amber-700 dark:text-amber-400' : 'text-muted-foreground'}>{c.cap_line}</p>}
        {c.leaderboard.length > 0 && (
          <ol className='grid gap-0.5'>{c.leaderboard.map((b) => <li key={b.place} className='flex justify-between'><span>{b.place}. {b.name}{b.badge ? ` · ${b.badge.name}` : ''}</span><span className='text-muted-foreground'>{b.score_text}</span></li>)}</ol>
        )}
        {c.result && <ol className='grid gap-0.5'>{c.result.map((r) => <li key={r.place}>{r.place}. {r.name} — {formatMoney(r.prize)}</li>)}</ol>}
        {c.status === 'paid' && <p className='text-muted-foreground'>Paid {formatMoney(c.paid_total)}{c.was_cut ? ' (cut to fit the budget)' : ''}.</p>}
        {c.status === 'active' && <div><Button size='sm' variant='outline' onClick={() => { if (confirm('Cancel this contest? No prizes will be paid.')) cancel.mutate({ path: `/contests/${c.id}/cancel` }) }}>Cancel contest</Button></div>}
      </CardContent>
    </Card>
  )
}

function ContestDialog({ c, rules }: { c?: Contest; rules: Record<string, string> }) {
  const started = !!c && new Date(c.starts_at) <= new Date()
  const now = new Date()
  const [d, setD] = useState({ name: c?.name ?? '', description: c?.description ?? '', rule: c?.rule ?? 'new_paying_members', min_score: c?.min_score ?? 1,
    starts_at: toLocal(c?.starts_at ?? now.toISOString()), ends_at: toLocal(c?.ends_at ?? new Date(now.getTime() + 30 * 86400000).toISOString()),
    prizes: c?.prizes ?? [300000, 200000, 100000] })
  const save = useMutate(c ? 'put' : 'post', ['contests'], 'Contest saved')
  return (
    <FormDialog title={c ? 'Edit contest' : 'New contest'} trigger={c ? <Button size='icon' variant='ghost' aria-label='Edit'><Pencil /></Button> : <Button><Plus /> New contest</Button>}
      onSave={() => save.mutateAsync({ path: c ? `/contests/${c.id}` : '/contests', body: { ...d, starts_at: new Date(d.starts_at).toISOString(), ends_at: new Date(d.ends_at).toISOString() } })}>
      <L label='Name'><Input value={d.name} placeholder='Timket contest' onChange={(e) => setD({ ...d, name: e.target.value })} /></L>
      <L label='Short text (optional)'><Textarea rows={2} value={d.description} onChange={(e) => setD({ ...d, description: e.target.value })} /></L>
      {started ? <p className='text-sm text-muted-foreground'>It has started: only the name and text can change now (fair for everyone).</p> : (
        <>
          <L label='Who wins'>
            <Select value={d.rule} onValueChange={(v) => setD({ ...d, rule: v })}><SelectTrigger><SelectValue /></SelectTrigger>
              <SelectContent>{Object.entries(rules).map(([k, v]) => <SelectItem key={k} value={k}>{v}</SelectItem>)}</SelectContent></Select>
          </L>
          <div className='grid grid-cols-1 gap-3 sm:grid-cols-2'>
            <L label='Starts'><Input type='datetime-local' value={d.starts_at} onChange={(e) => setD({ ...d, starts_at: e.target.value })} /></L>
            <L label='Ends'><Input type='datetime-local' value={d.ends_at} onChange={(e) => setD({ ...d, ends_at: e.target.value })} /></L>
          </div>
          <div className='grid gap-2'>
            <span className='text-sm font-medium'>Prizes (fixed, places 1–10)</span>
            {d.prizes.map((p, i) => (
              <div key={i} className='flex items-center gap-2'><span className='w-6 text-sm'>{i + 1}.</span>
                <MoneyInput value={p} onChange={(v) => setD({ ...d, prizes: d.prizes.map((x, j) => (j === i ? v : x)) })} />
                <Button size='icon' variant='ghost' aria-label='Remove place' disabled={d.prizes.length === 1} onClick={() => setD({ ...d, prizes: d.prizes.filter((_, j) => j !== i) })}><X /></Button></div>
            ))}
            {d.prizes.length < 10 && <Button size='sm' variant='outline' onClick={() => setD({ ...d, prizes: [...d.prizes, 50000] })}><Plus /> Add a place</Button>}
          </div>
          <L label='Smallest score to win'><Input type='number' min={1} value={d.min_score} onChange={(e) => setD({ ...d, min_score: Number(e.target.value) })} /></L>
        </>
      )}
    </FormDialog>
  )
}

function ChallengeDialog({ c }: { c?: Challenge }) {
  const [d, setD] = useState({ name: c?.name ?? '', target: c?.target ?? 3, reward: c?.reward ?? 30000, monthly_cap: c?.monthly_cap ?? null as number | null, is_active: c?.is_active ?? true })
  const save = useMutate(c ? 'put' : 'post', ['contests'], 'Challenge saved')
  return (
    <FormDialog title={c ? 'Edit challenge' : 'New challenge'} trigger={c ? <Button size='icon' variant='ghost' aria-label='Edit'><Pencil /></Button> : <Button size='sm'><Plus /> New challenge</Button>}
      onSave={() => save.mutateAsync({ path: c ? `/challenges/${c.id}` : '/challenges', body: d })}>
      <L label='Name'><Input value={d.name} placeholder='Bring 3' onChange={(e) => setD({ ...d, name: e.target.value })} /></L>
      <div className='grid grid-cols-1 gap-3 sm:grid-cols-2'>
        <L label='New paying members in one month'><Input type='number' min={1} value={d.target} onChange={(e) => setD({ ...d, target: Number(e.target.value) })} /></L>
        <L label='Reward each'><MoneyInput value={d.reward} onChange={(v) => setD({ ...d, reward: v })} /></L>
      </div>
      <L label='Most paid for it in one month (optional)'><MoneyInput value={d.monthly_cap} onChange={(v) => setD({ ...d, monthly_cap: v || null })} /></L>
      <label className='flex items-center gap-2 text-sm'><Switch checked={d.is_active} onCheckedChange={(v) => setD({ ...d, is_active: v })} /> On</label>
    </FormDialog>
  )
}

function L({ label, children }: { label: string; children: React.ReactNode }) {
  return <div className='grid gap-1.5 text-sm leading-none font-medium'><span>{label}</span>{children}</div>
}
