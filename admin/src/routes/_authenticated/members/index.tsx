import { createFileRoute } from '@tanstack/react-router'
import { MembersPage } from '@/features/members/members-pages'

export const Route = createFileRoute('/_authenticated/members/')({ component: MembersPage })
