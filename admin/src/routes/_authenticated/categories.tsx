import { createFileRoute } from '@tanstack/react-router'
import { CategoriesPage } from '@/features/shop/catalog-pages'

export const Route = createFileRoute('/_authenticated/categories')({ component: CategoriesPage })
