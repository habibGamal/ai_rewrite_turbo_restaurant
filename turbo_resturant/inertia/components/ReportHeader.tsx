import { Button, DatePicker, Divider, Select, Space, TableColumnsType } from 'antd'
import React from 'react'
import { Excel } from 'antd-table-saveas-excel'
import dayjs from 'dayjs'
import PageTitle from './PageTitle.js'

const dateOptiotns = [
  {
    label: 'اليوم',
    value: [dayjs().format('YYYY-MM-DD').toString(), dayjs().format('YYYY-MM-DD').toString()].join(
      ','
    ),
  },
  {
    label: 'من امس',
    value: [
      dayjs().add(-1, 'd').format('YYYY-MM-DD').toString(),
      dayjs().format('YYYY-MM-DD').toString(),
    ].join(','),
  },
  {
    label: 'اخر 3 ايام',
    value: [
      dayjs().add(-3, 'd').format('YYYY-MM-DD').toString(),
      dayjs().format('YYYY-MM-DD').toString(),
    ].join(','),
  },
  {
    label: 'اخر 7 ايام',
    value: [
      dayjs().add(-7, 'd').format('YYYY-MM-DD').toString(),
      dayjs().format('YYYY-MM-DD').toString(),
    ].join(','),
  },
  {
    label: 'اخر 14 يوم',
    value: [
      dayjs().add(-14, 'd').format('YYYY-MM-DD').toString(),
      dayjs().format('YYYY-MM-DD').toString(),
    ].join(','),
  },
  {
    label: 'اخر 30 يوم',
    value: [
      dayjs().add(-30, 'd').format('YYYY-MM-DD').toString(),
      dayjs().format('YYYY-MM-DD').toString(),
    ].join(','),
  },
  {
    label: 'اخر 90 يوم',
    value: [
      dayjs().add(-90, 'd').format('YYYY-MM-DD').toString(),
      dayjs().format('YYYY-MM-DD').toString(),
    ].join(','),
  },
]

export default function ReportHeader({
  title,
  getResults,
  columns,
  dataSource,
}: {
  title: string
  getResults: (from: string, to: string) => void
  columns: TableColumnsType<any>
  dataSource: any[]
}) {
  // get from , to dates from url query
  const urlParams = new URLSearchParams(window.location.search)
  const from = urlParams.get('from')
  const to = urlParams.get('to')
  const [period, setPeriod] = React.useState<{ from: string; to: string }>({
    from: from || '',
    to: to || '',
  })
  console.log(period)
  return (
    <div className="flex flex-wrap justify-between w-full">
      <PageTitle name={title} />
      <Space className="flex-wrap">
        <Select
          placeholder="فترات سابقة"
          className="min-w-[200px]"
          onSelect={(value) => {
            const [from, to] = value.split(',')
            getResults(from, to)
          }}
          options={dateOptiotns}
        />
        <Divider type="vertical" className="hidden md:block" />
        <DatePicker
          placeholder="بداية الفترة"
          onChange={(_, dateString) =>
            setPeriod({
              ...period,
              from: dateString,
            })
          }
          value={period.from ? dayjs(period.from) : undefined}
        />
        <DatePicker
          placeholder="نهاية الفترة"
          onChange={(_, dateString) =>
            setPeriod({
              ...period,
              to: dateString,
            })
          }
          value={period.to ? dayjs(period.to) : undefined}
        />
        <Button
          onClick={() => {
            getResults(period.from, period.to)
          }}
        >
          عرض النتائج
        </Button>
        <Divider type="vertical" className="hidden md:block" />
        {columns.length !== 0 && (
          <Button
            className="hidden md:block"
            onClick={() => {
              const excel = new Excel()
              excel
                .addSheet('export')
                .addColumns(columns as any)
                .addDataSource(dataSource)
                .saveAs(`${title}_${period.from}-${period.to}.xlsx`)
            }}
          >
            استخراج csv
          </Button>
        )}
      </Space>
    </div>
  )
}
