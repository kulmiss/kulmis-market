import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'

export function FilterBar({ children }: { children: React.ReactNode }) {
  return <div className='mb-3 flex flex-wrap items-center gap-2'>{children}</div>
}

export function SearchInput({ value, onChange, placeholder = 'Search…' }: { value: string; onChange: (v: string) => void; placeholder?: string }) {
  return <Input className='h-9 w-full sm:w-56' placeholder={placeholder} value={value} onChange={(e) => onChange(e.target.value)} />
}

export function SelectFilter({ value, onChange, options, placeholder }: {
  value: string; onChange: (v: string) => void; options: { value: string; label: string }[]; placeholder: string
}) {
  return (
    <Select value={value} onValueChange={onChange}>
      <SelectTrigger className='h-9 w-full sm:w-44'><SelectValue placeholder={placeholder} /></SelectTrigger>
      <SelectContent>
        <SelectItem value='all'>{placeholder}: all</SelectItem>
        {options.map((o) => <SelectItem key={o.value} value={o.value}>{o.label}</SelectItem>)}
      </SelectContent>
    </Select>
  )
}

export const opts = (...values: string[]) => values.map((v) => ({ value: v, label: v.replaceAll('_', ' ') }))
