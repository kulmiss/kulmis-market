import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { CheckCircle2, Lock, Plus, Send, Trash2, UserMinus } from 'lucide-react'
import { useMutate } from '@/hooks/use-mutate'
import { api } from '@/lib/api'
import { formatDate } from '@/lib/money'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Textarea } from '@/components/ui/textarea'
import { opts } from '@/components/km/filter-bar'
import { FormDialog } from '@/components/km/form-dialog'
import { Page } from '@/components/km/page'
import { ResourceTable, type ResourceColumn } from '@/components/km/resource-table'
import { Flag, StatusBadge } from '@/components/km/status-badge'

type Signal = { id: number; pair: string; direction: 'long' | 'short'; entry: string; targets: string[]; stop_loss: string; notes: string | null
  status: string; result: string | null; result_value: string | null; result_note: string | null; posted_at: string | null; closed_at: string | null
  locked_at: string | null; telegram_message_id: number | null }

export function SignalsPage() {
  const columns: ResourceColumn<Signal>[] = [
    { header: 'Posted', cell: ({ row }) => formatDate(row.original.posted_at, true), meta: { sort: 'posted', csv: (s) => s.posted_at } },
    { header: 'Pair', cell: ({ row }) => <b>{row.original.pair}</b>, meta: { sort: 'pair', csv: (s) => s.pair } },
    { header: 'Side', cell: ({ row }) => <span className={row.original.direction === 'long' ? 'text-emerald-600' : 'text-red-600'}>{row.original.direction.toUpperCase()}</span>, meta: { csv: (s) => s.direction } },
    { header: 'Entry', accessorKey: 'entry' },
    { header: 'Targets', cell: ({ row }) => row.original.targets.join(' / '), meta: { csv: (s) => s.targets.join(' / ') } },
    { header: 'SL', accessorKey: 'stop_loss' },
    { header: 'Telegram', cell: ({ row }) => (row.original.telegram_message_id ? <Send className='size-4 text-sky-500' /> : <span className='text-xs text-muted-foreground'>not sent</span>) },
    { header: 'Result', cell: ({ row }) => row.original.result
      ? <span className='flex items-center gap-1'><StatusBadge status={row.original.result} />{row.original.result_value}<Lock className='size-3 text-muted-foreground' /></span>
      : <StatusBadge status={row.original.status} /> },
    { header: '', id: 'act', meta: { label: 'Actions' }, cell: ({ row }) => !row.original.locked_at && <CloseSignal signal={row.original} /> },
  ]
  return (
    <Page title='Signals' description='Posted straight to your private Telegram channel. Closing records the result and locks it forever.' actions={<NewSignal />}>
      <ResourceTable<Signal> queryKey='signals' path='/signals' columns={columns} search={{ placeholder: 'Pair, e.g. BTC' }}
        filters={[{ key: 'status', label: 'Status', options: opts('open', 'closed') }, { key: 'result', label: 'Result', options: opts('win', 'loss', 'breakeven', 'cancelled') }]} />
    </Page>
  )
}

function NewSignal() {
  const [d, setD] = useState({ pair: '', direction: 'long', entry: '', targets: [''], stop_loss: '', notes: '' })
  const post = useMutate('post', ['signals'], 'Signal posted to Telegram')
  return (
    <FormDialog title='New signal' saveText='Post to channel' trigger={<Button><Plus /> New signal</Button>}
      onSave={() => post.mutateAsync({ path: '/signals', body: { ...d, targets: d.targets.filter(Boolean), notes: d.notes || null } })}>
      <div className='grid grid-cols-1 gap-3 sm:grid-cols-2'>
        <F label='Pair'><Input placeholder='BTC/USDT' value={d.pair} onChange={(e) => setD({ ...d, pair: e.target.value.toUpperCase() })} /></F>
        <F label='Direction'>
          <Select value={d.direction} onValueChange={(v) => setD({ ...d, direction: v })}>
            <SelectTrigger><SelectValue /></SelectTrigger>
            <SelectContent><SelectItem value='long'>Long / Buy</SelectItem><SelectItem value='short'>Short / Sell</SelectItem></SelectContent>
          </Select>
        </F>
        <F label='Entry'><Input placeholder='64,200 – 64,500' value={d.entry} onChange={(e) => setD({ ...d, entry: e.target.value })} /></F>
        <F label='Stop loss'><Input value={d.stop_loss} onChange={(e) => setD({ ...d, stop_loss: e.target.value })} /></F>
      </div>
      {d.targets.map((t, i) => (
        <F key={i} label={`Target ${i + 1}`}>
          <div className='flex gap-2'>
            <Input value={t} onChange={(e) => setD({ ...d, targets: d.targets.map((x, j) => (j === i ? e.target.value : x)) })} />
            {d.targets.length > 1 && <Button size='icon' variant='ghost' onClick={() => setD({ ...d, targets: d.targets.filter((_, j) => j !== i) })}><Trash2 /></Button>}
          </div>
        </F>
      ))}
      <Button size='sm' variant='outline' onClick={() => setD({ ...d, targets: [...d.targets, ''] })}><Plus /> Add target</Button>
      <F label='Notes'><Textarea rows={3} value={d.notes} onChange={(e) => setD({ ...d, notes: e.target.value })} /></F>
    </FormDialog>
  )
}

