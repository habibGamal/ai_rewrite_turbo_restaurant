import { Line } from '@ant-design/plots'
import { router } from '@inertiajs/react'
import { Button, Col, Divider, Row, TableColumnsType, Typography } from 'antd'
import { Excel } from 'antd-table-saveas-excel'
import React from 'react'
import ReportHeader from '../../components/ReportHeader.js'
import {
  Expense,
  Order,
  PurchaseInvoice,
  ReturnPurchaseInvoice,
  Stocktaking,
} from '../../types/Models.js'

const columns: TableColumnsType<{
  key: React.Key
  name: string
  salesQuantity: number
  salesTotal: number
  salesProfit: number
}> = [
  {
    title: 'أسم المنتج',
    dataIndex: 'name',
    key: 'name',
  },
  {
    title: 'الكمية المباعة',
    dataIndex: 'salesQuantity',
    key: 'salesQuantity',
  },
  {
    title: 'قيمة المبيعات',
    dataIndex: 'salesTotal',
    key: 'salesTotal',
    sorter: (a, b) => a.salesTotal - b.salesTotal,
    sortDirections: ['descend', 'ascend'],
  },
  {
    title: 'ربح المنتج',
    dataIndex: 'salesProfit',
    key: 'salesProfit',
    sorter: (a, b) => a.salesProfit - b.salesProfit,
    sortDirections: ['descend', 'ascend'],
  },
]

const configLine = (data: any, xField: string, yField: string): any => ({
  data,
  xField,
  yField,
  xAxis: {
    // type: 'timeCat',
    tickCount: 5,
  },
  yAxis: {
    grid: {
      line: {
        style: {
          strokeOpacity: 0.3,
        },
      },
    },
  },
  annotations: [
    // Color the region below y=0 red
    {
      type: 'regionFilter',
      start: ['min', 0],
      end: ['max', 'min'],
      color: '#F4664A',
    },
    // Draw a horizontal line at y=0
    {
      type: 'line',
      start: ['min', 0],
      end: ['max', 0],
      style: {
        stroke: '#F4664A',
        lineDash: [2, 2],
      },
    },
  ],
})

export default function ProductsReport({
  stocktakings,
  purchaseInvoices,
  returnPurchaseInvoices,
  orders,
  expenses,
}: {
  stocktakings: Stocktaking[]
  purchaseInvoices: PurchaseInvoice[]
  returnPurchaseInvoices: ReturnPurchaseInvoice[]
  orders: Order[]
  expenses: Expense[]
}) {
  const dataSource = []

  const getResults = (from: string, to: string) => {
    router.get(
      `/reports/detailed-report`,
      {
        from,
        to,
      },
      {
        preserveState: true,
      }
    )
  }

  return (
    <Row gutter={[0, 25]} className="m-8">
      <ReportHeader
        title="تقرير مفصل"
        getResults={getResults}
        columns={columns}
        dataSource={dataSource}
      />
      {/* <EmptyReport condition={dataSource.length === 0}>

      </EmptyReport> */}
      <Graph
        title="ارباح الطلبات"
        data={orders.map((point) => ({ ...point, total: point.profit }))}
        seriesField="typeString"
      />
      <Divider />
      <Graph title="المبيعات" data={orders} seriesField="typeString" />
      <Divider />
      <Graph
        title="المصاريف"
        data={expenses.map((point) => ({ ...point, total: point.amount }))}
        seriesField="typeString"
      />
      <Divider />
      <Graph
        title="الجرد"
        data={stocktakings.map((point) => ({ ...point, total: point.balance }))}
      />
      <Divider />
      <Graph title="فواتير الشراء" data={purchaseInvoices} />
      <Divider />
      <Graph title="فواتير الاسترجاع" data={returnPurchaseInvoices} />
    </Row>
  )
}

function Graph({
  title,
  data,
  seriesField,
}: {
  title: string
  seriesField?: string
  data: {
    created_at: string
    total: number
    [key: string]: any
  }[]
}) {
  const columns =
    data.length > 0
      ? Object.keys(data[0]).map((key) => ({
          title: key,
          dataIndex: key,
          key,
        }))
      : false

  const points: { day: string; total: number }[] = []

  data.forEach((point) => {
    const day = point.created_at.split(' ')[1]
    const index = points.findIndex((p) => p.day === day)
    if (index === -1) {
      points.push({
        ...point,
        day,
        total: point.total,
      })
    } else {
      points[index].total += point.total
    }
  })

  points.sort((a, b) => {
    if (a.day > b.day) {
      return 1
    }
    if (a.day < b.day) {
      return -1
    }
    return 0
  })

  points.map((point) =>
    seriesField
      ? {
          Day: point.day,
          Total: point.total,
          [seriesField]: point[seriesField],
        }
      : {
          Day: point.day,
          Total: point.total,
        }
  )
  return (
    <>
      <div className="flex justify-between items-center w-full">
        <Typography.Title level={5}>{title}</Typography.Title>
        <Button
          onClick={() => {
            if (!columns) return
            const excel = new Excel()
            excel.addSheet('export').addColumns(columns).addDataSource(data).saveAs(`${title}.xlsx`)
          }}
        >
          استخراج csv
        </Button>
      </div>
      <Col className="isolate ltr" span={24}>
        <Line
          {...configLine(
            points.map((point) =>
              seriesField
                ? {
                    Day: point.day,
                    Total: point.total,
                    [seriesField]: point[seriesField],
                  }
                : {
                    Day: point.day,
                    Total: point.total,
                  }
            ),
            'Day',
            'Total'
          )}
          shapeStyle={{
            strokeOpacity: 0.3,
          }}
          seriesField={seriesField}
        />
      </Col>
    </>
  )
}
