import { useQuery } from '@tanstack/react-query'
import { Link } from '@tanstack/react-router'
import { CircleCheck, CircleDashed, ListChecks } from 'lucide-react'
import { useAuthStore } from '@/stores/auth-store'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { setupQuery } from './api'

/** After setup: what is still not done (Super Admin only), until everything is done. */
export function SetupChecklist() {
  const superAdmin = useAuthStore((s) => s.user?.role === 'super_admin')
  const { data } = useQuery({ ...setupQuery, enabled: superAdmin })
  if (!superAdmin || !data || !data.finished || !data.on) return null
  const left = data.checklist.filter((i) => !i.done)
  if (left.length === 0) return null
  return (
    <Card className='border-amber-500/40' data-setup-checklist>
      <CardHeader className='pb-2'>
        <CardTitle className='flex items-center gap-2 text-base'><ListChecks className='size-4' /> Before you go live: {left.length} still to do</CardTitle>
        <CardDescription>This list goes away when everything is done.</CardDescription>
      </CardHeader>
      <CardContent className='grid gap-1.5 sm:grid-cols-2'>
        {data.checklist.map((i) => (
          <div key={i.key} className='flex items-center gap-2 text-sm' data-item={i.key} data-done={i.done}>
            {i.done ? <CircleCheck className='size-4 shrink-0 text-green-600' /> : <CircleDashed className='size-4 shrink-0 text-amber-600' />}
            {i.done ? <span className='text-muted-foreground line-through'>{i.text}</span> : <Link to={i.link} className='underline'>{i.text}</Link>}
          </div>
        ))}
      </CardContent>
    </Card>
  )
}
