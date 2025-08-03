import { usePage } from '@inertiajs/react'
import { Modal, Typography } from 'antd'
import { Card, Coin1, Diagram, DiscountCircle, Receipt2 } from 'iconsax-react'
import useModal from '../../hooks/useModal.js'
import ReportHeaderMini from '../ReportHeaderMini.js'
import MoneyInfo from './MoneyInfo.js'
import { ShiftReportOrdersTable } from './ShiftReportOrdersTable.js'

export default function DisplayOrdersByStatusOrType({
  modal,
  title,
}: {
  modal: ReturnType<typeof useModal>
  title: string
}) {
  const slug = 'ordersByStatusOrType'
  const statisticsByStatusOrType =( usePage().props.statisticsByStatusOrType || {
    count: 0,
    paidCash: 0,
    paidCard: 0,
    paidTalabatCard: 0,
    total: 0,
    profit: 0,
    discount: 0,
  })as unknown as {
    count: number
    paidCash: number
    paidCard: number
    paidTalabatCard: number
    total: number
    profit: number
    discount: number
  }
  const moneyInfo = [
    {
      title: 'المبيعات',
      value: statisticsByStatusOrType.total ?? 0,
      icon: <Receipt2 className="text-green-600" />,
      color: 'bg-green-200',
    },
    {
      title: 'الارباح',
      value: statisticsByStatusOrType.profit ?? 0,
      icon: <Diagram className="text-emerald-600" />,
      color: 'bg-emerald-200',
    },
    {
      title: 'الخصومات',
      value: statisticsByStatusOrType.discount ?? 0,
      icon: <DiscountCircle className="text-zinc-600" />,
      color: 'bg-zinc-200',
    },
    {
      title: 'النقود المدفوعة كاش',
      value: statisticsByStatusOrType.paidCash ?? 0,
      icon: <Coin1 className="text-yellow-600" />,
      color: 'bg-yellow-200',
    },
    {
      title: 'النقود المدفوعة فيزا',
      value: statisticsByStatusOrType.paidCard ?? 0,
      icon: <Card className="text-purple-600" />,
      color: 'bg-purple-200',
    },
    {
      title: 'النقود المدفوعة فيزا طلبات',
      value: statisticsByStatusOrType.paidTalabatCard ?? 0,
      icon: <Card className="text-orange-600" />,
      color: 'bg-orange-200',
    },
    {
      title: 'متوسط قيمة الاوردر',
      value:
        statisticsByStatusOrType.count > 0
          ? statisticsByStatusOrType.total / statisticsByStatusOrType.count
          : 0,
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
        <ReportHeaderMini title="الاوردرات" columns={[]} dataSource={[]} />
        <ShiftReportOrdersTable slug={slug} />
      </div>
    </Modal>
  )
}
