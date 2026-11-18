import { useQuery } from '@tanstack/react-query'
import { ArrowDown, ArrowUp, Braces, Plus, Trash2, TriangleAlert, X } from 'lucide-react'
import { api } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Input } from '@/components/ui/input'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Switch } from '@/components/ui/switch'
import { Textarea } from '@/components/ui/textarea'
import { OffFeatureNote } from '@/components/km/off-feature-note'
import { MediaField } from './media-picker'
import { ACCENTS, bestContrast, type Data, type Field, type HomeState, type Problem } from './types'

type Ctx = { state: HomeState; problems: Problem[]; sectionId: string }

/** All fields of a section (or of one item in a list), from the schema the server sends. */
export function Fields({ fields, data, onChange, ctx, path = '' }: {
  fields: Record<string, Field>; data: Data; onChange: (key: string, value: unknown) => void; ctx: Ctx; path?: string
}) {
  return (
    <div className='grid gap-4'>
      {Object.entries(fields).map(([key, f]) => {
        if (f.when && data[f.when[0]] !== f.when[1]) return null
        const msgs = ctx.problems.filter((p) => p.section === ctx.sectionId && p.field === path + key)
        return (
          <div key={key} className='grid gap-1.5'>
            <FieldInput name={key} field={f} value={data[key]} onChange={(v) => onChange(key, v)} ctx={ctx} path={path + key}
              onPoster={key === 'video' && 'poster' in fields ? (id) => { if (data.poster == null) onChange('poster', id) } : undefined} />
            {msgs.map((m, i) => <p key={i} role='alert' className='flex items-start gap-1.5 text-xs text-destructive'><TriangleAlert className='mt-0.5 size-3.5 shrink-0' />{m.message}</p>)}
          </div>
        )
      })}
    </div>
  )
}

function FieldInput({ name, field: f, value, onChange, ctx, path, onPoster }: {
  name: string; field: Field; value: unknown; onChange: (v: unknown) => void; ctx: Ctx; path: string; onPoster?: (id: number) => void
}) {
  const id = `f-${ctx.sectionId}-${path}`
  const label = <label htmlFor={id} className='text-sm font-medium'>{f.label}{f.required && <span className='text-destructive'> *</span>}</label>
  switch (f.type) {
    case 'text':
    case 'textarea': {
      const v = typeof value === 'string' ? value : ''
      return (
        <>
          <div className='flex items-end justify-between gap-2'>{label}<TokenButton onPick={(t) => onChange(v + t)} tokens={ctx.state.tokens} /></div>
          {f.type === 'text'
            ? <Input id={id} value={v} maxLength={f.max} onChange={(e) => onChange(e.target.value)} />
            : <Textarea id={id} value={v} maxLength={f.max} rows={4} onChange={(e) => onChange(e.target.value)} />}
          {f.max && v.length > f.max * 0.8 && <span className='text-end text-xs text-muted-foreground'>{v.length} / {f.max}</span>}
          <OffFeatureNote text={v} hidden='This text is left out of the page while it is off.' />
        </>
      )
    }
    case 'link':
    case 'video_url':
      return (
        <>
          {label}
          <Input id={id} value={typeof value === 'string' ? value : ''} inputMode='url' placeholder={f.type === 'link' ? '/shop or https://…' : 'https://youtu.be/… or https://vimeo.com/…'} onChange={(e) => onChange(e.target.value.trim())} />
          {f.type === 'link' && <span className='text-xs text-muted-foreground'>{ctx.state.link_help}</span>}
        </>
      )
    case 'select':
      return (
        <>
          {label}
          <Select value={String(value ?? '')} onValueChange={onChange}>
            <SelectTrigger id={id} className='w-full'><SelectValue /></SelectTrigger>
            <SelectContent>{Object.entries(f.options ?? {}).map(([k, l]) => <SelectItem key={k} value={k}>{l}</SelectItem>)}</SelectContent>
          </Select>
        </>
      )
    case 'toggle':
      return <label className='flex min-h-10 items-center justify-between gap-3 text-sm font-medium'>{f.label}<Switch checked={Boolean(value)} onCheckedChange={onChange} /></label>
    case 'number':
      return (
        <>
          {label}
          <Input id={id} type='number' inputMode='numeric' min={f.min} max={f.max} value={Number(value ?? f.min ?? 0)}
            onChange={(e) => onChange(Math.max(f.min ?? 0, Math.min(f.max ?? 100, Number(e.target.value) || (f.min ?? 0))))} className='w-28' />
        </>
      )
    case 'image':
    case 'video':
      return <MediaField kind={f.type} label={f.label} value={typeof value === 'number' ? value : null} onChange={onChange} videoUpload={ctx.state.video_upload} onPoster={onPoster} />
    case 'color':
      return <ColorField label={f.label} value={String(value ?? 'none')} onChange={onChange} />
    case 'checks': {
      const list = Array.isArray(value) ? (value as string[]) : []
      return (
        <fieldset className='grid gap-1'>
          <legend className='mb-1 text-sm font-medium'>{f.label}</legend>
          {Object.entries(f.options ?? {}).map(([k, l]) => (
            <label key={k} className='flex min-h-9 items-center gap-2 text-sm'>
              <Checkbox checked={list.includes(k)} onCheckedChange={(c) => onChange(c ? [...list, k] : list.filter((x) => x !== k))} />{l}
            </label>
          ))}
        </fieldset>
      )
    }
    case 'categories':
    case 'products':
    case 'faqs':
    case 'testimonials':
      return <PickField kind={f.type} label={f.label} max={f.max ?? 24} value={Array.isArray(value) ? (value as number[]) : []} onChange={onChange} />
    case 'list':
      return <ListField name={name} field={f} value={Array.isArray(value) ? (value as Data[]) : []} onChange={onChange} ctx={ctx} path={path} />
  }
}

