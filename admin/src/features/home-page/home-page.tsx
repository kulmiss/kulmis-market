import { useCallback, useEffect, useMemo, useRef, useState, useSyncExternalStore } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useBlocker } from '@tanstack/react-router'
import { AxiosError } from 'axios'
import { CalendarClock, ExternalLink, Redo2, RotateCcw, TriangleAlert, Undo2 } from 'lucide-react'
import { toast } from 'sonner'
import { api, errorMessage } from '@/lib/api'
import { formatDate } from '@/lib/money'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { Page } from '@/components/km/page'
import { PageSkeleton } from '@/components/km/page-skeleton'
import { AddSectionDialog, HistoryDialog, PublishDialog, QuotesDialog, SeoDialog, TemplatesDialog } from './dialogs'
import { Fields } from './field-editor'
import { Preview } from './preview'
import { SectionList } from './section-list'
import { newId, type HomeState, type Problems, type Section, type SectionType, type Seo } from './types'
import { useHistory } from './use-history'

type Doc = { sections: Section[]; seo: Seo }
type SaveState = 'saved' | 'saving' | 'dirty' | 'error' | 'conflict'

const homeQuery = { queryKey: ['home-page'], queryFn: async () => (await api.get<HomeState>('/home-page')).data }

export function HomePageBuilder() {
  const q = useQuery({ ...homeQuery, refetchOnWindowFocus: false })
  if (q.isLoading || !q.data) return <PageSkeleton />
  return <Editor initial={q.data} />
}

