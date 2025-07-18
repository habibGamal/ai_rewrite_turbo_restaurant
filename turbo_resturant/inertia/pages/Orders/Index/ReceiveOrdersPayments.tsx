import { PaymentMethod } from '#enums/PaymentEnums'
import { router } from '@inertiajs/react'
import { Badge, Button, Col, Popconfirm, Row, Space, Typography } from 'antd'
import { Cd } from 'iconsax-react'
import { orderStatus } from '../../../helpers/orderState.js'
import { Order as OrderType } from '~/types/Models.js'

export default function ReceiveOrdersPayments({ orders }: { orders: OrderType[] }) {
  orders.sort((a, b) => {
    if (a.status === b.status) {
      return 0
    }
    return a.status > b.status ? -1 : 1
  })

  return (
    <Row gutter={[24, 16]}>
      {orders.map((order) => (
        <Order key={order.id} order={order} />
      ))}
    </Row>
  )
}

const Order = ({ order }: { order: OrderType }) => {
  const requiredAmount = (
    order.total - order.payments!.reduce((acc, payment) => acc + payment.paid, 0)
  ).toFixed(2)
  const confirmCash = () => {
    router.post(`/orders/pay-old-order/${order.id}`, {
      paid: requiredAmount,
      method: PaymentMethod.Cash,
    })
  }
  const confirmCard = () => {
    router.post(`/orders/pay-old-order/${order.id}`, {
      paid: requiredAmount,
      method: PaymentMethod.Card,
    })
  }

  return (
    <Col span={6}>
      <Badge.Ribbon {...orderStatus(order.status)}>
        <div className="isolate grid place-items-center gap-4 rounded">
          <Typography.Title level={4}># طلب رقم {order.id}</Typography.Title>
          <Typography.Title className="flex items-center gap-2" level={5}>
            <Cd color="#d7a600" />
            تاريخ الطلب {order.createdAt}
          </Typography.Title>
          <Typography.Text>المتبقي {requiredAmount} جنيه</Typography.Text>
          <Space>
            <Popconfirm
              title="تسديد قيمة هذا الطلب؟"
              description={`هل انت متأكد من تسديد قيمة هذا الطلب (${requiredAmount})؟`}
              onConfirm={confirmCash}
              okText="تم"
              cancelText="الغاء"
              okButtonProps={{ size: 'large' }}
              cancelButtonProps={{ size: 'large' }}
            >
              <Button type="primary">تسديد كاش</Button>
            </Popconfirm>
            <Popconfirm
              title="تسديد قيمة هذا الطلب؟"
              description={`هل انت متأكد من تسديد قيمة هذا الطلب (${requiredAmount})؟`}
              onConfirm={confirmCard}
              okText="تم"
              cancelText="الغاء"
              okButtonProps={{ size: 'large' }}
              cancelButtonProps={{ size: 'large' }}
            >
              <Button type="primary">تسديد Card</Button>
            </Popconfirm>
          </Space>
        </div>
      </Badge.Ribbon>
    </Col>
  )
}
