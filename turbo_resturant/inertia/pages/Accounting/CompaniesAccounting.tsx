import { Button, Col, Row, Table, TableColumnsType } from 'antd'
import React from 'react'
import ReportHeaderMini from '../../components/ReportHeaderMini.js'
import { Customer, Order } from '../../types/Models.js'
import { Link, router } from '@inertiajs/react'

const customersColumns: TableColumnsType<{
  id: number
  name: string
  phone: string
  totalRemaining: number
}> = [
  {
    title: 'اسم العميل / الشركة',
    dataIndex: 'name',
    key: 'name',
    sorter: (a, b) => a.name.localeCompare(b.name),
  },
  {
    title: 'رقم الهاتف',
    dataIndex: 'phone',
    key: 'phone',
  },
  {
    title: 'المبلغ المتبقي',
    dataIndex: 'totalRemaining',
    key: 'totalRemaining',
  },
]

const ordersColumns: TableColumnsType<{
  id: number
  customer: string
  total: number
  paid: number
  remaining: number
}> = [
  {
    title: 'رقم الطلب المرجعي',
    dataIndex: 'id',
    key: 'id',
  },
  {
    title: 'رقم الطلب',
    dataIndex: 'orderNumber',
    key: 'order_number',
  },
  {
    title: 'العميل',
    dataIndex: 'customer',
    key: 'customer',
  },
  {
    title: 'المبلغ الكلي',
    dataIndex: 'total',
    key: 'total',
  },
  {
    title: 'المبلغ المدفوع',
    dataIndex: 'paid',
    key: 'paid',
  },
  {
    title: 'المبلغ المتبقي',
    dataIndex: 'remaining',
    key: 'remaining',
  },
  {
    title: 'التفاصيل',
    render: (record) => (
      <Button onClick={() => router.get(`/orders/${record.id}`)}>عرض التفاصيل</Button>
    ),
  },
]

const mappingCustomersData = (customers: Customer[]) =>
  customers.map((customer) => ({
    id: customer.id,
    name: customer.name,
    phone: customer.phone,
    totalRemaining: customer.orders?.reduce(
      (acc, order) =>
        acc + (order.total - order.payments!.reduce((acc, payment) => acc + payment.paid, 0)),
      0
    ),
  }))

const mappingOrdersData = (customers: Customer[], orders: Order[]) =>
  orders.map((order) => ({
    id: order.id,
    orderNumber: order.orderNumber,
    customer: customers.find((customer) => customer.id === order.customerId)?.name,
    total: order.total,
    paid: order.payments.reduce((acc, payment) => acc + payment.paid, 0),
    remaining: order.total - order.payments.reduce((acc, payment) => acc + payment.paid, 0),
  }))

export default function CompaniesAccounting({ customers }: { customers: Customer[] }) {
  const customersDataSource = mappingCustomersData(customers)

  const orders = customers.flatMap((customer) => customer.orders)
  const ordersDataSource = mappingOrdersData(customers, orders)

  return (
    <Row gutter={[0, 25]} className="m-8">
      <Col span={24} className="isolate">
        <ReportHeaderMini
          title="حسابات الشركات"
          dataSource={customersDataSource}
          columns={customersColumns}
        />
        <Table dataSource={customersDataSource} columns={customersColumns} pagination={false} />
      </Col>
      <Col span={24} className="isolate">
        <ReportHeaderMini
          title="تفاصيل الاوردرات الخاصة بالشركات"
          dataSource={ordersDataSource}
          columns={ordersColumns}
        />
        <Table dataSource={ordersDataSource} columns={ordersColumns} pagination={false} />
      </Col>
    </Row>
  )
}
