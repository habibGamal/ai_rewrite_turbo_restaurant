import { Table, TableColumnsType } from 'antd'
import React from 'react'

export default function SalesByClientsTable({
  columns,
  dataSource,
}: {
  columns: TableColumnsType<any>
  dataSource: any[]
}) {
  return <Table columns={columns} dataSource={dataSource} pagination={false} />
}
