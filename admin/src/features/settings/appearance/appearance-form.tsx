import { Check, Monitor, Moon, Sun } from 'lucide-react'
import { fonts } from '@/config/fonts'
import { cn } from '@/lib/utils'
import { useFont } from '@/context/font-provider'
import { useTheme } from '@/context/theme-provider'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Label } from '@/components/ui/label'

const themes = [
  { value: 'light', label: 'Light', icon: Sun },
  { value: 'dark', label: 'Dark', icon: Moon },
  { value: 'system', label: 'Same as my device', icon: Monitor },
] as const

/** Changes apply right away: no save button. */
export function AppearanceForm() {
  const { font, setFont } = useFont()
  const { theme, setTheme } = useTheme()

  return (
    <div className='grid max-w-xl gap-4'>
      <Card>
        <CardHeader>
          <CardTitle>Light or dark</CardTitle>
          <CardDescription>“Same as my device” follows your phone or computer.</CardDescription>
        </CardHeader>
        <CardContent>
          <div className='grid gap-2 sm:grid-cols-3' role='radiogroup' aria-label='Light or dark'>
            {themes.map((t) => (
              <button key={t.value} type='button' role='radio' aria-checked={theme === t.value} onClick={() => setTheme(t.value)}
                className={cn('flex min-h-12 items-center gap-2 rounded-md border px-3 text-start text-sm transition-colors hover:bg-accent',
                  theme === t.value && 'border-primary ring-1 ring-primary')}>
                <t.icon className='size-4 shrink-0' aria-hidden />
                <span className='flex-1'>{t.label}</span>
                {theme === t.value && <Check className='size-4 shrink-0' aria-hidden />}
              </button>
            ))}
          </div>
        </CardContent>
      </Card>
      <Card>
        <CardHeader>
          <CardTitle>Font</CardTitle>
          <CardDescription>The letters used in the admin.</CardDescription>
        </CardHeader>
        <CardContent>
          <Label htmlFor='admin-font' className='sr-only'>Font</Label>
          <select id='admin-font' value={font} onChange={(e) => setFont(e.target.value as (typeof fonts)[number])}
            className='h-12 w-full rounded-md border bg-background px-3 text-sm capitalize sm:w-60'>
            {fonts.map((f) => <option key={f} value={f}>{f}</option>)}
          </select>
        </CardContent>
      </Card>
    </div>
  )
}
