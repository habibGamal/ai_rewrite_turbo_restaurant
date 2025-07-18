import { OrderType } from '#enums/OrderEnums'
import { Link, router } from '@inertiajs/react'
import { Badge, Button, Col, Row, Typography } from 'antd'
import { Add, Cd } from 'iconsax-react'
import ChooseTableForm from '../../../components/ManageOrder/ChooseTableForm.js'
import { orderStatus } from '../../../helpers/orderState.js'
import useModal from '../../../hooks/useModal.js'
import { Order } from '../../../types/Models.js'

export default function DineIn({ orders }: { orders: Order[] }) {
  const tableModal = useModal()

  const addTable = () => {
    tableModal.showModal()
  }

  const makeNewOrder = (values: any) => {
    const tableNumber = `${values.tableType} - ${values.tableNumber}`
    router.post('/make-order', {
      tableNumber,
      type: OrderType.DineIn,
    })
  }

  orders.sort((a, b) => {
    if (a.status === b.status) {
      return 0
    }
    return a.status > b.status ? -1 : 1
  })

  return (
    <Row gutter={[24, 16]}>
      <ChooseTableForm tableModal={tableModal} onFinish={makeNewOrder} />
      <Col span={6}>
        <Button
          onClick={addTable}
          className="h-full"
          icon={<Add size={128} />}
          type="primary"
          block
        />
      </Col>
      {orders.map((order) => (
        <DineOrder key={order.id} order={order} />
      ))}
    </Row>
  )
}

const DineOrder = ({ order }: { order: Order }) => {
  return (
    <Col span={6}>
      <Link href={`/orders/manage-order/${order.id}`}>
        <Badge.Ribbon {...orderStatus(order.status)}>
          <div className="isolate grid place-items-center gap-4 rounded">
            <Typography.Title className="flex items-center gap-2" level={4}>
              <Cd color="#d7a600" />
              طاولة {order.dineTableNumber}
            </Typography.Title>
            <Typography.Title level={5}># طلب رقم {order.orderNumber}</Typography.Title>
          </div>
        </Badge.Ribbon>
      </Link>
    </Col>
  )
}
