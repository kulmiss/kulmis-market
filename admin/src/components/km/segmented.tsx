import { cn } from '@/lib/utils'

/**
 * One choice out of a few (like "Real data / Demo data"). Looks like tabs, but it is a
 * group of buttons with aria-pressed: real tabs need a panel for each choice, these filter one list.
 */
export function Segmented<T extends string>({ value, onChange, options, label, className, itemClassName }: {
  value: T; onChange: (v: T) => void; options: { value: T; label: string }[]; label: string; className?: string; itemClassName?: string
}) {
  return (
    <div role='group' aria-label={label} className={cn('inline-flex h-12 w-fit items-center rounded-lg bg-muted p-0.75 text-muted-foreground sm:h-9', className)}>
      {options.map((o) => (
        <button key={o.value} type='button' aria-pressed={value === o.value} onClick={() => onChange(o.value)}
          className={cn('inline-flex h-[calc(100%-1px)] min-h-10 flex-1 items-center justify-center rounded-md border border-transparent px-3 py-1 text-sm font-medium whitespace-nowrap text-foreground transition-[color,box-shadow] focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-1 aria-pressed:bg-background aria-pressed:shadow-sm dark:text-muted-foreground dark:aria-pressed:border-input dark:aria-pressed:bg-input/30 dark:aria-pressed:text-foreground', itemClassName)}>
          {o.label}
        </button>
      ))}
    </div>
  )
}
