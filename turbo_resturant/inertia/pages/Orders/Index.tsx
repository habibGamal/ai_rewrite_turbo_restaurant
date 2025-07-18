import { OrderStatus, OrderType } from '#enums/OrderEnums'
import { router, usePage } from '@inertiajs/react'
import { Button, Descriptions, Form, InputNumber, Modal, Popconfirm, Tabs } from 'antd'
import React from 'react'
import IsAdmin from '../../components/IsAdmin.js'
import useModal from '../../hooks/useModal.js'
import CashierLayout from '../../layouts/CashierLayout.js'
import { Order } from '../../types/Models.js'
import Companies from './Index/Companies.js'
import Delivery from './Index/Delivery.js'
import DineIn from './Index/DineIn.js'
import Display from './Index/Display.js'
import ReceiveOrdersPayments from './Index/ReceiveOrdersPayments.js'
import ShiftExpenses from './Index/ShiftExpenses.js'
import Takeaway from './Index/Takeaway.js'
import Talabat from './Index/Talabat.js'
type Props = {
  orders: Order[]
  previousPartialPaidOrders: Order[]
}
type User = {
  email: string
}
export default function Orders({ orders, previousPartialPaidOrders }: Props) {
  const { user } = usePage().props
  const dineInOrders = orders.filter(
    (order) => order.type === OrderType.DineIn && order.status === OrderStatus.Processing
  )
  const takeawayOrders = orders.filter(
    (order) => order.type === OrderType.Takeaway && order.status === OrderStatus.Processing
  )
  const deliveryOrders = orders.filter(
    (order) => order.type === OrderType.Delivery && order.status === OrderStatus.Processing
  )
  const talabatOrders = orders.filter(
    (order) => order.type === OrderType.Talabat && order.status === OrderStatus.Processing
  )
  const companiesOrders = orders.filter(
    (order) => order.type === OrderType.Companies && order.status === OrderStatus.Processing
  )
  const cancelledOrders = orders.filter((order) => order.status === OrderStatus.Cancelled)
  const completedOrders = orders.filter((order) => order.status === OrderStatus.Completed)

  const [tab, setTab] = React.useState(window.location.hash.replace('#', '') || 'dine_in')
  const endShiftModal = useModal()
  const [form] = Form.useForm()
  const endShift = (values: any) => {
    router.post('/end-shift', { ...values })
  }
  const endShiftWithZero = () => {
    router.post('/end-shift', { realEndCash: 0 })
  }
  return (
    <div className="p-4">
      <div className="flex justify-between">
        <Descriptions>
          <Descriptions.Item label="الموظف">{(user as User)?.email}</Descriptions.Item>
        </Descriptions>
        <IsAdmin>
          <Popconfirm
            title="هل انت متأكد؟"
            onConfirm={() => endShiftWithZero()}
            okText="نعم"
            cancelText="لا"
          >
            <Button>انهاء الشيفت</Button>
          </Popconfirm>
        </IsAdmin>
        <Modal {...endShiftModal} title="انهاء الشيفت" footer={null}>
          <Form onFinish={endShift} layout="vertical" form={form}>
            <Form.Item
              name="realEndCash"
              label="النقدية في الدرج"
              rules={[
                {
                  required: true,
                  message: 'النقدية في الدرج مطلوبة',
                },
              ]}
            >
              <InputNumber min={0} className="w-full" />
            </Form.Item>
            <Form.Item>
              <Button htmlType="submit" type="primary">
                تم
              </Button>
            </Form.Item>
          </Form>
        </Modal>
      </div>
      <Tabs
        onChange={(key) => {
          setTab(key)
          window.location.hash = key
        }}
        activeKey={tab}
        type="card"
        size="large"
        items={[
          {
            label: 'الصالة',
            children: <DineIn orders={dineInOrders} />,
            key: 'dine_in',
          },
          {
            label: 'الديلفري',
            children: <Delivery orders={deliveryOrders} />,
            key: 'delivery',
          },
          {
            label: 'التيك اواي',
            children: <Takeaway orders={takeawayOrders} />,
            key: 'takeaway',
          },
          {
            label: 'طلبات',
            children: <Talabat orders={talabatOrders} />,
            key: 'talabat',
          },
          {
            label: 'شركات',
            children: <Companies orders={companiesOrders} />,
            key: 'companies',
          },
          {
            label: 'تسديد اوردرات شركات سابقة',
            children: <ReceiveOrdersPayments orders={previousPartialPaidOrders} />,
            key: 'receive_orders_payments',
          },
          {
            label: 'مصاريف',
            children: <ShiftExpenses />,
            key: 'expenses',
          },
          {
            label: 'ملغي',
            children: <Display orders={cancelledOrders} />,
            key: 'cancelled',
          },
          {
            label: 'منتهي',
            children: <Display orders={completedOrders} />,
            key: 'completed',
          },
        ]}
      />
    </div>
  )
}

Orders.layout = (page: any) => <CashierLayout children={page} />
