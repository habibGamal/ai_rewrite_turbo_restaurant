import { useState } from 'react'
import { TablePaginationConfig } from 'antd'
import { FilterValue, SorterResult } from 'antd/es/table/interface'
import { TableParams } from '../types/TableParams.js'

const useTablePagination = <T>(slug: string) => {
  const params = new URLSearchParams(window.location.search)
  const currentPage = params.get(slug + '_page') ?? '1'
  const pageSize = params.get(slug + '_pageSize') ?? '10'

  const [tableParams, setTableParams] = useState<TableParams>({
    pagination: {
      current: parseInt(currentPage),
      pageSize: parseInt(pageSize),
    },
  })

  const resetPagination = () => {
    setTableParams((tableParams) => ({
      ...tableParams,
      pagination: {
        ...tableParams.pagination,
        current: 1,
      },
    }))
  }

  const updateTableParams = (
    pagination: TablePaginationConfig,
    filters: Record<string, FilterValue | null>,
    sorter: SorterResult<T> | SorterResult<T>[]
  ) => {
    setTableParams({
      pagination,
      filters,
      ...sorter,
    })
  }
  return { tableParams, setTableParams, updateTableParams, resetPagination }
}

export default useTablePagination
