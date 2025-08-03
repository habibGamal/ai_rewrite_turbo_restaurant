import { Modal, TableColumnsType, Typography } from 'antd'
import useModal from '../../hooks/useModal.js'
import { Expense } from '../../types/Models.js'
import ReportHeaderMini from '../ReportHeaderMini.js'
import ShiftReportExpensesTable from './ShiftReportExpensesTable.js'

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
        columns={[]}
        dataSource={[]}
      />
      <ShiftReportExpensesTable />
    </Modal>
  )
}
