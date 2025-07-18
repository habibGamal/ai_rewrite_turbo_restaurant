import { router } from '@inertiajs/react'
import { Button, Col, Descriptions, Divider, Row, Table, TableColumnsType } from 'antd'
import { Box, Card, Coin1, Home2, Send2, TruckFast } from 'iconsax-react'
import { OrderStatus, OrderType } from '#enums/OrderEnums'
import { PaymentMethod } from '#enums/PaymentEnums'
import PageTitle from '../../components/PageTitle.js'
import ReportCard from '../../components/ReportCard.js'
import ReportHeaderMini from '../../components/ReportHeaderMini.js'
import { Order, Shift } from '../../types/Models.js'

const ordersColumns: TableColumnsType<{
  id: number
  typeString: string
  discount: string
  total: number
  paid: number
  remaining: number
  profit: number
}> = [
  {
    title: 'الرقم المرجعي',
    dataIndex: 'id',
    key: 'id',
  },
  {
    title: 'رقم الاوردر',
    dataIndex: 'order_number',
    key: 'order_number',
  },
  {
    title: 'النوع',
    dataIndex: 'typeString',
    key: 'typeString',
  },
  {
    title: 'الخصم',
    dataIndex: 'discount',
    key: 'discount',
  },
  {
    title: 'قيمة الاوردر',
    dataIndex: 'total',
    key: 'total',
  },
  {
    title: 'المدفوع',
    dataIndex: 'paid',
    key: 'paid',
  },
  {
    title: 'متبقي',
    dataIndex: 'remaining',
    key: 'remaining',
  },
  {
    title: 'ربح الاوردر',
    dataIndex: 'profit',
    key: 'profit',
  },
  {
    title: 'التفاصيل',
    key: 'action',
    render: (_, record) => (
      <div className="flex gap-2">
        <Button onClick={() => router.get(`/orders/${record.id}`)}>التفاصيل</Button>{' '}
        <Button
          onClick={() =>
            router.get(`/print/${record.id}`, undefined, {
              preserveScroll: true,
            })
          }
        >
          طباعة
        </Button>
      </div>
    ),
  },
]

const expensesColumns: TableColumnsType<{
  id: number
  amount: number
  description: string
}> = [
  {
    title: 'القيمة',
    dataIndex: 'amount',
    key: 'amount',
  },
  {
    title: 'الوصف',
    dataIndex: 'description',
    key: 'description',
  },
]

const mappingData = (orders: Order[]) => {
  return orders.map((order) => {
    const paid = order.payments?.reduce((total, payment) => total + payment.paid, 0) || 0
    return {
      id: order.id,
      order_number: order.orderNumber,
      typeString: order.typeString,
      discount: order.discount.toFixed(2),
      total: order.total,
      paid,
      remaining: parseInt((order.total - paid).toString()),
      profit: order.profit,
    }
  })
}

