import CategoriesController from '#controllers/categories_controller'
import { Col, Row, Table } from 'antd/es'
import Header from '~/components/RenderModel/Header.js'
import ModalForm from '~/components/RenderModel/ModalForm.js'
import tableConfig, { TableData } from '~/helpers/tableConfig.js'
import useModalForm from '~/hooks/useModalForm.js'
import { InferPageProps } from '~/index'
import Pagination from '~/types/Pagination.js'
import TableController from '../components/TableController.js'
import { usePage } from '@inertiajs/react'
import { TableConfig } from '~/types/Types.js'

export type Props = InferPageProps<CategoriesController, 'index'>

export default function RenderModel({ data, actions, routes, noForm, noActions }: Props) {
  const paginationData = data as Pagination<any>

  const { add, edit, model, modalForm } = useModalForm()

  const tableData = paginationData.data.map((record) => ({
    key: record.id.toString(),
    ...record,
  })) as TableData[]

  const tableConfigrations = usePage().props as unknown as TableConfig
  const {
    tableParams,
    tableColumns,
    handleTableChange,
    search,
    tableLoading,
    searchableColumns,
    addControls,
    useSearchWhileTyping,
  } = tableConfig({ tableConfigrations })

  useSearchWhileTyping()

  if (noActions === false) addControls(actions, tableColumns, edit, routes?.destroy)

  return (
    <Row gutter={[0, 25]} className="m-8">
      <Header />
      <ModalForm model={model} modalForm={modalForm} />
      <Col span="24" className="isolate">
        <TableController
          addButtonText={noForm ? null : 'انشاء'}
          addButtonAction={add}
          searchButtonAction={() => search.enterSearchMode()}
          setSearch={search.setSearch}
          setAttribute={search.setAttribute}
          exitSearchMode={() => {}}
          options={searchableColumns}
        />
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
    </Row>
  )
}
