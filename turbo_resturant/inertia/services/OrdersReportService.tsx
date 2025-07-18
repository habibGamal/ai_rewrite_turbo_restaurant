import { OrderStatus } from '#enums/OrderEnums'
import { PaymentMethod } from '#enums/PaymentEnums'
import { router } from '@inertiajs/react'
import { Button, TableColumnsType } from 'antd'
import { Order, Payment } from '../types/Models.js'
import axios from 'axios'
import printTemplate, { printOrder } from '~/helpers/printTemplate.js'
import ReceiptTemplate from '~/components/Print/ReceiptTemplate.js'
import { OrderItemT } from '~/types/Types.js'
export default class OrdersReportService {
  private payments: Payment[]
  constructor(private orders: Order[]) {
    const payments: Payment[] = []
    orders.forEach((order) => {
      payments.push(...order.payments!)
    })
    this.payments = payments
  }

  public get sales() {
    return this.orders.reduce((total, order) => total + order.total, 0)
  }

  public get profit() {
    return this.orders.reduce((total, order) => total + order.profit, 0)
  }

  public get completedOrders() {
    return this.orders.filter((order) => order.status === OrderStatus.Completed).length
  }

  public get processingOrders() {
    return this.orders.filter((order) => order.status === OrderStatus.Processing).length
  }

  public get cancelledOrders() {
    return this.orders.filter((order) => order.status === OrderStatus.Cancelled).length
  }

  public get totalDiscount() {
    return this.orders.reduce((total, order) => total + order.discount, 0)
  }

  public get cashPaymentsvalue() {
    return this.payments.reduce((total, payment) => {
      return total + (payment.method === PaymentMethod.Cash ? payment.paid : 0)
    }, 0)
  }

  public get cardPaymentsvalue() {
    return this.payments.reduce((total, payment) => {
      return total + (payment.method === PaymentMethod.Card ? payment.paid : 0)
    }, 0)
  }

  public get talabatCardPaymentsvalue() {
    return this.payments.reduce((total, payment) => {
      return total + (payment.method === PaymentMethod.TalabatCard ? payment.paid : 0)
    }, 0)
  }

  public get avgReceiptValue() {
    return this.orders.length > 0 ? this.sales / this.orders.length : 0
  }

  public static mappingToTableData(orders: Order[]) {
    return orders.map((order) => {
      const paid = order.payments!.reduce((total, payment) => total + payment.paid, 0) || 0
      const paidCard = order.payments!.reduce(
        (total, payment) => total + (payment.method === PaymentMethod.Card ? payment.paid : 0),
        0
      )
      const paidCash = order.payments!.reduce(
        (total, payment) => total + (payment.method === PaymentMethod.Cash ? payment.paid : 0),
        0
      )
      const paidTalabatCard = order.payments!.reduce(
        (total, payment) =>
          total + (payment.method === PaymentMethod.TalabatCard ? payment.paid : 0),
        0
      )
      return {
        id: order.id,
        order_number: order.orderNumber,
        typeString: order.typeString,
        orderStatus: order.statusString,
        discount: order.discount.toFixed(2),
        total: order.total,
        paid,
        paidCard,
        paidCash,
        paidTalabatCard,
        remaining: parseInt((order.total - paid).toString()),
        profit: order.profit,
      }
    })
  }
}

export const ordersColumns: TableColumnsType<{
  id: number
  typeString: string
  orderStatus: string
  order_number: number
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
    dataIndex: 'order_number',
    key: 'order_number',
    sorter: (a, b) => a.order_number - b.order_number,
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
