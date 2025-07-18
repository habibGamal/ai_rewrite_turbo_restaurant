import { Divider, TableColumnsType } from 'antd'
import { Money, Receipt2 } from 'iconsax-react'
import { Customer } from '~/types/Models'

export default class ClientsRService {
  constructor(private customers: Customer[]) {}

  private cardStyle({
    title,
    value,
    description,
    phone,
    icon,
    color,
  }: {
    title: string
    value: string
    description: string
    phone: string
    icon: React.ReactNode
    color: string
  }) {
    return {
      title: (
        <>
          {value}
          <Divider type="vertical" className="mx-4" />
          {title}
        </>
      ),
      mainText: description,
      secondaryText: <>رقم هاتف العميل : {phone}</>,
      icon: icon,
      color: color,
    }
  }

  get cardsData() {
    return [
      this.cardStyle({
        title: 'اعلى قيمة شرائية',
        value: `${this.mostPurchasingCustomer?.ordersTotal} EGP`,
        description: `العميل : ${this.mostPurchasingCustomer?.name}`,
        phone: this.mostPurchasingCustomer?.phone ?? '',
        icon: <Receipt2 className="text-sky-600" />,
        color: 'bg-sky-200',
      }),
      this.cardStyle({
        title: 'اعلى قيمة ربحية',
        value: `${this.mostProfitableCustomer?.ordersProfit} EGP`,
        description: `العميل : ${this.mostProfitableCustomer?.name}`,
        phone: this.mostProfitableCustomer?.phone ?? '',
        icon: <Money className="text-green-600" />,
        color: 'bg-green-200',
      }),
    ]
  }

  get dataSource() {
    return this.customers.map((customer) => ({
      name: customer.name,
      phone: customer.phone,
      ordersTotal: customer.ordersTotal,
      ordersProfit: customer.ordersProfit,
    }))
  }

  get columns(): TableColumnsType<{
    name: string
    phone: string
    ordersTotal: number | undefined
    ordersProfit: number | undefined
  }> {
    return [
      {
        title: 'أسم العميل',
        dataIndex: 'name',
        key: 'name',
      },
      {
        title: 'رقم الهاتف',
        dataIndex: 'phone',
        key: 'phone',
      },
      {
        title: 'القيمة الشرائية',
        dataIndex: 'ordersTotal',
        key: 'ordersTotal',
        sorter: (a: any, b: any) => a.ordersTotal - b.ordersTotal,
        sortDirections: ['descend', 'ascend'],
      },
      {
        title: 'القيمة الربحية',
        dataIndex: 'ordersProfit',
        key: 'ordersProfit',
        sorter: (a: any, b: any) => a.ordersProfit - b.ordersProfit,
        sortDirections: ['descend', 'ascend'],
      },
    ]
  }

  private get mostPurchasingCustomer() {
    return this.customers.length > 0
      ? this.customers.reduce((prev, current) => {
          return prev.ordersTotal! > current.ordersTotal! ? prev : current
        })
      : undefined
  }

  private get mostProfitableCustomer() {
    return this.customers.length > 0
      ? this.customers.reduce((prev, current) => {
          return prev.ordersProfit! > current.ordersProfit! ? prev : current
        })
      : undefined
  }
}
