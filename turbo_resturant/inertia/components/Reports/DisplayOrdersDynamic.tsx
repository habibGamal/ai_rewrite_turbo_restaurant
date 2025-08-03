import { Modal } from 'antd'
import useModal from '../../hooks/useModal.js'
import ReportHeaderMini from '../ReportHeaderMini.js'
import { ShiftReportOrdersTable } from './ShiftReportOrdersTable.js'

export default function DisplayOrders({
  modal,
  title,
  slug,
  showOnMobile,
}: {
  modal: ReturnType<typeof useModal>
  title: string
  slug: string
  showOnMobile?: boolean
}) {
  return (
    <Modal className="!w-[90%]" title={title} destroyOnClose {...modal} footer={null}>
      <div className="isolate">
        <ReportHeaderMini
          title="الاوردرات"
          columns={[]}
          dataSource={[]}
        />
        <ShiftReportOrdersTable slug={slug} showOnMobile={showOnMobile}/>
      </div>
    </Modal>
  )
}
