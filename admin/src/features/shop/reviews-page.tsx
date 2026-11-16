import { useState } from 'react'
import { Check, Star, X } from 'lucide-react'
import { useMutate } from '@/hooks/use-mutate'
import { formatDate } from '@/lib/money'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { FormDialog } from '@/components/km/form-dialog'
import { Page } from '@/components/km/page'
import { ResourceTable, type ResourceColumn } from '@/components/km/resource-table'
import { StatusBadge } from '@/components/km/status-badge'

type Review = {
  id: number; rating: number; title: string | null; body: string; status: string; reject_reason: string | null; is_demo: boolean
  created_at: string; product: { id: number; name: string; slug: string } | null; author: string; phone: string | null; photos: string[]
}

function Stars({ n }: { n: number }) {
  return <span className='inline-flex' role='img' aria-label={`${n} out of 5 stars`}>{[1, 2, 3, 4, 5].map((i) => <Star key={i} className={`size-4 ${i <= n ? 'fill-amber-500 text-amber-500' : 'text-muted-foreground/40'}`} />)}</span>
}

function Decide({ review }: { review: Review }) {
  const act = useMutate('post', ['reviews'], 'Done')
  const [reason, setReason] = useState('')
  if (review.status !== 'pending') return null
  return (
    <div className='flex gap-1' onClick={(e) => e.stopPropagation()}>
      <Button size='sm' onClick={() => act.mutate({ path: `/reviews/${review.id}/approve` })}><Check /> Show it</Button>
      <FormDialog title='Do not show this review' saveText='Do not show it' trigger={<Button size='sm' variant='outline'><X /> Hide</Button>}
        onSave={() => act.mutateAsync({ path: `/reviews/${review.id}/reject`, body: { reason } })}>
        <label className='grid gap-1 text-sm'>Reason (for your records)<Input value={reason} onChange={(e) => setReason(e.target.value)} placeholder='e.g. not about the product' /></label>
      </FormDialog>
    </div>
  )
}

const columns: ResourceColumn<Review>[] = [
  { header: 'Product', cell: ({ row }) => <a className='hover:underline' href={`/shop/${row.original.product?.slug}`} target='_blank' rel='noreferrer'>{row.original.product?.name}</a>, meta: { csv: (r) => r.product?.name ?? '' } },
  { header: 'Stars', cell: ({ row }) => <Stars n={row.original.rating} />, meta: { sort: 'rating', csv: (r) => r.rating } },
  { header: 'Review', meta: { csv: (r) => `${r.title ?? ''} ${r.body}` }, cell: ({ row }) => (
    <div className='max-w-md whitespace-normal'>
      {row.original.title && <b className='block'>{row.original.title}</b>}
      <span className='line-clamp-3 text-sm'>{row.original.body}</span>
      {row.original.photos.length > 0 && <div className='mt-1 flex gap-1'>{row.original.photos.map((p) => <a key={p} href={p} target='_blank' rel='noreferrer'><img src={p} alt='Customer photo' className='size-12 rounded object-cover' loading='lazy' /></a>)}</div>}
    </div>
  ) },
  { header: 'From', cell: ({ row }) => <span>{row.original.author}{row.original.is_demo && <span className='ms-1 text-xs text-muted-foreground'>(demo)</span>}</span>, meta: { csv: (r) => r.author } },
  { header: 'Sent', cell: ({ row }) => formatDate(row.original.created_at, true), meta: { sort: 'created', csv: (r) => r.created_at } },
  { header: 'Status', cell: ({ row }) => <StatusBadge status={row.original.status === 'pending' ? 'awaiting_review' : row.original.status === 'approved' ? 'active' : 'rejected'} />, meta: { sort: 'status', csv: (r) => r.status } },
  { header: '', id: 'act', meta: { label: 'Actions' }, cell: ({ row }) => <Decide review={row.original} /> },
]

/** Reviews from people who bought and received the product. Nothing shows in the shop before you approve it. */
export function ReviewsPage() {
  return (
    <Page title='Reviews' description='Only people who bought the product and got it can write a review. Nothing shows in the shop until you press "Show it".'>
      <ResourceTable<Review> queryKey='reviews' path='/reviews' columns={columns} search={{ placeholder: 'Words or product name' }}
        filters={[
          { key: 'status', label: 'Status', default: 'pending', sendAll: true, options: [{ value: 'pending', label: 'Waiting for you' }, { value: 'approved', label: 'Shown' }, { value: 'rejected', label: 'Hidden' }] },
          { key: 'rating', label: 'Stars', options: [5, 4, 3, 2, 1].map((n) => ({ value: String(n), label: `${n} stars` })) },
        ]} empty='No reviews here.' />
    </Page>
  )
}
