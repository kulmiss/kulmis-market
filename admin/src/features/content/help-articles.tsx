import { useEffect, useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { ExternalLink, Pencil, Plus, RotateCcw, ThumbsDown, ThumbsUp } from 'lucide-react'
import { toast } from 'sonner'
import { api, errorMessage } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Switch } from '@/components/ui/switch'
import { Textarea } from '@/components/ui/textarea'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { StatusBadge } from '@/components/km/status-badge'

type Article = { slug: string; topic: string; title: string; body: string; feature: string | null; position: number; keywords: string; is_published: boolean; source: 'default' | 'edited' | 'new'; yes: number; no: number }
type Token = { token: string; value: string; label: string }
type Res = { data: Article[]; topics: string[]; tokens: Token[] }

const SOURCE: Record<Article['source'], string> = { default: 'As shipped', edited: 'Changed by you', new: 'Added by you' }

/** Member help articles: change, hide, reset, add; with a live preview and the "Was this helpful?" counts. */
export function HelpArticles() {
  const qc = useQueryClient()
  const { data } = useQuery({ queryKey: ['help-articles'], queryFn: async () => (await api.get<Res>('/help/articles')).data })
  if (!data) return null

  async function reset(a: Article) {
    if (!confirm(a.source === 'new' ? 'Delete this article?' : 'Go back to the text that came with the shop?')) return
    try {
      const r = await api.delete<Res>(`/help/articles/${a.slug}`)
      qc.setQueryData(['help-articles'], r.data)
      toast.success(a.source === 'new' ? 'Deleted' : 'Back to the original text')
    } catch (e) {
      toast.error(errorMessage(e))
    }
  }

  return (
    <div className='grid gap-3' data-help-articles>
      <div className='flex flex-wrap items-center gap-2'>
        <p className='text-sm text-muted-foreground'>Articles on /help. Numbers like <code className='rounded bg-muted px-1'>{'{membership_price}'}</code> always show the current setting. Articles about a switched-off feature are hidden.</p>
        <span className='ms-auto'><ArticleDialog topics={data.topics} tokens={data.tokens} /></span>
      </div>
      <div className='overflow-x-auto rounded-md border'>
        <Table>
          <TableHeader><TableRow><TableHead>Topic</TableHead><TableHead>Article</TableHead><TableHead>Text</TableHead><TableHead className='text-end'>Helpful?</TableHead><TableHead>Shown</TableHead><TableHead /></TableRow></TableHeader>
          <TableBody>
            {data.data.map((a) => (
              <TableRow key={a.slug} data-article={a.slug}>
                <TableCell className='text-muted-foreground'>{a.topic}</TableCell>
                <TableCell className='font-medium'>{a.title}</TableCell>
                <TableCell className='text-xs text-muted-foreground'>{SOURCE[a.source]}</TableCell>
                <TableCell className='text-end text-sm whitespace-nowrap' title='Yes / No answers to "Was this helpful?"'>
                  <span className='inline-flex items-center gap-1'><ThumbsUp className='size-3.5' />{a.yes}</span>{' '}
                  <span className='ms-2 inline-flex items-center gap-1'><ThumbsDown className='size-3.5' />{a.no}</span>
                </TableCell>
                <TableCell><StatusBadge status={a.is_published ? 'active' : 'off'} /></TableCell>
                <TableCell>
                  <div className='flex justify-end gap-1'>
                    <Button size='icon' variant='ghost' asChild aria-label='Open on the site'><a href={`/help/${a.slug}`} target='_blank' rel='noreferrer'><ExternalLink /></a></Button>
                    <ArticleDialog article={a} topics={data.topics} tokens={data.tokens} />
                    {a.source !== 'default' && <Button size='icon' variant='ghost' aria-label={a.source === 'new' ? 'Delete' : 'Back to the original'} onClick={() => reset(a)}><RotateCcw /></Button>}
                  </div>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>
    </div>
  )
}

function ArticleDialog({ article, topics, tokens }: { article?: Article; topics: string[]; tokens: Token[] }) {
  const qc = useQueryClient()
  const [open, setOpen] = useState(false)
  const [d, setD] = useState({ slug: article?.slug ?? '', topic: article?.topic ?? topics[0], title: article?.title ?? '', body: article?.body ?? '',
    feature: article?.feature ?? '', position: article?.position ?? 50, is_published: article?.is_published ?? true })
  const [preview, setPreview] = useState<{ title: string; html: string; mentions_off: string[] } | null>(null)

  useEffect(() => {
    if (!open || !d.body) return
    const t = setTimeout(() => {
      api.post<{ data: { title: string; html: string; mentions_off: string[] } }>('/help/articles/preview', { title: d.title, body: d.body })
        .then((r) => setPreview(r.data.data)).catch(() => undefined)
    }, 300)
    return () => clearTimeout(t)
  }, [open, d.title, d.body])

  async function save() {
    try {
      const body = { ...d, feature: d.feature || null, slug: article ? undefined : d.slug }
      const r = article ? await api.put<Res>(`/help/articles/${article.slug}`, body) : await api.post<Res>('/help/articles', body)
      qc.setQueryData(['help-articles'], r.data)
      setOpen(false)
      toast.success('Saved')
    } catch (e) {
      toast.error(errorMessage(e))
    }
  }

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>{article ? <Button size='icon' variant='ghost' aria-label='Edit'><Pencil /></Button> : <Button><Plus /> New article</Button>}</DialogTrigger>
      <DialogContent className='max-h-[92svh] overflow-y-auto sm:max-w-5xl'>
        <DialogHeader>
          <DialogTitle>{article ? 'Edit article' : 'New article'}</DialogTitle>
          <DialogDescription>Easy words, short sentences. Type a number as a setting, e.g. {'{cashout_min}'}, so it is always right.</DialogDescription>
        </DialogHeader>
        <div className='grid gap-4 lg:grid-cols-2'>
          <div className='grid content-start gap-3'>
            {!article && <L label='Address (e.g. how-to-pay)'><Input value={d.slug} onChange={(e) => setD({ ...d, slug: e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, '-') })} /></L>}
            <L label='Title'><Input value={d.title} onChange={(e) => setD({ ...d, title: e.target.value })} /></L>
            <div className='grid gap-3 sm:grid-cols-2'>
              <L label='Topic'>
                <select className='h-9 rounded-md border bg-transparent px-2 text-sm' value={d.topic} onChange={(e) => setD({ ...d, topic: e.target.value })}>
                  {topics.map((t) => <option key={t}>{t}</option>)}
                </select>
              </L>
              <L label='Order in the topic'><Input type='number' min={0} value={d.position} onChange={(e) => setD({ ...d, position: Number(e.target.value) })} /></L>
            </div>
            <L label='Text (Markdown: **bold**, - list, 1. steps)'><Textarea rows={14} className='font-mono text-sm' value={d.body} onChange={(e) => setD({ ...d, body: e.target.value })} /></L>
            <L label='Only while these switches are on (optional, e.g. wallet.cashout)'><Input value={d.feature} onChange={(e) => setD({ ...d, feature: e.target.value })} /></L>
            <label className='flex items-center gap-2 text-sm'><Switch checked={d.is_published} onCheckedChange={(v) => setD({ ...d, is_published: v })} /> Show on /help</label>
            <details className='text-sm'>
              <summary className='cursor-pointer font-medium'>Numbers you can use ({tokens.length})</summary>
              <div className='mt-2 grid max-h-48 gap-1 overflow-y-auto'>
                {tokens.map((t) => <div key={t.token} className='flex gap-2'><code className='rounded bg-muted px-1'>{t.token}</code><span className='text-muted-foreground'>{t.label}: <b>{t.value}</b></span></div>)}
              </div>
            </details>
          </div>
          <div className='grid content-start gap-2 rounded-lg border p-4' aria-label='Preview'>
            <p className='text-xs font-medium tracking-wide text-muted-foreground uppercase'>Preview: what members see</p>
            {preview?.mentions_off?.length ? <p className='rounded bg-amber-500/10 p-2 text-xs text-amber-700 dark:text-amber-300'>Talks about switched-off: {preview.mentions_off.join(', ')}. Those parts are hidden.</p> : null}
            <h2 className='text-xl font-semibold'>{preview?.title || d.title}</h2>
            <div className='prose prose-sm max-w-none text-sm dark:prose-invert [&_li]:ms-5 [&_ol]:list-decimal [&_p]:my-2 [&_table]:w-full [&_td]:border-b [&_td]:py-1 [&_ul]:list-disc' dangerouslySetInnerHTML={{ __html: preview?.html ?? '' }} />
          </div>
        </div>
        <DialogFooter><Button variant='ghost' onClick={() => setOpen(false)}>Cancel</Button><Button onClick={save}>Save</Button></DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

function L({ label, children }: { label: string; children: React.ReactNode }) {
  return <div className='grid gap-1.5 text-sm leading-none font-medium'><span>{label}</span>{children}</div>
}
