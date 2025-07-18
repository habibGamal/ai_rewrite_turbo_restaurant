import React from 'react'
import { Button, Col, Descriptions, Modal, Row, Space, Table, Tag } from 'antd/es'
import PageTitle from '../../components/PageTitle.js'
import { PurchaseInvoice, Stocktaking } from '../../types/Models.js'
import { PaymentStatus } from '#enums/InvoicePaymentEnums'
import { render } from 'react-dom'

const columns = [
  {
    title: 'أسم الصنف',
    dataIndex: 'name',
    key: 'name',
  },
  {
    title: 'تكلفة الوحدة',
    dataIndex: 'cost',
    key: 'cost',
  },
  {
    title: 'فرق الكمية',
    dataIndex: 'quantity',
    key: 'quantity',
    render: (quantity: number) =>
      quantity <= 0 ? <Tag color="red">{quantity}</Tag> : <Tag color="green">{quantity}</Tag>,
  },
  {
    title: 'فرق النقدية',
    dataIndex: 'total',
    key: 'total',
    render: (text: any) => text.toFixed(2),
  },
]
const remapData = (stocktaking: Stocktaking) =>
  stocktaking.items.map((item) => ({
    name: item.product.name,
    cost: item.cost,
    quantity: item.quantity,
    total: item.total,
  }))

export default function Show({ stocktaking }: { stocktaking: Stocktaking }) {


  return (
    <Row gutter={[0, 25]} className="m-8">
      <PageTitle name="عرض جرد مخزون" />
      <Col span="24" className="isolate-2">
        <Descriptions className="w-full" bordered>
          <Descriptions.Item label="رقم الجرد">{stocktaking.id}</Descriptions.Item>
          <Descriptions.Item label="محصلة الجرد">{stocktaking.balance}</Descriptions.Item>
          <Descriptions.Item label="تاريخ الجرد">{stocktaking.createdAt}</Descriptions.Item>
        </Descriptions>
      </Col>
      <Col span="24" className="isolate">
        <Table columns={columns} dataSource={remapData(stocktaking)} pagination={false} />
      </Col>
    </Row>
  )
}
