import { router, usePage } from '@inertiajs/react'
import {
  Button,
  Form,
  FormInstance,
  Input,
  InputNumber,
  Modal,
  Popconfirm,
  Select,
  Table,
  TableColumnsType,
  Typography
} from 'antd'
import React from 'react'
import useModal from '../../../hooks/useModal.js'
import { Expense, ExpenseType } from '../../../types/Models.js'

const ExpenseForm = ({
  initialValues,
  onFinish,
}: {
  initialValues?: Expense
  onFinish: (form: FormInstance<any>, values: any) => void
}) => {
  const [form] = Form.useForm()
  const expenseTypes = usePage().props.expenseTypes as ExpenseType[]
  const options = expenseTypes.map((type) => ({ label: type.name, value: type.id }))
  if (initialValues) {
    form.setFieldsValue(initialValues)
  }
  return (
    <Form
      form={form}
      onFinish={(values) => onFinish(form, values)}
      className={`${initialValues ? '' : 'isolate min-w-[500px]'}`}
      layout="vertical"
    >
      <Typography.Title className="my-0 text-center" level={4}>
        اضافة مصاريف
      </Typography.Title>
      {initialValues?.description}
      <Form.Item
        name="amount"
        label="المبلغ"
        rules={[
          {
            required: true,
            message: 'المبلغ مطلوب',
          },
        ]}
      >
        <InputNumber className="w-full" />
      </Form.Item>
      <Form.Item
        name="expenseTypeId"
        label="نوع المصروف"
        rules={[
          {
            required: true,
            message: 'نوع المصروف مطلوب',
          },
        ]}
      >
        <Select options={options} />
      </Form.Item>
      <Form.Item
        name="description"
        label="الوصف"
        rules={[
          {
            required: true,
            message: 'الوصف مطلوب',
          },
        ]}
      >
        <Input.TextArea />
      </Form.Item>
      <Form.Item>
        <Button htmlType="submit" type="primary">
          {initialValues ? 'تعديل' : 'اضافة'}
        </Button>
      </Form.Item>
    </Form>
  )
}

export default function ShiftExpenses() {
  const columns: TableColumnsType<{
    id: number
    amount: number
    description: string
    created_at: string
  }> = [
    {
      title: 'المبلغ',
      dataIndex: 'amount',
      key: 'amount',
    },
    {
      title: 'الوصف',
      dataIndex: 'description',
      key: 'description',
    },
    {
      title: 'نوع المصروف',
      dataIndex: ['expenseType', 'name'],
      key: 'expenseTypeId',
    },
    {
      title: 'التاريخ',
      dataIndex: 'createdAt',
      key: 'createdAt',
    },
    {
      title: 'التحكم',
      key: 'control',
      render: (_, record) => {

        return (
          <div className="flex gap-4">
            <Button
              type="primary"
              onClick={() => {
                setInitialValues(record as Expense)
                setTimeout(() => {
                  modal.showModal()
                }, 0)
              }}
            >
              تعديل
            </Button>
            <Popconfirm
              title="هل انت متأكد من حذف هذا المصروف؟"
              onConfirm={() => router.delete(`/expenses/${record.id}`)}
              okText="نعم"
              cancelText="لا"
            >
              <Button danger type="primary">
                حذف
              </Button>
            </Popconfirm>
          </div>
        )
      },
    },
  ]
  const modal = useModal()
  const expenses = usePage().props.expenses as Expense[]
  const onAdd = (form: FormInstance<any>, values: any) => {
    router.post(`/expenses`, values, {
      onSuccess: () => {
        form.resetFields()
      },
    })
  }
  const onEdit = (form: FormInstance<any>, values: any) => {
    router.put(`/expenses/${initialValues.id}`, values, {
      onSuccess: () => {
        modal.closeModal()
        form.resetFields()
      },
    })
  }
  const [initialValues, setInitialValues] = React.useState<Expense | undefined>()
  return (
    <div className="grid items-start grid-cols-3 gap-8 w-full min-h-[50vh]">
      <ExpenseForm onFinish={onAdd} />
      <Modal {...modal} title="تعديل" footer={null}>
        <ExpenseForm initialValues={initialValues} onFinish={onEdit} />
      </Modal>
      <Table
        className="col-span-2"
        columns={columns}
        dataSource={expenses}
        pagination={false}
        footer={() => {
          // sum of expenses
          const sum = expenses.reduce((acc, curr) => acc + curr.amount, 0)
          return <Typography.Text>المجموع : {sum}</Typography.Text>
        }}
      />
    </div>
  )
}
