import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { formatDate } from '@/lib/money'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Page } from '@/components/km/page'
import { StatusBadge } from '@/components/km/status-badge'

type S = { scheduler_last_run: string | null; scheduler_ok: boolean; queue_waiting: number; queue_failed: number; features_off: string[]
  php: string; laravel: string; environment: string; debug: boolean; https: boolean
  integrations: { chapa: boolean; chapa_webhook: boolean; telegram: boolean; sms: string; mail: string } }

export function StatusPage() {
  const q = useQuery({ queryKey: ['status'], queryFn: async () => (await api.get<{ data: S }>('/status')).data.data, refetchInterval: 30_000 })
  const s = q.data
  const ok = (v: boolean, good = 'active', bad = 'failed') => <StatusBadge status={v ? good : bad} />
  return (
    <Page title='System status' description='Is cron running, are messages going out, what is switched off.'>
      {s && (
        <div className='grid gap-4 md:grid-cols-2'>
          <Card>
            <CardHeader><CardTitle className='text-base'>Background work</CardTitle></CardHeader>
            <CardContent className='grid gap-2 text-sm'>
              <div className='flex justify-between'>Cron / scheduler {ok(s.scheduler_ok)}</div>
              <div className='flex justify-between'>Last run <span>{formatDate(s.scheduler_last_run, true)}</span></div>
              <div className='flex justify-between'>Jobs waiting <span>{s.queue_waiting}</span></div>
              <div className='flex justify-between'>Failed jobs <span className={s.queue_failed ? 'text-red-600' : ''}>{s.queue_failed}</span></div>
              {!s.scheduler_ok && <p className='text-amber-700'>Add the cron line: <code>* * * * * php /path/artisan schedule:run</code></p>}
            </CardContent>
          </Card>
          <Card>
            <CardHeader><CardTitle className='text-base'>Integrations</CardTitle></CardHeader>
            <CardContent className='grid gap-2 text-sm'>
              <div className='flex justify-between'>Chapa key {ok(s.integrations.chapa)}</div>
              <div className='flex justify-between'>Chapa webhook secret {ok(s.integrations.chapa_webhook)}</div>
              <div className='flex justify-between'>Telegram bot {ok(s.integrations.telegram)}</div>
              <div className='flex justify-between'>SMS driver <span>{s.integrations.sms}</span></div>
              <div className='flex justify-between'>Mail <span>{s.integrations.mail}</span></div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader><CardTitle className='text-base'>Server</CardTitle></CardHeader>
            <CardContent className='grid gap-2 text-sm'>
              <div className='flex justify-between'>Environment <span>{s.environment}</span></div>
              <div className='flex justify-between'>Debug mode {ok(!s.debug, 'active', 'failed')}</div>
              <div className='flex justify-between'>HTTPS {ok(s.https)}</div>
              <div className='flex justify-between'>PHP / Laravel <span>{s.php} / {s.laravel}</span></div>
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
