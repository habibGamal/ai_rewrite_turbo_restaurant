import ShiftsReport from '~/components/Reports/ShiftsReport.js'
import { Shift } from '../../types/Models.js'
import PageTitle from '~/components/PageTitle.js'

export default function CurrentShiftReport({ shift }: { shift: Shift }) {
  return <ShiftsReport shifts={[shift]} header={<PageTitle name="تقرير اليوم" />} />
}
