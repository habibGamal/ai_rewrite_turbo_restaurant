import { Divider, TableColumnsType } from 'antd'
import { Box, Money, Money2 } from 'iconsax-react'
import { Product } from '~/types/Models'

export default class ProductsRService {
  constructor(private products: Product[]) {}
  public get noProducts() {
    return this.products.length === 0
  }
  public get highestSalesQuantity(): Product | undefined {
    if (this.noProducts) return undefined
    return this.products.reduce((prev, current) => {
      return Number(prev.salesQuantity) > Number(current.salesQuantity) ? prev : current
    })
  }

  public get highestSalesTotal() {
    if (this.noProducts) return undefined
    return this.products.reduce((prev, current) => {
      return prev.salesTotal! > current.salesTotal! ? prev : current
    })
  }

  public get highestProfitTotal() {
    if (this.noProducts) return undefined
    return this.products.reduce((prev, current) => {
      return prev.salesProfit! > current.salesProfit! ? prev : current
    })
  }

  public get dataSource() {
    return this.products.map((product) => ({
      key: product.id,
      name: product.name,
      salesQuantity: product.salesQuantity,
      salesTotal: product.salesTotal,
      salesProfit: product.salesProfit,
    }))
  }

  public serviceUi() {
    return new ServiceUi(this)
  }
}

class ServiceUi {
  constructor(private service: ProductsRService) {}

  private cardStyle({
    title,
    value,
    description,
    secondaryText,
    icon,
    color,
  }: {
    title: string
    value: string
    description: string
    secondaryText: React.ReactNode
    icon: React.ReactNode
    color: string
  }) {
    return {
      title: (
        <>
          {value}
          <br />
          {title}
        </>
      ),
      mainText: description,
      secondaryText,
      icon,
      color,
    }
  }
  public get cardsData() {
    if (this.service.noProducts) return []
    const highestSalesQuantity = this.service.highestSalesQuantity!
    const highestSalesTotal = this.service.highestSalesTotal!
    const highestProfitTotal = this.service.highestProfitTotal!
    return [
      this.cardStyle({
        title: highestSalesQuantity.name,
        value: highestSalesQuantity.salesQuantity!.toString(),
        description: 'اعلى كمية مباعة',
        secondaryText: (
          <>
            ربح المنتج : {highestSalesQuantity.salesProfit} EGP
            <Divider type="vertical" />
            قيمة المبيعات : {highestSalesQuantity.salesTotal} EGP
          </>
        ),
        icon: <Box className="text-sky-600" />,
        color: 'bg-sky-200',
      }),
      this.cardStyle({
        title: highestSalesTotal.name,
        value: highestSalesTotal.salesTotal! + ' EGP',
        description: 'اعلى قيمة مبيعات',
        secondaryText: (
          <>
            ربح المنتج : {highestSalesTotal.salesProfit} EGP
            <Divider type="vertical" />
            الكمية المباعة : {highestSalesTotal.salesQuantity}
          </>
        ),
        icon: <Money2 className="text-green-600" />,
        color: 'bg-green-200',
      }),
      this.cardStyle({
        title: highestProfitTotal.name,
        value: highestProfitTotal.salesProfit! + ' EGP',
        description: 'اعلى قيمة ربحية',
        secondaryText: (
          <>
            قيمة المبيعات : {highestProfitTotal.salesTotal} EGP
            <Divider type="vertical" />
            الكمية المباعة : {highestProfitTotal.salesQuantity}
          </>
        ),
        icon: <Money className="text-violet-600" />,
        color: 'bg-violet-200',
      }),
    ]
  }

  public get columns(): TableColumnsType<{
    key: React.Key
    name: string
    salesQuantity: number
    salesTotal: number
    salesProfit: number
  }> {
    return [
      {
        title: 'اسم المنتج',
        dataIndex: 'name',
        key: 'name',
        sorter: (a, b) => a.name.localeCompare(b.name),
        sortDirections: ['descend', 'ascend'],
      },
      {
        title: 'كمية المبيعات',
        dataIndex: 'salesQuantity',
        key: 'salesQuantity',
        sorter: (a, b) => a.salesQuantity - b.salesQuantity,
        sortDirections: ['descend', 'ascend'],
      },
      {
        title: 'قيمة المبيعات',
        dataIndex: 'salesTotal',
        key: 'salesTotal',
        sorter: (a, b) => a.salesTotal - b.salesTotal,
        sortDirections: ['descend', 'ascend'],
      },
      {
        title: 'ربح المنتج',
        dataIndex: 'salesProfit',
        key: 'salesProfit',
        sorter: (a, b) => a.salesProfit - b.salesProfit,
        sortDirections: ['descend', 'ascend'],
      },
    ]
  }
}
