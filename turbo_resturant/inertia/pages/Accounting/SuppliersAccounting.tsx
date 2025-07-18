import { router } from '@inertiajs/react'
import { Button, Col, Row, Table, TableColumnsType } from 'antd'
import { PaymentStatus } from '#enums/InvoicePaymentEnums'
import ReportHeaderMini from '../../components/ReportHeaderMini.js'
import { PurchaseInvoice, ReturnPurchaseInvoice, Supplier } from '../../types/Models.js'

const suppliersColumns: TableColumnsType<{
  id: number
  name: string
  purchaseAmountRemaining: number
  returnAmountRemaining: number
}> = [
  {
    title: 'اسم المورد',
    dataIndex: 'name',
    key: 'name',
    sorter: (a, b) => a.name.localeCompare(b.name),
  },
  {
    title: 'المبلغ المتبقي من عمليات الشراء',
    dataIndex: 'purchaseAmountRemaining',
    key: 'purchaseAmountRemaining',
  },
  {
    title: 'المبلغ المتبقي من عمليات المرتجع',
    dataIndex: 'returnAmountRemaining',
    key: 'returnAmountRemaining',
  },
]

const purchaseColumns: TableColumnsType<{
  id: number
  supplier: string | undefined
  total: number
  paid: number
  remaining: number
}> = [
  {
    title: 'رقم الفاتورة',
    dataIndex: 'id',
    key: 'id',
  },
  {
    title: 'المورد',
    dataIndex: 'supplier',
    key: 'supplier',
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
    // display the invoice
    title: 'عرض الفاتورة',
    key: 'action',
    render: (text, record) => (
      <Button
        onClick={() => {
          router.get(`/purchase-invoices/${record.id}`)
        }}
      >
        عرض الفاتورة
      </Button>
    ),
  },
]

const returnColumns: TableColumnsType<{
  id: number
  supplier: string | undefined
  total: number
  paid: number
  remaining: number
}> = [
  {
    title: 'رقم الفاتورة',
    dataIndex: 'id',
    key: 'id',
  },
  {
    title: 'المورد',
    dataIndex: 'supplier',
    key: 'supplier',
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
    title: 'عرض الفاتورة',
    key: 'action',
    render: (text, record) => (
      <Button
        onClick={() => {
          router.get(`/return-purchase-invoices/${record.id}`)
        }}
      >
        عرض الفاتورة
      </Button>
    ),
  },
]

const mappingSuppliersData = (suppliers: Supplier[]) =>
  suppliers.map((supplier) => ({
    id: supplier.id,
    name: supplier.name,
    purchaseAmountRemaining: supplier.purchaseInvoices!.reduce(
      (acc, invoice) => acc + invoice.total - invoice.paid,
      0
    ),
    returnAmountRemaining: supplier.returnPurchaseInvoices!.reduce(
      (acc, invoice) => acc + invoice.total - invoice.received,
      0
    ),
  }))

const mappingPurchaseData = (suppliers: Supplier[], invoices: PurchaseInvoice[]) =>
  invoices.map((invoice) => ({
    id: invoice.id,
    supplier: suppliers.find((supplier) => supplier.id === invoice.supplierId)?.name,
    total: invoice.total,
    paid: invoice.paid,
    remaining: invoice.total - invoice.paid,
  }))

const mappingReturnData = (suppliers: Supplier[], invoices: ReturnPurchaseInvoice[]) =>
  invoices.map((invoice) => ({
    id: invoice.id,
    supplier: suppliers.find((supplier) => supplier.id === invoice.supplierId)?.name,
    total: invoice.total,
    paid: invoice.received,
    remaining: invoice.total - invoice.received,
  }))

export default function SuppliersAccounting({ suppliers }: { suppliers: Supplier[] }) {
  const suppliersDataSource = mappingSuppliersData(suppliers)

  const purchaseInvoices = suppliers.flatMap((supplier) =>
    supplier.purchaseInvoices!.filter((invoice) => invoice.status === PaymentStatus.PartialPaid)
  )
  const returnPurchaseInvoices = suppliers.flatMap((supplier) =>
    supplier.returnPurchaseInvoices!.filter(
      (invoice) => invoice.status === PaymentStatus.PartialPaid
    )
  )

  const purchaseInvoicesData = mappingPurchaseData(suppliers, purchaseInvoices)
  const returnPurchaseInvoicesData = mappingReturnData(suppliers, returnPurchaseInvoices)

  return (
    <Row gutter={[0, 25]} className="m-8">
      <Col span="24" className="isolate">
        <ReportHeaderMini
          title="حسابات الموردين"
          columns={suppliersColumns}
          dataSource={suppliersDataSource}
        />
        <Table columns={suppliersColumns} dataSource={suppliersDataSource} pagination={false} />
      </Col>

      <Col span="24" className="isolate">
        <ReportHeaderMini
          title="فواتير الشراء الغير مدفوعة"
          columns={purchaseColumns.filter((column) => column.key !== 'action')}
          dataSource={purchaseInvoicesData}
        />
        <Table columns={purchaseColumns} dataSource={purchaseInvoicesData} pagination={false} />
      </Col>
      <Col span="24" className="isolate">
        <ReportHeaderMini
          title="فواتير المرتجع الغير مدفوعة"
          columns={returnColumns.filter((column) => column.key !== 'action')}
          dataSource={returnPurchaseInvoicesData}
        />
        <Table columns={returnColumns} dataSource={returnPurchaseInvoicesData} pagination={false} />
      </Col>
    </Row>
  )
}
