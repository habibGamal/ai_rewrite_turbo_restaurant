import React from 'react'
import { Button, InputNumber, Tag, Typography } from 'antd'
import {
  MinusCircleOutlined,
  PlusCircleOutlined,
  DeleteOutlined,
  EditOutlined,
} from '@ant-design/icons'
import { OrderItemT, OrderItemsReducerActions } from '../../types/Types.js'
import KitchenNotesModal from './KitchenNotes.js'
import useModal from '../../hooks/useModal.js'
import { usePage } from '@inertiajs/react'
import { User } from '../../types/Models.js'

export default function OrderItem({
  orderItem,
  dispatch,
  disabled,
  forWeb,
}: {
  orderItem: OrderItemT
  dispatch: React.Dispatch<OrderItemsReducerActions>
  disabled?: boolean
  forWeb?: boolean
}) {
  const user = usePage().props.user as User
  const onChangeQuantity = (quantity: number) => {
    dispatch({ type: 'changeQuantity', id: orderItem.productId, quantity, user })
  }

  const onIncrement = () => {
    dispatch({ type: 'increment', id: orderItem.productId, user })
  }

  const onDecrement = () => {
    dispatch({ type: 'decrement', id: orderItem.productId, user })
  }

  const onDelete = () => {
    dispatch({ type: 'delete', id: orderItem.productId, user })
  }

  const onChangeNotes = (notes: string) => {
    dispatch({ type: 'changeNotes', id: orderItem.productId, notes, user })
  }

  const kitchenNotesModal = useModal()
  return (
    <div className="isolate-3 flex flex-col gap-4 my-4">
      <KitchenNotesModal
        kitchenModal={kitchenNotesModal}
        onFinish={(notes) => onChangeNotes(notes)}
        initialNotes={orderItem.notes || ''}
      />
      <div className="flex justify-between items-center">
        <Typography.Paragraph className="!my-0">{orderItem.name}</Typography.Paragraph>
        <div className="flex gap-2">
          <Button
            disabled={disabled || forWeb}
            onClick={onDecrement}
            className="icon-button"
            icon={<MinusCircleOutlined />}
          />
          <InputNumber
            disabled={disabled || forWeb}
            min={1}
            defaultValue={1}
            value={orderItem.quantity}
            onChange={onChangeQuantity}
          />
          <Button
            disabled={disabled || forWeb}
            onClick={onIncrement}
            className="icon-button"
            icon={<PlusCircleOutlined />}
          />
        </div>
      </div>
      <div className="flex justify-between items-center">
        <Typography.Text>
          السعر :
          <Tag className="mx-4 text-lg" bordered={false} color="success">
            {orderItem.price * orderItem.quantity}
          </Tag>
        </Typography.Text>
        <div className="flex gap-4">
          <Button
            disabled={disabled || forWeb}
            onClick={onDelete}
            danger
            type="primary"
            className="icon-button"
            icon={<DeleteOutlined />}
          />
          <Button
            disabled={disabled}
            onClick={() => kitchenNotesModal.showModal()}
            type="primary"
            className="icon-button"
            icon={<EditOutlined />}
          />
        </div>
      </div>
    </div>
  )
}
