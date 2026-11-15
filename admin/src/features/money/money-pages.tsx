import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Check, Copy, Eye, X } from 'lucide-react'
import { toast } from 'sonner'
import { useMutate } from '@/hooks/use-mutate'
import { api } from '@/lib/api'
import { formatDate, formatMoney } from '@/lib/money'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Switch } from '@/components/ui/switch'
import { FilterBar, opts } from '@/components/km/filter-bar'
import { FormDialog } from '@/components/km/form-dialog'
import { Page } from '@/components/km/page'
import { ResourceTable, type ResourceColumn } from '@/components/km/resource-table'
import { StatusBadge } from '@/components/km/status-badge'

/* ---------- Cash-outs ---------- */

type Payout = {
  id: number; amount: number; method: string; account: string; account_name: string; bank_name: string | null; status: string
  due_at: string; created_at: string; payout_reference: string | null; reject_reason: string | null; hold_reason: string | null
  user: { id: number; first_name: string; last_name: string; phone: string }
}

export function CashOutsPage() {
  const [overdue, setOverdue] = useState(false)
  const columns: ResourceColumn<Payout>[] = [
    { header: 'Requested', cell: ({ row }) => formatDate(row.original.created_at, true), meta: { sort: 'requested', csv: (p) => p.created_at } },
    { header: 'Member', cell: ({ row }) => `${row.original.user.first_name} ${row.original.user.last_name}`, meta: { csv: (p) => `${p.user.first_name} ${p.user.last_name}` } },
    { header: 'Amount', cell: ({ row }) => <b>{formatMoney(row.original.amount)}</b>, meta: { sort: 'amount', align: 'end', csv: (p) => p.amount / 100 } },
    { header: 'To', cell: ({ row }) => `${row.original.method}${row.original.bank_name ? ` · ${row.original.bank_name}` : ''} · ${row.original.account}`, meta: { csv: (p) => `${p.method} ${p.bank_name ?? ''} ${p.account}` } },
    { header: 'Due', cell: ({ row }) => {
      const late = new Date(row.original.due_at) < new Date() && ['requested', 'on_hold'].includes(row.original.status)
      return <span className={late ? 'font-semibold text-red-600' : ''}>{formatDate(row.original.due_at, true)}{late ? ' · overdue' : ''}</span>
    }, meta: { sort: 'due', csv: (p) => p.due_at } },
    { header: 'Status', cell: ({ row }) => <span><StatusBadge status={row.original.status} />{row.original.hold_reason && <span className='ms-1 text-xs'>{row.original.hold_reason}</span>}</span>, meta: { sort: 'status', csv: (p) => p.status } },
    { header: '', id: 'act', meta: { label: 'Actions' }, cell: ({ row }) => ['requested', 'on_hold'].includes(row.original.status) && <PayoutActions payout={row.original} /> },
  ]
  return (
    <Page title='Cash-outs' description='Pay within 3 days. Open a request to see the full account number, send the money, then record the reference.'>
      <ResourceTable<Payout> queryKey='cash-outs' path='/cash-outs' params={{ overdue: overdue ? 1 : undefined }} columns={columns}
        search={{ placeholder: 'Member, phone or reference' }}
        filters={[{ key: 'status', label: 'Status', default: 'requested', options: opts('requested', 'on_hold', 'paid', 'rejected') }]}
        actions={<label className='flex min-h-12 items-center gap-2 text-sm sm:min-h-9'><Switch checked={overdue} onCheckedChange={setOverdue} /> Late only</label>} />
    </Page>
  )
}

