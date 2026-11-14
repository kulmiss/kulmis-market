import { useQuery, useQueryClient } from '@tanstack/react-query'
import { CircleSlash } from 'lucide-react'
import { toast } from 'sonner'
import { api, errorMessage } from '@/lib/api'
import { type FeatureRow, useFeatures } from '@/stores/features-store'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Switch } from '@/components/ui/switch'
import { Page } from '@/components/km/page'

/** The "what's on/off" overview with every switch. */
export function FeaturesPage() {
  const qc = useQueryClient()
  const setRows = useFeatures((s) => s.setRows)
  const { data } = useQuery({
    queryKey: ['features'],
    queryFn: async () => (await api.get<{ data: FeatureRow[] }>('/features')).data.data,
  })

  async function toggle(key: string, enabled: boolean) {
    try {
      const res = await api.put<{ data: FeatureRow[] }>('/features', { features: { [key]: enabled } })
      qc.setQueryData(['features'], res.data.data)
      setRows(res.data.data)
      toast.success(`${enabled ? 'Turned on' : 'Turned off'}: ${res.data.data.find((r) => r.key === key)?.label}`)
    } catch (e) {
      toast.error(errorMessage(e))
    }
  }

  const rows = data ?? []
  const groups = [...new Set(rows.map((r) => r.group))]
  const onCount = rows.filter((r) => r.effective).length

  return (
    <Page title='Feature switches' description={`${onCount} of ${rows.length} features are live. Turning one off hides it everywhere (pages, app, admin) — no data is deleted.`}>
      <div className='grid gap-4 md:grid-cols-2 xl:grid-cols-3'>
        {groups.map((g) => (
          <Card key={g}>
            <CardHeader className='pb-2'><CardTitle className='text-base'>{g}</CardTitle></CardHeader>
            <CardContent className='grid gap-3'>
              {rows.filter((r) => r.group === g).map((r) => (
                <label key={r.key} className='flex items-center justify-between gap-3'>
                  <span className='text-sm'>
                    {r.label}
                    {r.enabled && !r.effective && (
                      <Badge variant='outline' className='ms-2 gap-1 text-[10px]'>
                        <CircleSlash className='size-3' /> off via {rows.find((p) => p.key === r.parent)?.label ?? r.parent}
                      </Badge>
                    )}
                  </span>
                  <Switch checked={r.enabled} onCheckedChange={(v) => toggle(r.key, v)} />
                </label>
              ))}
            </CardContent>
          </Card>
        ))}
      </div>
    </Page>
  )
}
