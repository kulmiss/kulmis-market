import { Link } from '@tanstack/react-router'
import { ScrollText, SlidersHorizontal, ToggleRight } from 'lucide-react'
import { Card, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Page } from '@/components/km/page'

const links = [
  { to: '/features', title: 'Feature switches', desc: 'What is on and off', icon: ToggleRight },
  { to: '/config', title: 'Settings', desc: 'Prices, rates, periods, texts', icon: SlidersHorizontal },
  { to: '/activity', title: 'Activity log', desc: 'Every important change', icon: ScrollText },
] as const

export function Dashboard() {
  return (
    <Page title='Dashboard' description='Welcome to the Kulmis Market admin.'>
      <div className='grid gap-4 sm:grid-cols-2 lg:grid-cols-4'>
        {links.map((l) => (
          <Link key={l.to} to={l.to}>
            <Card className='transition-colors hover:bg-muted/50'>
              <CardHeader>
                <l.icon className='size-5 text-muted-foreground' />
                <CardTitle className='text-base'>{l.title}</CardTitle>
                <CardDescription>{l.desc}</CardDescription>
              </CardHeader>
            </Card>
          </Link>
        ))}
      </div>
    </Page>
  )
}
