import { LockOutlined, SaveOutlined } from '@ant-design/icons'
import { router } from '@inertiajs/react'
import { Button, Col, Descriptions, Row, Select, Table } from 'antd'
import { useReducer, useRef, useState } from 'react'
import DeleteButton from '../../components/DeleteButton.js'
import EditableCell from '../../components/EditableCell.js'
import EditableRow from '../../components/EditableRow.js'
import PageTitle from '../../components/PageTitle.js'
import SelectItemsToImport from '../../components/SelectItemsToImport.js'
import mapEditableColumns from '../../helpers/mapEditableColumns.js'
import useModal from '../../hooks/useModal.js'
import useMultiplyKey from '../../hooks/useMultiplyKey.js'
import ColumnTypes from '../../types/ColumnTypes.js'
import EditableColumns from '../../types/EditableColumns.js'
import { InventoryItem, Snapshot, Stocktaking } from '../../types/Models.js'
const defaultColumns: EditableColumns = [
  {
    title: 'أسم الصنف',
    dataIndex: 'productName',
    key: 'productName',
  },
  {
    title: 'الرصيد الابتدائي',
    dataIndex: 'startQuantity',
    key: 'startQuantity',
  },
  {
    title: 'الكمية الحالية',
    dataIndex: 'currentQuantity',
    key: 'currentQuantity',
  },
  {
    title: 'الكمية الفعلية',
    dataIndex: 'quantity',
    key: 'quantity',
    editable: true,
  },
  {
    title: 'فرق النقدية',
    dataIndex: 'balance',
    key: 'balance',
    render: (text: any) => text.toFixed(2),
  },
]

type StocktakingItem = {
  productId: number
  productName: string
  startQuantity: number
  currentQuantity: number
  quantity: number
  cost: number
  balance: number
}

type Action =
  | {
      type: 'add' | 'edit' | 'delete'
      stocktakingItem: StocktakingItem
    }
  | {
      type: 'clear'
    }

function stocktakingItemReducer(state: StocktakingItem[], action: Action) {
  switch (action.type) {
    case 'add': {
      const itemExist = state.find((item) => item.productId === action.stocktakingItem.productId)
      if (itemExist) return state
      return [action.stocktakingItem, ...state]
    }
    case 'edit': {
      return state.map((item) => {
        if (item.productId === action.stocktakingItem.productId) {
          action.stocktakingItem.balance =
            (action.stocktakingItem.quantity - action.stocktakingItem.currentQuantity) * item.cost
          return action.stocktakingItem
        }
        return item
      })
    }
    case 'delete': {
      return state.filter((item) => item.productId !== action.stocktakingItem.productId)
    }
    case 'clear': {
      return []
    }
    default:
      throw Error('Unkown Operation')
  }
}
type Props = {
  stocktaking?: Stocktaking
  stocktakingNumber: number
  inventoryItems: InventoryItem[]
  snapshot: Snapshot
}

