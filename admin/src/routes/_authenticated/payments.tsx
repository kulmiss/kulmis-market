import { createFileRoute } from '@tanstack/react-router'
import { PaymentsPage } from '@/features/shop/payments-page'

export const Route = createFileRoute('/_authenticated/payments')({ component: PaymentsPage })
