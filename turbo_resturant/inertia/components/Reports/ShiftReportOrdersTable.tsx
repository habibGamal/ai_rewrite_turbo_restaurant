import { router, usePage } from '@inertiajs/react'
import { Button, Col, Table } from 'antd'
import tableConfig from '~/helpers/tableConfig.js'
import Pagination from '~/types/Pagination.js'
import OrdersReportService from '../../services/OrdersReportService.js'
import { Order } from '../../types/Models.js'
import TableController from '../TableController.js'
import { printOrder } from '~/helpers/printTemplate.js'
import axios from 'axios'
import clearSlugFromUrlQuery from '~/helpers/clearSlugFromUrlQuery.js'

export const ordersColumns = [
  {
    label: 'الرقم المرجعي',
    key: 'id',
    sortable: true,
  },
  {
    label: 'رقم الاوردر',
    key: 'orderNumber',
    sortable: true,
  },
  {
    label: 'النوع',
    key: 'type',
    dataIndex: 'typeString',
    sortable: true,
  },
  {
    label: 'الخصم',
    key: 'discount',
    sortable: true,
  },
  {
    label: 'قيمة الاوردر',
    key: 'total',
    sortable: true,
  },
  {
    label: 'المدفوع',
    key: 'paid',
    sortable: true,
  },
  {
    label: 'المدفوع كاش',
    key: 'paidCash',
    sortable: true,
  },
  {
    label: 'المدفوع فيزا',
    key: 'paidCard',
    sortable: true,
  },
  {
    label: 'المدفوع طلبات',
    key: 'paidTalabatCard',
    sortable: true,
  },
  {
    label: 'متبقي',
    key: 'remaining',
    sortable: true,
  },
  {
    label: 'ربح الاوردر',
    key: 'profit',
    sortable: true,
  },
  {
    label: 'حالة الاوردر',
    key: 'status',
    dataIndex: 'orderStatus',
    sortable: true,
  },
  {
    label: 'التفاصيل',
    key: 'action',
    render: (_, record) => (
      <div className="flex gap-2">
        <Button onClick={() => router.get(`/orders/${record.id}`)}>التفاصيل</Button>{' '}
        <Button
          onClick={async () => {
            const result = await axios.get<{ order: Order; receiptFooter: [{ value: string }] }>(
              `/load-order-to-print/${record.id}`
            )

            await printOrder(
              result.data.order,
              result.data.order.items?.map((item) => ({
                ...item,
                name: item.product?.name,
              })) as any,
              result.data.receiptFooter[0].value,
              {
                useApiCall: true,
              }
            )
          }}
        >
          طباعة
        </Button>
      </div>
    ),
  },
]

export function ShiftReportOrdersTable({
  slug,
  showOnMobile,
}: {
  slug: string
  showOnMobile?: boolean
}) {
  const options: { label: string; key: string }[] = [
    { label: 'الرقم المرجعي', key: 'orders/id' },
    { label: 'رقم الاوردر', key: 'orders/order_number' },
  ]

  const paginationData = usePage().props[slug] as Pagination<Order>
  const tableData = OrdersReportService.mappingToTableData(paginationData.data)

  const {
    tableParams,
    tableColumns,
    handleTableChange,
    search,
    tableLoading,
    searchableColumns,
    useSearchWhileTyping,
  } = tableConfig({
    tableConfigrations: {
      columns: ordersColumns,
      slug,
      useSlug: true,
      searchable: options,
    },
  })

  useSearchWhileTyping()

  const exportCSV = () => {
    router.reload({
      only: [slug],
      data: {
        export: true,
        columns: tableColumns.filter((column) => column.key !== 'action'),
        total: paginationData.meta.total,
        slug: slug,
      } as any,
      preserveState: true,
      onStart: () => tableLoading.stateLoading.onStart(),
      onFinish: () => {
        tableLoading.stateLoading.onFinish()
        clearSlugFromUrlQuery('export')
        clearSlugFromUrlQuery('columns[]')
        const a = document.createElement('a')
        a.href = '/exports/' + `${slug}.csv.xlsx`
        a.download = `${slug}.csv.xlsx`
        a.click()
      },
    })
  }
  return (
    <Col span="24" className={`${showOnMobile ? 'block' : 'hidden md:block'}`}>
      <div className="md:flex gap-4 hidden">
        <TableController
          searchButtonAction={() => search.enterSearchMode()}
          setSearch={search.setSearch}
          setAttribute={search.setAttribute}
          exitSearchMode={() => {}}
          options={searchableColumns}
        />
        <Button onClick={exportCSV}>استخراج csv</Button>
      </div>
      <Table
        columns={tableColumns}
        dataSource={tableData}
        pagination={{
          ...tableParams.pagination,
          total: paginationData.meta.total,
        }}
        loading={tableLoading.loading}
        bordered
        onChange={handleTableChange}
        scroll={{ x: true }}
        footer={() => 'عدد النتائج : ' + paginationData.meta.total}
      />
    </Col>
  )
}
