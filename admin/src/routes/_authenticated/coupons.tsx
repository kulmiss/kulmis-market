import { createFileRoute } from '@tanstack/react-router'
import { CouponsPage } from '@/features/shop/catalog-pages'

export const Route = createFileRoute('/_authenticated/coupons')({ component: CouponsPage })
