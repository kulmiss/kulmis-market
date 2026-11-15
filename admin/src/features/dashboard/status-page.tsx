import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { formatDate } from '@/lib/money'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Page } from '@/components/km/page'
import { Flag } from '@/components/km/status-badge'

type S = { scheduler_last_run: string | null; scheduler_ok: boolean; queue_waiting: number; queue_failed: number; features_off: string[]
  php: string; laravel: string; environment: string; debug: boolean; https: boolean
  integrations: { chapa: boolean; chapa_webhook: boolean; telegram: boolean; sms: string; mail: string } }

export function StatusPage() {
  const q = useQuery({ queryKey: ['status'], queryFn: async () => (await api.get<{ data: S }>('/status')).data.data, refetchInterval: 30_000 })
  const s = q.data
  return (
    <Page title='System status' description='Is cron running, are messages going out, what is switched off.'>
      {s && (
        <div className='grid grid-cols-1 gap-4 md:grid-cols-2'>
          <Card>
            <CardHeader><CardTitle className='text-base'>Background work</CardTitle></CardHeader>
            <CardContent className='grid gap-2 text-sm'>
              <div className='flex flex-wrap items-center justify-between gap-x-3 gap-y-1'>Cron / scheduler <Flag ok={s.scheduler_ok} yes='Running' no='Not running' noTone='bad' /></div>
              <div className='flex flex-wrap items-center justify-between gap-x-3 gap-y-1'>Last run <span>{formatDate(s.scheduler_last_run, true)}</span></div>
              <div className='flex flex-wrap items-center justify-between gap-x-3 gap-y-1'>Jobs waiting <span>{s.queue_waiting}</span></div>
              <div className='flex flex-wrap items-center justify-between gap-x-3 gap-y-1'>Failed jobs <span className={s.queue_failed ? 'text-red-600' : ''}>{s.queue_failed}</span></div>
              {!s.scheduler_ok && <p className='text-amber-700'>Add the cron line: <code>* * * * * php /path/artisan schedule:run</code></p>}
            </CardContent>
          </Card>
          <Card>
            <CardHeader><CardTitle className='text-base'>Integrations</CardTitle></CardHeader>
            <CardContent className='grid gap-2 text-sm'>
              <div className='flex flex-wrap items-center justify-between gap-x-3 gap-y-1'>Chapa key <Flag ok={s.integrations.chapa} /></div>
              <div className='flex flex-wrap items-center justify-between gap-x-3 gap-y-1'>Chapa webhook secret <Flag ok={s.integrations.chapa_webhook} /></div>
              <div className='flex flex-wrap items-center justify-between gap-x-3 gap-y-1'>Telegram bot <Flag ok={s.integrations.telegram} /></div>
              <div className='flex flex-wrap items-center justify-between gap-x-3 gap-y-1'>SMS driver <span>{s.integrations.sms}</span></div>
              <div className='flex flex-wrap items-center justify-between gap-x-3 gap-y-1'>Mail <span>{s.integrations.mail}</span></div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader><CardTitle className='text-base'>Server</CardTitle></CardHeader>
            <CardContent className='grid gap-2 text-sm'>
              <div className='flex flex-wrap items-center justify-between gap-x-3 gap-y-1'>Environment <span>{s.environment}</span></div>
              <div className='flex flex-wrap items-center justify-between gap-x-3 gap-y-1'>Debug mode <Flag ok={!s.debug} yes='Off' no='On (turn off when live)' /></div>
              <div className='flex flex-wrap items-center justify-between gap-x-3 gap-y-1'>HTTPS <Flag ok={s.https} yes='On' no='Off (needed when live)' /></div>
              <div className='flex flex-wrap items-center justify-between gap-x-3 gap-y-1'>PHP / Laravel <span>{s.php} / {s.laravel}</span></div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader><CardTitle className='text-base'>Switched off</CardTitle></CardHeader>
            <CardContent className='text-sm'>{s.features_off.length ? s.features_off.join(', ') : 'Everything is on.'}</CardContent>
          </Card>
        </div>
      )}
    </Page>
  )
}
