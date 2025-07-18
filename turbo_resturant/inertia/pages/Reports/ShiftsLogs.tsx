import { router } from '@inertiajs/react'
import { Button, Col, Row, Table, TableColumnsType } from 'antd'
import EmptyReport from '../../components/EmptyReport.js'
import ReportHeader from '../../components/ReportHeader.js'
import { Shift } from '../../types/Models.js'

const columns: TableColumnsType<{
  id: number
  startTime: string
  endTime: string
  userName: string
  totalShift: number
  actualTotal: number
}> = [
  {
    title: 'رقم الوردية',
    dataIndex: 'id',
    key: 'id',
  },
  {
    title: 'تاريخ بداية الوردية',
    dataIndex: 'startTime',
    key: 'startTime',
    // render: (text:string) => <span className='text-sm'>{text}</span>
  },
  {
    title: 'التفاصيل',
    dataIndex: 'id',
    key: 'show',
    render: (id: number) => (
      <Button size='small' onClick={() => router.get(`/reports/logs-report/${id}`)}>عرض</Button>
    ),
  },
]

const mappingData = (shifts: Shift[]) =>
  shifts.map((shift) => ({
    id: shift.id,
    startTime: shift.startAt,
    endTime: shift.endAt,
    userName: shift.user?.email,
    totalShift: shift.endCash,
    actualTotal: shift.realCash,
    defict: (shift.realCash - shift.endCash).toFixed(2),
  }))

export default function ShiftsLogs({ shifts }: { shifts: Shift[] }) {
  const dataSource = mappingData(shifts)

  const getResults = (from: string, to: string) => {
    router.get(`/reports/shifts-report`, {
      from,
      to,
    })
  }

  return (
    <Row gutter={[0, 12]} className="m-4 lg:m-8">
      <ReportHeader
        title="سجل الورديات"
        getResults={getResults}
        columns={columns}
        dataSource={dataSource}
      />
      <EmptyReport condition={dataSource.length === 0}>
        <Col span="24" className="isolate !p-0">
          <Table columns={columns} dataSource={dataSource} pagination={false} />
        </Col>
      </EmptyReport>
    </Row>
  )
}
