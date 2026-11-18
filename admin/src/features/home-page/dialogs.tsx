import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Clock, History, LayoutTemplate, MessageSquareQuote, Plus, Search as SearchIcon, Send, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { api, errorMessage } from '@/lib/api'
import { formatDate } from '@/lib/money'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { MediaField } from './media-picker'
import { Preview } from './preview'
import type { HomeState, Problems, Quote, Section, SectionType, Seo, Summary } from './types'

/** "Add section": the library, grouped. Sections of switched-off features are greyed out with a note. */
export function AddSectionDialog({ types, onAdd }: { types: SectionType[]; onAdd: (t: SectionType) => void }) {
  const [open, setOpen] = useState(false)
  const groups = [...new Set(types.map((t) => t.group))]
  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild><Button type='button' className='w-full'><Plus /> Add section</Button></DialogTrigger>
      <DialogContent className='max-h-[90vh] overflow-y-auto sm:max-w-3xl'>
        <DialogHeader><DialogTitle>Add a section</DialogTitle><DialogDescription>It goes under the section you are editing. You can move it later.</DialogDescription></DialogHeader>
        {groups.map((g) => (
          <section key={g} className='grid gap-2'>
            <h3 className='text-sm font-semibold text-muted-foreground'>{g}</h3>
            <div className='grid gap-2 sm:grid-cols-2'>
              {types.filter((t) => t.group === g).map((t) => (
                <button key={t.key} type='button' onClick={() => { onAdd(t); setOpen(false) }}
                  className={`grid gap-0.5 rounded-md border p-3 text-start hover:border-primary ${t.off ? 'opacity-60' : ''}`}>
                  <span className='font-medium'>{t.label}</span>
                  <span className='text-xs text-muted-foreground'>{t.description}</span>
                  {(t.off || t.note) && <span className={`text-xs ${t.off ? 'text-amber-700 dark:text-amber-300' : 'text-muted-foreground'}`}>{t.off ?? t.note}</span>}
                </button>
              ))}
            </div>
          </section>
        ))}
      </DialogContent>
    </Dialog>
  )
}

export function TemplatesDialog({ templates, onUse }: { templates: HomeState['templates']; onUse: (sections: Section[], label: string) => void }) {
  const [open, setOpen] = useState(false)
  const [busy, setBusy] = useState<string | null>(null)
  async function use(key: string, label: string) {
    if (!confirm(`Use "${label}"? It replaces the sections of your draft. Undo brings them back; the live page does not change until you publish.`)) return
    setBusy(key)
    try {
      const r = await api.post<{ sections: Section[] }>(`/home-page/templates/${key}`)
      onUse(r.data.sections, label)
      setOpen(false)
    } catch (e) { toast.error(errorMessage(e)) } finally { setBusy(null) }
  }
  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild><Button type='button' variant='outline' size='sm'><LayoutTemplate /> Templates</Button></DialogTrigger>
      <DialogContent className='sm:max-w-xl'>
        <DialogHeader><DialogTitle>Ready-made layouts</DialogTitle><DialogDescription>Start from one, then change anything.</DialogDescription></DialogHeader>
        <ul className='grid gap-2'>
          {Object.entries(templates).map(([k, t]) => (
            <li key={k} className='flex items-center justify-between gap-3 rounded-md border p-3'>
              <span className='grid gap-0.5'><span className='font-medium'>{t.label}</span><span className='text-xs text-muted-foreground'>{t.description}</span></span>
              <Button type='button' size='sm' disabled={busy !== null} onClick={() => use(k, t.label)}>{busy === k ? 'Loading…' : 'Use'}</Button>
            </li>
          ))}
        </ul>
      </DialogContent>
    </Dialog>
  )
}

