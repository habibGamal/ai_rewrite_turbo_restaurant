import PageTitle from '~/components/PageTitle.js'
import ShiftsReportComponent from '~/components/Reports/ShiftsReportComponent.js'
import Pagination from '~/types/Pagination.js'
import { ShiftStat } from '~/types/Types.js'
import { Order } from '../../types/Models.js'

export default function CurrentShiftReport({
  statistics,
  ordersPaginator,
}: {
  statistics: ShiftStat
  ordersPaginator: Pagination<Order>
}) {
  return (
    <ShiftsReportComponent
      data={{ statistics, ordersPaginator }}
      header={<PageTitle name="تقرير اليوم" />}
    />
  )
}