function PayoutActions({ payout }: { payout: Payout }) {
  const [reference, setReference] = useState('')
  const [reason, setReason] = useState('')
  const [full, setFull] = useState<{ account_number: string; account_verified: boolean; account_added_at: string | null; wallet: { available: number } } | null>(null)
  const [checked, setChecked] = useState(false)
  const pay = useMutate('post', ['cash-outs'], 'Marked as paid — member notified')
  const reject = useMutate('post', ['cash-outs'], 'Rejected — money returned to wallet')
  return (
    <div className='flex gap-1'>
      <FormDialog title={`Pay ${formatMoney(payout.amount)} to ${payout.account_name}`} saveText='I sent it — mark paid'
        trigger={<Button size='sm' onClick={async () => setFull((await api.get(`/cash-outs/${payout.id}`)).data.data)}><Eye /> Pay</Button>}
        onSave={() => pay.mutateAsync({ path: `/cash-outs/${payout.id}/pay`, body: { reference, account_checked: checked } })}>
        <div className='rounded-md bg-muted p-3 text-sm'>
          <div>{payout.method === 'bank' ? `Bank: ${payout.bank_name}` : 'Telebirr'}</div>
          <div>Name: <b>{payout.account_name}</b></div>
          <div className='flex items-center gap-2'>Account: <b className='font-mono'>{full?.account_number ?? '…'}</b>
            {full && <Button size='icon' variant='ghost' className='size-6' onClick={() => navigator.clipboard.writeText(full.account_number).then(() => toast.success('Copied'))}><Copy /></Button>}</div>
          {payout.status === 'on_hold' && <div className='mt-2 text-amber-700'>On hold: {payout.hold_reason ?? 'review the fraud queue first'}.</div>}
          {full && (full.account_verified
            ? <div className='mt-2 text-emerald-700'>Account verified on an earlier payout.</div>
            : <div className='mt-2 text-amber-700'>First payout to this account{full.account_added_at ? ` (added ${formatDate(full.account_added_at, true)})` : ''}: check the name shown by Telebirr/the bank before sending.</div>)}
        </div>
        {full && !full.account_verified && (
          <label className='flex items-start gap-2 text-sm'>
            <input type='checkbox' className='mt-1 size-4' checked={checked} onChange={(e) => setChecked(e.target.checked)} />
            I checked the account: the name on it is {payout.account_name}.
          </label>
        )}
        <Input placeholder='Transfer reference (required)' value={reference} onChange={(e) => setReference(e.target.value)} />
      </FormDialog>
      <FormDialog title='Reject cash-out' saveText='Reject' trigger={<Button size='sm' variant='ghost'><X /></Button>}
        onSave={() => reject.mutateAsync({ path: `/cash-outs/${payout.id}/reject`, body: { reason } })}>
        <Input placeholder='Reason (sent to the member)' value={reason} onChange={(e) => setReason(e.target.value)} />
      </FormDialog>
    </div>
  )
}

/* ---------- Ledger ---------- */

type Entry = { id: number; uuid: string; type: string; description: string | null; reason: string | null; created_at: string
  postings: { id: number; amount: number; account: { code: string; name: string } }[] }

export function LedgerPage() {
  const [user, setUser] = useState('')
  const accounts = useQuery({ queryKey: ['ledger-accounts'], queryFn: async () => (await api.get<{ data: { code: string; name: string; type: string; balance: number }[] }>('/ledger/accounts')).data.data })
  const columns: ResourceColumn<Entry>[] = [
    { header: 'When', cell: ({ row }) => formatDate(row.original.created_at, true), meta: { sort: 'when', csv: (e) => e.created_at } },
    { header: 'Type', cell: ({ row }) => <code className='text-xs'>{row.original.type}</code>, meta: { sort: 'type', csv: (e) => e.type } },
    { header: 'Description', cell: ({ row }) => <span>{row.original.description}{row.original.reason && <span className='block text-xs text-muted-foreground'>Reason: {row.original.reason}</span>}</span>, meta: { csv: (e) => `${e.description ?? ''}${e.reason ? ` (${e.reason})` : ''}` } },
    { header: 'Postings (+ debit / − credit)', cell: ({ row }) => (
      <div className='grid text-xs'>{row.original.postings.map((p) => <span key={p.id} className='font-mono'>{p.account.code}: {p.amount > 0 ? '+' : ''}{formatMoney(p.amount, false)}</span>)}</div>
    ) },
  ]
  return (
    <Page title='Ledger' description='Double-entry, integer santim. Balances are calculated from postings and never edited.'>
      <div className='mb-4 grid gap-3 sm:grid-cols-3 lg:grid-cols-6'>
        {accounts.data?.map((a) => (
          <Card key={a.code} className='gap-1 py-3'>
            <CardHeader className='px-3'><CardTitle className='text-xs font-normal text-muted-foreground'>{a.code}</CardTitle></CardHeader>
            <CardContent className='px-3 font-semibold'>{formatMoney(a.balance)}</CardContent>
          </Card>
        ))}
      </div>
      <ResourceTable<Entry> queryKey='ledger' path='/ledger/entries' params={{ user_id: user }} columns={columns}
        search={{ placeholder: 'Description or entry ID' }}
        filters={[{ key: 'type', label: 'Type', options: [{ value: 'payment.', label: 'Payments' }, { value: 'commission.', label: 'Team earnings' }, { value: 'badge.bonus', label: 'Badge bonus' }, { value: 'loyalty.', label: 'Loyalty reward' }, { value: 'payout.', label: 'Cash-outs' }, { value: 'wallet.', label: 'Wallet' }, { value: 'manual.', label: 'Fixes by hand' }] }]}
        actions={<Input className='h-12 w-36 sm:h-9' inputMode='numeric' placeholder='Member ID' aria-label='Member ID' value={user} onChange={(e) => setUser(e.target.value.replace(/\D/g, ''))} />} />
    </Page>
  )
}

