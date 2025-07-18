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

export default class ShiftRService {
  constructor(private shifts: Shift[]) {}

  private ordersProps(orders: Order[]) {
    return {
      orders,
      count: orders.length,
      value: orders.reduce((total, order) => total + order.total, 0),
      profit: orders.reduce((total, order) => total + order.profit, 0),
    }
  }

  private paymentsProps(payments: Payment[]) {
    const orders = payments.map((payment) => payment.order!)
    orders.forEach((order) => {
      order.payments = payments.filter((payment) => payment.orderId === order.id)
    })
    return {
      payments,
      value: payments.reduce((total, payment) => total + payment.paid, 0),
      orders,
    }
  }
  // private
  private get allOrders() {
    return this.shifts.flatMap((shift) => shift.orders!)
  }

  private get allPayments() {
    return this.shifts.flatMap((shift) => shift.payments!)
  }

  // public
  public get allExpenses() {
    return this.shifts.flatMap((shift) => shift.expenses!)
  }

  public get expensesByTypes() {
    const expenses = this.allExpenses
    const types = [...new Set(expenses.map((expense) => expense.expenseType!.name))]
    return types.map((type) => ({
      type,
      total: expenses
        .filter((expense) => expense.expenseType!.name === type)
        .reduce((total, expense) => total + expense.amount, 0),
    }))
  }

  public get ordersDataSource() {
    return OrdersReportService.mappingToTableData(this.allOrders)
  }

  public get doneOrders() {
    const doneOrders = this.allOrders.filter((order) => order.status === OrderStatus.Completed)
    return this.ordersProps(doneOrders)
  }

  public get processingOrders() {
    const processingOrders = this.allOrders.filter(
      (order) => order.status === OrderStatus.Processing
    )
    return this.ordersProps(processingOrders)
  }

  public get cancelledOrders() {
    const cancelledOrders = this.allOrders.filter((order) => order.status === OrderStatus.Cancelled)
    return this.ordersProps(cancelledOrders)
  }

  public get deliveryOrders() {
    const deliveryOrders = this.allOrders.filter((order) => order.type === OrderType.Delivery)
    return this.ordersProps(deliveryOrders)
  }

  public get takeawayOrders() {
    const takeawayOrders = this.allOrders.filter((order) => order.type === OrderType.Takeaway)
    return this.ordersProps(takeawayOrders)
  }

  public get dineInOrders() {
    const dineInOrders = this.allOrders.filter((order) => order.type === OrderType.DineIn)
    return this.ordersProps(dineInOrders)
  }

  public get talabatOrders() {
    const talabatOrders = this.allOrders.filter((order) => order.type === OrderType.Talabat)
    return this.ordersProps(talabatOrders)
  }

  public get companiesOrders() {
    const companiesOrders = this.allOrders.filter((order) => order.type === OrderType.Companies)
    return this.ordersProps(companiesOrders)
  }

  public get profitPercent() {
    return this.doneOrders.value > 0 ? (this.doneOrders.profit / this.doneOrders.value) * 100 : 0
  }

  public get cardPayments() {
    return this.paymentsProps(
      this.allPayments.filter((payment) => payment.method === PaymentMethod.Card)
    )
  }

  public get cashPayments() {
    return this.paymentsProps(
      this.allPayments.filter((payment) => payment.method === PaymentMethod.Cash)
    )
  }

  public get talabatCardPayments() {
    return this.paymentsProps(
      this.allPayments.filter((payment) => payment.method === PaymentMethod.TalabatCard)
    )
  }

  public get totalExpenses() {
    return this.allExpenses.reduce((total, expense) => total + expense.amount, 0)
  }

  public get totalDiscounts() {
    return this.allOrders.reduce((total, order) => total + order.discount, 0)
  }

  public get ordersHasDiscounts() {
    return this.allOrders.filter((order) => order.discount > 0)
  }

  public get avgReceiptValue() {
    return this.doneOrders.count > 0 ? this.doneOrders.value / this.doneOrders.count : 0
  }

  public get availableCash() {
    if (this.shifts.length === 1)
      return this.shifts[0].startCash + this.cashPayments.value - this.totalExpenses
    return null
  }

  public serviceUi(actions: Actions) {
    return new ServiceUi(actions, this)
  }
}
type Actions = {
  showExpenses: () => void
  showOrdersOfPaymentMethod: (orders: Order[], title: string) => void
}
class ServiceUi {
  constructor(
    private actions: Actions,
    private service: ShiftRService
  ) {}