export default function StockTakingForm({
  stocktaking,
  stocktakingNumber,
  inventoryItems,
  snapshot,
}: Props) {
  const searchInputRef = useRef<HTMLInputElement>(null)
  const mode = stocktaking ? 'edit' : 'create'

  const initalStocktakingItems = stocktaking
    ? stocktaking.items.map((item) => {
        const startQuantity = snapshot.data.find(
          (data) => data.product_id === item.productId
        )?.start_quantity
        const currentQuantity = inventoryItems.find(
          (inventoryItem) => inventoryItem.productId === item.productId
        )!.quantity
        return {
          productId: item.productId,
          productName: item.product.name,
          startQuantity: startQuantity ?? 0,
          currentQuantity,
          quantity: currentQuantity + item.quantity,
          cost: item.product.cost,
          balance: item.quantity * item.product.cost,
        }
      })
    : []

  const [stocktakingItems, dispatch] = useReducer(stocktakingItemReducer, initalStocktakingItems)

  const components = {
    body: {
      row: EditableRow,
      cell: EditableCell<StocktakingItem>,
    },
  }

  useMultiplyKey()

  const stocktakingBalance = stocktakingItems.reduce((total, item) => total + item.balance, 0)

  const [loading, setLoading] = useState(false)

  const edit = (invoiceItem: StocktakingItem) => {
    if (typeof invoiceItem.quantity === 'string') {
      invoiceItem.quantity = parseFloat(invoiceItem.quantity)
    }

    dispatch({
      type: 'edit',
      stocktakingItem: invoiceItem,
    })

    searchFocus()
  }

  const searchFocus = () => {
    // const timeout = setTimeout(() => {
    //   searchInputRef.current.focus()
    //   clearTimeout(timeout)
    // }, 0)
  }

  const columns = [
    ...mapEditableColumns<any>(defaultColumns, edit),
    {
      title: 'تحكم',
      dataIndex: 'operation',
      render: (_: any, record: any) => (
        <DeleteButton
          onClick={() => {
            dispatch({
              type: 'delete',
              stocktakingItem: record,
            })
          }}
        />
      ),
    },
  ]

  const selectOptions = inventoryItems.map((item) => ({
    value: item.productId.toString(),
    label: item.product.name,
  }))

  const onSelect = (key: string) => {
    const item = inventoryItems.find((item) => item.productId === parseInt(key))
    const startQuantity = snapshot.data.find(
      (data) => data.product_id === item?.productId
    )?.start_quantity
    console.log(item)
    dispatch({
      type: 'add',
      stocktakingItem: {
        productId: item?.productId ?? 0,
        productName: item?.product.name ?? '',
        startQuantity: startQuantity ?? 0,
        currentQuantity: item?.quantity ?? 0,
        quantity: item?.quantity,
        cost: item?.product.cost ?? 0,
        balance: 0,
      },
    })
  }

  const submit = (close?: boolean) => {
    const [data, options] = [
      {
        close,
        items: stocktakingItems,
      },
      {
        onStart: () => setLoading(true),
        onFinish: () => setLoading(false),
        onSuccess: () => {
          if (close) dispatch({ type: 'clear' })
        },
      },
    ]
    if (mode === 'edit') return router.put(`/stocktaking/${stocktaking!.id}`, data, options)
    router.post(`/stocktaking`, data, options)
  }

  const filterOption = (input: string, option?: { label: string; value: string }) =>
    (option?.label ?? '').toLowerCase().includes(input.toLowerCase())

  const selectModal = useModal()
  return (
    <Row gutter={[0, 25]} className="m-8">
      <SelectItemsToImport
        inventoryItems={inventoryItems}
        selectModal={selectModal}
        onSelect={onSelect}
      />
      <PageTitle name="جرد المخزون" />
      <div className="isolate-2 flex justify-between items-center w-full p-8 gap-8">
        <Descriptions className="w-full" bordered>
          <Descriptions.Item label="رقم الجرد">{stocktakingNumber}</Descriptions.Item>
          <Descriptions.Item label="المحصلة">{stocktakingBalance.toFixed(2)}</Descriptions.Item>
        </Descriptions>
        {mode === 'edit' ? (
          <>
            <Button
              icon={<SaveOutlined />}
              loading={loading}
              onClick={() => submit(false)}
              type="primary"
            >
              تعديل الجرد
            </Button>
            <Button icon={<LockOutlined />} onClick={() => submit(true)} type="primary" danger>
              اغلاق الجرد
            </Button>
          </>
        ) : (
          <Button loading={loading} onClick={() => submit(false)} type="primary">
            حفظ الجرد
          </Button>
        )}
      </div>
      <Col span="24" className="isolate">
        <div className="flex gap-6 mb-6">
          <Select
            id="productName"
            ref={searchInputRef}
            placeholder="اسم المنتج"
            style={{ width: '100%' }}
            onSelect={onSelect}
            options={selectOptions}
            optionFilterProp="children"
            showSearch
            filterOption={filterOption}
          />
          <Button onClick={() => selectModal.showModal()} className="mx-auto">
            اختر المنتجات
          </Button>
          <Button
            onClick={() => dispatch({ type: 'clear' })}
            className="mx-auto"
            danger
            type="primary"
          >
            الغاء العملية
          </Button>
        </div>
        <Table
          components={components}
          rowClassName={() => 'editable-row'}
          columns={columns as ColumnTypes}
          rowKey={(record: any) => record.productId.toString()}
          dataSource={stocktakingItems}
          pagination={false}
          loading={loading}
          bordered
          scroll={{ x: true }}
        />
      </Col>
    </Row>
  )
}
