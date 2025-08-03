import { Order, Payment, Shift } from '~/types/Models'
import OrdersReportService from '../OrdersReportService'
import { OrderStatus, OrderType } from '#enums/OrderEnums'
import {
  ArrowSwapHorizontal,
  Box,
  Card,
  Coin1,
  Diagram,
  DiscountCircle,
  Global,
  Home2,
  Money3,
  Receipt2,
  Send2,
  Setting2,
  Trash,
  TruckFast,
} from 'iconsax-react'
import { PaymentMethod } from '#enums/PaymentEnums'
import { TableColumnsType } from 'antd'
import { MoneyInfoCardKey, ShiftStat } from '~/types/Types'
import Pagination from '~/types/Pagination'

export default class ShiftRService {
  constructor(
    private data: {
      statistics: ShiftStat
      ordersPaginator: Pagination<Order>
    }
  ) {}

  private ordersProps(orders: ShiftStat['orders']) {
    return {
      count: orders.reduce((total, order) => total + order.count, 0),
      value: orders.reduce((total, order) => total + order.total, 0),
      profit: orders.reduce((total, order) => total + order.profit, 0),
    }
  }

  // private

  private get doneOrdersStats() {
    return this.data.statistics.orders.filter((order) => order.status === OrderStatus.Completed)
  }

  // public
  public get expensesByTypes() {
    return this.data.statistics.expenses
  }

  public get doneOrders() {
    const doneOrders = this.doneOrdersStats
    return this.ordersProps(doneOrders)
  }

  public get processingOrders() {
    const processingOrders = this.data.statistics.orders.filter(
      (order) => order.status === OrderStatus.Processing
    )
    return this.ordersProps(processingOrders)
  }

  public get cancelledOrders() {
    const cancelledOrders = this.data.statistics.orders.filter(
      (order) => order.status === OrderStatus.Cancelled
    )
    return this.ordersProps(cancelledOrders)
  }

  public get deliveryOrders() {
    const deliveryOrders = this.doneOrdersStats.filter((order) => order.type === OrderType.Delivery)
    return this.ordersProps(deliveryOrders)
  }

  public get takeawayOrders() {
    const takeawayOrders = this.doneOrdersStats.filter((order) => order.type === OrderType.Takeaway)
    return this.ordersProps(takeawayOrders)
  }

  public get dineInOrders() {
    const dineInOrders = this.doneOrdersStats.filter((order) => order.type === OrderType.DineIn)
    return this.ordersProps(dineInOrders)
  }

  public get talabatOrders() {
    const talabatOrders = this.doneOrdersStats.filter((order) => order.type === OrderType.Talabat)
    return this.ordersProps(talabatOrders)
  }

  public get webDeliveryOrders() {
    const webDeliveryOrders = this.doneOrdersStats.filter(
      (order) => order.type === OrderType.WebDelivery
    )
    return this.ordersProps(webDeliveryOrders)
  }

  public get webTakeawayOrders() {
    const webTakeawayOrders = this.doneOrdersStats.filter(
      (order) => order.type === OrderType.WebTakeaway
    )
    return this.ordersProps(webTakeawayOrders)
  }

  public get companiesOrders() {
    const companiesOrders = this.doneOrdersStats.filter(
      (order) => order.type === OrderType.Companies
    )
    return this.ordersProps(companiesOrders)
  }

  public get profitPercent() {
    return this.doneOrders.value > 0 ? (this.doneOrders.profit / this.doneOrders.value) * 100 : 0
  }

  public get totalCardPayments() {
    return (
      this.data.statistics.payments.find((payments) => payments.method === PaymentMethod.Card)
        ?.total || 0
    )
  }

  public get totalCashPayment() {
    return (
      this.data.statistics.payments.find((payments) => payments.method === PaymentMethod.Cash)
        ?.total || 0
    )
  }

