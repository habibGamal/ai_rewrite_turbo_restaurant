import { router } from '@inertiajs/react'
import { App, Button, Col, Descriptions, Row, Table, Tag } from 'antd/es'
import { PaymentStatus } from '#enums/InvoicePaymentEnums'
import PageTitle from '../../../components/PageTitle.js'
import { ReturnPurchaseInvoice } from '../../../types/Models.js'

const columns = [
  {
    title: 'أسم الصنف',
    dataIndex: 'name',
    key: 'name',
  },
  {
    title: 'سعر الشراء',
    dataIndex: 'price',
    key: 'price',
  },
  {
    title: 'عدد الوحدات',
    dataIndex: 'quantity',
    key: 'quantity',
  },
  {
    title: 'الاجمالي',
    dataIndex: 'totalCost',
    key: 'totalCost',
  },
]
const remapInvoiceData = (invoice: ReturnPurchaseInvoice) =>
  invoice.items.map((item) => ({
    name: item.product.name,
    price: item.price,
    quantity: item.quantity,
    totalCost: item.price * item.quantity,
  }))

const InvoiceStatus = ({ invoice }: { invoice: ReturnPurchaseInvoice }) => {
  const { modal } = App.useApp()

  const payOldInvoice = () => {
    modal.confirm({
      title: 'تسديد الفاتورة',
      content: `هل انت متأكد من تسديد الفاتورة رقم ${invoice.id}`,
      onOk: () => {
        router.post('/pay-old-return-invoice', { invoiceId: invoice.id })
      },
      okText: 'نعم',
      cancelText: 'لا',
    })
  }
  switch (invoice.status) {
    case PaymentStatus.FullPaid:
      return <Tag color="green">{invoice.statusString}</Tag>
    case PaymentStatus.PartialPaid:
      return (
        <>
          <Tag color="red">{invoice.statusString}</Tag>
          <Button onClick={payOldInvoice} className="mx-4">
            تم التسديد
          </Button>
        </>
      )
  }
}
export default function ReturnPurchaseInvoiceShow({ invoice }: { invoice: ReturnPurchaseInvoice }) {


  return (
    <Row gutter={[0, 25]} className="m-8">
      <PageTitle name="عرض فاتورة مشتريات" />
      <Col span="24" className="isolate-2">
        <Descriptions className="w-full" bordered>
          <Descriptions.Item label="رقم الفاتورة">{invoice.id}</Descriptions.Item>
          <Descriptions.Item label="الاجمالي">{invoice.total}</Descriptions.Item>
          <Descriptions.Item label="المدفوع">{invoice.received}</Descriptions.Item>
          <Descriptions.Item label="تاريخ الفاتورة">{invoice.createdAt}</Descriptions.Item>
          <Descriptions.Item label="حالة الفاتورة">
            <InvoiceStatus invoice={invoice} />
          </Descriptions.Item>
        </Descriptions>
      </Col>
      <Col span="24" className="isolate">
        <Table columns={columns} dataSource={remapInvoiceData(invoice)} pagination={false} />
      </Col>
    </Row>
  )
}
