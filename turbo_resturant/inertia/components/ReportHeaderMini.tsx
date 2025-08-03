import { Button, Divider, Space, TableColumnsType } from 'antd'
import React from 'react'
import { Excel } from 'antd-table-saveas-excel'
import PageTitle from './PageTitle.js'
import { router } from '@inertiajs/react'

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
      <div className="hidden md:block">
        {columns.length !== 0 && (
          <Button
            onClick={() => {
              if (dataSource.length !== 0) {
                const excel = new Excel()
                excel
                  .addSheet('export')
                  .addColumns(columns as any)
                  .addDataSource(dataSource)
                  .saveAs(`${title}.xlsx`)
              }
            }}
          >
            استخراج csv
          </Button>
        )}
        {extraChildren}
      </div>
    </div>
  )
}
