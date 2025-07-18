import { router } from '@inertiajs/react'
import ReportHeader from '../../components/ReportHeader.js'
import { Shift } from '../../types/Models.js'
import ShiftsReport from '~/components/Reports/ShiftsReport.js'

export default function FullShiftsReport({ shifts }: { shifts: Shift[] }) {
  const getResults = (from: string, to: string) => {
    router.get(
      `/reports/full-shifts-report`,
      {
        from,
        to,
      }
    )
  }
  return (
    <ShiftsReport
      shifts={shifts}
      header={
        <ReportHeader
          title="تقرير شامل للورديات"
          getResults={getResults}
          columns={[]}
          dataSource={[]}
        />
      }
    />
  )
}
