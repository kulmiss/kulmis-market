import { Outlet } from '@tanstack/react-router'
import { Page } from '@/components/km/page'

/** My account: how the admin looks for you (saved on this device). */
export function Settings() {
  return (
    <Page title='My account' description='How the admin looks for you. Saved on this device.'>
      <Outlet />
    </Page>
  )
}
