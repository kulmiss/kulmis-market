import { useState } from 'react'
import { useNavigate } from '@tanstack/react-router'
import { Loader2, LogIn } from 'lucide-react'
import { toast } from 'sonner'
import { api, ensureCsrf, errorMessage } from '@/lib/api'
import { useAuthStore } from '@/stores/auth-store'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { PasswordInput } from '@/components/password-input'

type Step = 'password' | 'code'

export function UserAuthForm({ redirectTo }: { redirectTo?: string }) {
  const navigate = useNavigate()
  const fetchMe = useAuthStore((s) => s.fetchMe)
  const [step, setStep] = useState<Step>('password')
  const [busy, setBusy] = useState(false)
  const [login, setLogin] = useState('')
  const [password, setPassword] = useState('')
  const [code, setCode] = useState('')
  const [useRecovery, setUseRecovery] = useState(false)

  async function finish(setupRequired: boolean) {
    await fetchMe()
    if (setupRequired) {
      navigate({ to: '/two-factor-setup', replace: true })
    } else {
      navigate({ to: (redirectTo as '/') || '/', replace: true })
    }
  }

  async function submitPassword(e: React.FormEvent) {
    e.preventDefault()
    setBusy(true)
    try {
      await ensureCsrf()
      const { data } = await api.post('/auth/login', { login, password })
      if (data.two_factor) {
        setStep('code')
      } else {
        await finish(Boolean(data.two_factor_setup_required))
      }
    } catch (err) {
      toast.error(errorMessage(err))
    } finally {
      setBusy(false)
    }
  }

  async function submitCode(e: React.FormEvent) {
    e.preventDefault()
    setBusy(true)
    try {
      const { data } = await api.post('/auth/two-factor', useRecovery ? { recovery_code: code } : { code })
      await finish(Boolean(data.two_factor_setup_required))
    } catch (err) {
      toast.error(errorMessage(err))
    } finally {
      setBusy(false)
    }
  }

  if (step === 'code') {
    return (
      <form onSubmit={submitCode} className='grid gap-3'>
        <Label htmlFor='code'>{useRecovery ? 'Recovery code' : 'Code from your authenticator app'}</Label>
        <Input
          id='code'
          autoFocus
          autoComplete='one-time-code'
          inputMode={useRecovery ? 'text' : 'numeric'}
          placeholder={useRecovery ? 'XXXXX-XXXXX' : '123456'}
          value={code}
          onChange={(e) => setCode(e.target.value)}
        />
        <Button className='mt-2' disabled={busy || code.length < 6}>
          {busy ? <Loader2 className='animate-spin' /> : <LogIn />} Verify
        </Button>
        <Button type='button' variant='link' size='sm' onClick={() => setUseRecovery(!useRecovery)}>
          {useRecovery ? 'Use the authenticator code' : 'Use a recovery code instead'}
        </Button>
      </form>
    )
  }

  return (
    <form onSubmit={submitPassword} className='grid gap-3'>
      <div className='grid gap-2'>
        <Label htmlFor='login'>Email or phone</Label>
        <Input id='login' autoComplete='username' value={login} onChange={(e) => setLogin(e.target.value)} />
      </div>
      <div className='grid gap-2'>
        <Label htmlFor='password'>Password</Label>
        <PasswordInput id='password' autoComplete='current-password' value={password} onChange={(e) => setPassword(e.target.value)} />
      </div>
      <Button className='mt-2' disabled={busy || !login || !password}>
        {busy ? <Loader2 className='animate-spin' /> : <LogIn />} Sign in
      </Button>
    </form>
  )
}
