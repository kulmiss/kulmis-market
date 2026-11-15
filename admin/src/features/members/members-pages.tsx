import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link, useNavigate } from '@tanstack/react-router'
import { ArrowLeft, Network } from 'lucide-react'
import { useMutate } from '@/hooks/use-mutate'
import { api } from '@/lib/api'
import { currencySymbol, formatDate, formatMoney } from '@/lib/money'
import { useAuthStore } from '@/stores/auth-store'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { opts } from '@/components/km/filter-bar'
import { DataTable, type DataColumn } from '@/components/km/data-table'
import { Page } from '@/components/km/page'
import { ResourceTable, type ResourceColumn } from '@/components/km/resource-table'
import { StatusBadge } from '@/components/km/status-badge'
import { PageSkeleton } from '@/components/km/page-skeleton'

export type Member = {
  id: number; first_name: string; last_name: string; phone: string | null; email: string | null; status: string
  referral_code: string | null; referrer_id: number | null; created_at: string; telegram_user_id: number | null
  membership: { status: string; current_period_end: string | null; grace_ends_at: string | null; continuous_since: string | null; periods_paid: number } | null
}

const columns: ResourceColumn<Member>[] = [
  { header: 'Name', cell: ({ row }) => `${row.original.first_name} ${row.original.last_name}`, meta: { sort: 'name', csv: (m) => `${m.first_name} ${m.last_name}` } },
  { header: 'Phone', accessorKey: 'phone', meta: { sort: 'phone' } },
  { header: 'Email', accessorKey: 'email', meta: { hidden: true } },
  { header: 'Code', cell: ({ row }) => <span className='font-mono text-xs'>{row.original.referral_code}</span>, meta: { sort: 'code', csv: (m) => m.referral_code } },
  { header: 'Membership', cell: ({ row }) => <StatusBadge status={row.original.membership?.status ?? 'none'} />, meta: { csv: (m) => m.membership?.status ?? 'none' } },
  { header: 'Renews', cell: ({ row }) => formatDate(row.original.membership?.current_period_end), meta: { csv: (m) => m.membership?.current_period_end ?? '' } },
  { header: 'Joined', cell: ({ row }) => formatDate(row.original.created_at), meta: { sort: 'joined', csv: (m) => m.created_at } },
  { header: 'Account', cell: ({ row }) => row.original.status === 'active' ? '' : <StatusBadge status={row.original.status} />, meta: { csv: (m) => m.status } },
]

type Purchase = { id: number; amount: number; wallet_used: number; status: string; period_start: string | null; period_end: string | null; paid_at: string | null }

const purchaseColumns: DataColumn<Purchase>[] = [
  { header: 'Paid', cell: ({ row }) => formatDate(row.original.paid_at, true), meta: { sort: 'paid_at', csv: (p) => p.paid_at } },
  { header: 'Period', cell: ({ row }) => `${formatDate(row.original.period_start)} → ${formatDate(row.original.period_end)}`, meta: { csv: (p) => `${p.period_start ?? ''} → ${p.period_end ?? ''}` } },
  { header: 'Amount', cell: ({ row }) => formatMoney(row.original.amount), meta: { sort: 'amount', align: 'end', csv: (p) => p.amount / 100 } },
  { header: 'From wallet', cell: ({ row }) => formatMoney(row.original.wallet_used), meta: { sort: 'wallet_used', align: 'end', csv: (p) => p.wallet_used / 100 } },
  { header: 'Status', cell: ({ row }) => <StatusBadge status={row.original.status} />, meta: { sort: 'status', csv: (p) => p.status } },
]

export function MembersPage() {
  const navigate = useNavigate()
  return (
    <Page title='Members' description='Customers and members. Super Admin sees everything.'>
      <ResourceTable<Member> queryKey='members' path='/members' columns={columns} search={{ placeholder: 'Name, phone or email' }}
        filters={[{ key: 'membership', label: 'Membership', options: opts('active', 'grace', 'expired', 'none') }]}
        onRowClick={(m) => navigate({ to: '/members/$id', params: { id: String(m.id) } })} />
    </Page>
  )
}

type Detail = {
  user: Member
  purchases: Purchase[]
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
  if (!d) return <Page title='Member'><PageSkeleton cards={3} /></Page>
  const u = d.user
  const m = u.membership

  return (
    <Page title={`${u.first_name} ${u.last_name}`} description={`${u.phone ?? ''} ${u.email ? `· ${u.email}` : ''} · joined ${formatDate(u.created_at)}`}
      actions={<>
        <Button variant='outline' asChild><Link to='/members'><ArrowLeft /> Members</Link></Button>
        {can('manage-referrals') && <Button variant='outline' asChild><Link to='/referrals' search={{ user: u.id }}><Network /> Referral tree</Link></Button>}
      </>}>
      <div className='grid grid-cols-1 gap-4 lg:grid-cols-3'>
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
            <span>Pending: <b>{formatMoney(d.wallet.pending)}</b></span>
            <span>Available: <b>{formatMoney(d.wallet.available)}</b></span>
            <span>Cash-out on hold: <b>{formatMoney(d.wallet.payout)}</b></span>
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
              <Input type='number' className='w-28' aria-label='Days to add (negative to remove)' value={days} onChange={(e) => setDays(Number(e.target.value))} />
              <Button variant='outline' disabled={!reason} onClick={() => adjust.mutate({ path: `/members/${id}/membership`, body: { days, reason } })}>Extend membership by {days} days</Button>
              <Button variant='outline' disabled={!reason} onClick={() => adjust.mutate({ path: `/members/${id}/membership`, body: { days: -3650, reason } })}>End membership now</Button>
              {u.status === 'active'
                ? <Button variant='destructive' disabled={!reason} onClick={() => status.mutate({ path: `/members/${id}/status`, body: { status: 'suspended', reason } })}>Suspend account</Button>
                : <Button disabled={!reason} onClick={() => status.mutate({ path: `/members/${id}/status`, body: { status: 'active', reason } })}>Re-activate account</Button>}
            </div>
            {can('manage-ledger') && (
              <div className='flex flex-wrap items-center gap-2'>
                <Input type='number' className='w-full sm:w-40' placeholder={`${currencySymbol()} (+/−)`} onChange={(e) => setAmount(Math.round(Number(e.target.value) * 100))} />
                <Button variant='outline' disabled={!reason || !amount}
                  onClick={() => credit.mutate({ path: '/ledger/adjustments', body: { user_id: u.id, bucket: 'available', amount, reason } })}>
                  Adjust available wallet by {formatMoney(amount)}
                </Button>
              </div>
            )}
            {isSuper && (
              <div className='flex flex-wrap items-center gap-2'>
                <Input className='w-full sm:w-56' placeholder='New referrer ID (empty = none)' value={newReferrer} onChange={(e) => setNewReferrer(e.target.value)} />
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
            <DataTable<Purchase> id='member-purchases' rows={d.purchases} columns={purchaseColumns} search={false} defaultPageSize={10}
              filters={[{ key: 'status', label: 'Status', options: opts('paid', 'pending', 'refunded', 'cancelled') }]} empty='No membership payments yet.' />
          </CardContent>
        </Card>
      </div>
    </Page>
  )
}
