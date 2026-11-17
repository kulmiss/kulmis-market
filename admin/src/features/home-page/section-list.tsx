import { closestCenter, DndContext, KeyboardSensor, PointerSensor, TouchSensor, useSensor, useSensors, type DragEndEvent } from '@dnd-kit/core'
import { arrayMove, SortableContext, sortableKeyboardCoordinates, useSortable, verticalListSortingStrategy } from '@dnd-kit/sortable'
import { CSS } from '@dnd-kit/utilities'
import { ArrowDown, ArrowUp, Copy, EllipsisVertical, EyeOff, GripVertical, Trash2, TriangleAlert } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { Switch } from '@/components/ui/switch'
import { newId, summaryOf, type Problem, type Section, type SectionType } from './types'

/** The sections in page order: drag (or use the menu / keyboard) to move, switch on/off, duplicate, delete. */
export function SectionList({ sections, types, selected, problems, onSelect, onChange }: {
  sections: Section[]; types: Map<string, SectionType>; selected: string | null; problems: Problem[]
  onSelect: (id: string) => void; onChange: (s: Section[]) => void
}) {
  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 6 } }),
    useSensor(TouchSensor, { activationConstraint: { delay: 180, tolerance: 6 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
  )
  const onDragEnd = (e: DragEndEvent) => {
    if (!e.over || e.active.id === e.over.id) return
    const from = sections.findIndex((s) => s.id === e.active.id)
    const to = sections.findIndex((s) => s.id === e.over?.id)
    onChange(arrayMove(sections, from, to))
  }
  const move = (i: number, d: number) => onChange(arrayMove(sections, i, i + d))

  if (sections.length === 0) return <p className='rounded-md border border-dashed p-6 text-center text-sm text-muted-foreground'>No sections yet. Add one, or pick a template.</p>

  return (
    <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={onDragEnd}>
      <SortableContext items={sections.map((s) => s.id)} strategy={verticalListSortingStrategy}>
        <ol className='grid gap-1.5' aria-label='Sections, in page order'>
          {sections.map((s, i) => (
            <Row key={s.id} s={s} t={types.get(s.type)} active={s.id === selected} first={i === 0} last={i === sections.length - 1}
              hasError={problems.some((p) => p.section === s.id)}
              onSelect={() => onSelect(s.id)}
              onToggle={(on) => onChange(sections.map((x) => (x.id === s.id ? { ...x, on } : x)))}
              onUp={() => move(i, -1)} onDown={() => move(i, 1)}
              onDuplicate={() => { const copy = { ...structuredClone(s), id: newId() }; onChange([...sections.slice(0, i + 1), copy, ...sections.slice(i + 1)]); onSelect(copy.id) }}
              onDelete={() => { if (confirm(`Delete the "${types.get(s.type)?.label ?? s.type}" section? (Undo brings it back.)`)) onChange(sections.filter((x) => x.id !== s.id)) }}
            />
          ))}
        </ol>
      </SortableContext>
    </DndContext>
  )
}

function Row({ s, t, active, first, last, hasError, onSelect, onToggle, onUp, onDown, onDuplicate, onDelete }: {
  s: Section; t?: SectionType; active: boolean; first: boolean; last: boolean; hasError: boolean
  onSelect: () => void; onToggle: (on: boolean) => void; onUp: () => void; onDown: () => void; onDuplicate: () => void; onDelete: () => void
}) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id: s.id })
  const label = t?.label ?? s.type
  const greyed = !s.on || Boolean(t?.off)
  return (
    <li ref={setNodeRef} style={{ transform: CSS.Transform.toString(transform), transition }}
      className={`flex items-center gap-1 rounded-md border bg-card ps-1 pe-1 ${active ? 'border-primary ring-1 ring-primary' : ''} ${isDragging ? 'z-10 shadow-lg' : ''}`}>
      <button type='button' {...attributes} {...listeners} className='flex h-11 w-7 shrink-0 cursor-grab touch-none items-center justify-center text-muted-foreground active:cursor-grabbing' aria-label={`Move ${label} (drag, or Space then arrow keys)`}>
        <GripVertical className='size-4' />
      </button>
      <button type='button' onClick={onSelect} className={`grid min-w-0 flex-1 py-2 text-start ${greyed ? 'opacity-55' : ''}`} aria-current={active ? 'true' : undefined}>
        <span className='flex items-center gap-1.5 text-sm font-medium'>
          {label}{!s.on && <EyeOff className='size-3.5' aria-label='switched off' />}{hasError && <TriangleAlert className='size-3.5 text-destructive' aria-label='needs fixing' />}
        </span>
        <span className='truncate text-xs text-muted-foreground'>{t?.off ?? summaryOf(s) ?? ''}</span>
      </button>
      <Switch checked={s.on} onCheckedChange={onToggle} aria-label={`Show ${label}`} />
      <DropdownMenu>
        <DropdownMenuTrigger asChild><Button type='button' size='icon' variant='ghost' className='size-9' aria-label={`More for ${label}`}><EllipsisVertical /></Button></DropdownMenuTrigger>
        <DropdownMenuContent align='end'>
          <DropdownMenuItem disabled={first} onClick={onUp}><ArrowUp /> Move up</DropdownMenuItem>
          <DropdownMenuItem disabled={last} onClick={onDown}><ArrowDown /> Move down</DropdownMenuItem>
          <DropdownMenuItem onClick={onDuplicate}><Copy /> Duplicate</DropdownMenuItem>
          <DropdownMenuSeparator />
          <DropdownMenuItem variant='destructive' onClick={onDelete}><Trash2 /> Delete</DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>
    </li>
  )
}
