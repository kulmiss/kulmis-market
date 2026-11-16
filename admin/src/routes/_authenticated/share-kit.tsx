import { createFileRoute } from '@tanstack/react-router'
import { ShareKitPage } from '@/features/content/share-kit-page'

export const Route = createFileRoute('/_authenticated/share-kit')({ component: ShareKitPage })
