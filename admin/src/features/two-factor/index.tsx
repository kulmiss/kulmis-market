import { useEffect, useState } from 'react'
import { useNavigate } from '@tanstack/react-router'
import { Copy, Loader2, ShieldCheck } from 'lucide-react'
import { toast } from 'sonner'
import { api, errorMessage } from '@/lib/api'
import { useAuthStore } from '@/stores/auth-store'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { AuthLayout } from '@/features/auth/auth-layout'

/** Required before any admin screen: scan, confirm, save recovery codes. */
export function TwoFactorSetup() {
  const navigate = useNavigate()
  const fetchMe = useAuthStore((s) => s.fetchMe)
  const [setup, setSetup] = useState<{ secret: string; qr_svg: string } | null>(null)
  const [code, setCode] = useState('')
  const [codes, setCodes] = useState<string[] | null>(null)
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    api.post('/two-factor/setup').then((r) => setSetup(r.data)).catch((e) => toast.error(errorMessage(e)))
  }, [])

  async function confirm(e: React.FormEvent) {
    e.preventDefault()
    setBusy(true)
    try {
      const { data } = await api.post('/two-factor/confirm', { code })
      setCodes(data.recovery_codes)
      await fetchMe()
    } catch (err) {
      toast.error(errorMessage(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <AuthLayout>
      <Card className='max-w-md gap-4'>
        <CardHeader>
          <CardTitle className='flex items-center gap-2'>
            <ShieldCheck className='size-5' /> Set up two-factor authentication
          </CardTitle>
          <CardDescription>
            Scan the QR code with Google Authenticator, Authy or 1Password, then type the 6-digit code.
          </CardDescription>
        </CardHeader>
        <CardContent className='grid gap-4'>
          {codes ? (
            <>
              <p className='text-sm'>
                Save these recovery codes somewhere safe. Each one works once if you lose your phone.
              </p>
              <pre className='rounded-md bg-muted p-3 text-sm leading-7'>{codes.join('\n')}</pre>
              <Button variant='outline' onClick={() => navigator.clipboard.writeText(codes.join('\n')).then(() => toast.success('Copied'))}>
                <Copy /> Copy codes
              </Button>
              <Button onClick={() => navigate({ to: '/', replace: true })}>Continue to admin</Button>
            </>
          ) : setup ? (
            <form onSubmit={confirm} className='grid gap-3'>
              <div
                className='mx-auto w-48 rounded-md bg-white p-2 [&_svg]:h-auto [&_svg]:w-full'
                dangerouslySetInnerHTML={{ __html: setup.qr_svg }}
              />
              <p className='text-center font-mono text-xs break-all text-muted-foreground'>{setup.secret}</p>
              <Input autoFocus inputMode='numeric' placeholder='123456' value={code} onChange={(e) => setCode(e.target.value)} />
              <Button disabled={busy || code.length < 6}>{busy && <Loader2 className='animate-spin' />} Turn on 2FA</Button>
            </form>
          ) : (
            <Loader2 className='mx-auto animate-spin' />
          )}
        </CardContent>
      </Card>
    </AuthLayout>
  )
}
