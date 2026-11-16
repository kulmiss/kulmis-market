import { createFileRoute } from '@tanstack/react-router'
import { BundlesPage } from '@/features/shop/promotions-page'

export const Route = createFileRoute('/_authenticated/bundles')({ component: BundlesPage })