  public get totalTalabatCardPayments() {
    return (
      this.data.statistics.payments.find(
        (payments) => payments.method === PaymentMethod.TalabatCard
      )?.total || 0
    )
  }

  public get totalExpenses() {
    return this.data.statistics.expenses.reduce((total, expense) => total + expense.total, 0)
  }

  public get totalDiscounts() {
    return this.data.statistics.orders.reduce((total, order) => total + order.discount, 0)
  }

  public get ordersHasDiscounts() {
    return this.allOrders.filter((order) => order.discount > 0)
  }

  public get avgReceiptValue() {
    return this.doneOrders.count > 0 ? this.doneOrders.value / this.doneOrders.count : 0
  }

  public get availableCash() {
    if (this.data.statistics.numberOfShifts === 1)
      return this.data.statistics.startCash + this.totalCashPayment! - this.totalExpenses
    return null
  }

  public serviceUi() {
    return new ServiceUi(this)
  }
}

class ServiceUi {
  constructor(private service: ShiftRService) {}

  public get ordersInfoCards(): {
    title: string
    count: number
    value: number
    profit: number
    icon: JSX.Element
    color: string
    ordersByKey: 'status'
    ordersByValue: OrderStatus
  }[] {
    return [
      {
        title: 'الاوردرات المكتملة',
        count: this.service.doneOrders.count,
        value: this.service.doneOrders.value,
        profit: this.service.doneOrders.profit,
        icon: <Box className="text-sky-600" />,
        color: 'bg-sky-200',
        ordersByKey: 'status',
        ordersByValue: OrderStatus.Completed,
      },
      {
        title: 'الاوردرات تحت التشغيل',
        count: this.service.processingOrders.count,
        value: this.service.processingOrders.value,
        profit: this.service.processingOrders.profit,
        icon: <Setting2 className="text-purple-600" />,
        color: 'bg-purple-200',
        ordersByKey: 'status',
        ordersByValue: OrderStatus.Processing,
      },
      {
        title: 'الاوردرات الملغية',
        count: this.service.cancelledOrders.count,
        value: this.service.cancelledOrders.value,
        profit: this.service.cancelledOrders.profit,
        icon: <Trash className="text-red-600" />,
        color: 'bg-red-200',
        ordersByKey: 'status',
        ordersByValue: OrderStatus.Cancelled,
      },
    ]
  }

  public get doneOrdersInfoCards(): {
    title: string
    count: number
    value: number
    profit: number
    icon: JSX.Element
    color: string
    ordersByKey: 'type'
    ordersByValue: OrderType
  }[] {
    return [
      {
        title: 'الاوردرات الصالة',
        count: this.service.dineInOrders.count,
        value: this.service.dineInOrders.value,
        profit: this.service.dineInOrders.profit,
        icon: <Home2 className="text-green-600" />,
        color: 'bg-green-200',
        ordersByKey: 'type',
        ordersByValue: OrderType.DineIn,
      },
      {
        title: 'الاوردرات ديليفري',
        count: this.service.deliveryOrders.count,
        value: this.service.deliveryOrders.value,
        profit: this.service.deliveryOrders.profit,
        icon: <TruckFast className="text-red-600" />,
        color: 'bg-red-200',
        ordersByKey: 'type',
        ordersByValue: OrderType.Delivery,
      },
      {
        title: 'الاوردرات تيك اواي',
        count: this.service.takeawayOrders.count,
        value: this.service.takeawayOrders.value,
        profit: this.service.takeawayOrders.profit,
        icon: <Send2 className="text-blue-600" />,
        color: 'bg-blue-200',
        ordersByKey: 'type',
        ordersByValue: OrderType.Takeaway,
      },
      {
        title: 'الاوردرات طلبات',
        count: this.service.talabatOrders.count,
        value: this.service.talabatOrders.value,
        profit: this.service.talabatOrders.profit,
        icon: <Send2 className="text-orange-600" />,
        color: 'bg-orange-200',
        ordersByKey: 'type',
        ordersByValue: OrderType.Talabat,
      },
      {
        title: 'الاوردرات اونلاين ديليفري',
        count: this.service.webDeliveryOrders.count,
        value: this.service.webDeliveryOrders.value,
        profit: this.service.webDeliveryOrders.profit,
        icon: <Global className="text-red-600" />,
        color: 'bg-red-200',
        ordersByKey: 'type',
        ordersByValue: OrderType.WebDelivery,
      },
      {
        title: 'الاوردرات اونلاين تيك اواي',
        count: this.service.webTakeawayOrders.count,
        value: this.service.webTakeawayOrders.value,
        profit: this.service.webTakeawayOrders.profit,
        icon: <Global className="text-blue-600" />,
        color: 'bg-blue-200',
        ordersByKey: 'type',
        ordersByValue: OrderType.WebTakeaway,
      },
      {
        title: 'الاوردرات الشركات',
        count: this.service.companiesOrders.count,
        value: this.service.companiesOrders.value,
        profit: this.service.companiesOrders.profit,
        icon: <Send2 className="text-purple-600" />,
        color: 'bg-purple-200',
        ordersByKey: 'type',
        ordersByValue: OrderType.Companies,
      },
    ]
  }

