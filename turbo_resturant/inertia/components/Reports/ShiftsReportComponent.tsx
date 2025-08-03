import { PaymentMethod } from '#enums/PaymentEnums'
import { router } from '@inertiajs/react'
import { Col, Row, Table, Typography } from 'antd'
import React, { ReactNode, useMemo } from 'react'
import clearSlugFromUrlQuery from '~/helpers/clearSlugFromUrlQuery.js'
import ShiftRService from '~/services/Reports/ShiftRService.js'
import Pagination from '~/types/Pagination.js'
import { MoneyInfoCardKey, ShiftStat } from '~/types/Types.js'
import useModal from '../../hooks/useModal.js'
import { Order } from '../../types/Models.js'
import ReportHeaderMini from '../ReportHeaderMini.js'
import DisplayOrders from './DisplayOrdersDynamic.js'
import DisplayOrdersByStatusOrType from './DisplayOrdersByStatusOrType.js'
import MoneyInfo from './MoneyInfo.js'
import OrdersInfo from './OrdersInfo.js'
import { ordersColumns, ShiftReportOrdersTable } from './ShiftReportOrdersTable.js'
import DisplayExpenses from './DisplayExpenses.js'
type Props = {
  data: {
    statistics: ShiftStat
    ordersPaginator: Pagination<Order>
  }
  header: ReactNode
}

export default function ShiftsReportComponent({ data, header }: Props) {
  const service = useMemo(() => new ShiftRService(data), [data])
  const serviceUi = service.serviceUi()
  const [modalTitle, setModalTitle] = React.useState<string>('')

  const expensesModal = useModal()
  const showExpenses = () => {
    clearSlugFromUrlQuery('expenses_')
    router.reload({
      only: ['expenses'],
      data: {
        slug: 'expenses',
      },
      onSuccess: () => {
        setTimeout(() => {
          expensesModal.showModal()
        }, 0)
      },
    })
  }

  const modalOrdersByStatusOrType = useModal()
  const showOrdersByStatusOrType = (
    title: string,
    ordersByKey: 'status' | 'type',
    ordersByValue: Order['status'] | Order['type']
  ) => {
    clearSlugFromUrlQuery('ordersByStatusOrType_')
    router.reload({
      only: ['ordersByStatusOrType', 'statisticsByStatusOrType'],
      data: {
        ordersByKey,
        ordersByValue,
        slug: 'ordersByStatusOrType',
      },
      onSuccess: () => {
        setModalTitle(title)
        setTimeout(() => {
          modalOrdersByStatusOrType.showModal()
        }, 0)
      },
    })
  }

  const modalOrdersHasDiscounts = useModal()
  const showOrdersHasDiscounts = (
  ) => {
    clearSlugFromUrlQuery('ordersHasDiscounts')
    router.reload({
      only: ['ordersHasDiscounts'],
      data: {
        slug: 'ordersHasDiscounts',
      },
      onSuccess: () => {
        setModalTitle('الاوردرات المحتوية على خصم')
        setTimeout(() => {
          modalOrdersHasDiscounts.showModal()
        }, 0)
      },
    })
  }

  const modalOrdersByPaymentMethod = useModal()
  const showOrdersOfPaymentMethod = (title: string, method: string) => {
    clearSlugFromUrlQuery('ordersByPaymentMethod_')
    router.reload({
      only: ['ordersByPaymentMethod'],
      data: {
        method,
        slug: 'ordersByPaymentMethod',
      },
      onSuccess: () => {
        setModalTitle(title)
        setTimeout(() => {
          modalOrdersByPaymentMethod.showModal()
        }, 0)
      },
    })
  }
  const moneyInfoActions: {
    [K in MoneyInfoCardKey]?: () => void
  } = {
    expenses: () => showExpenses(),
    cashPayments: () => showOrdersOfPaymentMethod('النقود المدفوعة كاش', PaymentMethod.Cash),
    cardPayments: () => showOrdersOfPaymentMethod('النقود المدفوعة فيزا', PaymentMethod.Card),
    talabatCardPayments: () =>
      showOrdersOfPaymentMethod('النقود المدفوعة فيزا طلبات', PaymentMethod.TalabatCard),
    discounts: () => showOrdersHasDiscounts(),
  }

  return (
    <Row gutter={[0, 25]} className="m-8">
      <DisplayExpenses modal={expensesModal} expensesDataSource={[]} />
      <DisplayOrdersByStatusOrType modal={modalOrdersByStatusOrType} title={modalTitle} />
      <DisplayOrders modal={modalOrdersByPaymentMethod} title={modalTitle} slug="ordersByPaymentMethod" />
      <DisplayOrders showOnMobile modal={modalOrdersHasDiscounts} title={modalTitle} slug="ordersHasDiscounts" />
      {header}
      <Col span="24">
        <Typography.Title level={4}>الإيراد</Typography.Title>
        <div className="grid gap-8 mb-8 cards-grid">
          {serviceUi.moneyInfoCards.map((info) => (
            <MoneyInfo
              mainText={info.title}
              value={info.value!}
              icon={info.icon}
              color={info.color}
              onClick={moneyInfoActions[info.key]}
            />
          ))}
        </div>
        <Typography.Title level={4}>حالة الاوردرات</Typography.Title>
        <div className="grid gap-8 mb-8 cards-grid">
          {serviceUi.ordersInfoCards.map((info) => (
            <OrdersInfo
              onClick={() =>
                showOrdersByStatusOrType(info.title, info.ordersByKey, info.ordersByValue)
              }
              count={info.count}
              mainText={info.title}
              value={info.value}
              profit={info.profit}
              icon={info.icon}
              color={info.color}
            />
          ))}
        </div>
        <Typography.Title level={4}>الاوردرات المكتملة</Typography.Title>
        <div className="grid gap-8 mb-8 cards-grid">
          {serviceUi.doneOrdersInfoCards.map((info) => (
            <OrdersInfo
              onClick={() =>
                showOrdersByStatusOrType(info.title, info.ordersByKey, info.ordersByValue)
              }
              count={info.count}
              mainText={info.title}
              value={info.value}
              profit={info.profit}
              icon={info.icon}
              color={info.color}
            />
          ))}
        </div>
      </Col>

      <Col span="24" className="isolate ">
        <ReportHeaderMini
          title="الاوردرات"
          columns={[]}
          dataSource={[]}
        />
        <ShiftReportOrdersTable slug="main" />
      </Col>

      <Col span="24" className="isolate ">
        <ReportHeaderMini
          title="اجمالي المصاريف"
          columns={serviceUi.expensesByTypesColumns}
          dataSource={service.expensesByTypes}
        />
        <Table
          columns={serviceUi.expensesByTypesColumns}
          dataSource={service.expensesByTypes}
          pagination={false}
          scroll={{ x: true }}
        />
      </Col>
    </Row>
  )
}
