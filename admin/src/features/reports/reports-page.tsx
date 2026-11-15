import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { FileDown, FileText } from 'lucide-react'
import { api } from '@/lib/api'
import { formatMoney } from '@/lib/money'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Page } from '@/components/km/page'

type Report = { title: string; columns: Record<string, [string, 'text' | 'money' | 'int']>; rows: Record<string, string | number>[]; totals: Record<string, number>; from: string; to: string }

const today = () => new Date().toISOString().slice(0, 10)
const monthStart = () => { const d = new Date(); d.setDate(1); return d.toISOString().slice(0, 10) }

export function ReportsPage() {
  const [type, setType] = useState('revenue')
  const [from, setFrom] = useState(monthStart())
  const [to, setTo] = useState(today())
  const types = useQuery({ queryKey: ['report-types'], queryFn: async () => (await api.get<{ data: Record<string, string> }>('/reports')).data.data })
  const r = useQuery({ queryKey: ['report', type, from, to], queryFn: async () => (await api.get<{ data: Report }>(`/reports/${type}`, { params: { from, to } })).data.data })
  const cell = (v: string | number | undefined, kind: string) => (kind === 'money' ? formatMoney(Number(v ?? 0)) : String(v ?? ''))
  const exportUrl = (format: 'csv' | 'pdf') => `/api/v1/admin/reports/${type}?${new URLSearchParams({ from, to, format })}`
  const quick = (days: number) => { const d = new Date(); d.setDate(d.getDate() - days + 1); setFrom(d.toISOString().slice(0, 10)); setTo(today()) }
  return (
    <Page title='Reports' description='Real data only (demo data never counts). Pick a report and the dates; download as CSV (spreadsheets) or PDF.'>
      <div className='mb-4 grid gap-3 md:grid-cols-[1fr_auto_auto_auto]'>
        <Select value={type} onValueChange={setType}>
          <SelectTrigger aria-label='Report'><SelectValue /></SelectTrigger>
          <SelectContent>{Object.entries(types.data ?? {}).map(([k, v]) => <SelectItem key={k} value={k}>{v}</SelectItem>)}</SelectContent>
        </Select>
        <Input type='date' aria-label='From' value={from} max={to} onChange={(e) => setFrom(e.target.value)} />
        <Input type='date' aria-label='To' value={to} min={from} onChange={(e) => setTo(e.target.value)} />
        <div className='flex gap-2'>
          <Button variant='outline' asChild><a href={exportUrl('csv')}><FileDown /> CSV</a></Button>
          <Button variant='outline' asChild><a href={exportUrl('pdf')}><FileText /> PDF</a></Button>
        </div>
      </div>
      <div className='mb-4 flex flex-wrap gap-2 text-sm'>
        {[['Today', 1], ['7 days', 7], ['30 days', 30], ['90 days', 90], ['1 year', 365]].map(([l, n]) => <Button key={l} size='sm' variant='ghost' onClick={() => quick(Number(n))}>{l}</Button>)}
      </div>
      <Card>
        <CardContent className='overflow-x-auto p-0'>
          {r.isLoading ? <p className='p-6 text-sm text-muted-foreground'>Working it out…</p> : r.data && (
            <Table>
              <TableHeader><TableRow>{Object.entries(r.data.columns).map(([k, [label, kind]]) => <TableHead key={k} className={kind !== 'text' ? 'text-end' : ''}>{label}</TableHead>)}</TableRow></TableHeader>
              <TableBody>
                {r.data.rows.length === 0 && <TableRow><TableCell colSpan={Object.keys(r.data.columns).length} className='text-muted-foreground'>Nothing in these dates.</TableCell></TableRow>}
                {r.data.rows.map((row, i) => (
                  <TableRow key={i}>{Object.entries(r.data!.columns).map(([k, [, kind]]) => (
                    <TableCell key={k} className={kind !== 'text' ? 'text-end tabular-nums' : ''}><span className={kind === 'money' && Number(row[k]) < 0 ? 'text-red-700 dark:text-red-400' : ''}>{cell(row[k], kind)}</span></TableCell>
                  ))}</TableRow>
                ))}
              </TableBody>
              {r.data.rows.length > 1 && Object.keys(r.data.totals).length > 0 && (
                <TableFooter><TableRow>{Object.entries(r.data.columns).map(([k, [, kind]], i) => (
                  <TableCell key={k} className={kind !== 'text' ? 'text-end tabular-nums font-semibold' : 'font-semibold'}>{i === 0 ? 'Total' : k in r.data!.totals ? cell(r.data!.totals[k], kind) : ''}</TableCell>
                ))}</TableRow></TableFooter>
              )}
            </Table>
          )}
        </CardContent>
      </Card>
    </Page>
  )
}
