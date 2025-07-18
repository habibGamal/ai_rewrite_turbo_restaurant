import { LockOutlined, SaveOutlined } from '@ant-design/icons'
import { router } from '@inertiajs/react'
import { Button, Col, Descriptions, Row, Select, Table } from 'antd'
import { useReducer, useRef, useState } from 'react'
import DeleteButton from '../../components/DeleteButton.js'
import EditableCell from '../../components/EditableCell.js'
import EditableRow from '../../components/EditableRow.js'
import PageTitle from '../../components/PageTitle.js'
import mapEditableColumns from '../../helpers/mapEditableColumns.js'
import useMultiplyKey from '../../hooks/useMultiplyKey.js'
import ColumnTypes from '../../types/ColumnTypes.js'
import EditableColumns from '../../types/EditableColumns.js'
import { Product, Waste } from '../../types/Models.js'
const defaultColumns: EditableColumns = [
  {
    title: 'أسم الصنف',
    dataIndex: 'productName',
    key: 'productName',
  },
  {
    title: 'الكمية الهالكة',
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

type WastedItem = {
  productId: number
  productName: string
  quantity: number
  cost: number
  balance: number
}

type Action =
  | {
      type: 'add' | 'edit' | 'delete'
      wastedItem: WastedItem
    }
  | {
      type: 'clear'
    }

function wasteItemReducer(state: WastedItem[], action: Action) {
  switch (action.type) {
    case 'add': {
      const itemExist = state.find((item) => item.productId === action.wastedItem.productId)
      if (itemExist) return state
      return [action.wastedItem, ...state]
    }
    case 'edit': {
      return state.map((item) => {
        if (item.productId === action.wastedItem.productId) {
          action.wastedItem.balance = action.wastedItem.quantity * item.cost
          return action.wastedItem
        }
        return item
      })
    }
    case 'delete': {
      return state.filter((item) => item.productId !== action.wastedItem.productId)
    }
    case 'clear': {
      return []
    }
    default:
      throw Error('Unkown Operation')
  }
}
type Props = {
  waste?: Waste
  wasteNumber: number
  products: Product[]
}

export default function WastesForm({ waste, wasteNumber, products }: Props) {
  const searchInputRef = useRef<HTMLInputElement>(null)

  const mode = waste ? 'edit' : 'create'

  const initialItems = waste
    ? waste.items.map((item) => ({
        productId: item.product.id,
        productName: item.product.name,
        quantity: item.quantity,
        cost: item.product.cost,
        balance: item.quantity * item.product.cost,
      }))
    : []

  const [wastedItems, dispatch] = useReducer(wasteItemReducer, initialItems)

  const components = {
    body: {
      row: EditableRow,
      cell: EditableCell<WastedItem>,
    },
  }

  useMultiplyKey()

  const total = wastedItems.reduce((total, item) => total + item.balance, 0)

  const [loading, setLoading] = useState(false)

  const edit = (invoiceItem: WastedItem) => {
    if (typeof invoiceItem.quantity === 'string') {
      invoiceItem.quantity = parseFloat(invoiceItem.quantity)
    }

    dispatch({
      type: 'edit',
      wastedItem: invoiceItem,
    })

    searchFocus()
  }

  const searchFocus = () => {
    const timeout = setTimeout(() => {
      searchInputRef.current.focus()
      clearTimeout(timeout)
    }, 0)
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
              wastedItem: record,
            })
          }}
        />
      ),
    },
  ]

  const selectOptions = products.map((item) => ({
    value: item.id.toString(),
    label: item.name,
  }))

  const onSelect = (key: string, option: { value: string; label: string }) => {
    const item = products.find((item) => item.id === parseInt(key))
    dispatch({
      type: 'add',
      wastedItem: {
        productId: item?.id ?? 0,
        productName: item?.name ?? '',
        quantity: 0,
        cost: item?.cost ?? 0,
        balance: 0,
      },
    })
  }

  const submit = (close?: boolean) => {
    const [data, options] = [
      {
        close,
        items: wastedItems,
      },
      {
        onStart: () => setLoading(true),
        onFinish: () => setLoading(false),
        onSuccess: () => {
          if (close) dispatch({ type: 'clear' })
        },
      },
    ]
    if (mode === 'edit') return router.put(`/wastes/${waste!.id}`, data, options)
    router.post(`/wastes`, data, options)
  }
  const filterOption = (input: string, option?: { label: string; value: string }) =>
    (option?.label ?? '').toLowerCase().includes(input.toLowerCase())

  return (
    <Row gutter={[0, 25]} className="m-8">
      <PageTitle name="الهالك" />
      <div className="isolate-2 flex justify-between items-center w-full p-8 gap-8">
        <Descriptions className="w-full" bordered>
          <Descriptions.Item label="رقم الهالك">{wasteNumber}</Descriptions.Item>
          <Descriptions.Item label="المحصلة">{total.toFixed(2)}</Descriptions.Item>
        </Descriptions>
        {mode === 'edit' ? (
          <>
            <Button
              icon={<SaveOutlined />}
              loading={loading}
              onClick={() => submit(false)}
              type="primary"
            >
              تعديل الهالك
            </Button>
            <Button icon={<LockOutlined />} onClick={() => submit(true)} type="primary" danger>
              اغلاق الهالك
            </Button>
          </>
        ) : (
          <Button loading={loading} onClick={() => submit(false)} type="primary">
            حفظ الهالك
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
          dataSource={wastedItems}
          pagination={false}
          loading={loading}
          bordered
          scroll={{ x: true }}
        />
      </Col>
    </Row>
  )
}
