import { Modal, Table, Typography } from 'antd'
import { ArrowSwapHorizontal, Card, Coin1, Diagram, DiscountCircle, Money3, Receipt2 } from 'iconsax-react'
import React from 'react'
import ReportHeaderMini from '../../components/ReportHeaderMini.js'
import useModal from '../../hooks/useModal.js'
import OrdersReportService, { ordersColumns } from '../../services/OrdersReportService.js'
import { Order } from '../../types/Models.js'
import MoneyInfo from './MoneyInfo.js'

export default function DisplayOrderType({
  modal,
  title,
  orders,
}: {
  modal: ReturnType<typeof useModal>
  title: string
  orders: Order[]
}) {
  const ordersDataSource = OrdersReportService.mappingToTableData(orders)
  const reportService = new OrdersReportService(orders)

  const moneyInfo = [
    {
      title: 'المبيعات',
      value: reportService.sales,
      icon: <Receipt2 className="text-green-600" />,
      color: 'bg-green-200',
    },
    {
      title: 'الارباح',
      value: reportService.profit,
      icon: <Diagram className="text-emerald-600" />,
      color: 'bg-emerald-200',
    },
    {
      title: 'الخصومات',
      value: reportService.totalDiscount,
      icon: <DiscountCircle className="text-zinc-600" />,
      color: 'bg-zinc-200',
    },
    {
      title: 'النقود المدفوعة كاش',
      value: reportService.cashPaymentsvalue,
      icon: <Coin1 className="text-yellow-600" />,
      color: 'bg-yellow-200',
    },
    {
      title: 'النقود المدفوعة فيزا',
      value: reportService.cardPaymentsvalue,
      icon: <Card className="text-purple-600" />,
      color: 'bg-purple-200',
    },
    {
      title: 'النقود المدفوعة فيزا طلبات',
      value: reportService.talabatCardPaymentsvalue,
      icon: <Card className="text-orange-600" />,
      color: 'bg-orange-200',
    },
    {
      title: 'متوسط قيمة الاوردر',
      value: reportService.avgReceiptValue,
      icon: <Receipt2 className="text-green-600" />,
      color: 'bg-green-200',
    },
  ]
  return (
    <Modal className="!w-[90%]" title={title} destroyOnClose {...modal} footer={null}>
      <Typography.Title level={4}>الإيراد</Typography.Title>
      <div className="grid gap-8 mb-8 cards-grid">
        {moneyInfo.map((info) => (
          <MoneyInfo mainText={info.title} value={info.value} icon={info.icon} color={info.color} />
        ))}
      </div>
      <div className="isolate no-mobile">
        <ReportHeaderMini title="الاوردرات" columns={ordersColumns} dataSource={ordersDataSource} />
        <Table columns={ordersColumns} dataSource={ordersDataSource} scroll={{ x: true }} />
      </div>
    </Modal>
  )
}
