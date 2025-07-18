import { router } from '@inertiajs/react'
import { Button, Form, InputNumber, Modal, Radio } from 'antd'
import useModal from '../../hooks/useModal.js'
import { Order } from '../../types/Models.js'

export default function OrderDiscountModal({
  orderDiscountModal,
  order,
}: {
  orderDiscountModal: ReturnType<typeof useModal>
  order: Order
}) {
  const [form] = Form.useForm()
  const makeDiscount = async (values: any) => {
    try {
      router.post(`/orders/make-discount/${order.id}`, values, {
        onSuccess: (page) => {
          const order = page.props.order as Order
          if (order.tempDiscountPercent !== 0) {
            form.setFieldsValue({ discount: order.tempDiscountPercent, discountType: 'percent' })
          } else {
            form.setFieldsValue({ discount: order.discount, discountType: 'value' })
          }
          orderDiscountModal.closeModal()
        },
      })
    } catch (e) {

    }
  }

  const options = [
    {
      label: 'نسبة',
      value: 'percent',
    },
    {
      label: 'قيمة',
      value: 'value',
    },
  ]

  return (
    <Modal {...orderDiscountModal} title="خصم للطلب" footer={null} destroyOnClose>
      <Form
        onFinish={makeDiscount}
        name="tableNumber"
        layout="vertical"
        form={form}
        initialValues={{
          discount:
            order.tempDiscountPercent !== 0 ? order.tempDiscountPercent : order.discount,
          discountType: order.tempDiscountPercent !== 0 ? 'percent' : 'value',
        }}
      >
        <Form.Item
          label="نوع الخصم"
          name="discountType"
          rules={[{ required: true, message: 'يرجى اختيار نوع الخصم' }]}
        >
          <Radio.Group options={options} optionType="button" buttonStyle="solid" />
        </Form.Item>
        <Form.Item
          label="الخصم"
          name="discount"
          rules={[{ required: true, message: 'يرجى تحديد الخصم' }]}
        >
          <InputNumber min={0} className="w-full" />
        </Form.Item>
        <Form.Item>
          <Button type="primary" htmlType="submit">
            تم
          </Button>
        </Form.Item>
      </Form>
    </Modal>
  )
}
