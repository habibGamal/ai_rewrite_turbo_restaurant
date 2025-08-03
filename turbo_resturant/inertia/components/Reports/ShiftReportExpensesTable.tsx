import { router, usePage } from '@inertiajs/react'
import { Button, Table } from 'antd'
import tableConfig from '~/helpers/tableConfig.js'
import Pagination from '~/types/Pagination.js'
import { Expense } from '../../types/Models.js'
import TableController from '../TableController.js'
import clearSlugFromUrlQuery from '~/helpers/clearSlugFromUrlQuery.js'

export default function ShiftReportExpensesTable() {
  const slug = 'expenses'
  const options: { label: string; key: string }[] = [{ label: 'الوصف', key: 'description' }]
  const paginationData = usePage().props[slug] as Pagination<Expense>
  const tableData = paginationData
    ? paginationData.data.map((expense) => ({
        ...expense,
        type: expense.meta.type,
      }))
    : []
  console.log(paginationData)
  const ordersColumns = [
    {
      label: 'القيمة',
      dataIndex: 'amount',
      key: 'amount',
      sortable: true,
    },
    {
      label: 'نوع المصروف',
      dataIndex: 'type',
      key: 'type',
      sortable: true,
    },
    {
      label: 'الوصف',
      dataIndex: 'description',
      key: 'description',
      sortable: true,
    },
  ]

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
    <>
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
          total: paginationData ? paginationData.meta.total : 0,
        }}
        loading={tableLoading.loading}
        bordered
        onChange={handleTableChange}
        scroll={{ x: true }}
        footer={() => 'عدد النتائج : ' + paginationData.meta.total}
      />
    </>
  )
}
