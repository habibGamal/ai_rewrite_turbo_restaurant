import { router } from '@inertiajs/react'
import { Button, Form, Input, Modal } from 'antd'
import useModal from '../../hooks/useModal.js'
import { Order } from '../../types/Models.js'

export default function OrderNotesModal({
  orderNotesModal,
  order,
}: {
  orderNotesModal: ReturnType<typeof useModal>
  order: Order
}) {
  const [form] = Form.useForm()
  const onFinish = async (values: any) => {
    try {
      router.post(`/orders/order-notes/${order.id}`, values, {
        onSuccess: (page) => {
          form.setFieldsValue({ notes: (page.props.order as Order).orderNotes })
          orderNotesModal.closeModal()
        },
      })
    } catch (e) {

    }
  }

  return (
    <Modal {...orderNotesModal} title="ملاحظات للطلب" footer={null} destroyOnClose>
      <Form
        form={form}
        className="mt-4"
        onFinish={onFinish}
        initialValues={{ notes: order.orderNotes }}
      >
        <Form.Item name="notes">
          <Input.TextArea placeholder="ملاحظات..." />
        </Form.Item>
        <div className="flex gap-4">
          <Button htmlType="submit" type="primary">
            حفط
          </Button>
        </div>
      </Form>
    </Modal>
  )
}