export function HistoryDialog({ onRestored }: { onRestored: () => void }) {
  const [open, setOpen] = useState(false)
  const [preview, setPreview] = useState<{ id: number; html: string } | null>(null)
  const list = useQuery({ queryKey: ['home-versions'], enabled: open, queryFn: async () => (await api.get<{ data: (Summary & { live: boolean })[] }>('/home-page/versions')).data.data })
  const qc = useQueryClient()
  async function show(id: number) {
    try { setPreview({ id, html: (await api.get<{ html: string }>(`/home-page/versions/${id}/preview`)).data.html }) } catch (e) { toast.error(errorMessage(e)) }
  }
  async function restore(id: number) {
    if (!confirm(`Put version #${id} back as the live home page now? Your draft is kept.`)) return
    try {
      await api.post(`/home-page/versions/${id}/restore`)
      toast.success(`Version #${id} is live again`)
      await qc.invalidateQueries({ queryKey: ['home-versions'] })
      setPreview(null)
      onRestored()
    } catch (e) { toast.error(errorMessage(e)) }
  }
  return (
    <Dialog open={open} onOpenChange={(o) => { setOpen(o); if (!o) setPreview(null) }}>
      <DialogTrigger asChild><Button type='button' variant='outline' size='sm'><History /> History</Button></DialogTrigger>
      <DialogContent className='max-h-[92vh] overflow-y-auto sm:max-w-5xl'>
        <DialogHeader><DialogTitle>Version history</DialogTitle><DialogDescription>Every publish is kept. Look at an old one, or put it back in one click.</DialogDescription></DialogHeader>
        <div className='grid gap-4 lg:grid-cols-[320px_1fr]'>
          <ul className='grid content-start gap-2'>
            {list.isLoading && <li className='text-sm text-muted-foreground'>Loading…</li>}
            {list.data?.length === 0 && <li className='text-sm text-muted-foreground'>Nothing published yet. Visitors see the fixed home page.</li>}
            {list.data?.map((v) => (
              <li key={v.id} className={`grid gap-1 rounded-md border p-3 text-sm ${preview?.id === v.id ? 'border-primary' : ''}`}>
                <span className='flex items-center gap-2 font-medium'>Version #{v.id}{v.live && <Badge>Live</Badge>}</span>
                <span className='text-xs text-muted-foreground'>{formatDate(v.published_at, true)} · {v.by ?? 'Planned publish'} · {v.sections} sections</span>
                {v.note && <span className='text-xs'>{v.note}</span>}
                <span className='mt-1 flex gap-2'>
                  <Button type='button' size='sm' variant='outline' onClick={() => show(v.id)}>Preview</Button>
                  {!v.live && <Button type='button' size='sm' onClick={() => restore(v.id)}>Restore</Button>}
                </span>
              </li>
            ))}
          </ul>
          <div>{preview ? <Preview html={preview.html} /> : <p className='rounded-md border border-dashed p-8 text-center text-sm text-muted-foreground'>Pick "Preview" to see an old version.</p>}</div>
        </div>
      </DialogContent>
    </Dialog>
  )
}