function TokenButton({ tokens, onPick }: { tokens: HomeState['tokens']; onPick: (t: string) => void }) {
  return (
    <Popover>
      <PopoverTrigger asChild><Button type='button' size='sm' variant='ghost' className='h-7 px-2 text-xs'><Braces className='size-3.5' /> Live number</Button></PopoverTrigger>
      <PopoverContent align='end' className='max-h-80 w-80 overflow-y-auto p-1'>
        <p className='px-2 py-1 text-xs text-muted-foreground'>Adds a word that always shows today's number from Settings.</p>
        {tokens.map((t) => (
          <button key={t.token} type='button' onClick={() => onPick(t.token)} className='flex w-full items-baseline justify-between gap-2 rounded px-2 py-1.5 text-start text-xs hover:bg-muted'>
            <span>{t.label}</span><span className='shrink-0 font-mono text-muted-foreground'>{t.value}</span>
          </button>
        ))}
      </PopoverContent>
    </Popover>
  )
}

function ColorField({ label, value, onChange }: { label: string; value: string; onChange: (v: string) => void }) {
  const custom = value.startsWith('#')
  const contrast = custom ? bestContrast(value) : 21
  return (
    <fieldset>
      <legend className='mb-1.5 text-sm font-medium'>{label}</legend>
      <div className='flex flex-wrap items-center gap-2'>
        {Object.entries(ACCENTS).map(([k, a]) => (
          <button key={k} type='button' onClick={() => onChange(k)} aria-pressed={value === k} title={a.label}
            className={`flex h-9 items-center gap-1.5 rounded-full border px-2.5 text-xs ${value === k ? 'ring-2 ring-primary' : ''}`}>
            <span className='size-4 rounded-full border' style={{ background: a.swatch }} />{a.label}
          </button>
        ))}
        <label className={`flex h-9 items-center gap-1.5 rounded-full border px-2.5 text-xs ${custom ? 'ring-2 ring-primary' : ''}`}>
          <input type='color' value={custom ? value : '#1f8a4c'} onChange={(e) => onChange(e.target.value.toLowerCase())} className='size-5 cursor-pointer rounded-full border-0 bg-transparent p-0' aria-label='My own colour' />
          My colour
        </label>
      </div>
      {custom && contrast < 4.5 && <p role='alert' className='mt-1.5 flex items-center gap-1.5 text-xs text-amber-700 dark:text-amber-300'><TriangleAlert className='size-3.5' /> Text on this colour is hard to read ({contrast.toFixed(1)} : 1, needs 4.5). Pick a darker or lighter one.</p>}
    </fieldset>
  )
}

