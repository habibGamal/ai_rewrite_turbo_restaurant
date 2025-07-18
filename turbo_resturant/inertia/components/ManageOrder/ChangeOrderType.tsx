import { router } from '@inertiajs/react'
import { Button, Form, InputNumber, Modal, Radio } from 'antd'
import React from 'react'
import useModal from '../../hooks/useModal.js'
import { Order } from '../../types/Models.js'
import { OrderType } from '#enums/OrderEnums'

export default function ChangeOrderTypeModal({
  changeOrderTypeModal,
  order,
}: {
  changeOrderTypeModal: ReturnType<typeof useModal>
  order: Order
}) {
  const [form] = Form.useForm()
  const changeOrderType = async (values: any) => {
    const tableNumber = `${values.tableType} - ${values.tableNumber}`
    try {
      router.post(
        `/orders/change-order-type/${order.id}`,
        {
          ...values,
          tableNumber: values.type === OrderType.DineIn ? tableNumber : null,
        },
        {
          onSuccess: (page) => {
            changeOrderTypeModal.closeModal()
          },
        }
      )
    } catch (e) {

    }
  }

  const options = [
    {
      label: 'صالة',
      value: OrderType.DineIn,
    },
    {
      label: 'ديليفري',
      value: OrderType.Delivery,
    },
    {
      label: 'تيك أواي',
      value: OrderType.Takeaway,
    },
    {
      label: 'طلبات',
      value: OrderType.Talabat,
    },
    {
      label: 'شركات',
      value: OrderType.Companies,
    },
  ]

  const tableOptions = [
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
  const [isDineIn, setIsDineIn] = React.useState(false)
  return (
    <Modal {...changeOrderTypeModal} title="تغير نوع الطلب" footer={null} destroyOnClose>
      <Form onFinish={changeOrderType} name="tableNumber" layout="vertical" form={form}>
        <Form.Item
          label="نوع الطلب"
          name="type"
          rules={[{ required: true, message: 'يرجى اختيار نوع الطلب' }]}
        >
          <Radio.Group
            options={options}
            optionType="button"
            buttonStyle="solid"
            onChange={(e) => {
              setIsDineIn(e.target.value === OrderType.DineIn)
            }}
          />
        </Form.Item>
        {isDineIn && (
          <>
            <Form.Item
              label="نوع الطاولة"
              name="tableType"
              rules={[{ required: true, message: 'يرجى اختيار نوع الطاولة' }]}
            >
              <Radio.Group options={tableOptions} optionType="button" buttonStyle="solid" />
            </Form.Item>
            <Form.Item
              label="رقم الطاولة"
              name="tableNumber"
              rules={[{ required: true, message: 'يرجى اختيار رقم الطاولة' }]}
            >
              <InputNumber min={1} className="w-full" />
            </Form.Item>
          </>
        )}
        <Form.Item>
          <Button type="primary" htmlType="submit">
            تم
          </Button>
        </Form.Item>
      </Form>
    </Modal>
  )
}
