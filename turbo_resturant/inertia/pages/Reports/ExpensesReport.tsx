import { router } from '@inertiajs/react'
import { Col, Row, Table, TableColumnsType } from 'antd'
import EmptyReport from '../../components/EmptyReport.js'
import ReportHeader from '../../components/ReportHeader.js'
import ReportHeaderMini from '../../components/ReportHeaderMini.js'
import { Expense } from '../../types/Models.js'

const expensesColumns: TableColumnsType<{
  id: number
  expensetype: string
  amount: number
  description: string
  createdAt: string
}> = [
  {
    title: 'نوع المصروف',
    dataIndex: 'expensetype',
    key: 'expensetype',
    sorter: (a, b) => a.expensetype.localeCompare(b.expensetype),
  },
  {
    title: 'المبلغ',
    dataIndex: 'amount',
    key: 'amount',
  },
  {
    title: 'الوصف',
    dataIndex: 'description',
    key: 'description',
  },
  {
    title: 'تاريخ المصروف',
    dataIndex: 'createdAt',
    key: 'createdAt',
  },
]

const totalsColumns: TableColumnsType<{
  type: string
  total: number
}> = [
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

const mappingTotals = (totals: Record<string, number>) =>
  Object.entries(totals).map(([type, total]) => ({
    type,
    total,
  }))

const mappingData = (expenses: Expense[]) =>
  expenses.map((expense) => ({
    id: expense.id,
    expensetype: expense.expenseType.name,
    amount: expense.amount,
    description: expense.description,
    createdAt: expense.createdAt,
  }))

export default function ExpensesReport({ expenses }: { expenses: Expense[] }) {
  const expensesDataSource = mappingData(expenses)

  // total of each type of expense
  const totals = expenses.reduce(
    (acc, expense) => {
      const type = expense.expenseType.name
      if (!acc[type]) {
        acc[type] = 0
      }
      acc[type] += expense.amount
      return acc
    },
    {} as Record<string, number>
  )

  const totalDataSource = mappingTotals(totals)

  const getResults = (from: string, to: string) => {
    router.get(`/reports/expenses-report`, {
      from,
      to,
    })
  }

  return (
    <Row gutter={[0, 25]} className="m-8">
      <ReportHeader
        title="تقرير المصروفات"
        getResults={getResults}
        columns={expensesColumns}
        dataSource={expensesDataSource}
      />
      <EmptyReport condition={expensesDataSource.length === 0}>
        <Col span="24" className="isolate">
          <Table columns={expensesColumns} dataSource={expensesDataSource} />
        </Col>
      </EmptyReport>
      <Col span="24" className="isolate">
        <ReportHeaderMini title="الاجمالي" columns={totalsColumns} dataSource={totalDataSource} />
        <Table columns={totalsColumns} dataSource={totalDataSource} pagination={false} />
      </Col>
    </Row>
  )
}
