import { createFileRoute } from '@tanstack/react-router'
import { HomePageBuilder } from '@/features/home-page/home-page'

export const Route = createFileRoute('/_authenticated/home-page')({ component: HomePageBuilder })
