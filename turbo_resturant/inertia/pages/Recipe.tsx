import { router } from '@inertiajs/react'
import { Button, Col, message, Row, Select, Table, Typography } from 'antd'
import { useReducer, useRef, useState } from 'react'
import DeleteButton from '../components/DeleteButton.js'
import EditableCell from '../components/EditableCell.js'
import EditableRow from '../components/EditableRow.js'
import PageTitle from '../components/PageTitle.js'
import mapEditableColumns from '../helpers/mapEditableColumns.js'
import ColumnTypes from '../types/ColumnTypes.js'
import EditableColumns from '../types/EditableColumns.js'
import { Product } from '../types/Models.js'
import { ProductType } from '#enums/ProductEnums'

const defaultColumns: EditableColumns = [
  {
    title: 'أسم الصنف',
    dataIndex: 'productName',
    key: 'productName',
  },
  {
    title: 'نوع الصنف',
    dataIndex: 'productType',
    key: 'productType',
    render: (value: string) =>
      value === ProductType.Manifactured
        ? 'مصنع'
        : value === ProductType.RawMaterial
          ? 'خام'
          : 'استهلاكي',
  },
  {
    title: 'سعر الوحدة',
    dataIndex: 'cost',
    key: 'cost',
  },
  {
    title: 'عدد الوحدات',
    dataIndex: 'quantity',
    key: 'quantity',
    editable: true,
  },
  {
    title: 'الاجمالي',
    dataIndex: 'total',
    key: 'total',
    render: (value) => value.toFixed(2),
  },
]

type Component = {
  key: string
  productId: number
  productName: string
  productType: string
  quantity: number
  cost: number
  total: number
}

type Action =
  | {
      type: 'add' | 'edit' | 'delete'
      component: Component
    }
  | {
      type: 'clear'
    }

function componentReducer(state: Component[], action: Action) {
  switch (action.type) {
    case 'add': {
      const item = state.find((item) => item.productId === action.component.productId)
      if (item) {
        // focus to edit quantity of that item
        const lastItemQuantity = document.querySelector(
          `.editable-quantity[data-key="${item.key}-quantity"]`
        ) as HTMLElement
        lastItemQuantity?.click()
        return state
      }
      return [action.component, ...state]
    }
    case 'edit': {
      return state.map((item) => {
        if (item.productId === action.component.productId) {
          action.component.total = action.component.cost * action.component.quantity
          return action.component
        }
        return item
      })
    }
    case 'delete': {
      return state.filter((item) => item.productId !== action.component.productId)
    }
    case 'clear': {
      return []
    }
    default:
      throw Error('Unkown Operation')
  }
}

type Props = {
  product: Product
  products: Product[]
}

export default function Recipe({ product, products }: Props) {
  const searchInputRef = useRef<HTMLInputElement>(null)
  const [productComponents, dispatch] = useReducer(
    componentReducer,
    product.ingredients
      ? product.ingredients.map((product) => ({
          key: product.productId.value.toString(),
          productId: product.productId.value,
          productType: product.type,
          productName: product.productId.label,
          cost: product.cost,
          quantity: product.quantity,
          total: product.quantity * product.cost,
        }))
      : []
  )

  const components = {
    body: {
      row: EditableRow,
      cell: EditableCell<Component>,
    },
  }

  const totalCost = productComponents.reduce((total, item) => total + item.total, 0).toFixed(2)

  const [loading, setLoading] = useState(false)

  const edit = (component: Component) => {
    if (typeof component.quantity === 'string') {
      component.quantity = parseFloat(component.quantity)
    }

    dispatch({
      type: 'edit',
      component: component,
    })

    searchFocus()
  }

  const searchFocus = () => {
    const timeout = setTimeout(() => {
      searchInputRef.current?.focus()
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
              component: record,
            })
          }}
        />
      ),
    },
  ]

  const onSelectProduct = (key: string, option: { value: string; label: string }) => {
    const component = products.find((product) => product.id === parseInt(key))
    if (!component) return
    if (component.id === product.id) return message.error('لا يمكن اضافة المنتج الى نفسه')
    dispatch({
      type: 'add',
      component: {
        key: key,
        productName: component.name,
        productType: component.type,
        productId: component.id,
        cost: component.cost,
        quantity: 1,
        total: component.cost,
      },
    })
  }
  const selectProducts = products.map((product) => ({
    value: product.id.toString(),
    label: product.name,
  }))
  const filterProduct = (input: string, option?: { label: string; value: string }) =>
    (option?.label ?? '').toLowerCase().includes(input.toLowerCase())

  const submit = () => {
    router.put(`/manifacture-product/components/${product.id}`, {
      components: productComponents,
    })
  }
  return (
    <Row gutter={[0, 25]} className="m-8">
      <PageTitle name={product.name} />
      <Col span="24" className="isolate">
        <div className="flex gap-6 mb-6">
          <Select
            id="productName"
            ref={searchInputRef}
            placeholder="اسم المنتج"
            style={{ width: '100%' }}
            onSelect={onSelectProduct}
            options={selectProducts}
            optionFilterProp="children"
            showSearch
            filterOption={filterProduct}
            onKeyDown={(e) => {
              if (e.code !== 'NumpadMultiply') return
              e.preventDefault()
              const lastItemQuantity = document.querySelector('.editable-quantity') as HTMLElement
              lastItemQuantity?.click()
            }}
          />
          <Button
            onClick={() => dispatch({ type: 'clear' })}
            className="mx-auto"
            danger
            type="primary"
          >
            مسح الكل
          </Button>
          <Button onClick={submit} className="mx-auto" type="primary">
            حفظ
          </Button>
        </div>

        <Table
          components={components}
          rowClassName={() => 'editable-row'}
          columns={columns as ColumnTypes}
          rowKey={(record: any) => {
            return record.productId.toString()
          }}
          dataSource={productComponents}
          pagination={false}
          loading={loading}
          bordered
          scroll={{ x: true }}
          footer={() => <Typography.Text>اجمالي التكلفة : {totalCost}</Typography.Text>}
        />
      </Col>
    </Row>
  )
}
