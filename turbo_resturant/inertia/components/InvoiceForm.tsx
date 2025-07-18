import { LockOutlined, SaveOutlined } from '@ant-design/icons'
import { router } from '@inertiajs/react'
import { Button, Col, Descriptions, Form, InputNumber, Row, Select, Table } from 'antd'
import { useReducer, useRef, useState } from 'react'
import mapEditableColumns from '../helpers/mapEditableColumns.js'
import ColumnTypes from '../types/ColumnTypes.js'
import EditableColumns from '../types/EditableColumns.js'
import DeleteButton from './DeleteButton.js'
import EditableCell from './EditableCell.js'
import EditableRow from './EditableRow.js'
import PageTitle from './PageTitle.js'
const defaultColumns: EditableColumns = [
  {
    title: 'أسم الصنف',
    dataIndex: 'productName',
    key: 'productName',
  },
  {
    title: 'سعر الوحدة',
    dataIndex: 'cost',
    key: 'cost',
    editable: true,
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
  },
]

type InvoiceItem = {
  key: string
  productId: number
  productName: string
  quantity: number
  cost: number
  total: number
}

type Action =
  | {
      type: 'add' | 'edit' | 'delete'
      invoiceItem: InvoiceItem
    }
  | {
      type: 'clear'
    }

function invoiceItemReducer(state: InvoiceItem[], action: Action) {
  switch (action.type) {
    case 'add': {
      const item = state.find((item) => item.productId === action.invoiceItem.productId)
      if (item) {
        // focus to edit quantity of that item
        const lastItemQuantity = document.querySelector(
          `.editable-quantity[data-key="${item.key}-quantity"]`
        ) as HTMLElement

        lastItemQuantity?.click()
        return state
      }
      return [action.invoiceItem, ...state]
    }
    case 'edit': {

      return state.map((item) => {
        if (item.productId === action.invoiceItem.productId) {
          action.invoiceItem.total = action.invoiceItem.cost * action.invoiceItem.quantity
          return action.invoiceItem
        }
        return item
      })
    }
    case 'delete': {
      return state.filter((item) => item.productId !== action.invoiceItem.productId)
    }
    case 'clear': {
      return []
    }
    default:
      throw Error('Unkown Operation')
  }
}
type Props = {
  title: string
  invoiceNumber: number
  route: string
  products: { id: number; name: string; cost: number }[]
  suppliers: { id: number; name: string }[]
  mapper: (values: {
    close: boolean
    supplierId: number
    paid: number
    items: InvoiceItem[]
  }) => any
  initialValues?: {
    supplierId: number
    paid: number
    invoiceItems: InvoiceItem[]
  }
  mode: 'create' | 'edit'
}

export default function InvoiceForm({
  title,
  invoiceNumber,
  route,
  mapper,
  products,
  suppliers,
  initialValues,
  mode,
}: Props) {
  const searchInputRef = useRef<HTMLInputElement>(null)

  const [invoiceItems, dispatch] = useReducer(
    invoiceItemReducer,
    initialValues ? initialValues.invoiceItems : []
  )

  const components = {
    body: {
      row: EditableRow,
      cell: EditableCell<InvoiceItem>,
    },
  }

  const totalInvoice = invoiceItems.reduce((total, item) => total + item.total, 0)

  const [loading, setLoading] = useState(false)

  const edit = (invoiceItem: InvoiceItem) => {
    if (typeof invoiceItem.quantity === 'string') {
      invoiceItem.quantity = parseFloat(invoiceItem.quantity)
    }

    dispatch({
      type: 'edit',
      invoiceItem,
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
              invoiceItem: record,
            })
          }}
        />
      ),
    },
  ]

  const onSelectProduct = (key: string, option: { value: string; label: string }) => {
    const product = products.find((product) => product.id === parseInt(key))
    if (!product) return
    dispatch({
      type: 'add',
      invoiceItem: {
        key: key,
        productName: product.name,
        productId: product.id,
        cost: product.cost,
        quantity: 1,
        total: product.cost,
      },
    })
  }

  const [form] = Form.useForm()

  const fullPaid = () =>
    form.setFieldsValue({
      paid: totalInvoice,
    })

  const submit = async (close: boolean = false) => {
    await form.validateFields()
    const values = form.getFieldsValue() as { supplierId: number; paid: number }
    const [data, options] = [
      mapper({
        ...values,
        close,
        items: invoiceItems,
      }),
      {
        onStart: () => setLoading(true),
        onFinish: () => setLoading(false),
        onSuccess: () => {
          if (mode === 'edit') return
          dispatch({ type: 'clear' })
          form.resetFields()
        },
      },
    ]
    if (mode === 'create') return router.post(`/${route}`, data, options)
    router.put(`/${route}/${invoiceNumber}`, data, options)
  }

  const selectProducts = products.map((product) => ({
    value: product.id.toString(),
    label: product.name,
  }))
  const filterProduct = (input: string, option?: { label: string; value: string }) =>
    (option?.label ?? '').toLowerCase().includes(input.toLowerCase())

  const selectSupplier = suppliers.map((supplier) => ({
    value: supplier.id.toString(),
    label: supplier.name,
  }))

  return (
    <Row gutter={[0, 25]} className="m-8">
      <PageTitle name={title} />
      <div className="isolate-2 flex justify-between items-center w-full p-8 gap-8">
        <Descriptions className="w-full" bordered>
          <Descriptions.Item label="رقم الفاتورة">{invoiceNumber}</Descriptions.Item>
          <Descriptions.Item label="الاجمالي">{totalInvoice.toFixed(2)}</Descriptions.Item>
        </Descriptions>
        {mode === 'edit' ? (
          <>
            <Button
              loading={loading}
              icon={<SaveOutlined />}
              onClick={() => submit(false)}
              type="primary"
            >
              حفط
            </Button>
            <Button icon={<LockOutlined />} onClick={() => submit(true)} type="primary" danger>
              اغلاق
            </Button>
          </>
        ) : (
          <Button loading={loading} onClick={() => submit(false)} type="primary">
            انشاء فاتورة
          </Button>
        )}
      </div>
      <div className="isolate w-full">
        <Form layout="inline" form={form}>
          <Form.Item
            name="supplierId"
            label="اسم المورد"
            rules={[
              {
                required: true,
                message: 'اسم المورد مطلوب',
              },
            ]}
            initialValue={mode === 'edit' ? initialValues?.supplierId.toString() : undefined}
          >
            <Select
              style={{ width: '100%', minWidth: '200px' }}
              options={selectSupplier}
              optionFilterProp="children"
              showSearch
            />
          </Form.Item>
          <Form.Item
            name="paid"
            label="المبلغ المدفوع"
            initialValue={mode === 'edit' ? initialValues?.paid : undefined}
            rules={[
              {
                required: true,
                message: 'المبلغ المدفوع مطلوب',
              },
            ]}
          >
            <InputNumber min={0} className="w-full" />
          </Form.Item>
          <Form.Item>
            <Button onClick={fullPaid}>المبلغ كامل</Button>
          </Form.Item>
          <Form.Item></Form.Item>
        </Form>
      </div>
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
            الغاء العملية
          </Button>
        </div>

        <Table
          components={components}
          rowClassName={() => 'editable-row'}
          columns={columns as ColumnTypes}
          rowKey={(record: any) => {

            return record.productId.toString()
          }}
          dataSource={invoiceItems}
          pagination={false}
          loading={loading}
          bordered
          scroll={{ x: true }}
        />
      </Col>
    </Row>
  )
}