/* ---------- Fraud ---------- */

type Flag = { id: number; type: string; status: string; details: Record<string, unknown> | null; note: string | null; created_at: string
  user: { id: number; first_name: string; last_name: string; phone: string; referral_code: string; status: string } }

export function FraudPage() {
  const columns: ResourceColumn<Flag>[] = [
    { header: 'When', cell: ({ row }) => formatDate(row.original.created_at, true), meta: { sort: 'created', csv: (f) => f.created_at } },
    { header: 'Member', cell: ({ row }) => <a className='underline' href={`/admin/members/${row.original.user.id}`}>{row.original.user.first_name} {row.original.user.last_name}</a>, meta: { csv: (f) => `${f.user.first_name} ${f.user.last_name}` } },
    { header: 'Signal', cell: ({ row }) => row.original.type.replaceAll('_', ' '), meta: { sort: 'type', csv: (f) => f.type } },
    { header: 'Details', cell: ({ row }) => (
      <span className='text-xs text-muted-foreground'>
        {Object.entries(row.original.details ?? {}).map(([k, v]) => `${k.replaceAll('_', ' ')}: ${Array.isArray(v) ? v.join(', ') : String(v)}`).join(' · ') || '—'}
      </span>
    ) },
    { header: 'Status', cell: ({ row }) => <StatusBadge status={row.original.status} />, meta: { sort: 'status', csv: (f) => f.status } },
    { header: '', id: 'act', meta: { label: 'Actions' }, cell: ({ row }) => row.original.status === 'open' && <Review flag={row.original} /> },
  ]
  return (
    <Page title='Fraud queue' description="An open flag holds the member's earnings and cash-outs until you clear it.">
      <ResourceTable<Flag> queryKey='fraud' path='/fraud' columns={columns} search={{ placeholder: 'Member name or phone' }}
        filters={[{ key: 'status', label: 'Status', default: 'open', sendAll: true, options: opts('open', 'cleared', 'confirmed') }]} />
    </Page>
  )
}

function Review({ flag }: { flag: Flag }) {
  const review = useMutate('post', ['fraud'], 'Reviewed')
  const [note, setNote] = useState('')
  const [suspend, setSuspend] = useState(false)
  return (
    <FormDialog title='Review flag' saveText='Confirm fraud' trigger={<Button size='sm' variant='outline'>Review</Button>}
      onSave={() => review.mutateAsync({ path: `/fraud/${flag.id}/review`, body: { decision: 'confirmed', note, suspend } })}>
      <Input placeholder='Note' value={note} onChange={(e) => setNote(e.target.value)} />
      <label className='flex items-center gap-2 text-sm'><Switch checked={suspend} onCheckedChange={setSuspend} /> Also suspend the account</label>
      <Button variant='outline' onClick={() => review.mutate({ path: `/fraud/${flag.id}/review`, body: { decision: 'cleared', note } })}>
        <Check /> It's fine — clear and release holds
      </Button>
    </FormDialog>
  )
}

/* ---------- Commissions ---------- */

type CommissionRow = { id: number; type: string; level: number; base_amount: number; rate_bps: number; amount: number; reversed_amount: number
  status: string; skip_reason: string | null; available_at: string | null; on_hold: boolean; created_at: string
  recipient: { id: number; first_name: string; last_name: string } }

