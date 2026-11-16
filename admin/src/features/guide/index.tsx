import { useMemo, useState } from 'react'
import { Link } from '@tanstack/react-router'
import { useQuery } from '@tanstack/react-query'
import { ArrowLeft, ArrowRight, FileDown, Search } from 'lucide-react'
import { api } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Page } from '@/components/km/page'
import { PageSkeleton } from '@/components/km/page-skeleton'
import { guideListQuery } from './api'

/** The Super Admin guide: setup, daily use, problems and answers, quick reference. */
export function GuideIndex() {
  const { data } = useQuery(guideListQuery)
  const [q, setQ] = useState('')
  const hits = useMemo(() => {
    const words = q.toLowerCase().split(/\s+/).filter(Boolean)
    return (data ?? []).filter((g) => words.every((w) => `${g.title} ${g.text}`.toLowerCase().includes(w)))
  }, [data, q])
  const parts = [...new Set(hits.map((g) => g.part))]

  return (
    <Page title='Guide' description='How to set up and run the shop, step by step. The numbers are your real settings.'
      actions={<Button variant='outline' asChild><a href='/api/v1/admin/guide/pdf' data-guide-pdf><FileDown /> Download as PDF</a></Button>}>
      <div className='relative mb-4 max-w-xl'>
        <Search className='absolute start-3 top-2.5 size-4 text-muted-foreground' />
        <Input className='ps-9' placeholder='Search the guide, e.g. refund, cash-out, telegram' value={q} onChange={(e) => setQ(e.target.value)} aria-label='Search the guide' data-guide-search />
      </div>
      {!data ? <PageSkeleton /> : (
        <div className='grid gap-6' data-guide-list>
          {parts.map((part) => (
            <section key={part}>
              <h2 className='mb-2 font-semibold'>{part}</h2>
              <div className='grid gap-2 sm:grid-cols-2 lg:grid-cols-3'>
                {hits.filter((g) => g.part === part).map((g) => (
                  <Link key={g.slug} to='/guide/$slug' params={{ slug: g.slug }} className='rounded-lg border p-3 text-sm font-medium hover:bg-muted' data-guide-item={g.slug}>{g.title}</Link>
                ))}
              </div>
            </section>
          ))}
          {hits.length === 0 && <p className='text-sm text-muted-foreground'>Nothing found. Try another word.</p>}
        </div>
      )}
    </Page>
  )
}

export function GuidePage({ slug }: { slug: string }) {
  const list = useQuery(guideListQuery).data
  const { data, isError } = useQuery({ queryKey: ['guide', slug], queryFn: async () => (await api.get<{ data: { title: string; part: string; html: string } }>(`/guide/${slug}`)).data.data })
  const i = list?.findIndex((g) => g.slug === slug) ?? -1
  const prev = list && i > 0 ? list[i - 1] : null
  const next = list && i >= 0 && i < list.length - 1 ? list[i + 1] : null

  return (
    <Page title={data?.title ?? 'Guide'} description={data?.part}
      actions={<Button variant='outline' asChild><Link to='/guide'><ArrowLeft /> All guide pages</Link></Button>}>
      {isError ? <p>This guide page does not exist. <Link to='/guide' className='underline'>See all pages</Link>.</p> : !data ? <PageSkeleton /> : (
        <div className='grid gap-4'>
          <Card><CardContent className='km-prose max-w-3xl' data-guide-body dangerouslySetInnerHTML={{ __html: data.html }} /></Card>
          <div className='flex flex-wrap justify-between gap-2'>
            {prev ? <Button variant='ghost' asChild><Link to='/guide/$slug' params={{ slug: prev.slug }}><ArrowLeft /> {prev.title}</Link></Button> : <span />}
            {next && <Button variant='ghost' asChild><Link to='/guide/$slug' params={{ slug: next.slug }}>{next.title} <ArrowRight /></Link></Button>}
          </div>
        </div>
      )}
    </Page>
  )
}
