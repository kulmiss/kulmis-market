import { createFileRoute } from '@tanstack/react-router'
import { ReviewsPage } from '@/features/shop/reviews-page'

export const Route = createFileRoute('/_authenticated/reviews')({ component: ReviewsPage })
