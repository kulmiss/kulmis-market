import { createFileRoute } from '@tanstack/react-router'
import { CommissionsPage } from '@/features/money/money-pages'

export const Route = createFileRoute('/_authenticated/commissions')({ component: CommissionsPage })
