import { createFileRoute } from '@tanstack/react-router'
import { CustomOrdersPage } from '@/features/shop/custom-orders-page'

export const Route = createFileRoute('/_authenticated/custom-orders')({ component: CustomOrdersPage })
