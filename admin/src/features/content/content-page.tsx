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
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { Textarea } from '@/components/ui/textarea'
import { OffFeatureNote } from '@/components/km/off-feature-note'
import { FormDialog } from '@/components/km/form-dialog'
import { DataTable, type DataColumn } from '@/components/km/data-table'
import { Page } from '@/components/km/page'

type PageRow = { id: number; slug: string; title: string; body: string; seo_description: string | null; is_published: boolean; in_footer: boolean; position: number; updated_at: string }
type BannerRow = { id: number; color?: string | null; placement: string; title: string; text: string | null; link_url: string | null; link_text: string | null; starts_at: string | null; ends_at: string | null; is_active: boolean; position: number }

const pageColumns: DataColumn<PageRow>[] = [
  { header: 'Title', accessorKey: 'title', cell: ({ row }) => <span>{row.original.title}{!row.original.is_published && <span className='ms-2 text-xs text-muted-foreground'>(hidden)</span>}</span>, meta: { sort: 'title' } },
  { header: 'Address', accessorKey: 'slug', cell: ({ row }) => <a className='inline-flex items-center gap-1 underline' href={`/p/${row.original.slug}`} target='_blank' rel='noreferrer'>/p/{row.original.slug} <ExternalLink className='size-3' /></a>, meta: { sort: 'slug' } },
  { header: 'Footer', cell: ({ row }) => (row.original.in_footer ? 'Yes' : ''), meta: { csv: (p) => (p.in_footer ? 'yes' : '') } },
  { header: 'Updated', cell: ({ row }) => formatDate(row.original.updated_at), meta: { sort: 'updated_at', csv: (p) => p.updated_at } },
  { header: '', id: 'edit', meta: { label: 'Edit' }, cell: ({ row }) => <div className='text-end'><PageDialog page={row.original} /></div> },
]

const bannerColumns: DataColumn<BannerRow>[] = [
  { header: 'Where', accessorKey: 'placement', cell: ({ row }) => row.original.placement.replace('_', ' '), meta: { sort: 'placement' } },
  { header: 'Title', accessorKey: 'title', meta: { sort: 'title' } },
  { header: 'Dates', cell: ({ row }) => `${formatDate(row.original.starts_at)} → ${formatDate(row.original.ends_at)}`, meta: { sort: 'starts_at', csv: (b) => `${b.starts_at ?? ''} → ${b.ends_at ?? ''}` } },
  { header: 'Active', cell: ({ row }) => (row.original.is_active ? 'Yes' : 'No'), meta: { csv: (b) => (b.is_active ? 'yes' : 'no') } },
]

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
          <DataTable<PageRow> id='pages' rows={pages.data ?? []} loading={pages.isLoading} columns={pageColumns} search={{ placeholder: 'Page title' }} />
        </TabsContent>
        <TabsContent value='banners' className='mt-4'>
          <div className='mb-3 flex justify-end'><BannerDialog /></div>
          <DataTable<BannerRow> id='banners' rows={banners.data ?? []} loading={banners.isLoading} search={{ placeholder: 'Banner title' }}
            filters={[{ key: 'is_active', label: 'Active', options: [{ value: 'true', label: 'On' }, { value: 'false', label: 'Off' }] }]}
            columns={[...bannerColumns, { header: '', id: 'edit', meta: { label: 'Edit' }, cell: ({ row }) => (
              <div className='text-end'><BannerDialog banner={row.original} /><Button size='icon' variant='ghost' aria-label={`Delete ${row.original.title}`} onClick={() => del.mutate({ path: `/banners/${row.original.id}` })}><Trash2 /></Button></div>
            ) }]} />
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
      <div className='grid grid-cols-1 gap-3 sm:grid-cols-2'>
        <label className='grid gap-1.5 text-sm leading-none font-medium'>Title<Input value={d.title} onChange={(e) => setD({ ...d, title: e.target.value })} /></label>
        <label className='grid gap-1.5 text-sm leading-none font-medium'>Address (/p/…)<Input value={d.slug} onChange={(e) => setD({ ...d, slug: e.target.value })} /></label>
      </div>
      <label className='grid gap-1.5 text-sm leading-none font-medium'>Text (Markdown: ## heading, - list, **bold**)<Textarea rows={14} className='font-mono text-xs' value={d.body} onChange={(e) => setD({ ...d, body: e.target.value })} /></label>
      <OffFeatureNote text={`${d.title} ${d.slug.replace(/-/g, ' ')}`} hidden='The whole page is hidden while it is off.' />
      <OffFeatureNote text={d.body} hidden='Parts with a heading, list item or table row about it are hidden; other sentences are still shown.' />
      <label className='grid gap-1.5 text-sm leading-none font-medium'>Search description<Input value={d.seo_description} onChange={(e) => setD({ ...d, seo_description: e.target.value })} /></label>
      <div className='flex gap-6 text-sm'>
        <label className='flex items-center gap-2'><Switch checked={d.is_published} onCheckedChange={(v) => setD({ ...d, is_published: v })} /> Published</label>
        <label className='flex items-center gap-2'><Switch checked={d.in_footer} onCheckedChange={(v) => setD({ ...d, in_footer: v })} /> Link in footer</label>
      </div>
    </FormDialog>
  )
}

