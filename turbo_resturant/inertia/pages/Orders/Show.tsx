import { Col, Descriptions, Row, Table } from 'antd/es'
import PageTitle from '../../components/PageTitle.js'
import { Order } from '../../types/Models.js'

const columns = [
  {
    title: 'أسم الصنف',
    dataIndex: 'productId',
    key: 'name',
    render(text: string, record: any) {
      return <span>{record.product?.name}</span>
    },
  },
  {
    title: 'السعر',
    dataIndex: 'price',
    key: 'price',
  },
  {
    title: 'الكمية',
    dataIndex: 'quantity',
    key: 'quantity',
  },
  {
    title: 'الاجمالي',
    dataIndex: 'total',
    key: 'total',
  },
]

export default function Show({ order }: { order: Order }) {
  const details = [
    {
      key: '1',
      label: 'نوع الطلب',
      children: order.typeString,
    },
    {
      key: '2',
      label: 'رقم  الطلب المرجعي',
      children: order.id,
    },
    {
      key: 'orderNumber',
      label: 'رقم  الطلب',
      children: order.orderNumber,
    },
    {
      key: 'orderNumber',
      label: 'رقم الوردية',
      children: order.shiftId ,
    },

    {
      key: '3',
      label: 'تاريخ الطلب',
      children: order.createdAt,
    },
    {
      key: '4',
      label: 'رقم الطاولة',
      children: order.dineTableNumber,
    },
    {
      key: '5',
      label: 'رقم العميل',
      children: order.customer ? order.customer.phone : 'لا يوجد',
    },
    {
      key: '6',
      label: 'ملاحظات',
      children: order.orderNotes,
    },
  ]

  const payments = [
    {
      key: '1',
      label: 'المجموع',
      children: order.subTotal.toFixed(1),
    },
    {
      key: '2',
      label: 'الضريبة',
      children: order.tax.toFixed(1),
    },
    {
      key: '3',
      label: 'الخدمة',
      children: order.service.toFixed(1),
    },
    {
      key: '4',
      label: 'الخصم',
      children: order.discount.toFixed(1),
    },
    {
      key: '5',
      label: 'الاجمالي',
      children: order.total.toFixed(1),
    },
  ]

  return (
    <Row gutter={[0, 25]} className="m-8">
      <PageTitle name="عرض طلب" />
      <Col span="24" className="isolate-2">
        <Descriptions bordered title="بيانات الطلب" column={2} items={details} />
      </Col>
      <Col span="24" className="isolate-2">
        <Descriptions bordered title="الحساب" column={2} items={payments} />
      </Col>
      <Col span="24" className="isolate">
        <Table columns={columns} dataSource={order.items} pagination={false} />
      </Col>
    </Row>
  )
}
