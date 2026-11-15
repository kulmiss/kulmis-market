import { Input } from '@/components/ui/input'
import { currencySymbol, toBirr, toSantim } from '@/lib/money'

type MoneyInputProps = Omit<React.ComponentProps<typeof Input>, 'value' | 'onChange'> & {
  value: number | null | undefined
  onChange: (santim: number) => void
}

/** Type whole units (birr), store minor units (santim). */
export function MoneyInput({ value, onChange, ...props }: MoneyInputProps) {
  return (
    <div className='relative'>
      <Input
        inputMode='decimal'
        {...props}
        value={toBirr(value)}
        onChange={(e) => onChange(toSantim(e.target.value))}
        className='pe-12'
      />
      <span className='pointer-events-none absolute inset-y-0 end-3 flex items-center text-xs text-muted-foreground'>{currencySymbol()}</span>
    </div>
  )
}
