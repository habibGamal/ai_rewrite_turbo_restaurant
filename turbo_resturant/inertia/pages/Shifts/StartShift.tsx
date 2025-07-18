import { router } from '@inertiajs/react'
import { Button, Form, Input } from 'antd'
import CashierLayout from '../../layouts/CashierLayout.js'

export default function StartShift() {
  const onFinish = (values: any) => {
    router.post('/start-shift', values, {
      onFinish: (props) => {

      },
      onError: (props) => {

      },
    })
  }

  return (
    <div className="grid place-items-center w-full min-h-[50vh]">
      <Form
        name="startShift"
        className="isolate min-w-[500px]"
        layout="vertical"
        onFinish={onFinish}
      >
        <Form.Item
          label="النقود المتوفرة"
          name="startCash"
          rules={[{ required: true, message: 'هذا الحقل مطلوب' }]}
        >
          <Input />
        </Form.Item>

        <Form.Item>
          <Button type="primary" htmlType="submit">
            بداية الوردية
          </Button>
        </Form.Item>
      </Form>
    </div>
  )
}

StartShift.layout = (page: any) => <CashierLayout children={page} />