function CloseSignal({ signal }: { signal: Signal }) {
  const [d, setD] = useState({ result: 'win', result_value: '', result_note: '' })
  const close = useMutate('post', ['signals'], 'Result posted and locked')
  return (
    <FormDialog title={`Close ${signal.pair}`} saveText='Close & lock' trigger={<Button size='sm' variant='outline'><CheckCircle2 /> Close</Button>}
      onSave={() => close.mutateAsync({ path: `/signals/${signal.id}/close`, body: { ...d, result_value: d.result_value || null, result_note: d.result_note || null } })}>
      <F label='Result'>
        <Select value={d.result} onValueChange={(v) => setD({ ...d, result: v })}>
          <SelectTrigger><SelectValue /></SelectTrigger>
          <SelectContent>{['win', 'loss', 'breakeven', 'cancelled'].map((r) => <SelectItem key={r} value={r}>{r}</SelectItem>)}</SelectContent>
        </Select>
      </F>
      <F label='Result value (optional)'><Input placeholder='+3.2% or -1R' value={d.result_value} onChange={(e) => setD({ ...d, result_value: e.target.value })} /></F>
      <F label='Note (optional)'><Input value={d.result_note} onChange={(e) => setD({ ...d, result_note: e.target.value })} /></F>
      <p className='text-xs text-muted-foreground'>Once closed, this signal can never be edited.</p>
    </FormDialog>
  )
}

type Sub = { id: number; source: string; first_name: string; last_name: string; phone: string | null; status: string; entitled: boolean
  current_period_end: string | null; grace_ends_at: string | null; telegram_user_id: number | null; joined_at: string | null; removed_at: string | null }

export function SubscribersPage() {
  const columns: ResourceColumn<Sub>[] = [
    { header: 'Name', cell: ({ row }) => `${row.original.first_name} ${row.original.last_name}`, meta: { sort: 'name', csv: (s) => `${s.first_name} ${s.last_name}` } },
    { header: 'Phone', accessorKey: 'phone' },
    { header: 'Source', accessorKey: 'source', meta: { sort: 'source' } },
    { header: 'Access until', cell: ({ row }) => (row.original.source === 'membership' ? 'with membership' : formatDate(row.original.grace_ends_at, true)), meta: { sort: 'ends', csv: (s) => (s.source === 'membership' ? 'with membership' : s.grace_ends_at) } },
    { header: 'In channel', cell: ({ row }) => (row.original.joined_at && !row.original.removed_at ? `since ${formatDate(row.original.joined_at)}` : row.original.telegram_user_id ? 'linked, not joined' : 'not linked'), meta: { csv: (s) => (s.joined_at && !s.removed_at ? `since ${s.joined_at}` : s.telegram_user_id ? 'linked, not joined' : 'not linked') } },
    { header: 'Status', cell: ({ row }) => <StatusBadge status={row.original.entitled ? 'active' : row.original.status} />, meta: { sort: 'status', csv: (s) => (s.entitled ? 'active' : s.status) } },
    { header: '', id: 'act', meta: { label: 'Actions' }, cell: ({ row }) => row.original.status !== 'ended' && <RemoveSub sub={row.original} /> },
  ]
  return (
    <Page title='Signal subscribers' description='Members get access free; guests buy it monthly. People are removed automatically when access ends.' actions={<AddSub />}>
      <ResourceTable<Sub> queryKey='subscribers' path='/signals/subscribers' columns={columns} search={{ placeholder: 'Name or phone' }}
        filters={[{ key: 'source', label: 'Source', options: opts('purchase', 'membership', 'manual') }, { key: 'status', label: 'Status', options: opts('active', 'pending', 'ended') }]} />
    </Page>
  )
}

