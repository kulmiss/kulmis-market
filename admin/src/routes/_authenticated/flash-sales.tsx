import { createFileRoute } from '@tanstack/react-router'
import { FlashSalesPage } from '@/features/shop/promotions-page'

export const Route = createFileRoute('/_authenticated/flash-sales')({ component: FlashSalesPage })
