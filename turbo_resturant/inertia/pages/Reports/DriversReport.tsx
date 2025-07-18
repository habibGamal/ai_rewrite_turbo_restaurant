import { router } from '@inertiajs/react'
import { Button, Col, Row, Table, TableColumnsType } from 'antd'
import { useState } from 'react'
import EmptyReport from '../../components/EmptyReport.js'
import ReportHeader from '../../components/ReportHeader.js'
import DisplayOrders from '../../components/Reports/DisplayOrders.js'
import useModal from '../../hooks/useModal.js'
import { Driver, Order } from '../../types/Models.js'

const columns: TableColumnsType<{
  id: number
  name: string
  phone: string
  totalOrders: number
  driver: Driver
  displayDriverOrders: (driver: Driver) => void
}> = [
  {
    title: 'الاسم',
    dataIndex: 'name',
    key: 'name',
  },
  {
    title: 'الهاتف',
    dataIndex: 'phone',
    key: 'phone',
  },
  {
    title: 'اجمالي الطلبات',
    dataIndex: 'totalOrders',
    key: 'totalOrders',
  },
  {
    title: 'الطلبات',
    dataIndex: 'orders',
    key: 'orders',
    render: (orders: Order[], record) => {
      return (
        <>
          <Button
            onClick={() => {
              record.displayDriverOrders(record.driver)
            }}
          >
            عرض
          </Button>
        </>
      )
    },
  },
]

const mappingData = (drivers: Driver[], displayDriverOrders: (driver: Driver) => void) =>
  drivers.map((driver) => ({
    id: driver.id,
    name: driver.name,
    phone: driver.phone,
    totalOrders: driver.orders?.reduce((acc, order) => acc + order.total, 0),
    driver,
    displayDriverOrders,
  }))

export default function DriversReport({ drivers }: { drivers: Driver[] }) {
  const modal = useModal()
  const [driver, setDriver] = useState<Driver | null>(null)
  const displayDriverOrders = (driver: Driver) => {
    setDriver(driver)
    setTimeout(() => {
      modal.showModal()
    }, 0)
  }

  const dataSource = mappingData(drivers, displayDriverOrders)

  const getResults = (from: string, to: string) => {
    router.get(
      `/reports/drivers-report`,
      {
        from,
        to,
      }
    )
  }

  return (
    <Row gutter={[0, 25]} className="m-8">
      <ReportHeader
        title="تقرير السائقين"
        getResults={getResults}
        columns={columns.filter((column) => column.key !== 'orders')}
        dataSource={dataSource}
      />

      <DisplayOrders
        modal={modal}
        title={`اوردرات ${driver?.name}`}
        orders={driver ? driver.orders : []}
      />
      <EmptyReport condition={dataSource.length === 0}>
        <Col span="24" className="isolate">
          <Table columns={columns} dataSource={dataSource} pagination={false} />
        </Col>
      </EmptyReport>
    </Row>
  )
}
