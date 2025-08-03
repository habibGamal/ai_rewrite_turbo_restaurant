import React, { useState } from 'react';
import { Button, InputNumber, Tag, Typography, Modal, Input } from 'antd';
import {
    MinusCircleOutlined,
    PlusCircleOutlined,
    DeleteOutlined,
    EditOutlined,
} from '@ant-design/icons';

import { OrderItemData, OrderItemAction, User } from '@/types';
import { formatCurrency } from '@/utils/orderCalculations';

const { TextArea } = Input;

interface OrderItemProps {
    orderItem: OrderItemData;
    dispatch: React.Dispatch<OrderItemAction>;
    disabled?: boolean;
    user: User;
}

export default function OrderItem({ orderItem, dispatch, disabled, user }: OrderItemProps) {
    const [isNotesModalOpen, setIsNotesModalOpen] = useState(false);
    const [notes, setNotes] = useState(orderItem.notes || '');

    const onChangeQuantity = (quantity: number | null) => {
        if (quantity !== null) {
            dispatch({ type: 'changeQuantity', id: orderItem.product_id, quantity, user });
        }
    };

    const onIncrement = () => {
        dispatch({ type: 'increment', id: orderItem.product_id, user });
    };

    const onDecrement = () => {
        dispatch({ type: 'decrement', id: orderItem.product_id, user });
    };

    const onDelete = () => {
        dispatch({ type: 'delete', id: orderItem.product_id, user });
    };

    const onSaveNotes = () => {
        dispatch({ type: 'changeNotes', id: orderItem.product_id, notes, user });
        setIsNotesModalOpen(false);
    };

    const onOpenNotesModal = () => {
        setNotes(orderItem.notes || '');
        setIsNotesModalOpen(true);
    };

    return (
        <>
            <div className="isolate-3 flex flex-col gap-4 my-4">
                <div className="flex justify-between items-center">
                    <Typography.Paragraph className="!my-0">{orderItem.name}</Typography.Paragraph>
                    <div className="flex gap-2">
                        <Button
                            disabled={disabled}
                            onClick={onDecrement}
                            className="icon-button"
                            icon={<MinusCircleOutlined />}
                            size="small"
                        />
                        <InputNumber
                            disabled={disabled}
                            min={1}
                            value={orderItem.quantity}
                            onChange={onChangeQuantity}
                            style={{ width: 80 }}
                        />
                        <Button
                            disabled={disabled}
                            onClick={onIncrement}
                            className="icon-button"
                            icon={<PlusCircleOutlined />}
                            size="small"
                        />
                    </div>
                </div>
                <div className="flex justify-between items-center">
                    <Typography.Text>
                        السعر :
                        <Tag className="mx-4 text-lg" bordered={false} color="success">
                            {formatCurrency(orderItem.price * orderItem.quantity)}
                        </Tag>
                    </Typography.Text>
                    <div className="flex gap-4">
                        <Button
                            disabled={disabled}
                            onClick={onDelete}
                            danger
                            type="primary"
                            className="icon-button"
                            icon={<DeleteOutlined />}
                            size="small"
                        />
                        <Button
                            disabled={disabled}
                            onClick={onOpenNotesModal}
                            type="primary"
                            className="icon-button"
                            icon={<EditOutlined />}
                            size="small"
                        />
                    </div>
                </div>
            </div>

            <Modal
                title="ملاحظات الصنف"
                open={isNotesModalOpen}
                onOk={onSaveNotes}
                onCancel={() => setIsNotesModalOpen(false)}
                okText="حفظ"
                cancelText="إلغاء"
            >
                <TextArea
                    value={notes}
                    onChange={(e) => setNotes(e.target.value)}
                    placeholder="اكتب ملاحظات الصنف هنا..."
                    rows={4}
                />
            </Modal>
        </>
    );
}
