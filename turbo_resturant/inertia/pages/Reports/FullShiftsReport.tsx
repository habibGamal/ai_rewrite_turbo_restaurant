import { router } from '@inertiajs/react'
import ShiftsReportComponent from '~/components/Reports/ShiftsReportComponent.js'
import Pagination from '~/types/Pagination.js'
import { ShiftStat } from '~/types/Types.js'
import ReportHeader from '../../components/ReportHeader.js'
import { Order } from '../../types/Models.js'

export default function FullShiftsReport({
  statistics,
  ordersPaginator,
}: {
  statistics: ShiftStat
  ordersPaginator: Pagination<Order>
}) {

  const getResults = (from: string, to: string) => {
    router.get(`/reports/full-shifts-report`, {
      from,
      to,
    })
  }
  return (
    <ShiftsReportComponent
      data={{ statistics, ordersPaginator }}
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
