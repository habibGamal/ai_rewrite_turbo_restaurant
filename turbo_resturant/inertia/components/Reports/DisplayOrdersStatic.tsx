import { Button, Modal, Table, TableColumnsType } from 'antd'
import useModal from '../../hooks/useModal.js'
import OrdersReportService from '../../services/OrdersReportService.js'
import { Order } from '../../types/Models.js'
import ReportHeaderMini from '../ReportHeaderMini.js'
import { router } from '@inertiajs/react'
import { printOrder } from '~/helpers/printTemplate.js'
import axios from 'axios'

export const ordersColumnsStatic: TableColumnsType<{
  id: number
  typeString: string
  orderStatus: string
  orderNumber: number
  discount: string
  total: number
  paid: number
  paidCard: number
  paidCash: number
  paidTalabatCard: number
  remaining: number
  profit: number
}> = [
  {
    title: 'الرقم المرجعي',
    dataIndex: 'id',
    key: 'id',
    sorter: (a, b) => a.id - b.id,
  },
  {
    title: 'رقم الاوردر',
    dataIndex: 'orderNumber',
    key: 'orderNumber',
    sorter: (a, b) => a.orderNumber - b.orderNumber,
  },
  {
    title: 'النوع',
    dataIndex: 'typeString',
    key: 'typeString',
    sorter: (a, b) => a.typeString.localeCompare(b.typeString),
  },
  {
    title: 'الخصم',
    dataIndex: 'discount',
    key: 'discount',
    sorter: (a, b) => parseFloat(a.discount) - parseFloat(b.discount),
  },
  {
    title: 'قيمة الاوردر',
    dataIndex: 'total',
    key: 'total',
    sorter: (a, b) => a.total - b.total,
  },
  {
    title: 'المدفوع',
    dataIndex: 'paid',
    key: 'paid',
    sorter: (a, b) => a.paid - b.paid,
  },
  {
    title: 'المدفوع كاش',
    dataIndex: 'paidCash',
    key: 'paidCash',
    sorter: (a, b) => a.paidCash - b.paidCash,
  },
  {
    title: 'المدفوع فيزا',
    dataIndex: 'paidCard',
    key: 'paidCard',
    sorter: (a, b) => a.paidCard - b.paidCard,
  },
  {
    title: 'المدفوع طلبات',
    dataIndex: 'paidTalabatCard',
    key: 'paidTalabatCard',
    sorter: (a, b) => a.paidTalabatCard - b.paidTalabatCard,
  },
  {
    title: 'متبقي',
    dataIndex: 'remaining',
    key: 'remaining',
    sorter: (a, b) => a.remaining - b.remaining,
  },
  {
    title: 'ربح الاوردر',
    dataIndex: 'profit',
    key: 'profit',
    sorter: (a, b) => a.profit - b.profit,
  },
  {
    title: 'حالة الاوردر',
    dataIndex: 'orderStatus',
    key: 'orderStatus',
    sorter: (a, b) => a.orderStatus.localeCompare(b.orderStatus),
  },
  {
    title: 'التفاصيل',
    key: 'action',
    render: (_, record) => (
      <div className="flex gap-2">
        <Button onClick={() => router.get(`/orders/${record.id}`)}>التفاصيل</Button>{' '}
        <Button
          onClick={async () => {
            const result = await axios.get<{ order: Order; receiptFooter: [{ value: string }] }>(
              `/load-order-to-print/${record.id}`
            )

            await printOrder(
              result.data.order,
              result.data.order.items?.map((item) => ({
                ...item,
                name: item.product?.name,
              })) as any,
              result.data.receiptFooter[0].value
            )
          }}
        >
          طباعة
        </Button>
      </div>
    ),
  },
]

export default function DisplayOrdersStatic({
  modal,
  title,
  orders,
}: {
  modal: ReturnType<typeof useModal>
  title: string
  orders: Order[]
}) {
  const ordersDataSource = OrdersReportService.mappingToTableData(orders)
  console.log("ordersDataSource",ordersDataSource)
  return (
    <Modal className="!w-[90%]" title={title} destroyOnClose {...modal} footer={null}>
      <div className="isolate">
        <ReportHeaderMini
          title="الاوردرات"
          columns={ordersColumnsStatic.filter((column) => column.key !== 'action')}
          dataSource={ordersDataSource}
        />
        <Table columns={ordersColumnsStatic} dataSource={ordersDataSource} scroll={{ x: true }} />
      </div>
    </Modal>
  )
}
