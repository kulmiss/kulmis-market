import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { ExternalLink, Pencil, Plus, Trash2 } from 'lucide-react'
import { useMutate } from '@/hooks/use-mutate'
import { api } from '@/lib/api'
import { formatDate } from '@/lib/money'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Switch } from '@/components/ui/switch'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { Textarea } from '@/components/ui/textarea'
import { FormDialog } from '@/components/km/form-dialog'
import { Page } from '@/components/km/page'

type PageRow = { id: number; slug: string; title: string; body: string; seo_description: string | null; is_published: boolean; in_footer: boolean; position: number; updated_at: string }
type BannerRow = { id: number; placement: string; title: string; text: string | null; link_url: string | null; link_text: string | null; starts_at: string | null; ends_at: string | null; is_active: boolean; position: number }

export function ContentPage() {
  const pages = useQuery({ queryKey: ['pages'], queryFn: async () => (await api.get<{ data: PageRow[] }>('/pages')).data.data })
  const banners = useQuery({ queryKey: ['banners'], queryFn: async () => (await api.get<{ data: BannerRow[] }>('/banners')).data.data })
  const del = useMutate('delete', ['banners'], 'Banner deleted')
  return (
    <Page title='Pages & banners' description='Terms, privacy, refund & shipping, membership terms, referral rules — and banners on the shop.'>
      <Tabs defaultValue='pages'>
        <TabsList><TabsTrigger value='pages'>Pages</TabsTrigger><TabsTrigger value='banners'>Banners</TabsTrigger></TabsList>
        <TabsContent value='pages' className='mt-4'>
          <div className='mb-3 flex justify-end'><PageDialog /></div>
          <Table>
            <TableHeader><TableRow><TableHead>Title</TableHead><TableHead>Address</TableHead><TableHead>Footer</TableHead><TableHead>Updated</TableHead><TableHead /></TableRow></TableHeader>
            <TableBody>
              {pages.data?.map((p) => (
                <TableRow key={p.id}>
                  <TableCell>{p.title}{!p.is_published && <span className='ms-2 text-xs text-muted-foreground'>(hidden)</span>}</TableCell>
                  <TableCell><a className='inline-flex items-center gap-1 underline' href={`/p/${p.slug}`} target='_blank' rel='noreferrer'>/p/{p.slug} <ExternalLink className='size-3' /></a></TableCell>
                  <TableCell>{p.in_footer ? 'Yes' : ''}</TableCell><TableCell>{formatDate(p.updated_at)}</TableCell>
                  <TableCell className='text-end'><PageDialog page={p} /></TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </TabsContent>
        <TabsContent value='banners' className='mt-4'>
          <div className='mb-3 flex justify-end'><BannerDialog /></div>
          <Table>
            <TableHeader><TableRow><TableHead>Where</TableHead><TableHead>Title</TableHead><TableHead>Dates</TableHead><TableHead>Active</TableHead><TableHead /></TableRow></TableHeader>
            <TableBody>
              {banners.data?.map((b) => (
                <TableRow key={b.id}>
                  <TableCell>{b.placement.replace('_', ' ')}</TableCell><TableCell>{b.title}</TableCell>
                  <TableCell>{formatDate(b.starts_at)} → {formatDate(b.ends_at)}</TableCell><TableCell>{b.is_active ? 'Yes' : 'No'}</TableCell>
                  <TableCell className='text-end'><BannerDialog banner={b} /><Button size='icon' variant='ghost' onClick={() => del.mutate({ path: `/banners/${b.id}` })}><Trash2 /></Button></TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </TabsContent>
      </Tabs>
    </Page>
  )
}

function PageDialog({ page }: { page?: PageRow }) {
  const [d, setD] = useState({ slug: page?.slug ?? '', title: page?.title ?? '', body: page?.body ?? '', seo_description: page?.seo_description ?? '',
    is_published: page?.is_published ?? true, in_footer: page?.in_footer ?? true, position: page?.position ?? 0 })
  const save = useMutate(page ? 'put' : 'post', ['pages'], 'Page saved')
  return (
    <FormDialog title={page ? `Edit ${page.title}` : 'New page'} trigger={page ? <Button size='icon' variant='ghost'><Pencil /></Button> : <Button><Plus /> New page</Button>}
      onSave={() => save.mutateAsync({ path: page ? `/pages/${page.id}` : '/pages', body: d })}>
      <div className='grid grid-cols-2 gap-3'>
        <label className='grid gap-1.5 text-sm leading-none font-medium'>Title<Input value={d.title} onChange={(e) => setD({ ...d, title: e.target.value })} /></label>
        <label className='grid gap-1.5 text-sm leading-none font-medium'>Address (/p/…)<Input value={d.slug} onChange={(e) => setD({ ...d, slug: e.target.value })} /></label>
      </div>
      <label className='grid gap-1.5 text-sm leading-none font-medium'>Text (Markdown: ## heading, - list, **bold**)<Textarea rows={14} className='font-mono text-xs' value={d.body} onChange={(e) => setD({ ...d, body: e.target.value })} /></label>
      <label className='grid gap-1.5 text-sm leading-none font-medium'>Search description<Input value={d.seo_description} onChange={(e) => setD({ ...d, seo_description: e.target.value })} /></label>
      <div className='flex gap-6 text-sm'>
        <label className='flex items-center gap-2'><Switch checked={d.is_published} onCheckedChange={(v) => setD({ ...d, is_published: v })} /> Published</label>
        <label className='flex items-center gap-2'><Switch checked={d.in_footer} onCheckedChange={(v) => setD({ ...d, in_footer: v })} /> Link in footer</label>
      </div>
    </FormDialog>
  )
}

function BannerDialog({ banner }: { banner?: BannerRow }) {
  const [d, setD] = useState({ placement: banner?.placement ?? 'home', title: banner?.title ?? '', text: banner?.text ?? '', link_url: banner?.link_url ?? '',
    link_text: banner?.link_text ?? '', starts_at: banner?.starts_at?.slice(0, 10) ?? '', ends_at: banner?.ends_at?.slice(0, 10) ?? '', is_active: banner?.is_active ?? true })
  const save = useMutate(banner ? 'put' : 'post', ['banners'], 'Banner saved')
  return (
    <FormDialog title={banner ? 'Edit banner' : 'New banner'} trigger={banner ? <Button size='icon' variant='ghost'><Pencil /></Button> : <Button><Plus /> New banner</Button>}
      onSave={() => save.mutateAsync({ path: banner ? `/banners/${banner.id}` : '/banners', body: { ...d, starts_at: d.starts_at || null, ends_at: d.ends_at || null, link_url: d.link_url || null } })}>
      <div className='grid gap-1.5'><Label>Where</Label>
        <Select value={d.placement} onValueChange={(v) => setD({ ...d, placement: v })}>
          <SelectTrigger><SelectValue /></SelectTrigger>
          <SelectContent>{['top_bar', 'home', 'shop', 'membership', 'signals'].map((p) => <SelectItem key={p} value={p}>{p.replace('_', ' ')}</SelectItem>)}</SelectContent>
        </Select>
      </div>
      <label className='grid gap-1.5 text-sm leading-none font-medium'>Title<Input value={d.title} onChange={(e) => setD({ ...d, title: e.target.value })} /></label>
      <label className='grid gap-1.5 text-sm leading-none font-medium'>Text<Input value={d.text} onChange={(e) => setD({ ...d, text: e.target.value })} /></label>
      <div className='grid grid-cols-2 gap-3'>
        <label className='grid gap-1.5 text-sm leading-none font-medium'>Link (/shop or https://…)<Input value={d.link_url} onChange={(e) => setD({ ...d, link_url: e.target.value })} /></label>
        <label className='grid gap-1.5 text-sm leading-none font-medium'>Button text<Input value={d.link_text} onChange={(e) => setD({ ...d, link_text: e.target.value })} /></label>
        <label className='grid gap-1.5 text-sm leading-none font-medium'>From<Input type='date' value={d.starts_at} onChange={(e) => setD({ ...d, starts_at: e.target.value })} /></label>
        <label className='grid gap-1.5 text-sm leading-none font-medium'>Until<Input type='date' value={d.ends_at} onChange={(e) => setD({ ...d, ends_at: e.target.value })} /></label>
      </div>
      <label className='flex items-center gap-2 text-sm'><Switch checked={d.is_active} onCheckedChange={(v) => setD({ ...d, is_active: v })} /> Active</label>
    </FormDialog>
  )
}