  public get ordersInfoCards() {
    return [
      {
        title: 'الاوردرات المكتملة',
        orders: this.service.doneOrders.orders,
        count: this.service.doneOrders.count,
        value: this.service.doneOrders.value,
        profit: this.service.doneOrders.profit,
        icon: <Box className="text-sky-600" />,
        color: 'bg-sky-200',
      },
      {
        title: 'الاوردرات تحت التشغيل',
        orders: this.service.processingOrders.orders,
        count: this.service.processingOrders.count,
        value: this.service.processingOrders.value,
        profit: this.service.processingOrders.profit,
        icon: <Setting2 className="text-purple-600" />,
        color: 'bg-purple-200',
      },
      {
        title: 'الاوردرات الملغية',
        orders: this.service.cancelledOrders.orders,
        count: this.service.cancelledOrders.count,
        value: this.service.cancelledOrders.value,
        profit: this.service.cancelledOrders.profit,
        icon: <Trash className="text-red-600" />,
        color: 'bg-red-200',
      },
    ]
  }

  public get doneOrdersInfoCards() {
    return [
      {
        title: 'الاوردرات الصالة',
        orders: this.service.dineInOrders.orders,
        count: this.service.dineInOrders.count,
        value: this.service.dineInOrders.value,
        profit: this.service.dineInOrders.profit,
        icon: <Home2 className="text-green-600" />,
        color: 'bg-green-200',
      },
      {
        title: 'الاوردرات ديليفري',
        orders: this.service.deliveryOrders.orders,
        count: this.service.deliveryOrders.count,
        value: this.service.deliveryOrders.value,
        profit: this.service.deliveryOrders.profit,
        icon: <TruckFast className="text-red-600" />,
        color: 'bg-red-200',
      },
      {
        title: 'الاوردرات تيك اواي',
        orders: this.service.takeawayOrders.orders,
        count: this.service.takeawayOrders.count,
        value: this.service.takeawayOrders.value,
        profit: this.service.takeawayOrders.profit,
        icon: <Send2 className="text-blue-600" />,
        color: 'bg-blue-200',
      },
      {
        title: 'الاوردرات طلبات',
        orders: this.service.talabatOrders.orders,
        count: this.service.talabatOrders.count,
        value: this.service.talabatOrders.value,
        profit: this.service.talabatOrders.profit,
        icon: <Send2 className="text-orange-600" />,
        color: 'bg-orange-200',
      },
      {
        title: 'الاوردرات الشركات',
        orders: this.service.companiesOrders.orders,
        count: this.service.companiesOrders.count,
        value: this.service.companiesOrders.value,
        profit: this.service.companiesOrders.profit,
        icon: <Send2 className="text-purple-600" />,
        color: 'bg-purple-200',
      },
    ]
  }

  public get moneyInfoCards() {
    const cards:{
      title: React.ReactNode
      value: number
      icon: React.ReactNode
      color: string
      onClick?: () => void
    }[] = [
      {
        title: 'المبيعات',
        value: this.service.doneOrders.value,
        icon: <Receipt2 className="text-green-600" />,
        color: 'bg-green-200',
      },
      {
        title: <>الارباح {this.service.profitPercent.toFixed(2)}%</>,
        value: this.service.doneOrders.profit,
        icon: <Diagram className="text-emerald-600" />,
        color: 'bg-emerald-200',
      },
      {
        title: 'المصروفات',
        value: this.service.totalExpenses,
        icon: <ArrowSwapHorizontal className="text-red-600" />,
        color: 'bg-red-200',
        onClick: () => this.actions.showExpenses(),
      },
      {
        title: 'الخصومات',
        value: this.service.totalDiscounts,
        icon: <DiscountCircle className="text-zinc-600" />,
        color: 'bg-zinc-200',
        onClick: () =>
          this.actions.showOrdersOfPaymentMethod(
            this.service.ordersHasDiscounts,
            'الخصومات'
          ),
      },
      {
        title: 'النقود المدفوعة كاش',
        value: this.service.cashPayments.value,
        icon: <Coin1 className="text-yellow-600" />,
        color: 'bg-yellow-200',
        onClick: () =>
          this.actions.showOrdersOfPaymentMethod(
            this.service.cashPayments.orders,
            'النقود المدفوعة كاش'
          ),
      },
      {
        title: 'النقود المدفوعة فيزا',
        value: this.service.cardPayments.value,
        icon: <Card className="text-purple-600" />,
        color: 'bg-purple-200',
        onClick: () =>
          this.actions.showOrdersOfPaymentMethod(
            this.service.cardPayments.orders,
            'النقود المدفوعة فيزا'
          ),
      },
      {
        title: 'النقود المدفوعة فيزا طلبات',
        value: this.service.talabatCardPayments.value,
        icon: <Card className="text-orange-600" />,
        color: 'bg-orange-200',
        onClick: () =>
          this.actions.showOrdersOfPaymentMethod(
            this.service.talabatCardPayments.orders,
            'النقود المدفوعة فيزا طلبات'
          ),
      },
      {
        title: 'متوسط قيمة الاوردر',
        value: this.service.avgReceiptValue,
        icon: <Receipt2 className="text-green-600" />,
        color: 'bg-green-200',
      },
    ]
    if (this.service.availableCash)
      cards.splice(4, 0, {
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
