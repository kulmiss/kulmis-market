import { formatDate } from '@/lib/money'
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
      <ResourceTable<Row> queryKey='activity' path='/activity' columns={columns} search={{ placeholder: 'Action or reason' }}
        filters={[{ key: 'action', label: 'Area', options: [
          { value: 'payment.', label: 'Payments' }, { value: 'order.', label: 'Orders' }, { value: 'membership.', label: 'Memberships' },
          { value: 'payout.', label: 'Cash-outs' }, { value: 'security.', label: 'Security' }, { value: 'feature.', label: 'Feature switches' },
          { value: 'setting.', label: 'Settings' }, { value: 'badge', label: 'Badges and bonuses' }, { value: 'fraud.', label: 'Fraud' },
        ] }]} />
    </Page>
  )
}
