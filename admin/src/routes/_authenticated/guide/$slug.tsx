import { createFileRoute } from '@tanstack/react-router'
import { GuidePage } from '@/features/guide'

export const Route = createFileRoute('/_authenticated/guide/$slug')({ component: GuideRoute })

function GuideRoute() {
  const { slug } = Route.useParams()
  return <GuidePage key={slug} slug={slug} />
}
