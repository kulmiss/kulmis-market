import { createFileRoute } from '@tanstack/react-router'
import { MemberDetail } from '@/features/members/members-pages'

function MemberRoute() {
  const { id } = Route.useParams()
  return <MemberDetail key={id} id={id} />
}

export const Route = createFileRoute('/_authenticated/members/$id')({ component: MemberRoute })
