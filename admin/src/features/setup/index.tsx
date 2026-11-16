import { useEffect, useRef, useState } from 'react'
import { useNavigate } from '@tanstack/react-router'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, ArrowRight, Check, CircleAlert, CircleCheck, HelpCircle, ImageUp, Loader2, ShieldCheck, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { api, errorMessage } from '@/lib/api'
import { formatMoney } from '@/lib/money'
import { useAuthStore } from '@/stores/auth-store'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { MoneyInput } from '@/components/km/money-input'
import { Logo } from '@/assets/logo'
import { setupQuery } from './api'

type Service = { key: string; name: string; connected: boolean; detail: string; can_test: boolean; help: string }
type Feature = { key: string; on: boolean; label: string; description: string }
export type ChecklistItem = { key: string; text: string; done: boolean; link: string }
export type SetupState = {
  step: number
  finished: boolean
  on: boolean
  security: { two_factor: boolean; recovery_codes_left: number; backup_key: boolean }
  values: Record<string, string | number>
  logo_url: string | null
  guard: { keep: number; total_bps: number; max_bps: number }
  services: Service[]
  features: Feature[]
  demo: { exists: boolean }
  checklist: ChecklistItem[]
}

const PAGES = ['Welcome', 'Your shop', 'Prices', 'Services', 'Finish']
const SHOP_KEYS = ['site.name', 'appearance.shop_color', 'currency.code', 'currency.symbol', 'site.whatsapp', 'site.telegram_support', 'site.support_email']
const PRICE_KEYS = ['membership.price', 'pricing.member_discount_bps', 'signals.guest_price', 'referral.rate.membership.l1',
  'referral.rate.membership.l2', 'referral.rate.membership.l3', 'wallet.cashout_min']


