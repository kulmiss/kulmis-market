import { createFileRoute } from '@tanstack/react-router'
import { DemoPage } from '@/features/demo/demo-page'

export const Route = createFileRoute('/_authenticated/demo')({ component: DemoPage })
