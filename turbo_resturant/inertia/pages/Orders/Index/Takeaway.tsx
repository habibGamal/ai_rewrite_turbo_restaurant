import { OrderType } from '#enums/OrderEnums'
import { Link, router } from '@inertiajs/react'
import { Badge, Button, Col, Row, Typography } from 'antd'
import { Add, Cd } from 'iconsax-react'
import { orderStatus } from '../../../helpers/orderState.js'
import { Order } from '../../../types/Models.js'

export default function Takeaway({ orders }: { orders: Order[] }) {
  const makeNewOrder = (values: any) => {
    router.post(`/make-order`, { type: OrderType.Takeaway })
  }

  orders.sort((a, b) => {
    if (a.status === b.status) {
      return 0
    }
    return a.status > b.status ? -1 : 1
  })

  return (
    <Row gutter={[24, 16]}>
      <Col span={6}>
        <Button
          onClick={makeNewOrder}
          className="h-full"
          icon={<Add size={128} />}
          type="primary"
          block
        />
      </Col>
      {orders.map((order) => (
        <TakeAwayOrder key={order.id} order={order} />
      ))}
    </Row>
  )
}

const TakeAwayOrder = ({ order }: { order: Order }) => {
  return (
    <Col span={6}>
      <Link href={`/orders/manage-order/${order.id}`}>
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
