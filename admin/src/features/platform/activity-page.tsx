import { useState } from 'react'
import { type ColumnDef } from '@tanstack/react-table'
import { formatDate } from '@/lib/money'
import { FilterBar, SearchInput } from '@/components/km/filter-bar'
import { Page } from '@/components/km/page'
import { ResourceTable } from '@/components/km/resource-table'

type Row = {
  id: number; action: string; reason: string | null; created_at: string; ip_address: string | null
  subject_type: string | null; subject_id: number | null; properties: Record<string, unknown> | null
  actor: { first_name: string; last_name: string; role: string } | null
}

const columns: ColumnDef<Row, unknown>[] = [
  { header: 'When', cell: ({ row }) => formatDate(row.original.created_at, true) },
  { header: 'Who', cell: ({ row }) => (row.original.actor ? `${row.original.actor.first_name} ${row.original.actor.last_name}` : 'System') },
  { header: 'Action', accessorKey: 'action', cell: ({ row }) => <code className='text-xs'>{row.original.action}</code> },
  { header: 'Subject', cell: ({ row }) => (row.original.subject_type ? `${row.original.subject_type.split('\\').pop()} #${row.original.subject_id}` : '—') },
  { header: 'Details', cell: ({ row }) => <span className='block max-w-md truncate text-xs text-muted-foreground'>{row.original.properties ? JSON.stringify(row.original.properties) : ''}</span> },
  { header: 'Reason', cell: ({ row }) => row.original.reason ?? '' },
]

export function ActivityPage() {
  const [action, setAction] = useState('')
  return (
    <Page title='Activity log' description='Every important change, append-only.'>
      <FilterBar><SearchInput value={action} onChange={setAction} placeholder='Action starts with… (e.g. payment.)' /></FilterBar>
      <ResourceTable<Row> queryKey='activity' path='/activity' params={{ action }} columns={columns} />
    </Page>
  )
}
