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
  },
  {
    title: 'المسؤل',
    dataIndex: 'userName',
    key: 'userName',
  },
  {
    title: 'صافي الوردية',
    dataIndex: 'totalShift',
    key: 'totalShift',
  },
  // {
  //   title: 'المستلم من الوردية',
  //   dataIndex: 'actualTotal',
  //   key: 'actualTotal',
  // },
  // {
  //   title: 'عجز/فائض',
  //   dataIndex: 'defict',
  //   key: 'defict',
  //   render: (defict: number) => (
  //     <Tag className="text-lg" bordered={false} color={defict < 0 ? 'red' : 'green'}>
  //       {defict}
  //     </Tag>
  //   ),
  // },
  {
    title: 'التفاصيل',
    dataIndex: 'id',
    key: 'show',
    render: (id: number) => (
      <Button onClick={() => router.get(`/reports/shift-report/${id}`)}>عرض</Button>
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

export default function ShiftsReport({ shifts }: { shifts: Shift[] }) {
  const dataSource = mappingData(shifts)

  const getResults = (from: string, to: string) => {
    router.get(`/reports/shifts-report`, {
      from,
      to,
    })
  }

  return (
    <Row gutter={[0, 25]} className="m-8">
      <ReportHeader
        title="تقرير الورديات"
        getResults={getResults}
        columns={columns}
        dataSource={dataSource}
      />
      <EmptyReport condition={dataSource.length === 0}>
        <Col span="24" className="isolate">
          <Table columns={columns} dataSource={dataSource} pagination={false} />
        </Col>
      </EmptyReport>
    </Row>
  )
}