export function PublishDialog({ problems, onPublish, onSchedule, scheduled, disabled }: {
  problems: Problems; onPublish: (note: string) => Promise<void>; onSchedule: (at: string) => Promise<void>; scheduled: boolean; disabled: boolean
}) {
  const [open, setOpen] = useState(false)
  const [note, setNote] = useState('')
  const [later, setLater] = useState(false)
  const [at, setAt] = useState('')
  const [minAt, setMinAt] = useState('')
  const [busy, setBusy] = useState(false)
  const blocked = problems.errors.length > 0
  async function go() {
    setBusy(true)
    try {
      if (later) await onSchedule(new Date(at).toISOString())
      else await onPublish(note)
      setOpen(false)
      setNote('')
    } catch { /* toast shown */ } finally { setBusy(false) }
  }
  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild><Button type='button' size='sm' disabled={disabled}><Send /> Publish</Button></DialogTrigger>
      <DialogContent className='sm:max-w-lg'>
        <DialogHeader><DialogTitle>Publish the home page</DialogTitle><DialogDescription>Visitors see it right away (cached pages are cleared). The old one stays in the history.</DialogDescription></DialogHeader>
        {blocked && (
          <div role='alert' className='rounded-md border border-destructive/40 bg-destructive/10 p-3 text-sm'>
            <p className='font-medium'>Fix these first:</p>
            <ul className='mt-1 list-disc ps-5'>{problems.errors.map((p, i) => <li key={i}>{p.message}</li>)}</ul>
          </div>
        )}
        {!blocked && problems.warnings.length > 0 && (
          <div className='rounded-md border border-amber-500/40 bg-amber-500/10 p-3 text-sm'>
            <p className='font-medium'>Good to check (you can still publish):</p>
            <ul className='mt-1 list-disc ps-5'>{problems.warnings.map((p, i) => <li key={i}>{p.message}</li>)}</ul>
          </div>
        )}
        <label className='flex items-center gap-2 text-sm'><Checkbox checked={later} onCheckedChange={(c) => { setLater(Boolean(c)); setMinAt(localInput(Date.now() + 120_000)) }} /> Publish later, at a set time{scheduled && ' (replaces the planned one)'}</label>
        {later
          ? <label className='grid gap-1.5 text-sm font-medium'>Date and time<Input type='datetime-local' value={at} onChange={(e) => setAt(e.target.value)} min={minAt} /></label>
          : <label className='grid gap-1.5 text-sm font-medium'>Note for the history (optional)<Input value={note} maxLength={200} onChange={(e) => setNote(e.target.value)} placeholder='e.g. Timket sale' /></label>}
        <DialogFooter>
          <Button type='button' disabled={busy || blocked || (later && !at)} onClick={go}>{later ? <><Clock /> Plan it</> : <><Send /> Publish now</>}</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

export function SeoDialog({ seo, onChange, videoUpload }: { seo: Seo; onChange: (s: Seo) => void; videoUpload: boolean }) {
  return (
    <Dialog>
      <DialogTrigger asChild><Button type='button' variant='outline' size='sm'><SearchIcon /> Google & sharing</Button></DialogTrigger>
      <DialogContent className='sm:max-w-lg'>
        <DialogHeader><DialogTitle>Google and link previews</DialogTitle><DialogDescription>Empty = the shop name and the usual description.</DialogDescription></DialogHeader>
        <label className='grid gap-1.5 text-sm font-medium'>Page title<Input value={seo.title} maxLength={70} onChange={(e) => onChange({ ...seo, title: e.target.value })} />
          <span className='text-xs font-normal text-muted-foreground'>{seo.title.length} / 70 · shown in the browser tab and on Google</span></label>
        <label className='grid gap-1.5 text-sm font-medium'>Description<Textarea rows={3} value={seo.description} maxLength={200} onChange={(e) => onChange({ ...seo, description: e.target.value })} />
          <span className='text-xs font-normal text-muted-foreground'>{seo.description.length} / 200 · best under 160</span></label>
        <MediaField kind='image' label='Picture for Telegram, WhatsApp and Facebook links' value={seo.image} onChange={(image) => onChange({ ...seo, image })} videoUpload={videoUpload} />
      </DialogContent>
    </Dialog>
  )
}

const blankQuote = { name: '', city: '', text: '', confirmed_real: false, photo_ok: false, is_active: true, position: 0 }

/** Customer quotes: typed in by you, shown only with the "real customer" tick. */
export function QuotesDialog() {
  const qc = useQueryClient()
  const [open, setOpen] = useState(false)
  const list = useQuery({ queryKey: ['home-quotes'], enabled: open, queryFn: async () => (await api.get<{ data: Quote[] }>('/home-page/testimonials')).data.data })
  const [edit, setEdit] = useState<(typeof blankQuote & { id?: number; photoFile?: File | null; remove_photo?: boolean }) | null>(null)
  async function save() {
    if (!edit) return
    const body = new FormData()
    for (const k of ['name', 'city', 'text'] as const) body.append(k, edit[k] ?? '')
    for (const k of ['confirmed_real', 'photo_ok', 'is_active'] as const) body.append(k, edit[k] ? '1' : '0')
    body.append('position', String(edit.position ?? 0))
    if (edit.photoFile) body.append('photo', edit.photoFile)
    if (edit.remove_photo) body.append('remove_photo', '1')
    try {
      await api.post(edit.id ? `/home-page/testimonials/${edit.id}` : '/home-page/testimonials', body)
      toast.success('Quote saved')
      setEdit(null)
      await qc.invalidateQueries({ queryKey: ['home-quotes'] })
      await qc.invalidateQueries({ queryKey: ['home-options', 'testimonials'] })
    } catch (e) { toast.error(errorMessage(e)) }
  }
  async function remove(q: Quote) {
    if (!confirm(`Delete the quote from ${q.name}?`)) return
    try { await api.delete(`/home-page/testimonials/${q.id}`); await qc.invalidateQueries({ queryKey: ['home-quotes'] }) } catch (e) { toast.error(errorMessage(e)) }
  }
  return (
    <Dialog open={open} onOpenChange={(o) => { setOpen(o); if (!o) setEdit(null) }}>
      <DialogTrigger asChild><Button type='button' variant='outline' size='sm'><MessageSquareQuote /> Customer quotes</Button></DialogTrigger>
      <DialogContent className='max-h-[90vh] overflow-y-auto sm:max-w-2xl'>
        <DialogHeader><DialogTitle>Customer quotes</DialogTitle><DialogDescription>Only real words from real customers. A quote shows only after you tick "A real customer said this".</DialogDescription></DialogHeader>
        {edit ? (
          <div className='grid gap-3'>
            <div className='grid gap-3 sm:grid-cols-2'>
              <label className='grid gap-1.5 text-sm font-medium'>Name (as they want it shown)<Input value={edit.name} maxLength={80} onChange={(e) => setEdit({ ...edit, name: e.target.value })} /></label>
              <label className='grid gap-1.5 text-sm font-medium'>Town<Input value={edit.city ?? ''} maxLength={80} onChange={(e) => setEdit({ ...edit, city: e.target.value })} /></label>
            </div>
            <label className='grid gap-1.5 text-sm font-medium'>What they said<Textarea rows={4} maxLength={600} value={edit.text} onChange={(e) => setEdit({ ...edit, text: e.target.value })} /></label>
            <label className='grid gap-1.5 text-sm font-medium'>Photo (optional)<Input type='file' accept='image/jpeg,image/png,image/webp' onChange={(e) => setEdit({ ...edit, photoFile: e.target.files?.[0] ?? null })} /></label>
            <label className='flex items-start gap-2 text-sm'><Checkbox checked={edit.photo_ok} onCheckedChange={(c) => setEdit({ ...edit, photo_ok: Boolean(c) })} /> They said yes to showing their photo</label>
            <label className='flex items-start gap-2 text-sm font-medium'><Checkbox checked={edit.confirmed_real} onCheckedChange={(c) => setEdit({ ...edit, confirmed_real: Boolean(c) })} /> A real customer said this (required to show it)</label>
            <label className='flex items-start gap-2 text-sm'><Checkbox checked={edit.is_active} onCheckedChange={(c) => setEdit({ ...edit, is_active: Boolean(c) })} /> Show it</label>
            <DialogFooter><Button type='button' variant='ghost' onClick={() => setEdit(null)}>Back</Button><Button type='button' onClick={save} disabled={!edit.name.trim() || !edit.text.trim()}>Save</Button></DialogFooter>
          </div>
        ) : (
          <>
            <Button type='button' onClick={() => setEdit({ ...blankQuote })} className='justify-self-start'><Plus /> Add a quote</Button>
            <ul className='grid gap-2'>
              {list.data?.length === 0 && <li className='text-sm text-muted-foreground'>No quotes yet.</li>}
              {list.data?.map((q) => (
                <li key={q.id} className='flex items-start gap-3 rounded-md border p-3 text-sm'>
                  {q.photo && <img src={q.photo} alt='' className='size-10 rounded-full object-cover' />}
                  <div className='grid min-w-0 flex-1 gap-0.5'>
                    <span className='font-medium'>{q.name}{q.city && <span className='font-normal text-muted-foreground'> · {q.city}</span>}{q.is_demo && <Badge variant='secondary' className='ms-2'>Demo</Badge>}</span>
                    <span className='line-clamp-2 text-muted-foreground'>“{q.text}”</span>
                    {q.not_shown.length > 0 && <span className='text-xs text-amber-700 dark:text-amber-300'>{q.not_shown.join(' · ')}</span>}
                  </div>
                  {!q.is_demo && <Button type='button' size='sm' variant='outline' onClick={() => setEdit({ ...q, city: q.city ?? '' })}>Edit</Button>}
                  <Button type='button' size='icon' variant='ghost' aria-label={`Delete the quote from ${q.name}`} onClick={() => remove(q)}><Trash2 /></Button>
                </li>
              ))}
            </ul>
          </>
        )}
      </DialogContent>
    </Dialog>
  )
}

/** A time as the value of an <input type="datetime-local"> (the computer's own time zone). */
function localInput(ms: number): string {
  return new Date(ms - new Date(ms).getTimezoneOffset() * 60_000).toISOString().slice(0, 16)
}