  public get moneyInfoCards() {
    const cards: {
      key: MoneyInfoCardKey
      title: React.ReactNode
      value: number
      icon: React.ReactNode
      color: string
    }[] = [
      {
        key: 'sales',
        title: 'المبيعات',
        value: this.service.doneOrders.value,
        icon: <Receipt2 className="text-green-600" />,
        color: 'bg-green-200',
      },
      {
        key: 'profit',
        title: <>الارباح {this.service.profitPercent.toFixed(2)}%</>,
        value: this.service.doneOrders.profit,
        icon: <Diagram className="text-emerald-600" />,
        color: 'bg-emerald-200',
      },
      {
        key: 'expenses',
        title: 'المصروفات',
        value: this.service.totalExpenses,
        icon: <ArrowSwapHorizontal className="text-red-600" />,
        color: 'bg-red-200',
      },
      {
        key: 'discounts',
        title: 'الخصومات',
        value: this.service.totalDiscounts,
        icon: <DiscountCircle className="text-zinc-600" />,
        color: 'bg-zinc-200',
      },
      {
        key: 'cashPayments',
        title: 'النقود المدفوعة كاش',
        value: this.service.totalCashPayment,
        icon: <Coin1 className="text-yellow-600" />,
        color: 'bg-yellow-200',
      },
      {
        key: 'cardPayments',
        title: 'النقود المدفوعة فيزا',
        value: this.service.totalCardPayments,
        icon: <Card className="text-purple-600" />,
        color: 'bg-purple-200',
      },
      {
        key: 'talabatCardPayments',
        title: 'النقود المدفوعة فيزا طلبات',
        value: this.service.totalTalabatCardPayments,
        icon: <Card className="text-orange-600" />,
        color: 'bg-orange-200',
      },
      {
        key: 'avgReceiptValue',
        title: 'متوسط قيمة الاوردر',
        value: this.service.avgReceiptValue,
        icon: <Receipt2 className="text-green-600" />,
        color: 'bg-green-200',
      },
    ]
    if (this.service.availableCash)
      cards.splice(4, 0, {
        key: 'availableCash',
        title: 'النقدية المتاحة',
        value: this.service.availableCash,
        icon: <Money3 className="text-blue-600" />,
        color: 'bg-blue-200',
      })
    return cards
  }

  public get expensesByTypesColumns(): TableColumnsType<{
    type: string
    total: number
  }> {
    return [
      {
        title: 'نوع المصروف',
        dataIndex: 'type',
        key: 'type',
      },
      {
        title: 'الاجمالي',
        dataIndex: 'total',
        key: 'total',
      },
    ]
  }
}
