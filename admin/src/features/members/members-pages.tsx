import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link, useNavigate } from '@tanstack/react-router'
import { type ColumnDef } from '@tanstack/react-table'
import { ArrowLeft, Network } from 'lucide-react'
import { useMutate } from '@/hooks/use-mutate'
import { api } from '@/lib/api'
import { formatDate, formatETB } from '@/lib/money'
import { useAuthStore } from '@/stores/auth-store'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { FilterBar, SearchInput, SelectFilter, opts } from '@/components/km/filter-bar'
import { Page } from '@/components/km/page'
import { ResourceTable } from '@/components/km/resource-table'
import { StatusBadge } from '@/components/km/status-badge'

export type Member = {
  id: number; first_name: string; last_name: string; phone: string | null; email: string | null; status: string
  referral_code: string | null; referrer_id: number | null; created_at: string; telegram_user_id: number | null
  membership: { status: string; current_period_end: string | null; grace_ends_at: string | null; continuous_since: string | null; periods_paid: number } | null
}

const columns: ColumnDef<Member, unknown>[] = [
  { header: 'Name', cell: ({ row }) => `${row.original.first_name} ${row.original.last_name}` },
  { header: 'Phone', accessorKey: 'phone' },
  { header: 'Code', cell: ({ row }) => <span className='font-mono text-xs'>{row.original.referral_code}</span> },
  { header: 'Membership', cell: ({ row }) => <StatusBadge status={row.original.membership?.status ?? 'none'} /> },
  { header: 'Renews', cell: ({ row }) => formatDate(row.original.membership?.current_period_end) },
  { header: 'Joined', cell: ({ row }) => formatDate(row.original.created_at) },
  { header: 'Account', cell: ({ row }) => row.original.status === 'active' ? '' : <StatusBadge status={row.original.status} /> },
]

export function MembersPage() {
  const navigate = useNavigate()
  const [q, setQ] = useState('')
  const [membership, setMembership] = useState('all')
  return (
    <Page title='Members' description='Customers and members. Super Admin sees everything.'>
      <FilterBar>
        <SearchInput value={q} onChange={setQ} placeholder='Name, phone or email' />
        <SelectFilter value={membership} onChange={setMembership} placeholder='Membership' options={opts('active', 'grace', 'expired', 'none')} />
      </FilterBar>
      <ResourceTable<Member> queryKey='members' path='/members' params={{ q, membership }} columns={columns}
        onRowClick={(m) => navigate({ to: '/members/$id', params: { id: String(m.id) } })} />
    </Page>
  )
}

type Detail = {
  user: Member
  purchases: { id: number; amount: number; wallet_used: number; status: string; period_start: string | null; period_end: string | null; paid_at: string | null }[]
  wallet: { pending: number; available: number; payout: number }
}