function Editor({ initial }: { initial: HomeState }) {
  const qc = useQueryClient()
  const [state, setState] = useState(initial)
  const doc = useHistory<Doc>({ sections: initial.draft.sections, seo: initial.draft.seo })
  const [selected, setSelected] = useState<string | null>(initial.draft.sections[0]?.id ?? null)
  const [problems, setProblems] = useState<Problems>(initial.problems)
  const [save, setSave] = useState<SaveState>('saved')
  const [savedAt, setSavedAt] = useState<string | null>(initial.draft.updated_at)
  const [hasDraft, setHasDraft] = useState(initial.draft.saved)
  const [tab, setTab] = useState<'sections' | 'edit' | 'preview'>('sections')
  const wide = useWide()
  const revision = useRef(initial.draft.revision)
  const lastSent = useRef(JSON.stringify({ sections: initial.draft.sections, seo: initial.draft.seo }))
  const types = useMemo(() => new Map(state.types.map((t) => [t.key, t])), [state.types])
  const current = doc.value.sections.find((s) => s.id === selected) ?? null
  const currentType = current ? types.get(current.type) : undefined

  // Autosave a moment after the last change.
  const flush = useCallback(async (value: Doc) => {
    const body = JSON.stringify(value)
    if (body === lastSent.current) { setSave('saved'); return }
    setSave('saving')
    try {
      const r = await api.put<{ revision: number; updated_at: string; problems: Problems }>('/home-page/draft', { ...value, revision: revision.current })
      revision.current = r.data.revision
      lastSent.current = body
      setProblems(r.data.problems)
      setSavedAt(r.data.updated_at)
      setHasDraft(true)
      setSave('saved')
    } catch (e) {
      if (e instanceof AxiosError && e.response?.status === 409) setSave('conflict')
      else { setSave('error'); toast.error(`Not saved: ${errorMessage(e)}`) }
    }
  }, [])
  useEffect(() => {
    if (save === 'conflict') return
    if (JSON.stringify(doc.value) === lastSent.current) return
    setSave('dirty')
    const t = setTimeout(() => flush(doc.value), 1200)
    return () => clearTimeout(t)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [doc.value, flush])

  // Warn before leaving with changes that are not saved yet.
  const unsaved = save === 'dirty' || save === 'saving' || save === 'error' || save === 'conflict'
  useEffect(() => {
    const h = (e: BeforeUnloadEvent) => { if (unsaved) { e.preventDefault(); e.returnValue = '' } }
    window.addEventListener('beforeunload', h)
    return () => window.removeEventListener('beforeunload', h)
  }, [unsaved])
  useBlocker({ shouldBlockFn: () => unsaved && !confirm('Some changes are not saved yet. Leave anyway?'), enableBeforeUnload: false })

  // Keyboard: Ctrl/⌘+Z undo, Ctrl/⌘+Shift+Z or Ctrl+Y redo (not while typing in a field: the field has its own undo).
  useEffect(() => {
    const h = (e: KeyboardEvent) => {
      const t = e.target as HTMLElement
      if (!(e.ctrlKey || e.metaKey) || t.closest('input, textarea, [contenteditable]')) return
      if (e.key.toLowerCase() === 'z' && !e.shiftKey) { e.preventDefault(); doc.undo() }
      if ((e.key.toLowerCase() === 'z' && e.shiftKey) || e.key.toLowerCase() === 'y') { e.preventDefault(); doc.redo() }
    }
    window.addEventListener('keydown', h)
    return () => window.removeEventListener('keydown', h)
  }, [doc])

  const setSections = (sections: Section[], group?: string) => doc.set((d) => ({ ...d, sections }), group)
  const setField = (key: string, value: unknown) => {
    if (!current) return
    doc.set((d) => ({ ...d, sections: d.sections.map((s) => (s.id === current.id ? { ...s, data: { ...s.data, [key]: value } } : s)) }), `${current.id}.${key}`)
  }
  const add = (t: SectionType) => {
    const s: Section = { id: newId(), type: t.key, on: true, data: structuredClone(t.defaults) }
    const i = doc.value.sections.findIndex((x) => x.id === selected)
    setSections(i < 0 ? [...doc.value.sections, s] : [...doc.value.sections.slice(0, i + 1), s, ...doc.value.sections.slice(i + 1)])
    setSelected(s.id)
    setTab('edit')
  }

  async function reload(message?: string) {
    const fresh = (await qc.fetchQuery({ ...homeQuery, staleTime: 0 }))
    setState(fresh)
    doc.reset({ sections: fresh.draft.sections, seo: fresh.draft.seo })
    revision.current = fresh.draft.revision
    lastSent.current = JSON.stringify({ sections: fresh.draft.sections, seo: fresh.draft.seo })
    setProblems(fresh.problems)
    setHasDraft(fresh.draft.saved)
    setSavedAt(fresh.draft.updated_at)
    setSave('saved')
    if (!fresh.draft.sections.some((s) => s.id === selected)) setSelected(fresh.draft.sections[0]?.id ?? null)
    if (message) toast.success(message)
  }

  async function publish(note: string) {
    await flush(doc.value)
    try {
      await api.post('/home-page/publish', { note: note || null })
      await reload('Published. Visitors see the new home page now.')
    } catch (e) { toast.error(errorMessage(e)); throw e }
  }
  async function schedule(at: string) {
    await flush(doc.value)
    try {
      await api.post('/home-page/schedule', { publish_at: at })
      await reload(`Planned for ${formatDate(at, true)}`)
    } catch (e) { toast.error(errorMessage(e)); throw e }
  }
  async function cancelSchedule() {
    try { await api.delete('/home-page/schedule'); await reload('The planned publish is cancelled. It is your draft again.') } catch (e) { toast.error(errorMessage(e)) }
  }
  async function discard() {
    if (!confirm('Throw away the draft and go back to the live page? This cannot be undone.')) return
    try { await api.delete('/home-page/draft'); await reload('Draft discarded') } catch (e) { toast.error(errorMessage(e)) }
  }

  const status = { saved: savedAt ? `Draft saved ${formatDate(savedAt, true)}` : hasDraft ? 'Draft saved' : 'No changes yet', saving: 'Saving…', dirty: 'Not saved yet…', error: 'Not saved', conflict: 'Changed in another window' }[save]
  const sectionsPane = (
    <div className='grid content-start gap-3'>
      <AddSectionDialog types={state.types} onAdd={add} />
      <SectionList sections={doc.value.sections} types={types} selected={selected} problems={problems.errors}
        onSelect={(id) => { setSelected(id); setTab('edit') }} onChange={(s) => setSections(s)} />
      <ProblemsBox problems={problems} onSelect={(id) => { setSelected(id); setTab('edit') }} />
    </div>
  )
  const editPane = current && currentType ? (
    <div className='grid content-start gap-4'>
      <div>
        <h2 className='text-lg font-semibold'>{currentType.label}</h2>
        <p className='text-sm text-muted-foreground'>{currentType.description}</p>
        {currentType.off && <p className='mt-1 text-sm text-amber-700 dark:text-amber-300'>{currentType.off} It is greyed out and not shown until then.</p>}
        {currentType.note && <p className='mt-1 text-sm text-muted-foreground'>{currentType.note}</p>}
        {!current.on && <p className='mt-1 text-sm text-amber-700 dark:text-amber-300'>This section is switched off: it is not on the page.</p>}
      </div>
      {Object.keys(currentType.fields).length === 0 ? <p className='text-sm text-muted-foreground'>Nothing to set here.</p>
        : <Fields fields={currentType.fields} data={current.data} onChange={setField} ctx={{ state, problems: [...problems.errors, ...problems.warnings], sectionId: current.id }} />}
    </div>
  ) : <p className='rounded-md border border-dashed p-6 text-center text-sm text-muted-foreground'>Pick a section to edit it.</p>
  const previewPane = <Preview sections={doc.value.sections} seo={doc.value.seo} selected={selected} />

  return (
    <Page title='Home page' description='Build the front page of the shop. Changes are saved as a draft; visitors see them after you publish.'
      actions={<>
        <span className='self-center text-xs text-muted-foreground' aria-live='polite'>{status}</span>
        <Button type='button' size='icon' variant='outline' onClick={doc.undo} disabled={!doc.canUndo} aria-label='Undo' title='Undo (Ctrl+Z)'><Undo2 /></Button>
        <Button type='button' size='icon' variant='outline' onClick={doc.redo} disabled={!doc.canRedo} aria-label='Redo' title='Redo (Ctrl+Shift+Z)'><Redo2 /></Button>
        <TemplatesDialog templates={state.templates} onUse={(sections, label) => { setSections(sections); setSelected(sections[0]?.id ?? null); toast.success(`"${label}" is in your draft. Undo brings back what you had.`) }} />
        <SeoDialog seo={doc.value.seo} onChange={(seo) => doc.set((d) => ({ ...d, seo }), 'seo')} videoUpload={state.video_upload} />
        <QuotesDialog />
        <HistoryDialog onRestored={() => reload()} />
        <Button type='button' size='sm' variant='outline' asChild><a href='/' target='_blank' rel='noreferrer'><ExternalLink /> Live page</a></Button>
        {hasDraft && <Button type='button' size='sm' variant='ghost' onClick={discard}><RotateCcw /> Discard draft</Button>}
        <PublishDialog problems={problems} onPublish={publish} onSchedule={schedule} scheduled={Boolean(state.scheduled)} disabled={save === 'conflict'} />
      </>}>
      {!state.enabled && (
        <Alert className='mb-4'><TriangleAlert /><AlertTitle>The "Home page builder" switch is off</AlertTitle>
          <AlertDescription>Visitors see the fixed home page. You can still build and publish; it shows when you turn the switch on in <Link to='/features' className='underline'>Switches</Link>.</AlertDescription></Alert>
      )}
      {save === 'conflict' && (
        <Alert variant='destructive' className='mb-4'><TriangleAlert /><AlertTitle>The draft was changed in another window</AlertTitle>
          <AlertDescription><span>To keep both safe, nothing more is saved here. <Button type='button' size='sm' variant='outline' className='ms-2' onClick={() => reload()}>Load the newest draft</Button></span></AlertDescription></Alert>
      )}
      {state.scheduled && (
        <Alert className='mb-4'><CalendarClock /><AlertTitle>Planned publish: {formatDate(state.scheduled.publish_at, true)}</AlertTitle>
          <AlertDescription><span>It goes live by itself.{hasDraft ? ' Your draft below is newer and is not part of it.' : ''} <Button type='button' size='sm' variant='outline' className='ms-2' onClick={cancelSchedule}>Cancel the plan</Button></span></AlertDescription></Alert>
      )}
      <p className='mb-3 text-sm text-muted-foreground'>
        {state.live ? <>Live: version #{state.live.id}, published {formatDate(state.live.published_at, true)}{state.live.by ? ` by ${state.live.by}` : ''}.</> : <>Nothing published yet: visitors see the fixed home page. The draft starts as a copy of it.</>}
      </p>

      {/* Phone and tablet: one part at a time. Desktop: list, side panel and preview side by side. */}
      {!wide ? <div>
        <Tabs value={tab} onValueChange={(v) => setTab(v as typeof tab)}>
          <TabsList className='mb-3 grid w-full grid-cols-3'>
            <TabsTrigger value='sections'>Sections</TabsTrigger><TabsTrigger value='edit'>Edit</TabsTrigger><TabsTrigger value='preview'>Preview</TabsTrigger>
          </TabsList>
        </Tabs>
        {tab === 'sections' && sectionsPane}
        {tab === 'edit' && editPane}
        {tab === 'preview' && previewPane}
      </div> : <div className='grid grid-cols-[280px_360px_minmax(0,1fr)] gap-4'>
        <div className='max-h-[calc(100vh-11rem)] overflow-y-auto pe-1'>{sectionsPane}</div>
        <div className='max-h-[calc(100vh-11rem)] overflow-y-auto rounded-md border p-4'>{editPane}</div>
        <div className='max-h-[calc(100vh-11rem)] overflow-y-auto' data-preview-scroll>{previewPane}</div>
      </div>}
    </Page>
  )
}

/** Wide enough for the three columns side by side (1024 px and up)? */
function useWide(): boolean {
  return useSyncExternalStore(
    (cb) => { const m = window.matchMedia('(min-width: 1024px)'); m.addEventListener('change', cb); return () => m.removeEventListener('change', cb) },
    () => window.matchMedia('(min-width: 1024px)').matches,
  )
}

function ProblemsBox({ problems, onSelect }: { problems: Problems; onSelect: (id: string) => void }) {
  if (problems.errors.length + problems.warnings.length === 0) return <p className='text-xs text-muted-foreground'>No problems found. Ready to publish.</p>
  const item = (p: Problems['errors'][number], i: number, error: boolean) => (
    <li key={i}>
      {p.section && p.section !== 'seo'
        ? <button type='button' className={`text-start underline-offset-2 hover:underline ${error ? 'text-destructive' : 'text-amber-700 dark:text-amber-300'}`} onClick={() => onSelect(p.section!)}>{p.message}</button>
        : <span className={error ? 'text-destructive' : 'text-amber-700 dark:text-amber-300'}>{p.message}</span>}
    </li>
  )
  return (
    <div className='grid gap-2 rounded-md border p-3 text-xs' aria-live='polite'>
      {problems.errors.length > 0 && <><p className='font-semibold'>To fix before publishing</p><ul className='grid gap-1'>{problems.errors.map((p, i) => item(p, i, true))}</ul></>}
      {problems.warnings.length > 0 && <><p className='font-semibold'>Good to check</p><ul className='grid gap-1'>{problems.warnings.map((p, i) => item(p, i, false))}</ul></>}
    </div>
  )
}
