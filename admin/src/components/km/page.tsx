import { Link, useLocation } from '@tanstack/react-router'
import { useQuery } from '@tanstack/react-query'
import { CircleHelp } from 'lucide-react'
import { guideFor, guideListQuery } from '@/features/guide/api'
import { Button } from '@/components/ui/button'
import { Header } from '@/components/layout/header'
import { Main } from '@/components/layout/main'
import { ProfileDropdown } from '@/components/profile-dropdown'
import { Search } from '@/components/search'
import { ThemeSwitch } from '@/components/theme-switch'

type PageProps = {
  title: string
  description?: string
  actions?: React.ReactNode
  children: React.ReactNode
}

/** Standard admin page: header bar + title row + content. */
export function Page({ title, description, actions, children }: PageProps) {
  return (
    <>
      <Header fixed>
        <Search />
        <div className='ms-auto flex items-center space-x-4'>
          <PageHelp />
          <ThemeSwitch />
          <ProfileDropdown />
        </div>
      </Header>
      <Main>
        <div className='mb-4 flex flex-wrap items-end justify-between gap-2'>
          <div>
            <h1 className='text-2xl font-bold tracking-tight'>{title}</h1>
            {description && <p className='text-muted-foreground'>{description}</p>}
          </div>
          {actions && <div className='flex flex-wrap gap-2'>{actions}</div>}
        </div>
        {children}
      </Main>
    </>
  )
}

/** "?" in the top bar: opens the guide page about this screen. */
function PageHelp() {
  const path = useLocation({ select: (l) => l.pathname })
  const { data } = useQuery(guideListQuery)
  const slug = guideFor(data, path)
  if (!slug) return null
  return (
    <Button variant='ghost' size='icon' className='rounded-full' asChild>
      <Link to='/guide/$slug' params={{ slug }} aria-label='Help for this page' title='Help for this page' data-page-help><CircleHelp className='size-5' /></Link>
    </Button>
  )
}
