import { type NavLink } from './types'
import { sidebarData } from './data/sidebar-data'

export const navLinks = sidebarData.navGroups.flatMap((g) => g.items).filter((i): i is NavLink => 'url' in i && typeof i.url === 'string')

/** The menu entry a path belongs to (/orders/12 → Orders). */
export function navItemFor(path: string): NavLink | undefined {
  const clean = path.replace(/\/+$/, '') || '/'
  return navLinks
    .filter((l) => (l.url === '/' ? clean === '/' : clean === l.url || clean.startsWith(`${l.url}/`)))
    .sort((a, b) => String(b.url).length - String(a.url).length)[0]
}

