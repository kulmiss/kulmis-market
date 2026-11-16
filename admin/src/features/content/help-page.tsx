import { useState } from 'react'
import { Pencil, Plus, Trash2 } from 'lucide-react'
import { useMutate } from '@/hooks/use-mutate'
import { formatDate } from '@/lib/money'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Switch } from '@/components/ui/switch'
import { Textarea } from '@/components/ui/textarea'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { FormDialog } from '@/components/km/form-dialog'
import { OffFeatureNote } from '@/components/km/off-feature-note'
import { Page } from '@/components/km/page'
import { ResourceTable, type ResourceColumn } from '@/components/km/resource-table'
import { StatusBadge } from '@/components/km/status-badge'
import { useAuthStore } from '@/stores/auth-store'

type Faq = { id: number; category: string | null; question: string; answer: string; position: number; is_published: boolean }
type Msg = { id: number; name: string; contact: string; topic: string | null; topic_label: string; message: string; status: string; created_at: string }

const faqColumns: ResourceColumn<Faq>[] = [
  { header: 'Group', accessorKey: 'category', meta: { sort: 'category' } },
  { header: 'Question', accessorKey: 'question', meta: { sort: 'question', card: 'title' } },
  { header: 'Order', accessorKey: 'position', meta: { sort: 'position', align: 'end' } },
  { header: 'Shown', cell: ({ row }) => <StatusBadge status={row.original.is_published ? 'active' : 'off'} />, meta: { csv: (f) => (f.is_published ? 'yes' : 'no') } },
  { header: '', id: 'edit', meta: { label: 'Edit' }, cell: ({ row }) => <div className='flex justify-end gap-1'><FaqDialog faq={row.original} /><DeleteFaq id={row.original.id} /></div> },
]

const msgColumns: ResourceColumn<Msg>[] = [
  { header: 'When', cell: ({ row }) => formatDate(row.original.created_at, true), meta: { sort: 'created', csv: (m) => m.created_at } },
  { header: 'From', cell: ({ row }) => <span>{row.original.name}<span className='block text-xs text-muted-foreground'>{row.original.contact}</span></span>, meta: { csv: (m) => `${m.name} ${m.contact}`, card: 'title' } },
  { header: 'About', accessorKey: 'topic_label' },
  { header: 'Message', cell: ({ row }) => <p className='max-w-md whitespace-pre-wrap break-words text-sm'>{row.original.message}</p>, meta: { csv: (m) => m.message } },
  { header: 'Status', cell: ({ row }) => <StatusBadge status={row.original.status === 'new' ? 'pending' : row.original.status === 'answered' ? 'cleared' : 'rejected'} />, meta: { sort: 'status', csv: (m) => m.status } },
  { header: '', id: 'act', meta: { label: 'Actions' }, cell: ({ row }) => <MsgActions msg={row.original} /> },
]

export function HelpPage() {
  const can = useAuthStore((s) => s.can)
  const editor = can('manage-content')
  return (
    <Page title='Help centre' description='The questions and answers on /help, and messages people sent with the contact form.' actions={editor ? <FaqDialog /> : undefined}>
      <Tabs defaultValue='messages'>
        <TabsList><TabsTrigger value='messages'>Messages</TabsTrigger>{editor && <TabsTrigger value='faqs'>Questions</TabsTrigger>}</TabsList>
        <TabsContent value='messages' className='mt-4'>
          <ResourceTable<Msg> queryKey='help-messages' path='/help/messages' columns={msgColumns} search={{ placeholder: 'Name, phone, email or text' }}
            filters={[{ key: 'status', label: 'Status', default: 'new', sendAll: true, options: [{ value: 'new', label: 'New' }, { value: 'answered', label: 'Answered' }, { value: 'spam', label: 'Spam' }, { value: 'all', label: 'All' }] }]} />
        </TabsContent>
        {editor && (
          <TabsContent value='faqs' className='mt-4'>
            <ResourceTable<Faq> queryKey='help-faqs' path='/help/faqs' columns={faqColumns} search={{ placeholder: 'Question or answer' }} />
          </TabsContent>
        )}
      </Tabs>
    </Page>
  )
}

function MsgActions({ msg }: { msg: Msg }) {
  const set = useMutate('put', ['help-messages'], 'Saved')
  return (
    <div className='flex justify-end gap-1'>
      {msg.status !== 'answered' && <Button size='sm' variant='outline' onClick={() => set.mutate({ path: `/help/messages/${msg.id}`, body: { status: 'answered' } })}>Answered</Button>}
      {msg.status !== 'spam' && <Button size='sm' variant='ghost' onClick={() => set.mutate({ path: `/help/messages/${msg.id}`, body: { status: 'spam' } })}>Spam</Button>}
      {msg.status !== 'new' && <Button size='sm' variant='ghost' onClick={() => set.mutate({ path: `/help/messages/${msg.id}`, body: { status: 'new' } })}>Back to new</Button>}
    </div>
  )
}

function DeleteFaq({ id }: { id: number }) {
  const del = useMutate('delete', ['help-faqs'], 'Deleted')
  return <Button size='icon' variant='ghost' aria-label='Delete' onClick={() => { if (confirm('Delete this question?')) del.mutate({ path: `/help/faqs/${id}` }) }}><Trash2 /></Button>
}

function FaqDialog({ faq }: { faq?: Faq }) {
  const [d, setD] = useState({ category: faq?.category ?? '', question: faq?.question ?? '', answer: faq?.answer ?? '', position: faq?.position ?? 0, is_published: faq?.is_published ?? true })
  const save = useMutate(faq ? 'put' : 'post', ['help-faqs'], 'Saved')
  return (
    <FormDialog title={faq ? 'Edit question' : 'New question'}
      trigger={faq ? <Button size='icon' variant='ghost' aria-label='Edit'><Pencil /></Button> : <Button><Plus /> New question</Button>}
      onSave={() => save.mutateAsync({ path: faq ? `/help/faqs/${faq.id}` : '/help/faqs', body: { ...d, category: d.category || null } })}>
      <L label='Group (e.g. Orders)'><Input value={d.category} onChange={(e) => setD({ ...d, category: e.target.value })} /></L>
      <L label='Question'><Input value={d.question} onChange={(e) => setD({ ...d, question: e.target.value })} /></L>
      <L label='Answer (easy words)'><Textarea rows={6} value={d.answer} onChange={(e) => setD({ ...d, answer: e.target.value })} /></L>
      <OffFeatureNote text={`${d.question} ${d.answer}`} hidden='This question is hidden on /help while it is off.' />
      <L label='Order in the group'><Input type='number' min={0} value={d.position} onChange={(e) => setD({ ...d, position: Number(e.target.value) })} /></L>
      <label className='flex items-center gap-2 text-sm'><Switch checked={d.is_published} onCheckedChange={(v) => setD({ ...d, is_published: v })} /> Show on /help</label>
    </FormDialog>
  )
}

function L({ label, children }: { label: string; children: React.ReactNode }) {
  return <div className='grid gap-1.5 text-sm leading-none font-medium'><span>{label}</span>{children}</div>
}
