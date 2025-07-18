import React from 'react'
import { Button, Form, Input, Modal } from 'antd'
import useModal from '../../hooks/useModal.js'

export default function KitchenNotesModal({
  kitchenModal,
  onFinish,
  initialNotes,
}: {
  kitchenModal: ReturnType<typeof useModal>
  onFinish: (notes:string) => void
  initialNotes: string
}) {
  const [form] = Form.useForm()
  const changeKitchenNotes =  (values: any) => {
    onFinish(values.notes)
    kitchenModal.closeModal()
  }

  return (
    <Modal {...kitchenModal} title="ملاحظات للمطبخ" footer={null} destroyOnClose>
      <Form
        form={form}
        className="mt-4"
        onFinish={changeKitchenNotes}
        initialValues={{ notes: initialNotes }}
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