export default function ShiftReport({ shift }: { shift: Shift }) {
  const ordersDataSource = mappingData(shift.orders || [])
  const expensesDataSource = shift.expenses || []

  const doneOrders = shift.orders?.filter((order) => order.status === OrderStatus.Completed)
  const doneOrdersCount = doneOrders?.length || 0
  const doneOrdersValue = doneOrders?.reduce((total, order) => total + order.total, 0) || 0

  const deliveryOrders = doneOrders?.filter((order) => order.type === OrderType.Delivery)
  const deliveryOrdersCount = deliveryOrders?.length || 0
  const deleveryOrdersValue = deliveryOrders?.reduce((total, order) => total + order.total, 0) || 0

  const takeawayOrders = doneOrders?.filter((order) => order.type === OrderType.Takeaway)
  const takeawayOrdersCount = takeawayOrders?.length || 0
  const takeawayOrdersValue = takeawayOrders?.reduce((total, order) => total + order.total, 0) || 0

  const dineinOrders = doneOrders?.filter((order) => order.type === OrderType.DineIn)
  const dineinOrdersCount = dineinOrders?.length || 0
  const dineinOrdersValue = dineinOrders?.reduce((total, order) => total + order.total, 0) || 0

  const cardPayments = shift.payments?.filter((payment) => payment.method === PaymentMethod.Card)
  const cardPaymentsvalue = cardPayments?.reduce((total, payment) => total + payment.paid, 0) || 0
  const talabatCardPayments = shift.payments?.filter(
    (payment) => payment.method === PaymentMethod.TalabatCard
  )
  const talabatCardPaymentsvalue =
    talabatCardPayments?.reduce((total, payment) => total + payment.paid, 0) || 0
  const cashPayments = shift.payments?.filter((payment) => payment.method === PaymentMethod.Cash)
  const cashPaymentsvalue = cashPayments?.reduce((total, payment) => total + payment.paid, 0) || 0

  return (
    <Row gutter={[0, 25]} className="m-8">
      <PageTitle name="تقرير الورديات" />
      <Col span="24">
        <div className="grid gap-8 grid-cols-4">
          <ReportCard
            title={
              <>
                {doneOrdersCount}
                <Divider type="vertical" className="mx-4" />
                اوردر
              </>
            }
            mainText={<>عدد الاوردرات المكتملة</>}
            secondaryText={
              <>
                بقيمة
                <Divider type="vertical" className="mx-4" />
                {doneOrdersValue.toFixed(2)}
              </>
            }
            icon={<Box className="text-sky-600" />}
            color="bg-sky-200"
          />
          <ReportCard
            title={
              <>
                {dineinOrdersCount}
                <Divider type="vertical" className="mx-4" />
                اوردر
              </>
            }
            mainText={<>عدد اوردرات الصالة</>}
            secondaryText={
              <>
                بقيمة
                <Divider type="vertical" className="mx-4" />
                {dineinOrdersValue.toFixed(2)}
              </>
            }
            icon={<Home2 className="text-green-600" />}
            color="bg-green-200"
          />
          <ReportCard
            title={
              <>
                {deliveryOrdersCount}
                <Divider type="vertical" className="mx-4" />
                اوردر
              </>
            }
            mainText={<>عدد اوردرات ديليفري</>}
            secondaryText={
              <>
                بقيمة
                <Divider type="vertical" className="mx-4" />
                {deleveryOrdersValue.toFixed(2)}
              </>
            }
            icon={<TruckFast className="text-red-600" />}
            color="bg-red-200"
          />
          <ReportCard
            title={
              <>
                {takeawayOrdersCount}
                <Divider type="vertical" className="mx-4" />
                اوردر
              </>
            }
            mainText={<>عدد اوردرات تيك اواي</>}
            secondaryText={
              <>
                بقيمة
                <Divider type="vertical" className="mx-4" />
                {takeawayOrdersValue.toFixed(2)}
              </>
            }
            icon={<Send2 className="text-blue-600" />}
            color="bg-blue-200"
          />
          <ReportCard
            title={
              <>
                {cashPaymentsvalue.toFixed(2)}
                <Divider type="vertical" className="mx-4" />
                جنية
              </>
            }
            mainText={<>النقود المدفوعة كاش</>}
            secondaryText={<></>}
            icon={<Coin1 className="text-yellow-600" />}
            color="bg-yellow-200"
          />
          <ReportCard
            title={
              <>
                {cardPaymentsvalue.toFixed(2)}
                <Divider type="vertical" className="mx-4" />
                جنية
              </>
            }
            mainText={<>النقود المدفوعة فيزا</>}
            secondaryText={<></>}
            icon={<Card className="text-purple-600" />}
            color="bg-purple-200"
          />
          <ReportCard
            title={
              <>
                {talabatCardPaymentsvalue.toFixed(2)}
                <Divider type="vertical" className="mx-4" />
                جنية
              </>
            }
            mainText={<>النقود المدفوعة فيزا طلبات</>}
            secondaryText={<></>}
            icon={<Card className="text-orange-600" />}
            color="bg-orange-200"
          />
        </div>
      </Col>
      <Col span="24" className="isolate">
        <Descriptions
          title="بيانات الوردية"
          bordered
          column={{ xxl: 4, xl: 3, lg: 3, md: 3, sm: 2, xs: 1 }}
          items={[
            {
              key: 'اسم المسؤل',
              label: 'اسم المسؤل',
              children: shift.user?.email,
            },
            {
              key: 'تاريخ بداية الوردية',
              label: 'تاريخ بداية الوردية',
              children: shift.startAt,
            },
            {
              key: 'تاريخ نهاية الوردية',
              label: 'تاريخ نهاية الوردية',
              children: shift.endAt,
            },
            {
              key: 'مصاريف الوردية',
              label: 'مصاريف الوردية',
              children: shift.expenses?.reduce((total, expense) => total + expense.amount, 0) || 0,
            },
            {
              key: 'خدمات توصيل ديليفري',
              label: 'خدمات توصيل ديليفري',
              children: deliveryOrders?.reduce((acc, order) => acc + order.service, 0) || 0,
            },
            {
              key: 'بداية الوردية',
              label: 'بداية الوردية',
              children: shift.startCash,
            },
            {
              key: 'نهاية الوردية',
              label: 'نهاية الوردية',
              children: shift.endCash,
            },
            // {
            //   key: 'المستلم من الوردية',
            //   label: 'المستلم من الوردية',
            //   children: shift.real_cash,
            // },
            // {
            //   key: 'عجز/فائض',
            //   label: 'عجز/فائض',
            //   children: (shift.real_cash - shift.end_cash).toFixed(2),
            // },
          ]}
        />
      </Col>
      <Col span="24" className="isolate">
        <ReportHeaderMini
          title="الاوردرات"
          columns={ordersColumns}
          dataSource={ordersDataSource}
          extraChildren={
            <Button
              className="mr-4"
              onClick={() => {
                console.log('print')
              }}
            >
              طباعة الوردية
            </Button>
          }
        />
        <Table columns={ordersColumns} dataSource={ordersDataSource} pagination={false} />
      </Col>
      <Col span="24" className="isolate">
        <ReportHeaderMini
          title="المصاريف"
          columns={expensesColumns}
          dataSource={expensesDataSource}
        />
        <Table columns={expensesColumns} dataSource={expensesDataSource} pagination={false} />
      </Col>
    </Row>
  )
}
