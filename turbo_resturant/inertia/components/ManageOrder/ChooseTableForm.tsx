import { Button, Form, InputNumber, Modal, Radio } from 'antd'
import React from 'react'
import useModal from '../../hooks/useModal.js'
export default function ChooseTableForm({
  onFinish,
  tableModal,
}: {
  tableModal: ReturnType<typeof useModal>
  onFinish: (values: any) => void
}) {
  const options = [
    {
      label: 'VIP',
      value: 'VIP',
    },
    {
      label: 'كلاسيك',
      value: 'كلاسيك',
    },
    {
      label: 'بدوي',
      value: 'بدوي',
    },
  ]
  return (
    <Modal title="الطاولة" {...tableModal} footer={null} destroyOnClose>
      <Form onFinish={onFinish} name="tableNumber" layout="vertical">
        <Form.Item
          label="نوع الطاولة"
          name="tableType"
          rules={[{ required: true, message: 'يرجى اختيار نوع الطاولة' }]}
        >
          <Radio.Group options={options} optionType="button" buttonStyle="solid" />
        </Form.Item>
        <Form.Item
          label="رقم الطاولة"
          name="tableNumber"
          rules={[{ required: true, message: 'يرجى اختيار رقم الطاولة' }]}
        >
          <InputNumber min={1} className="w-full" />
        </Form.Item>
        <Form.Item>
          <Button type="primary" htmlType="submit">
            إضافة
          </Button>
        </Form.Item>
      </Form>
    </Modal>
  )
}