export function MemberDetail({ id }: { id: string }) {
  const isSuper = useAuthStore((s) => s.user?.role === 'super_admin')
  const can = useAuthStore((s) => s.can)
  const q = useQuery({ queryKey: ['member', id], queryFn: async () => (await api.get<{ data: Detail }>(`/members/${id}`)).data.data })
  const adjust = useMutate('post', ['member', 'members'], 'Membership updated')
  const status = useMutate('post', ['member', 'members'], 'Account updated')
  const referrer = useMutate('post', ['member'], 'Referrer changed')
  const credit = useMutate('post', ['member'], 'Wallet adjusted')
  const [days, setDays] = useState(30)
  const [reason, setReason] = useState('')
  const [newReferrer, setNewReferrer] = useState('')
  const [amount, setAmount] = useState(0)

  const d = q.data
  if (!d) return <Page title='Member'>Loading…</Page>
  const u = d.user
  const m = u.membership

  return (
    <Page title={`${u.first_name} ${u.last_name}`} description={`${u.phone ?? ''} ${u.email ? `· ${u.email}` : ''} · joined ${formatDate(u.created_at)}`}
      actions={<>
        <Button variant='outline' asChild><Link to='/members'><ArrowLeft /> Members</Link></Button>
        {can('manage-referrals') && <Button variant='outline' asChild><Link to='/referrals' search={{ user: u.id }}><Network /> Referral tree</Link></Button>}
      </>}>
      <div className='grid gap-4 lg:grid-cols-3'>
        <Card>
          <CardHeader><CardTitle className='text-base'>Membership</CardTitle></CardHeader>
          <CardContent className='grid gap-1 text-sm'>
            <div><StatusBadge status={m?.status ?? 'none'} /></div>
            <span>Renews: {formatDate(m?.current_period_end)}</span>
            <span>Grace until: {formatDate(m?.grace_ends_at)}</span>
            <span>Continuous since: {formatDate(m?.continuous_since)}</span>
            <span>Months paid: {m?.periods_paid ?? 0}</span>
            <span>Telegram linked: {u.telegram_user_id ? 'yes' : 'no'}</span>
          </CardContent>
        </Card>
        <Card>
          <CardHeader><CardTitle className='text-base'>Wallet</CardTitle></CardHeader>
          <CardContent className='grid gap-1 text-sm'>
            <span>Pending: <b>{formatETB(d.wallet.pending)}</b></span>
            <span>Available: <b>{formatETB(d.wallet.available)}</b></span>
            <span>Cash-out on hold: <b>{formatETB(d.wallet.payout)}</b></span>
          </CardContent>
        </Card>
        <Card>
          <CardHeader><CardTitle className='text-base'>Referral</CardTitle></CardHeader>
          <CardContent className='grid gap-1 text-sm'>
            <span>Code: <span className='font-mono'>{u.referral_code}</span></span>
            <span>Referred by: {u.referrer_id ? <Link className='underline' to='/members/$id' params={{ id: String(u.referrer_id) }}>#{u.referrer_id}</Link> : '—'}</span>
          </CardContent>
        </Card>

        <Card className='lg:col-span-3'>
          <CardHeader><CardTitle className='text-base'>Actions (every action needs a reason and is logged)</CardTitle></CardHeader>
          <CardContent className='grid gap-3'>
            <Input placeholder='Reason' value={reason} onChange={(e) => setReason(e.target.value)} />
            <div className='flex flex-wrap items-center gap-2'>
              <Input type='number' className='w-28' value={days} onChange={(e) => setDays(Number(e.target.value))} />
              <Button variant='outline' disabled={!reason} onClick={() => adjust.mutate({ path: `/members/${id}/membership`, body: { days, reason } })}>Extend membership by {days} days</Button>
              <Button variant='outline' disabled={!reason} onClick={() => adjust.mutate({ path: `/members/${id}/membership`, body: { days: -3650, reason } })}>End membership now</Button>
              {u.status === 'active'
                ? <Button variant='destructive' disabled={!reason} onClick={() => status.mutate({ path: `/members/${id}/status`, body: { status: 'suspended', reason } })}>Suspend account</Button>
                : <Button disabled={!reason} onClick={() => status.mutate({ path: `/members/${id}/status`, body: { status: 'active', reason } })}>Re-activate account</Button>}
            </div>
            {can('manage-ledger') && (
              <div className='flex flex-wrap items-center gap-2'>
                <Input type='number' className='w-40' placeholder='ETB (+/−)' onChange={(e) => setAmount(Math.round(Number(e.target.value) * 100))} />
                <Button variant='outline' disabled={!reason || !amount}
                  onClick={() => credit.mutate({ path: '/ledger/adjustments', body: { user_id: u.id, bucket: 'available', amount, reason } })}>
                  Adjust available wallet by {formatETB(amount)}
                </Button>
              </div>
            )}
            {isSuper && (
              <div className='flex flex-wrap items-center gap-2'>
                <Input className='w-40' placeholder='New referrer ID (empty = none)' value={newReferrer} onChange={(e) => setNewReferrer(e.target.value)} />
                <Button variant='outline' disabled={!reason || reason.length < 5}
                  onClick={() => referrer.mutate({ path: `/referrals/members/${id}/referrer`, body: { referrer_id: newReferrer ? Number(newReferrer) : null, reason } })}>
                  Change referrer (Super Admin)
                </Button>
              </div>
            )}
          </CardContent>
        </Card>

        <Card className='lg:col-span-3'>
          <CardHeader><CardTitle className='text-base'>Membership payments</CardTitle></CardHeader>
          <CardContent>
            <Table>
              <TableHeader><TableRow><TableHead>Paid</TableHead><TableHead>Period</TableHead><TableHead>Amount</TableHead><TableHead>From wallet</TableHead><TableHead>Status</TableHead></TableRow></TableHeader>
              <TableBody>
                {d.purchases.map((p) => (
                  <TableRow key={p.id}>
                    <TableCell>{formatDate(p.paid_at, true)}</TableCell>
                    <TableCell>{formatDate(p.period_start)} → {formatDate(p.period_end)}</TableCell>
                    <TableCell>{formatETB(p.amount)}</TableCell><TableCell>{formatETB(p.wallet_used)}</TableCell>
                    <TableCell><StatusBadge status={p.status} /></TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </CardContent>
        </Card>
      </div>
    </Page>
  )
}
