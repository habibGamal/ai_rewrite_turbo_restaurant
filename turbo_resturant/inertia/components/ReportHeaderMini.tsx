import { Button, Divider, Space, TableColumnsType } from 'antd'
import React from 'react'
import { Excel } from 'antd-table-saveas-excel'
import PageTitle from './PageTitle.js'

export default function ReportHeaderMini({
  title,
  columns,
  dataSource,
  extraChildren,
}: {
  title: string
  columns: TableColumnsType<any>
  dataSource: any[]
  extraChildren?: React.ReactNode
}) {
  return (
    <div className="flex justify-between w-full">
      <PageTitle name={title} />
      <div className='hidden md:block'>
        <Button
          onClick={() => {
            const excel = new Excel()
            excel
              .addSheet('export')
              .addColumns(columns as any)
              .addDataSource(dataSource)
              .saveAs(`${title}.xlsx`)
          }}
        >
          استخراج csv
        </Button>
        {extraChildren}
      </div>
    </div>
  )
}
