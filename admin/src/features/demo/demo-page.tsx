import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { api, errorMessage } from '@/lib/api'
import { useAuthStore } from '@/stores/auth-store'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Switch } from '@/components/ui/switch'
import { Table, TableBody, TableCell, TableRow } from '@/components/ui/table'
import { ConfirmDialog } from '@/components/confirm-dialog'
import { Page } from '@/components/km/page'
import { PageSkeleton } from '@/components/km/page-skeleton'

type DemoState = { visible: boolean; exists: boolean; counts: Record<string, number>; confirm_text: string; can_seed: boolean }

const LABELS: Record<string, string> = {
  users: 'People (members, shoppers, demo staff)', products: 'Products', categories: 'Categories', orders: 'Orders', payments: 'Payments',
  commissions: 'Commissions', payout_requests: 'Cash-outs', fraud_flags: 'Fraud checks', signals: 'Signals', signal_subscriptions: 'Telegram subscribers',
  memberships: 'Memberships', membership_purchases: 'Membership payments', journal_entries: 'Ledger entries', activity_log: 'Activity log entries',
  coupons: 'Coupons', user_badges: 'Badges unlocked', user_notifications: 'Notifications', loyalty_rewards: 'Loyalty rewards',
  refunds: 'Refunds', payout_accounts: 'Cash-out accounts', shipping_zones: 'Shipping zones', couriers: 'Couriers', signal_purchases: 'Signal payments',
  ledger_postings: 'Ledger lines',
}

/** Show/hide demo data everywhere, or delete all of it. Real data is never touched. */
export function DemoPage() {
  const qc = useQueryClient()
  const isSuper = useAuthStore((s) => s.user?.role === 'super_admin')
  const q = useQuery({ queryKey: ['demo'], queryFn: async () => (await api.get<{ data: DemoState }>('/demo')).data.data })
  const [open, setOpen] = useState(false)
  const [typed, setTyped] = useState('')
  const d = q.data

  async function toggle(show: boolean) {
    try {
      qc.setQueryData(['demo'], (await api.put<{ data: DemoState }>('/demo', { show })).data.data)
      qc.invalidateQueries()
      toast.success(show ? 'Demo data is shown' : 'Demo data is hidden everywhere')
    } catch (e) { toast.error(errorMessage(e)) }
  }

  async function purge() {
    try {
      const res = await api.delete<{ data: DemoState }>('/demo', { data: { confirm: typed } })
      qc.setQueryData(['demo'], res.data.data)
      qc.invalidateQueries()
      setOpen(false)
      setTyped('')
      toast.success('All demo data was deleted')
    } catch (e) { toast.error(errorMessage(e)) }
  }

  return (
    <Page title='Demo data' description='Sample shop, members, orders and money to try everything. It is never counted with real money.'>
      {!d ? <PageSkeleton cards={2} rows={3} /> : (
        <div className='grid gap-4 lg:grid-cols-2'>
          <Card>
            <CardHeader>
              <CardTitle className='text-base'>Show demo data</CardTitle>
              <CardDescription>Off hides every demo record: storefront, member areas, admin lists, dashboards and totals. Nothing is deleted.</CardDescription>
            </CardHeader>
            <CardContent className='grid gap-4'>
              <label className='flex items-center gap-3 text-sm font-medium'>
                <Switch checked={d.visible} onCheckedChange={toggle} aria-label='Show demo data' /> {d.visible ? 'Shown' : 'Hidden'}
              </label>
              {!d.exists && <p className='text-sm text-muted-foreground'>There is no demo data. {d.can_seed ? 'Add it with: php artisan db:seed --class=DemoSeeder' : 'Demo data can not be added on a live shop.'}</p>}
            </CardContent>
          </Card>
          <Card>
            <CardHeader>
              <CardTitle className='text-base'>Delete all demo data</CardTitle>
              <CardDescription>Removes every demo record and demo photo for good. Real orders, members and money stay exactly as they are.</CardDescription>
            </CardHeader>
            <CardContent>
              <Button variant='destructive' disabled={!d.exists || !isSuper} onClick={() => setOpen(true)}><Trash2 /> Delete all demo data</Button>
              {!isSuper && <p className='mt-2 text-xs text-muted-foreground'>Only Super Admin can delete demo data.</p>}
            </CardContent>
          </Card>
          {d.exists && (
            <Card className='lg:col-span-2'>
              <CardHeader><CardTitle className='text-base'>What is demo data</CardTitle></CardHeader>
              <CardContent>
                <Table><TableBody>
                  {Object.entries(d.counts).map(([k, v]) => <TableRow key={k}><TableCell>{LABELS[k] ?? k}</TableCell><TableCell className='text-end tabular-nums'>{v}</TableCell></TableRow>)}
                </TableBody></Table>
              </CardContent>
            </Card>
          )}
          <ConfirmDialog open={open} onOpenChange={setOpen} title='Delete all demo data?' destructive confirmText='Delete for good'
            disabled={typed !== d.confirm_text}
            desc={<span>This can not be undone. Type <b>{d.confirm_text}</b> to confirm.</span>}
            handleConfirm={purge}>
            <Input value={typed} onChange={(e) => setTyped(e.target.value)} placeholder={d.confirm_text} aria-label='Confirmation text' />
          </ConfirmDialog>
        </div>
      )}
    </Page>
  )
}