function AddSub() {
  const [d, setD] = useState({ first_name: '', last_name: '', phone: '', days: 30, reason: '' })
  const add = useMutate('post', ['subscribers'], 'Access granted — they get the personal link by SMS')
  return (
    <FormDialog title='Give access by hand' saveText='Give access' trigger={<Button><Plus /> Add subscriber</Button>}
      onSave={() => add.mutateAsync({ path: '/signals/subscribers', body: d })}>
      <div className='grid grid-cols-1 gap-3 sm:grid-cols-2'>
        <F label='First name'><Input value={d.first_name} onChange={(e) => setD({ ...d, first_name: e.target.value })} /></F>
        <F label='Last name'><Input value={d.last_name} onChange={(e) => setD({ ...d, last_name: e.target.value })} /></F>
        <F label='Phone'><Input value={d.phone} onChange={(e) => setD({ ...d, phone: e.target.value })} /></F>
        <F label='Days'><Input type='number' value={d.days} onChange={(e) => setD({ ...d, days: Number(e.target.value) })} /></F>
      </div>
      <F label='Reason (logged)'><Input value={d.reason} onChange={(e) => setD({ ...d, reason: e.target.value })} /></F>
    </FormDialog>
  )
}

function RemoveSub({ sub }: { sub: Sub }) {
  const [reason, setReason] = useState('')
  const remove = useMutate('post', ['subscribers'], 'Removed from the channel')
  return (
    <FormDialog title={`Remove ${sub.first_name}`} saveText='Remove now' trigger={<Button size='sm' variant='ghost'><UserMinus /></Button>}
      onSave={() => remove.mutateAsync({ path: `/signals/subscribers/${sub.id}/remove`, body: { reason } })}>
      <Input placeholder='Reason (logged)' value={reason} onChange={(e) => setReason(e.target.value)} />
    </FormDialog>
  )
}

type Tg = { configured: boolean; bot_username: string | null; channel_id_set: boolean; webhook_secret_set: boolean
  webhook: { url: string | null; pending: number | null; last_error: string | null } | null; error: string | null }

export function TelegramPage() {
  const q = useQuery({ queryKey: ['telegram'], queryFn: async () => (await api.get<{ data: Tg }>('/signals/telegram')).data.data })
  const t = q.data
  const ok = (v: boolean) => <Flag ok={v} />
  return (
    <Page title='Telegram bot' description='Bot settings live in the server .env (secrets never go in the database).'>
      {t && (
        <div className='grid grid-cols-1 gap-4 md:grid-cols-2'>
          <Card>
            <CardHeader><CardTitle className='text-base'>Status</CardTitle></CardHeader>
            <CardContent className='grid gap-2 text-sm'>
              <div className='flex justify-between'>Bot token {ok(t.configured)}</div>
              <div className='flex justify-between'>Bot username <span>{t.bot_username ? `@${t.bot_username}` : '—'}</span></div>
              <div className='flex justify-between'>Private channel {ok(t.channel_id_set)}</div>
              <div className='flex justify-between'>Secret key for Telegram messages {ok(t.webhook_secret_set)}</div>
              <div className='flex justify-between'>Address Telegram sends to <span className='max-w-[60%] truncate'>{t.webhook?.url || '—'}</span></div>
              {t.webhook?.last_error && <div className='text-red-600'>Last error: {t.webhook.last_error}</div>}
              {t.error && <div className='text-red-600'>{t.error}</div>}
            </CardContent>
          </Card>
          <Card>
            <CardHeader><CardTitle className='text-base'>Set up (once)</CardTitle></CardHeader>
            <CardContent className='grid gap-2 text-sm'>
              <ol className='list-decimal space-y-1 ps-5'>
                <li>Create a bot with @BotFather → put the token and username in <code>.env</code>.</li>
                <li>Create a private channel, add the bot as admin with "Invite users" + "Ban users".</li>
                <li>Put the channel id (starts with -100) and a long random webhook secret in <code>.env</code>.</li>
                <li>Run <code>php artisan telegram:set-webhook</code> on the server.</li>
              </ol>
            </CardContent>
          </Card>
        </div>
      )}
    </Page>
  )
}

function F({ label, children }: { label: string; children: React.ReactNode }) {
  return <label className='grid gap-1.5 text-sm leading-none font-medium'>{label}{children}</label>
}