/** First sign-in of the Super Admin: 5 short pages. Everything can be changed later in Settings. */
export function SetupWizard() {
  const navigate = useNavigate()
  const qc = useQueryClient()
  const fetchMe = useAuthStore((s) => s.fetchMe)
  const { data } = useQuery(setupQuery)
  const [step, setStep] = useState(1)
  const [draft, setDraft] = useState<Record<string, string | number>>({})
  const [busy, setBusy] = useState(false)
  const started = useRef(false)

  useEffect(() => {
    if (data && !started.current) {
      started.current = true
      setStep(data.step)
      setDraft(data.values)
    }
  }, [data])

  if (!data) return <div className='grid h-svh place-items-center'><Loader2 className='animate-spin' /></div>

  const set = (k: string, v: string | number) => setDraft((d) => ({ ...d, [k]: v }))
  const changed = (keys: string[]) => Object.fromEntries(keys.filter((k) => draft[k] !== data.values[k]).map((k) => [k, draft[k]]))

  async function go(to: number, save: boolean) {
    setBusy(true)
    try {
      if (save) {
        const keys = step === 2 ? SHOP_KEYS : step === 3 ? PRICE_KEYS : []
        const settings = changed(keys)
        if (Object.keys(settings).length) {
          const confirm = Object.keys(settings).some((k) => k.startsWith('currency.'))
          await api.put('/settings', { settings, reason: 'Setup wizard', confirm_currency_change: confirm || undefined })
        }
      }
      const res = await api.put<{ data: SetupState }>('/setup', { step: to })
      qc.setQueryData(['setup'], res.data.data)
      setStep(to)
      window.scrollTo(0, 0)
    } catch (e) {
      toast.error(errorMessage(e))
    } finally {
      setBusy(false)
    }
  }

  async function finish() {
    setBusy(true)
    try {
      await api.put('/setup', { finished: true })
      await fetchMe()
      qc.invalidateQueries({ queryKey: ['setup'] })
      toast.success('All set! You can change everything later in Settings.')
      navigate({ to: '/', replace: true })
    } catch (e) {
      toast.error(errorMessage(e))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className='min-h-svh bg-muted/30' data-setup-wizard>
      <div className='mx-auto flex w-full max-w-2xl flex-col gap-4 px-4 py-6 sm:py-10'>
        <div className='flex items-center justify-between gap-2'>
          <div className='flex items-center gap-2 font-medium'><Logo /> Setup</div>
          <div className='flex items-center gap-2' aria-label={`Page ${step} of ${PAGES.length}`} data-progress>
            {PAGES.map((p, i) => (
              <span key={p} title={p} className={`size-2.5 rounded-full ${i + 1 === step ? 'bg-primary' : i + 1 < step ? 'bg-primary/50' : 'bg-muted-foreground/30'}`} />
            ))}
            <span className='ms-1 text-sm text-muted-foreground'>{step} of {PAGES.length}</span>
          </div>
        </div>

        {step === 1 && <Welcome data={data} />}
        {step === 2 && <Shop data={data} draft={draft} set={set} />}
        {step === 3 && <Prices data={data} draft={draft} set={set} />}
        {step === 4 && <Services data={data} />}
        {step === 5 && <Finish data={data} />}

        <div className='sticky bottom-0 -mx-4 flex flex-wrap items-center gap-2 border-t bg-background/95 px-4 py-3 backdrop-blur sm:static sm:mx-0 sm:rounded-lg sm:border'>
          <Button variant='ghost' disabled={step === 1 || busy} onClick={() => go(step - 1, false)}><ArrowLeft /> Back</Button>
          <Button variant='link' className='text-muted-foreground' disabled={busy} onClick={() => (step === PAGES.length ? finish() : go(step + 1, false))}>Skip for now</Button>
          <span className='ms-auto' />
          {step < PAGES.length
            ? <Button disabled={busy} onClick={() => go(step + 1, true)} data-next>{busy ? <Loader2 className='animate-spin' /> : null} Next <ArrowRight /></Button>
            : <Button disabled={busy} onClick={finish} data-finish><Check /> Finish</Button>}
        </div>
        <p className='text-center text-xs text-muted-foreground'>Everything here can be changed later in Settings.</p>
      </div>
    </div>
  )
}

function Status({ ok, children }: { ok: boolean; children: React.ReactNode }) {
  return (
    <div className={`flex items-start gap-2 rounded-md border p-3 text-sm ${ok ? 'border-green-600/30 bg-green-600/5' : 'border-amber-500/40 bg-amber-500/5'}`}>
      {ok ? <CircleCheck className='mt-0.5 size-4 shrink-0 text-green-600' /> : <CircleAlert className='mt-0.5 size-4 shrink-0 text-amber-600' />}
      <div>{children}</div>
    </div>
  )
}

function HelpLink({ slug, children = 'How to set this up' }: { slug: string; children?: React.ReactNode }) {
  return <a className='inline-flex items-center gap-1 text-sm underline' href={`/admin/guide/${slug}`} target='_blank' rel='noreferrer'><HelpCircle className='size-3.5' /> {children}</a>
}

function Welcome({ data }: { data: SetupState }) {
  return (
    <Card data-page='1'>
      <CardHeader>
        <CardTitle className='text-2xl'>Welcome to Kulmis Market</CardTitle>
        <CardDescription className='grid gap-1 text-base'>
          <span>Sell your handmade products online.</span>
          <span>Members pay a monthly fee, get lower prices and earn from the people they invite.</span>
          <span>You see every birr that comes in and goes out.</span>
        </CardDescription>
      </CardHeader>
      <CardContent className='grid gap-3'>
        <h2 className='flex items-center gap-2 font-semibold'><ShieldCheck className='size-4' /> Keep your account safe</h2>
        <Status ok={data.security.two_factor}>
          {data.security.two_factor
            ? <>Two-step sign-in is <b>on</b>. You have <b>{data.security.recovery_codes_left}</b> recovery codes left. Keep them in a password manager: they open your account if you lose your phone.</>
            : <>Two-step sign-in is not on yet. Open <b>My account</b> and turn it on.</>}
        </Status>
        <Status ok={data.security.backup_key}>
          {data.security.backup_key
            ? <>Backup key is set. Keep a copy in your password manager: without it a backup cannot be opened.</>
            : <>No backup key yet. Run <code className='rounded bg-muted px-1'>php artisan km:backup-key</code> on the server and copy the line into <code className='rounded bg-muted px-1'>.env</code>. <HelpLink slug='setup-backups'>How to</HelpLink></>}
        </Status>
      </CardContent>
    </Card>
  )
}

function Field({ label, children, hint }: { label: string; children: React.ReactNode; hint?: string }) {
  return <div className='grid content-start gap-1.5'><Label>{label}</Label>{children}{hint && <p className='text-xs text-muted-foreground'>{hint}</p>}</div>
}

function Shop({ data, draft, set }: { data: SetupState; draft: Record<string, string | number>; set: (k: string, v: string | number) => void }) {
  const qc = useQueryClient()
  const [uploading, setUploading] = useState(false)
  const color = String(draft['appearance.shop_color'] ?? '#18743f')

  async function upload(file: File | undefined) {
    if (!file) return
    setUploading(true)
    try {
      const form = new FormData()
      form.append('logo', file)
      const res = await api.post<{ data: SetupState }>('/setup/logo', form)
      qc.setQueryData(['setup'], res.data.data)
    } catch (e) {
      toast.error(errorMessage(e))
    } finally {
      setUploading(false)
    }
  }
  async function removeLogo() {
    const res = await api.delete<{ data: SetupState }>('/setup/logo')
    qc.setQueryData(['setup'], res.data.data)
  }

  return (
    <Card data-page='2'>
      <CardHeader><CardTitle className='text-2xl'>Your shop</CardTitle><CardDescription>Name, logo, colour and how customers reach you.</CardDescription></CardHeader>
      <CardContent className='grid gap-4'>
        <div className='overflow-hidden rounded-lg border' aria-label='Preview of the shop header'>
          <div className='flex h-14 items-center gap-2 bg-white px-4 text-zinc-900'>
            {data.logo_url ? <img src={data.logo_url} alt='' className='size-8 object-contain' /> : <span className='size-8 rounded-full' style={{ background: color }} />}
            <span className='truncate font-serif text-lg font-semibold'>{String(draft['site.name'] ?? '')}</span>
            <span className='ms-auto rounded-full px-3 py-1.5 text-sm font-medium text-white' style={{ background: color }}>Shop now</span>
          </div>
        </div>
        <Field label='Shop name'><Input value={String(draft['site.name'] ?? '')} maxLength={60} onChange={(e) => set('site.name', e.target.value)} /></Field>
        <Field label='Logo' hint='PNG, JPG or WebP, square works best. We remove hidden data from the file.'>
          <div className='flex flex-wrap items-center gap-2'>
            <Button variant='outline' asChild disabled={uploading}>
              <label className='cursor-pointer'>{uploading ? <Loader2 className='animate-spin' /> : <ImageUp />} Upload logo
                <input type='file' accept='image/png,image/jpeg,image/webp' className='sr-only' onChange={(e) => upload(e.target.files?.[0])} />
              </label>
            </Button>
            {data.logo_url && <Button variant='ghost' onClick={removeLogo}><Trash2 /> Use the standard mark</Button>}
          </div>
        </Field>
        <Field label='Main colour' hint='Buttons and links in the shop.'>
          <div className='flex items-center gap-2'>
            <Input type='color' className='h-9 w-14 p-1' value={color} onChange={(e) => set('appearance.shop_color', e.target.value)} aria-label='Main colour' />
            <Input className='w-32 font-mono' value={color} onChange={(e) => set('appearance.shop_color', e.target.value)} aria-label='Main colour (hex)' />
          </div>
        </Field>
        <div className='grid gap-4 sm:grid-cols-2'>
          <Field label='Currency code' hint='ETB for Ethiopian birr.'><Input maxLength={3} className='uppercase' value={String(draft['currency.code'] ?? '')} onChange={(e) => set('currency.code', e.target.value.toUpperCase())} /></Field>
          <Field label='Currency symbol'><Input maxLength={6} value={String(draft['currency.symbol'] ?? '')} onChange={(e) => set('currency.symbol', e.target.value)} /></Field>
        </div>
        <div className='grid gap-4 sm:grid-cols-3'>
          <Field label='WhatsApp number'><Input inputMode='tel' placeholder='+251 9…' value={String(draft['site.whatsapp'] ?? '')} onChange={(e) => set('site.whatsapp', e.target.value)} /></Field>
          <Field label='Telegram username'><Input placeholder='kulmis_help' value={String(draft['site.telegram_support'] ?? '')} onChange={(e) => set('site.telegram_support', e.target.value)} /></Field>
          <Field label='Email'><Input type='email' value={String(draft['site.support_email'] ?? '')} onChange={(e) => set('site.support_email', e.target.value)} /></Field>
        </div>
      </CardContent>
    </Card>
  )
}

function Percent({ value, onChange, label }: { value: number; onChange: (v: number) => void; label: string }) {
  return (
    <div className='relative'>
      <Input aria-label={label} inputMode='decimal' value={value / 100} onChange={(e) => onChange(Math.round(Number(e.target.value || 0) * 100))} className='pe-8' />
      <span className='absolute inset-y-0 end-3 flex items-center text-xs text-muted-foreground'>%</span>
    </div>
  )
}

function Prices({ data, draft, set }: { data: SetupState; draft: Record<string, string | number>; set: (k: string, v: string | number) => void }) {
  const [guard, setGuard] = useState<{ keep: number; safe: boolean; total_bps: number; max_bps: number }>({ ...data.guard, safe: data.guard.total_bps <= data.guard.max_bps })
  const n = (k: string) => Number(draft[k] ?? 0)
  const key = PRICE_KEYS.map((k) => draft[k]).join('|')

  useEffect(() => {
    const t = setTimeout(() => {
      const settings = Object.fromEntries(PRICE_KEYS.map((k) => [k, draft[k]]))
      api.post<{ data: typeof guard }>('/setup/preview', { settings }).then((r) => setGuard(r.data.data)).catch(() => undefined)
    }, 300)
    return () => clearTimeout(t)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key])

  return (
    <Card data-page='3'>
      <CardHeader><CardTitle className='text-2xl'>Prices and earnings</CardTitle><CardDescription>Already filled in with your current numbers. Change only what you want.</CardDescription></CardHeader>
      <CardContent className='grid gap-4'>
        <div className='grid gap-4 sm:grid-cols-2'>
          <Field label='Membership price (per month)'><MoneyInput value={n('membership.price')} onChange={(v) => set('membership.price', v)} /></Field>
          <Field label='Member price cut on products'><Percent label='Member price cut' value={n('pricing.member_discount_bps')} onChange={(v) => set('pricing.member_discount_bps', v)} /></Field>
          <Field label='Signals price for guests (per month)'><MoneyInput value={n('signals.guest_price')} onChange={(v) => set('signals.guest_price', v)} /></Field>
          <Field label='Smallest cash-out'><MoneyInput value={n('wallet.cashout_min')} onChange={(v) => set('wallet.cashout_min', v)} /></Field>
        </div>
        <div>
          <Label>Team earnings on each membership</Label>
          <div className='mt-1.5 grid grid-cols-3 gap-2'>
            {[1, 2, 3].map((l) => (
              <div key={l} className='grid gap-1'>
                <span className='text-xs text-muted-foreground'>Level {l}</span>
                <Percent label={`Level ${l}`} value={n(`referral.rate.membership.l${l}`)} onChange={(v) => set(`referral.rate.membership.l${l}`, v)} />
              </div>
            ))}
          </div>
        </div>
        <Status ok={guard.safe}>
          {guard.safe
            ? <>You keep at least <b data-keep>{formatMoney(guard.keep)}</b> of every {formatMoney(n('membership.price'))} membership, even if every bonus is paid.</>
            : <>These numbers pay out more than {guard.max_bps / 100}% of a membership. The shop must keep at least {100 - guard.max_bps / 100}%, so they can't be saved. Lower a rate.</>}
        </Status>
      </CardContent>
    </Card>
  )
}

function Services({ data }: { data: SetupState }) {
  const [result, setResult] = useState<Record<string, { ok: boolean; message: string }>>({})
  const [testing, setTesting] = useState('')
  async function test(key: string) {
    setTesting(key)
    try {
      const r = await api.post<{ data: { ok: boolean; message: string } }>(`/setup/test/${key}`)
      setResult((x) => ({ ...x, [key]: r.data.data }))
    } catch (e) {
      setResult((x) => ({ ...x, [key]: { ok: false, message: errorMessage(e) } }))
    } finally {
      setTesting('')
    }
  }
  return (
    <Card data-page='4'>
      <CardHeader>
        <CardTitle className='text-2xl'>Connect your services</CardTitle>
        <CardDescription>You can do this later. The shop already works: customers pay by Telebirr or bank transfer and send a receipt.</CardDescription>
      </CardHeader>
      <CardContent className='grid gap-3'>
        {data.services.map((s) => (
          <div key={s.key} className='grid gap-2 rounded-lg border p-3' data-service={s.key}>
            <div className='flex flex-wrap items-center gap-2'>
              <span className='font-semibold'>{s.name}</span>
              <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${s.connected ? 'bg-green-600/10 text-green-700 dark:text-green-400' : 'bg-muted text-muted-foreground'}`}>{s.connected ? 'Connected' : 'Not connected'}</span>
              <span className='ms-auto flex gap-2'>
                {s.can_test && <Button size='sm' variant='outline' disabled={testing === s.key} onClick={() => test(s.key)}>{testing === s.key && <Loader2 className='animate-spin' />} Test</Button>}
              </span>
            </div>
            <p className='text-sm text-muted-foreground'>{s.detail}</p>
            {result[s.key] && <p role='status' className={`text-sm ${result[s.key].ok ? 'text-green-700 dark:text-green-400' : 'text-destructive'}`}>{result[s.key].message}</p>}
            <HelpLink slug={s.help} />
          </div>
        ))}
        <p className='text-sm text-muted-foreground'>Passwords and keys go only in the <code className='rounded bg-muted px-1'>.env</code> file on your server, never on this screen. We never show them here.</p>
      </CardContent>
    </Card>
  )
}

function Finish({ data }: { data: SetupState }) {
  const qc = useQueryClient()
  const [features, setFeatures] = useState(data.features)
  const [deleting, setDeleting] = useState(false)

  async function toggle(f: Feature, on: boolean) {
    try {
      await api.put('/features', { features: { [f.key]: on } })
      setFeatures((all) => all.map((x) => (x.key === f.key ? { ...x, on } : x)))
      qc.invalidateQueries({ queryKey: ['features'] })
    } catch (e) {
      toast.error(errorMessage(e))
    }
  }
  async function deleteDemo() {
    setDeleting(true)
    try {
      const { data: d } = await api.get<{ data: { confirm_text: string } }>('/demo')
      await api.delete('/demo', { data: { confirm: d.data.confirm_text } })
      const res = await api.get<{ data: SetupState }>('/setup')
      qc.setQueryData(['setup'], res.data.data)
      toast.success('Demo data deleted')
    } catch (e) {
      toast.error(errorMessage(e))
    } finally {
      setDeleting(false)
    }
  }

  return (
    <Card data-page='5'>
      <CardHeader><CardTitle className='text-2xl'>Choose what to use</CardTitle><CardDescription>Switched off = customers never see it. You can switch it on any time.</CardDescription></CardHeader>
      <CardContent className='grid gap-3'>
        <div className='grid gap-2 sm:grid-cols-2'>
          {features.map((f) => (
            <label key={f.key} className='flex items-start gap-3 rounded-lg border p-3' data-feature={f.key}>
              <Switch checked={f.on} onCheckedChange={(v) => toggle(f, v)} aria-label={f.label} />
              <span className='grid gap-0.5'><span className='font-medium'>{f.label}</span><span className='text-xs text-muted-foreground'>{f.description}</span></span>
            </label>
          ))}
        </div>
        <div className='rounded-lg border p-3'>
          <p className='font-semibold'>Demo data</p>
          {data.demo.exists
            ? <div className='mt-1 grid gap-2 text-sm'><p className='text-muted-foreground'>Example products, members and orders for testing. Keep them while you try things out; delete them before you go live.</p>
                <div><Button variant='destructive' size='sm' disabled={deleting} onClick={deleteDemo} data-delete-demo>{deleting && <Loader2 className='animate-spin' />} Delete demo data now</Button></div></div>
            : <p className='mt-1 text-sm text-muted-foreground'>No demo data. Good to go live.</p>}
        </div>
        <p className='text-sm text-muted-foreground'>After <b>Finish</b>, the dashboard shows a short list of what is still not done.</p>
      </CardContent>
    </Card>
  )
}
