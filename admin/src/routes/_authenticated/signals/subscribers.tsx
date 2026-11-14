import { createFileRoute } from '@tanstack/react-router'
import { SubscribersPage } from '@/features/signals/signals-pages'

export const Route = createFileRoute('/_authenticated/signals/subscribers')({ component: SubscribersPage })
