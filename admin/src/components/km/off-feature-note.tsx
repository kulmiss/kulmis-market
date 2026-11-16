import { useEffect, useMemo } from 'react'
import { TriangleAlert } from 'lucide-react'
import { offMentions, useFeatures } from '@/stores/features-store'

/** "This text mentions Signals, which is off" under a text field (nothing when all is fine). */
export function OffFeatureNote({ text, hidden }: { text: string; hidden?: string }) {
  const { words, on, label, loaded, load } = useFeatures()
  useEffect(() => {
    if (!loaded) load()
  }, [loaded, load])
  const off = useMemo(() => offMentions(text, words, on, label), [text, words, on, label])
  if (off.length === 0) return null
  const labels = off.map((o) => o.label)
  const names = labels.length === 1 ? labels[0] : `${labels.slice(0, -1).join(', ')} and ${labels[labels.length - 1]}`
  return (
    <p role='status' className='flex items-start gap-2 rounded-md border border-amber-500/40 bg-amber-500/10 px-3 py-2 text-xs font-normal leading-snug text-amber-900 dark:text-amber-200'>
      <TriangleAlert className='mt-0.5 size-4 shrink-0' />
      <span>
        This text mentions <b>{names}</b>, which {off.length === 1 ? 'is' : 'are'} off. {hidden ?? 'Customers would still see it.'} To keep it, put that part in{' '}
        <code className='whitespace-nowrap'>{`{if ${off[0].key}}…{/if}`}</code>: it then shows only while that is on.
      </span>
    </p>
  )
}