export function CommissionsPage() {
  const columns: ResourceColumn<CommissionRow>[] = [
    { header: 'When', cell: ({ row }) => formatDate(row.original.created_at, true), meta: { sort: 'created', csv: (c) => c.created_at } },
    { header: 'Recipient', cell: ({ row }) => `${row.original.recipient.first_name} ${row.original.recipient.last_name}`, meta: { csv: (c) => `${c.recipient.first_name} ${c.recipient.last_name}` } },
    { header: 'Sale', cell: ({ row }) => `${row.original.type} · L${row.original.level}`, meta: { sort: 'level', csv: (c) => `${c.type} L${c.level}` } },
    { header: 'Base', cell: ({ row }) => formatMoney(row.original.base_amount), meta: { align: 'end', csv: (c) => c.base_amount / 100 } },
    { header: 'Rate', cell: ({ row }) => `${row.original.rate_bps / 100}%`, meta: { align: 'end', csv: (c) => c.rate_bps / 100 } },
    { header: 'Amount', cell: ({ row }) => <span>{formatMoney(row.original.amount)}{row.original.reversed_amount > 0 && <span className='ms-1 text-xs text-red-600'>−{formatMoney(row.original.reversed_amount)}</span>}</span>, meta: { sort: 'amount', align: 'end', csv: (c) => (c.amount - c.reversed_amount) / 100 } },
    { header: 'Status', cell: ({ row }) => <span><StatusBadge status={row.original.on_hold ? 'on_hold' : row.original.status} />{row.original.skip_reason && <span className='ms-1 text-xs text-muted-foreground'>{row.original.skip_reason.replaceAll('_', ' ')}</span>}</span>, meta: { sort: 'status', csv: (c) => (c.on_hold ? 'on_hold' : c.status) } },
    { header: 'Available', cell: ({ row }) => formatDate(row.original.available_at), meta: { sort: 'available', csv: (c) => c.available_at } },
  ]
  return (
    <Page title='Commissions' description='Pending for 14 days, then available. Skipped = recipient was not an active member (the shop keeps it).'>
      <ResourceTable<CommissionRow> queryKey='commissions' path='/referrals/commissions' columns={columns} search={{ placeholder: 'Recipient name or phone' }}
        filters={[
          { key: 'status', label: 'Status', options: opts('pending', 'available', 'reversed', 'skipped') },
          { key: 'type', label: 'Type', options: opts('membership', 'signals', 'products') },
          { key: 'level', label: 'Level', options: [1, 2, 3].map((l) => ({ value: String(l), label: `Level ${l}` })) },
        ]} />
    </Page>
  )
}

/* ---------- Referral tree ---------- */

type Node = { id: number; name: string; phone: string | null; code: string | null; active: boolean; joined: string | null
  wallet: { pending: number; available: number; payout: number }; children_count: number; children: Node[] }

export function ReferralTreePage({ initialUser }: { initialUser?: number }) {
  const [userId, setUserId] = useState(initialUser ? String(initialUser) : '')
  const [show, setShow] = useState(initialUser ? String(initialUser) : '')
  const tree = useQuery({
    queryKey: ['tree', show], enabled: !!show,
    queryFn: async () => (await api.get<{ data: { upline: { id: number; name: string; code: string }[]; node: Node } }>(`/referrals/tree/${show}`)).data.data,
  })
  return (
    <Page title='Referral tree' description='Full view for Super Admin: 3 levels down, upline, wallets.'>
      <FilterBar>
        <Input className='h-9 w-full sm:w-48' placeholder='Member ID' value={userId} onChange={(e) => setUserId(e.target.value)} />
        <Button size='sm' onClick={() => setShow(userId)}>Show</Button>
      </FilterBar>
      {tree.data && (
        <div className='grid gap-3'>
          {tree.data.upline.length > 0 && (
            <div className='text-sm text-muted-foreground'>Upline: {tree.data.upline.map((u) => (
              <button key={u.id} className='me-2 underline' onClick={() => { setUserId(String(u.id)); setShow(String(u.id)) }}>{u.name} ({u.code})</button>
            ))}</div>
          )}
          <TreeNode node={tree.data.node} level={0} onOpen={(id) => { setUserId(String(id)); setShow(String(id)) }} />
        </div>
      )}
    </Page>
  )
}

function TreeNode({ node, level, onOpen }: { node: Node; level: number; onOpen: (id: number) => void }) {
  return (
    <div className={level > 0 ? 'ms-6 border-s ps-3' : ''}>
      <div className='flex flex-wrap items-center gap-2 py-1 text-sm'>
        <button className='font-medium underline-offset-4 hover:underline' onClick={() => onOpen(node.id)}>{node.name}</button>
        <span className='font-mono text-xs text-muted-foreground'>{node.code}</span>
        <StatusBadge status={node.active ? 'active' : 'expired'} />
        <span className='text-xs text-muted-foreground'>{level === 0 ? 'you are viewing' : `L${level}`} · pending {formatMoney(node.wallet.pending)} · available {formatMoney(node.wallet.available)} · {node.children_count} direct</span>
      </div>
      {node.children.map((c) => <TreeNode key={c.id} node={c} level={level + 1} onOpen={onOpen} />)}
    </div>
  )
}
