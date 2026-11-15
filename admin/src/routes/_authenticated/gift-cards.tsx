import { createFileRoute } from '@tanstack/react-router'
import { GiftCardsPage } from '@/features/shop/gift-cards-page'

export const Route = createFileRoute('/_authenticated/gift-cards')({ component: GiftCardsPage })
