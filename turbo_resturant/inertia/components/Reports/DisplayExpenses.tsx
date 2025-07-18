import { Modal, Table, TableColumnsType, Typography } from 'antd'
import {
  ArrowSwapHorizontal,
  Card,
  Coin1,
  Diagram,
  DiscountCircle,
  Money3,
  Receipt2,
} from 'iconsax-react'
import React from 'react'
import ReportHeaderMini from '../ReportHeaderMini.js'
import useModal from '../../hooks/useModal.js'
import OrdersReportService, { ordersColumns } from '../../services/OrdersReportService.js'
import { Expense, Order } from '../../types/Models.js'
import MoneyInfo from './MoneyInfo.js'

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
    title: 'نوع المصروف',
    dataIndex: 'expenseType',
    key: 'type',
    render: (expenseType) => <Typography.Text>{expenseType?.name}</Typography.Text>,
  },
  {
    title: 'الوصف',
    dataIndex: 'description',
    key: 'description',
    render: (description) => <Typography.Text>{description}</Typography.Text>,
  },
]

export default function DisplayExpenses({
  modal,
  expensesDataSource,
}: {
  modal: ReturnType<typeof useModal>
  expensesDataSource: Expense[]
}) {


  return (
    <Modal className="!w-[90%]" title="-" destroyOnClose {...modal} footer={null}>
      <ReportHeaderMini
        title="المصاريف"
        columns={expensesColumns}
        dataSource={expensesDataSource}
      />
      <Table columns={expensesColumns} dataSource={expensesDataSource} />
    </Modal>
  )
}
