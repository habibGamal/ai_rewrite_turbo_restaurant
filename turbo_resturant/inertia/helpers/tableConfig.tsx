import { PageProps } from '@adonisjs/inertia/types'
import { router, usePage } from '@inertiajs/react'
import { Button, Space, Tag } from 'antd/es'
import { ColumnsType, TablePaginationConfig } from 'antd/es/table'
import { FilterValue, SortOrder, SorterResult } from 'antd/es/table/interface'
import React from 'react'
import useLoading from '~/hooks/useLoading.js'
import useSortTable from '~/hooks/useSortTable.js'
import useTablePagination from '~/hooks/useTablePagination.js'
import useTableSearch from '~/hooks/useTableSearch.js'
import useWhileTyping from '~/hooks/useWhileTyping.js'
import { Props } from '~/pages/RenderModel.js'
import DeleteButton from '../components/DeleteButton.js'
import EditButton from '../components/EditButton.js'
import sortInfoMapping from '../helpers/sortInfoMapping.js'
import { TableConfig } from '~/types/Types.js'
type RowData = {
  id: number
  [key: string]: any
}

export type TableData = {
  key: string
  sorter?: boolean
  sortOrder?: SortOrder | undefined
  onHeaderCell?: () => {
    onClick: () => void
  }
  sortDirections?: SortOrder[]
} & RowData

type UpdateTableDataArgs = {
  slug: string
  useSlug?: boolean
  page?: number
  pageSize?: number
  order?: string | null
  columnKey?: React.Key | null
  search: string
  attribute: string
  tableLoading: ReturnType<typeof useLoading>
}

export default function tableConfig({ tableConfigrations }: { tableConfigrations: TableConfig }) {
  const { columns, slug, searchable, useSlug } = tableConfigrations
  const tableLoading = useLoading()

  const searchableColumns = searchable.map(({ key, label }) => ({
    label: label,
    value: key,
  }))

  const search = useTableSearch(searchableColumns[0].value)

  const { tableParams, updateTableParams, resetPagination } = useTablePagination(slug)

  const handleTableChange = (
    pagination: TablePaginationConfig,
    filters: Record<string, FilterValue | null>,
    sorter: SorterResult<any> | SorterResult<any>[]
  ) => {
    updateTableParams(pagination, filters, sorter)
    const sortInfo = sortInfoMapping(sorter as SorterResult<any>)
    updateTableData({
      slug,
      useSlug,
      page: pagination.current!,
      pageSize: pagination.pageSize!,
      order: sortInfo.order,
      columnKey: sortInfo.columnKey,
      search: search.search,
      attribute: search.attribute,
      tableLoading,
    })
  }
  const sortingArrows = useSortTable('createdAt')
  const tableColumns: ColumnsType<TableData> = columnsMapper(columns, sortingArrows)
  const useSearchWhileTyping = () =>
    useWhileTyping(
      () => {
        // reset pagination and sort states
        resetPagination!()
        sortingArrows!.resetSortState()
        updateTableData({
          slug,
          useSlug,
          search: search.search,
          attribute: search.attribute,
          tableLoading,
        })
      },
      // the hook function ^ work only when search mode is true
      search.searchMode,
      // either search mode or search value changed the function run
      [search.searchMode, search.search, search.attribute]
    )
  return {
    tableParams,
    tableColumns,
    handleTableChange,
    search,
    tableLoading,
    resetPagination,
    sortingArrows,
    updateTableData,
    searchableColumns,
    addControls,
    useSearchWhileTyping,
  }
}

const addControls = (
  actions: Props['actions'],
  tableColumns: ColumnsType<TableData>,
  edit: (model: any) => void,
  deleteRoute?: string
) => {
  const controls = {
    title: 'تحكم',
    key: 'control',
    render: (record: TableData) => (
      <Space size="middle">
        {actions?.editable && (
          <EditButton
            onClick={() => {
              edit(record)
            }}
          />
        )}
        {actions?.deletable && (
          <DeleteButton
            onClick={() => {
              if (!deleteRoute) return
              router.delete(`/${deleteRoute}/${record.id}`)
            }}
          />
        )}
        {actions?.customActions?.map(({ label, actionRoute, options }) => {
          const btnType =
            options?.type === 'primary' || options?.type === 'danger' ? 'primary' : 'default'
          const danger = options?.type === 'danger'
          const method = (options?.method ?? 'get') as 'get' | 'post' | 'put' | 'delete'
          return (
            <Button
              key={label}
              onClick={() => {
                if (actionRoute.includes(':id')) {
                  router.visit(`/${actionRoute.replace(':id', record.id)}`, { method })
                  return
                }
                router.visit(`/${actionRoute}/${record.id}`, { method })
              }}
              type={btnType}
              danger={danger}
            >
              {label}
            </Button>
          )
        }) ?? null}
      </Space>
    ),
  }
  actions && tableColumns.push(controls)
}

const columnsMapper = (columns: Props['columns'], sortingArrows: ReturnType<typeof useSortTable>) =>
  columns.map(({ key, label, sortable, color, dataIndex, mappingValues, render }) => {
    const getDataIndexFromkey = key.includes('.') ? key.split('.') : key
    const dataIndexValue: string | string[] = dataIndex || getDataIndexFromkey
    let column: any = {
      key,
      title: label,
      dataIndex: dataIndexValue,
    }
    if (color) {
      column = {
        ...column,
        render: (value: number) =>
          value <= 0 ? (
            <Tag bordered={false} className="text-lg" color="red">
              {value}
            </Tag>
          ) : (
            <Tag bordered={false} className="text-lg" color="green">
              {value}
            </Tag>
          ),
      }
    }
    if (mappingValues) {
      column = {
        ...column,
        render: (value: number) => mappingValues[value],
      }
    }
    if (sortable)
      column = {
        ...column,
        ...sortingArrows.getSortProps(key),
      }
    if (render) column.render = render
    return column
  })

const updateTableData = ({
  slug,
  useSlug = false,
  page,
  pageSize,
  order,
  columnKey,
  search,
  attribute,
  tableLoading,
}: UpdateTableDataArgs) =>
  router.reload({
    only: [useSlug ? slug : 'data'],
    data: {
      [`${slug}_page`]: page,
      [`${slug}_pageSize`]: pageSize,
      [`${slug}_order`]: order,
      [`${slug}_columnKey`]: columnKey,
      [`${slug}_search`]: search,
      [`${slug}_attribute`]: attribute,
      slug: slug,
    } as any,
    preserveState: true,
    onStart: () => tableLoading.stateLoading.onStart(),
    onFinish: () => tableLoading.stateLoading.onFinish(),
  })