function BannerDialog({ banner }: { banner?: BannerRow }) {
  const [d, setD] = useState({ color: banner?.color ?? 'dark', placement: banner?.placement ?? 'home', title: banner?.title ?? '', text: banner?.text ?? '', link_url: banner?.link_url ?? '',
    link_text: banner?.link_text ?? '', starts_at: banner?.starts_at?.slice(0, 10) ?? '', ends_at: banner?.ends_at?.slice(0, 10) ?? '', is_active: banner?.is_active ?? true })
  const save = useMutate(banner ? 'put' : 'post', ['banners'], 'Banner saved')
  return (
    <FormDialog title={banner ? 'Edit banner' : 'New banner'} trigger={banner ? <Button size='icon' variant='ghost'><Pencil /></Button> : <Button><Plus /> New banner</Button>}
      onSave={() => save.mutateAsync({ path: banner ? `/banners/${banner.id}` : '/banners', body: { ...d, starts_at: d.starts_at || null, ends_at: d.ends_at || null, link_url: d.link_url || null } })}>
      <div className='grid gap-1.5'><Label>Where</Label>
        <Select value={d.placement} onValueChange={(v) => setD({ ...d, placement: v })}>
          <SelectTrigger><SelectValue /></SelectTrigger>
          <SelectContent>{['top_bar', 'home', 'shop', 'membership', 'signals'].map((p) => <SelectItem key={p} value={p}>{p === 'top_bar' ? 'Announcement bar (top of every page)' : p.replace('_', ' ')}</SelectItem>)}</SelectContent>
        </Select>
      </div>
      {d.placement === 'top_bar' && (
        <div className='grid gap-1.5'><Label>Colour</Label>
          <Select value={d.color ?? 'dark'} onValueChange={(v) => setD({ ...d, color: v })}>
            <SelectTrigger><SelectValue /></SelectTrigger>
            <SelectContent>{[['green', 'Green'], ['gold', 'Gold'], ['dark', 'Dark'], ['red', 'Red (urgent)']].map(([v, l]) => <SelectItem key={v} value={v}>{l}</SelectItem>)}</SelectContent>
          </Select>
        </div>
      )}
      <label className='grid gap-1.5 text-sm leading-none font-medium'>Title<Input value={d.title} onChange={(e) => setD({ ...d, title: e.target.value })} /></label>
      <label className='grid gap-1.5 text-sm leading-none font-medium'>Text<Input value={d.text} onChange={(e) => setD({ ...d, text: e.target.value })} /></label>
      <div className='grid grid-cols-1 gap-3 sm:grid-cols-2'>
        <label className='grid gap-1.5 text-sm leading-none font-medium'>Link (/shop or https://…)<Input value={d.link_url} onChange={(e) => setD({ ...d, link_url: e.target.value })} /></label>
        <label className='grid gap-1.5 text-sm leading-none font-medium'>Button text<Input value={d.link_text} onChange={(e) => setD({ ...d, link_text: e.target.value })} /></label>
        <div className='sm:col-span-2'><OffFeatureNote text={`${d.title} ${d.text ?? ''} ${d.link_text ?? ''}`} hidden='This banner is hidden while it is off.' /></div>
        <label className='grid gap-1.5 text-sm leading-none font-medium'>From<Input type='date' value={d.starts_at} onChange={(e) => setD({ ...d, starts_at: e.target.value })} /></label>
        <label className='grid gap-1.5 text-sm leading-none font-medium'>Until<Input type='date' value={d.ends_at} onChange={(e) => setD({ ...d, ends_at: e.target.value })} /></label>
      </div>
      <label className='flex items-center gap-2 text-sm'><Switch checked={d.is_active} onCheckedChange={(v) => setD({ ...d, is_active: v })} /> Active</label>
    </FormDialog>
  )
}
