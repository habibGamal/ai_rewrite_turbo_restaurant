import { router } from '@inertiajs/react'
import { Button, Col, Row, Table, Typography } from 'antd'
import React, { ReactNode, useMemo } from 'react'
import SimpleTableSearch from '~/components/SimpleTableSearch.js'
import useSimpleTableSearch from '~/hooks/useSimpleTableSearch.js'
import ShiftRService from '~/services/Reports/ShiftRService.js'
import useModal from '../../hooks/useModal.js'
import { ordersColumns } from '../../services/OrdersReportService.js'
import { Order, Shift } from '../../types/Models.js'
import ReportHeaderMini from '../ReportHeaderMini.js'
import DisplayExpenses from './DisplayExpenses.js'
import DisplayOrders from './DisplayOrders.js'
import DisplayOrderType from './DisplayOrderType.js'
import MoneyInfo from './MoneyInfo.js'
import OrdersInfo from './OrdersInfo.js'
import { printShiftSummery } from '~/helpers/printTemplate.js'

type Attribute = 'order_number' | 'id'

export default function ShiftsReport({ shifts, header }: { shifts: Shift[]; header: ReactNode }) {
  const actions = {
    showExpenses: () => expensesModal.showModal(),
    showOrdersOfPaymentMethod: (orders: Order[], title: string) =>
      showOrdersOfPaymentMethod(orders, title),
  }

  const service = useMemo(() => new ShiftRService(shifts), [shifts])
  const serviceUi = service.serviceUi(actions)

  const expensesModal = useModal()

  const modal = useModal()
  const [ordersType, setOrdersType] = React.useState<Order[]>([])
  const [modalTitle, setModalTitle] = React.useState<string>('')
  const showOrdersType = (orders: Order[], title: string) => {
    setOrdersType(orders)
    setModalTitle(title)
    setTimeout(() => {
      modal.showModal()
    }, 0)
  }

  const modalOrders = useModal()
  const [orders, setOrders] = React.useState<Order[]>([])
  const showOrdersOfPaymentMethod = (orders: Order[], title: string) => {
    setOrders(orders)
    setModalTitle(title)
    setTimeout(() => {
      modalOrders.showModal()
    }, 0)
  }

  const options: { label: string; value: Attribute }[] = [
    { label: 'الرقم المرجعي', value: 'id' },
    { label: 'رقم الاوردر', value: 'order_number' },
  ]
  const {
    data: ordersDataSource,
    setAttribute,
    onSearch,
  } = useSimpleTableSearch<Attribute>({
    options,
    dataSource: service.ordersDataSource,
  })
  return (
    <Row gutter={[0, 25]} className="m-8">
      <DisplayExpenses modal={expensesModal} expensesDataSource={service.allExpenses} />
      <DisplayOrderType modal={modal} title={modalTitle} orders={ordersType} />
      <DisplayOrders modal={modalOrders} title={modalTitle} orders={orders} />
      {header}
      <Col span="24">
        <Typography.Title level={4}>الإيراد</Typography.Title>
        <div className="grid gap-8 mb-8 cards-grid">
          {serviceUi.moneyInfoCards.map((info) => (
            <MoneyInfo
              mainText={info.title}
              value={info.value}
              icon={info.icon}
              color={info.color}
              onClick={info.onClick}
            />
          ))}
        </div>
        <Typography.Title level={4}>حالة الاوردرات</Typography.Title>
        <div className="grid gap-8 mb-8 cards-grid">
          {serviceUi.ordersInfoCards.map((info) => (
            <OrdersInfo
              onClick={() => showOrdersType(info.orders, info.title)}
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
              onClick={() => showOrdersType(info.orders, info.title)}
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
      <Col span="24" className="isolate hidden lg:block">
        <ReportHeaderMini
          title="الاوردرات"
          columns={ordersColumns.filter((column) => column.title !== 'التفاصيل')}
          dataSource={ordersDataSource}
          extraChildren={
            shifts.length === 1 && (
              <Button
                className="mr-4"
                onClick={() => {
                  printShiftSummery(
                    shifts[0].id,
                    serviceUi.moneyInfoCards.map((info) => ({
                      title: info.title,
                      value: info.value.toString(),
                    }))
                  )
                }}
              >
                طباعة الوردية
              </Button>
            )
          }
        />
        <SimpleTableSearch<Attribute>
          options={options}
          onSearch={onSearch}
          setAttribute={setAttribute}
        />
        <Table columns={ordersColumns} dataSource={ordersDataSource} scroll={{ x: true }} />
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
