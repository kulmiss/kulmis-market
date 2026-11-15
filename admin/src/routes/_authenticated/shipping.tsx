import { createFileRoute } from '@tanstack/react-router'
import { ShippingPage } from '@/features/shop/catalog-pages'

export const Route = createFileRoute('/_authenticated/shipping')({ component: ShippingPage })
