import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Pencil, Plus, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { api, errorMessage } from '@/lib/api'
import { useMutate } from '@/hooks/use-mutate'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Switch } from '@/components/ui/switch'
import { Textarea } from '@/components/ui/textarea'
import { OffFeatureNote } from '@/components/km/off-feature-note'
import { FormDialog } from '@/components/km/form-dialog'
import { Page } from '@/components/km/page'
import { PageSkeleton } from '@/components/km/page-skeleton'

type Item = { id: number; title: string; target: string; text: string; position: number; is_active: boolean; image: string; has_image: boolean }
type Data = { data: Item[]; targets: Record<string, string> }

export function ShareKitPage() {
  const q = useQuery({ queryKey: ['share-kit'], queryFn: async () => (await api.get<Data>('/share-kit')).data })
  if (!q.data) return <PageSkeleton />
  return (
    <Page title='Share kit' description='Ready-made posts members share. {name}, {code} and {link} become each member’s own.' actions={<ItemDialog targets={q.data.targets} />}>
      <div className='grid gap-4 md:grid-cols-2 lg:grid-cols-3'>
        {q.data.data.map((i) => (
          <Card key={i.id} className={i.is_active ? '' : 'opacity-60'}>
            <CardHeader className='pb-2'><CardTitle className='flex items-center justify-between text-base'>{i.title}
              <span className='flex gap-1'><ItemDialog item={i} targets={q.data.targets} /><Delete id={i.id} /></span></CardTitle></CardHeader>
            <CardContent className='grid gap-2 text-sm'>
              <img src={i.image} alt='' className='aspect-video w-full rounded-md object-cover' loading='lazy' />
              <p className='text-muted-foreground'>{q.data.targets[i.target]}</p>
              <p className='whitespace-pre-wrap'>{i.text}</p>
            </CardContent>
          </Card>
        ))}
      </div>
    </Page>
  )
}

function Delete({ id }: { id: number }) {
  const del = useMutate('delete', ['share-kit'], 'Deleted')
  return <Button size='icon' variant='ghost' aria-label='Delete' onClick={() => { if (confirm('Delete this post?')) del.mutate({ path: `/share-kit/${id}` }) }}><Trash2 /></Button>
}

function ItemDialog({ item, targets }: { item?: Item; targets: Record<string, string> }) {
  const qc = useQueryClient()
  const [d, setD] = useState({ title: item?.title ?? '', target: item?.target ?? 'shop', text: item?.text ?? 'Have a look: {link}\nMy code: {code}', position: item?.position ?? 0, is_active: item?.is_active ?? true })
  const [file, setFile] = useState<File | null>(null)
  const [remove, setRemove] = useState(false)
  const save = async () => {
    const form = new FormData()
    Object.entries(d).forEach(([k, v]) => form.append(k, typeof v === 'boolean' ? (v ? '1' : '0') : String(v)))
    if (file) form.append('image', file)
    if (remove) form.append('remove_image', '1')
    try {
      await api.post(item ? `/share-kit/${item.id}` : '/share-kit', form)
      toast.success('Saved')
      qc.invalidateQueries({ queryKey: ['share-kit'] })
    } catch (e) { toast.error(errorMessage(e)); throw e }
  }
  return (
    <FormDialog title={item ? 'Edit post' : 'New post'} trigger={item ? <Button size='icon' variant='ghost' aria-label='Edit'><Pencil /></Button> : <Button><Plus /> New post</Button>} onSave={save}>
      <L label='Title'><Input value={d.title} onChange={(e) => setD({ ...d, title: e.target.value })} /></L>
      <L label='Link goes to'>
        <Select value={d.target} onValueChange={(v) => setD({ ...d, target: v })}><SelectTrigger><SelectValue /></SelectTrigger>
          <SelectContent>{Object.entries(targets).map(([k, v]) => <SelectItem key={k} value={k}>{v}</SelectItem>)}</SelectContent></Select>
      </L>
      <L label='Text (keep {link}; you can use {name} and {code})'><Textarea rows={5} value={d.text} onChange={(e) => setD({ ...d, text: e.target.value })} /></L>
      <OffFeatureNote text={`${d.title} ${d.text}`} hidden='This post is hidden from the share kit while it is off.' />
      <L label='Picture (optional, JPG/PNG/WebP)'><Input type='file' accept='image/jpeg,image/png,image/webp' onChange={(e) => setFile(e.target.files?.[0] ?? null)} /></L>
      {item?.has_image && <label className='flex items-center gap-2 text-sm'><Switch checked={remove} onCheckedChange={setRemove} /> Remove the picture</label>}
      <L label='Order'><Input type='number' min={0} value={d.position} onChange={(e) => setD({ ...d, position: Number(e.target.value) })} /></L>
      <label className='flex items-center gap-2 text-sm'><Switch checked={d.is_active} onCheckedChange={(v) => setD({ ...d, is_active: v })} /> Show to members</label>
    </FormDialog>
  )
}

function L({ label, children }: { label: string; children: React.ReactNode }) {
  return <div className='grid gap-1.5 text-sm leading-none font-medium'><span>{label}</span>{children}</div>
}