function PickField({ kind, label, max, value, onChange }: { kind: string; label: string; max: number; value: number[]; onChange: (v: number[]) => void }) {
  const opts = useQuery({ queryKey: ['home-options', kind], queryFn: async () => (await api.get<{ data: { id: number; label: string }[] }>(`/home-page/options/${kind}`)).data.data })
  const byId = new Map((opts.data ?? []).map((o) => [o.id, o.label]))
  const left = (opts.data ?? []).filter((o) => !value.includes(o.id))
  const move = (i: number, d: number) => { const n = [...value]; [n[i], n[i + d]] = [n[i + d], n[i]]; onChange(n) }
  return (
    <div className='grid gap-1.5'>
      <span className='text-sm font-medium'>{label}</span>
      {value.length > 0 && (
        <ol className='grid gap-1'>
          {value.map((id, i) => (
            <li key={id} className='flex items-center gap-1 rounded-md border px-2 py-1 text-sm'>
              <span className='min-w-0 flex-1 truncate'>{byId.get(id) ?? `#${id} (gone)`}</span>
              <Button type='button' size='icon' variant='ghost' className='size-8' disabled={i === 0} onClick={() => move(i, -1)} aria-label='Move up'><ArrowUp /></Button>
              <Button type='button' size='icon' variant='ghost' className='size-8' disabled={i === value.length - 1} onClick={() => move(i, 1)} aria-label='Move down'><ArrowDown /></Button>
              <Button type='button' size='icon' variant='ghost' className='size-8' onClick={() => onChange(value.filter((x) => x !== id))} aria-label='Take out'><X /></Button>
            </li>
          ))}
        </ol>
      )}
      {value.length < max && left.length > 0 && (
        <Select value='' onValueChange={(v) => onChange([...value, Number(v)])}>
          <SelectTrigger className='w-full'><SelectValue placeholder='Add…' /></SelectTrigger>
          <SelectContent>{left.map((o) => <SelectItem key={o.id} value={String(o.id)}>{o.label}</SelectItem>)}</SelectContent>
        </Select>
      )}
      {opts.data && opts.data.length === 0 && <span className='text-xs text-muted-foreground'>Nothing to pick yet.</span>}
    </div>
  )
}

function ListField({ name, field, value, onChange, ctx, path }: { name: string; field: Field; value: Data[]; onChange: (v: Data[]) => void; ctx: Ctx; path: string }) {
  const sub = field.fields ?? {}
  const blank = (): Data => Object.fromEntries(Object.entries(sub).map(([k, f]) => [k, f.type === 'image' ? null : f.type === 'select' ? Object.keys(f.options ?? {})[0] : '']))
  const move = (i: number, d: number) => { const n = [...value]; [n[i], n[i + d]] = [n[i + d], n[i]]; onChange(n) }
  return (
    <fieldset className='grid gap-2'>
      <legend className='mb-1 text-sm font-medium'>{field.label}</legend>
      {value.map((item, i) => (
        <div key={i} className='rounded-md border p-3'>
          <div className='mb-2 flex items-center justify-between'>
            <span className='text-xs font-semibold text-muted-foreground'>{i + 1}</span>
            <div className='flex'>
              <Button type='button' size='icon' variant='ghost' className='size-8' disabled={i === 0} onClick={() => move(i, -1)} aria-label='Move up'><ArrowUp /></Button>
              <Button type='button' size='icon' variant='ghost' className='size-8' disabled={i === value.length - 1} onClick={() => move(i, 1)} aria-label='Move down'><ArrowDown /></Button>
              <Button type='button' size='icon' variant='ghost' className='size-8' onClick={() => onChange(value.filter((_, j) => j !== i))} aria-label={`Delete ${name} ${i + 1}`}><Trash2 /></Button>
            </div>
          </div>
          <Fields fields={sub} data={item} ctx={ctx} path={`${path}.${i}.`} onChange={(k, v) => onChange(value.map((x, j) => (j === i ? { ...x, [k]: v } : x)))} />
        </div>
      ))}
      {value.length < (field.max ?? 12) && <Button type='button' variant='outline' size='sm' onClick={() => onChange([...value, blank()])}><Plus /> Add</Button>}
    </fieldset>
  )
}
