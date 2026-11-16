import { Navigate, Outlet, useRouterState } from '@tanstack/react-router'
import { useAuthStore } from '@/stores/auth-store'
import { useFeatures } from '@/stores/features-store'
import { ForbiddenError } from '@/features/errors/forbidden'
import { CircleSlash } from 'lucide-react'
import { type NavLink } from './types'
import { navLinks as links, navItemFor } from './nav-access'

/**
 * Same rules as the menu: a staff member who opens a page their role can't use
 * sees "403" instead of an empty or endlessly loading page, and someone without
 * the dashboard lands on their first allowed page.
 */
export function RouteGuard() {
  const path = useRouterState({ select: (s) => s.location.pathname.replace(/^\/admin/, '') || '/' })
  const can = useAuthStore((s) => s.can)
  const features = useFeatures()
  const item = navItemFor(path)
  const allowed = (l: NavLink) => (!l.ability || can(l.ability)) && (!features.loaded || features.on(l.feature))

  if (item && item.ability && !can(item.ability)) {
    if (item.url === '/') {
      const first = links.find((l) => l.url !== '/' && allowed(l))
      return <Navigate to={String(first?.url ?? '/settings')} replace />
    }
    return <ForbiddenError />
  }
  if (item && features.loaded && !features.on(item.feature)) {
    // Its history stays here; customers and members see nothing of it.
    return (
      <>
        <p role='status' className='mx-4 mt-4 flex items-start gap-2 rounded-md border border-amber-500/40 bg-amber-500/10 px-3 py-2 text-sm text-amber-900 sm:mx-6 dark:text-amber-200'>
          <CircleSlash className='mt-0.5 size-4 shrink-0' />
          <span><b>{features.label(item.feature ?? '')}</b> is switched off. Customers and members see nothing of it. Its history stays here, and you can finish open work.</span>
        </p>
        <Outlet />
      </>
    )
  }

  return <Outlet />
}
