import { createFileRoute } from '@tanstack/react-router'
import { ProductEditor } from '@/features/shop/product-editor'

function ProductRoute() {
  const { id } = Route.useParams()
  return <ProductEditor key={id} id={id} />
}

export const Route = createFileRoute('/_authenticated/products/$id')({ component: ProductRoute })
