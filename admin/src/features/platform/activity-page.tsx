import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { formatDate } from '@/lib/money'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Page } from '@/components/km/page'
import { ResourceTable, type ResourceColumn } from '@/components/km/resource-table'

type Row = {
  id: number; action: string; reason: string | null; created_at: string; ip_address: string | null
  subject_type: string | null; subject_id: number | null; properties: Record<string, unknown> | null
  actor: { first_name: string; last_name: string; role: string } | null
}

const columns: ResourceColumn<Row>[] = [
  { header: 'When', cell: ({ row }) => formatDate(row.original.created_at, true), meta: { sort: 'when', csv: (r) => r.created_at } },
  { header: 'Who', cell: ({ row }) => (row.original.actor ? `${row.original.actor.first_name} ${row.original.actor.last_name}` : 'System'), meta: { csv: (r) => (r.actor ? `${r.actor.first_name} ${r.actor.last_name}` : 'System') } },
  { header: 'Action', accessorKey: 'action', cell: ({ row }) => <code className='text-xs'>{row.original.action}</code>, meta: { sort: 'action' } },
  { header: 'Subject', cell: ({ row }) => (row.original.subject_type ? `${row.original.subject_type.split('\\').pop()} #${row.original.subject_id}` : '—'), meta: { csv: (r) => (r.subject_type ? `${r.subject_type.split('\\').pop()} #${r.subject_id}` : '') } },
  { header: 'Details', cell: ({ row }) => <span className='block max-w-md truncate text-xs text-muted-foreground'>{row.original.properties ? JSON.stringify(row.original.properties) : ''}</span>, meta: { csv: (r) => (r.properties ? JSON.stringify(r.properties) : '') } },
  { header: 'Reason', cell: ({ row }) => row.original.reason ?? '', meta: { csv: (r) => r.reason ?? '' } },
]

export function ActivityPage() {
  return (
    <Page title='Activity log' description='Every important change. Nothing here can be changed or deleted.'>
      <StaffOverview />
      <ResourceTable<Row> queryKey='activity' path='/activity' columns={columns} search={{ placeholder: 'Action or reason' }}
        filters={[{ key: 'action', label: 'Area', options: [
          { value: 'payment.', label: 'Payments' }, { value: 'order.', label: 'Orders' }, { value: 'membership.', label: 'Memberships' },
          { value: 'payout.', label: 'Cash-outs' }, { value: 'security.', label: 'Security' }, { value: 'feature.', label: 'Feature switches' },
          { value: 'setting.', label: 'Settings' }, { value: 'badge', label: 'Badges and bonuses' }, { value: 'fraud.', label: 'Fraud' },
        ] }]} />
    </Page>
  )
}

type Staff = { id: number; name: string; role: string; actions: number; last_at: string | null; top: { action: string; n: number }[] }

function StaffOverview() {
  const [days, setDays] = useState('30')
  const q = useQuery({ queryKey: ['staff-activity', days], queryFn: async () => (await api.get<{ data: { staff: Staff[] } }>('/activity/staff', { params: { days } })).data.data })
  return (
    <Card className='mb-4'>
      <CardHeader className='flex-row items-center justify-between gap-2 pb-2'>
        <CardTitle className='text-base'>Staff at a glance</CardTitle>
        <Select value={days} onValueChange={setDays}><SelectTrigger className='h-9 w-36' aria-label='Period'><SelectValue /></SelectTrigger>
          <SelectContent>{[['7', 'Last 7 days'], ['30', 'Last 30 days'], ['90', 'Last 90 days']].map(([v, l]) => <SelectItem key={v} value={v}>{l}</SelectItem>)}</SelectContent></Select>
      </CardHeader>
      <CardContent className='grid gap-3 md:grid-cols-2 lg:grid-cols-3'>
        {(q.data?.staff ?? []).map((s) => (
          <div key={s.id} className='rounded-md border p-3 text-sm'>
            <div className='flex justify-between gap-2'><b>{s.name}</b><span className='text-muted-foreground'>{s.role}</span></div>
            <div className='text-muted-foreground'>{s.actions} actions · last {s.last_at ? formatDate(s.last_at, true) : 'never'}</div>
            {s.top.length > 0 && <div className='mt-1 flex flex-wrap gap-1'>{s.top.map((t) => <code key={t.action} className='rounded bg-muted px-1.5 text-xs'>{t.action} ×{t.n}</code>)}</div>}
          </div>
        ))}
      </CardContent>
    </Card>
  )
}
