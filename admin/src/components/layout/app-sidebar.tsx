import { useEffect } from 'react'
import { useLayout } from '@/context/layout-provider'
import { useAuthStore } from '@/stores/auth-store'
import { useFeatures } from '@/stores/features-store'
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarRail } from '@/components/ui/sidebar'
import { AppTitle } from './app-title'
import { sidebarData } from './data/sidebar-data'
import { NavGroup } from './nav-group'
import { NavUser } from './nav-user'

export function AppSidebar() {
  const { collapsible, variant } = useLayout()
  const user = useAuthStore((s) => s.user)
  const can = useAuthStore((s) => s.can)
  const features = useFeatures()

  useEffect(() => {
    if (!features.loaded) features.load()
  }, [features])

  // Screens of switched-off features keep their history: they move to their own group at the end.
  const mine = sidebarData.navGroups.map((g) => ({ ...g, items: g.items.filter((i) => !i.ability || can(i.ability)) }))
  const groups = mine.map((g) => ({ ...g, items: g.items.filter((i) => features.on(i.feature)) })).filter((g) => g.items.length > 0)
  const off = mine.flatMap((g) => g.items.filter((i) => !features.on(i.feature)))
  if (off.length > 0) groups.push({ title: 'Switched-off features', items: off })

  return (
    <Sidebar collapsible={collapsible} variant={variant}>
      <SidebarHeader>
        <AppTitle />
      </SidebarHeader>
      <SidebarContent>
        {groups.map((props) => (
          <NavGroup key={props.title} {...props} />
        ))}
      </SidebarContent>
      <SidebarFooter>
        <NavUser user={{ name: user?.name ?? '', email: user?.role_label ?? '', avatar: '' }} />
      </SidebarFooter>
      <SidebarRail />
    </Sidebar>
  )
}
