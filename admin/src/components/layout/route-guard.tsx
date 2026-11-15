import { Navigate, Outlet, useRouterState } from '@tanstack/react-router'
import { useAuthStore } from '@/stores/auth-store'
import { useFeatures } from '@/stores/features-store'
import { ForbiddenError } from '@/features/errors/forbidden'
import { NotFoundError } from '@/features/errors/not-found-error'
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
  if (item && features.loaded && !features.on(item.feature)) return <NotFoundError />

  return <Outlet />
}
