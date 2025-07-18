import { Modal, Table } from 'antd'
import useModal from '../../hooks/useModal.js'
import OrdersReportService, { ordersColumns } from '../../services/OrdersReportService.js'
import { Order } from '../../types/Models.js'
import ReportHeaderMini from '../ReportHeaderMini.js'

export default function DisplayOrders({
  modal,
  title,
  orders,
}: {
  modal: ReturnType<typeof useModal>
  title: string
  orders: Order[]
}) {
  const ordersDataSource = OrdersReportService.mappingToTableData(orders)

  return (
    <Modal className="!w-[90%]" title={title} destroyOnClose {...modal} footer={null}>
      <div className="isolate">
        <ReportHeaderMini
          title="الاوردرات"
          columns={ordersColumns.filter((column) => column.key !== 'action')}
          dataSource={ordersDataSource}
        />
        <Table columns={ordersColumns} dataSource={ordersDataSource}  scroll={{ x: true }} />
      </div>
    </Modal>
  )
}
