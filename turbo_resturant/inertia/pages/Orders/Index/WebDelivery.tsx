import { Link } from '@inertiajs/react'
import { Badge, Col, Empty, Row, Typography } from 'antd'
import { Cd } from 'iconsax-react'
import { orderStatus } from '../../../helpers/orderState.js'
import { Order } from '../../../types/Models.js'

export default function WebDelivery({ orders }: { orders: Order[] }) {
  orders.sort((a, b) => {
    return a.orderNumber > b.orderNumber ? -1 : 1
  })

  if (orders.length === 0)
    return <Empty className='mt-8' image={Empty.PRESENTED_IMAGE_SIMPLE} description="لا يوجد طلبات" />
  return (
    <Row gutter={[24, 16]}>
      {orders.map((order) => (
        <WebDeliveryOrder key={order.id} order={order} />
      ))}
    </Row>
  )
}

const WebDeliveryOrder = ({ order }: { order: Order }) => {
  return (
    <Col span={6}>
      <Link href={`/orders/manage-web-order/${order.id}`}>
        <Badge.Ribbon {...orderStatus(order.status)}>
          <div className="isolate grid place-items-center gap-4 rounded">
            <Typography.Title level={4}># طلب رقم {order.orderNumber}</Typography.Title>
            <Typography.Title className="flex items-center gap-2" level={5}>
              <Cd color="#d7a600" />
              رقم العميل {order.customer?.phone || 'غير معروف'}
            </Typography.Title>
          </div>
        </Badge.Ribbon>
      </Link>
    </Col>
  )
}
