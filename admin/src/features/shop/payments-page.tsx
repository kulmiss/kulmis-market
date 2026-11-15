import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { api, type Paginated } from '@/lib/api'
import { FilterBar, SearchInput, SelectFilter, opts } from '@/components/km/filter-bar'
import { Page } from '@/components/km/page'
import { PaymentRow } from './order-detail'
import { type Payment } from './types'

/** All payments; "awaiting review" first is the proof-approval queue. */
export function PaymentsPage() {
  const [status, setStatus] = useState('awaiting_review')
  const [provider, setProvider] = useState('all')
  const [q, setQ] = useState('')
  const params = Object.fromEntries(Object.entries({ status, provider, q }).filter(([, v]) => v && v !== 'all'))
  const list = useQuery({ queryKey: ['payments', params], queryFn: async () => (await api.get<Paginated<Payment>>('/payments', { params: { ...params, per_page: 50 } })).data })

  return (
    <Page title='Payments' description='Approve bank / Telebirr receipts, refund, download receipts.'>
      <FilterBar>
        <SelectFilter value={status} onChange={setStatus} placeholder='Status' options={opts('awaiting_review', 'pending', 'paid', 'partially_refunded', 'refunded', 'rejected', 'failed')} />
        <SelectFilter value={provider} onChange={setProvider} placeholder='Method' options={opts('telebirr', 'bank_transfer', 'wallet')} />
        <SearchInput value={q} onChange={setQ} placeholder='Reference' />
      </FilterBar>
      <div className='grid gap-3'>
        {list.data?.data.map((p) => (
          <div key={p.id}>
            <div className='mb-1 text-xs text-muted-foreground'>{p.payable_type.split('\\').pop()} {p.payable?.number ?? `#${p.payable_id}`}</div>
            <PaymentRow payment={p} />
          </div>
        ))}
        {list.data?.data.length === 0 && <p className='text-sm text-muted-foreground'>Nothing here.</p>}
      </div>
    </Page>
  )
}
