import { createFileRoute } from '@tanstack/react-router'
import { OrderDetail } from '@/features/shop/order-detail'

export const Route = createFileRoute('/_authenticated/orders/$id')({ component: OrderRoute })

function OrderRoute() {
  const { id } = Route.useParams()
  return <OrderDetail key={id} id={id} />
}
