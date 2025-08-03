import React from 'react';
import { Modal, Button, message } from 'antd';
import { router } from '@inertiajs/react';
import { Order, OrderItemData } from '@/types';

interface PrintInKitchenModalProps {
    open: boolean;
    onCancel: () => void;
    order: Order;
    orderItems: OrderItemData[];
}

export default function PrintInKitchenModal({
    open,
    onCancel,
    order,
    orderItems
}: PrintInKitchenModalProps) {

    const handlePrintKitchen = () => {
        router.post(`/orders/print-kitchen/${order.id}`, {}, {
            onSuccess: () => {
                message.success('تم إرسال الطلب للمطبخ');
                onCancel();
            },
            onError: () => {
                message.error('حدث خطأ أثناء إرسال الطلب للمطبخ');
            },
        });
    };

    return (
        <Modal
            title="طباعة في المطبخ"
            open={open}
            onCancel={onCancel}
            onOk={handlePrintKitchen}
            okText="طباعة"
            cancelText="إلغاء"
        >
            <p>هل تريد إرسال هذا الطلب للمطبخ؟</p>
            <p>الطلب رقم: {order.order_number}</p>
            <p>عدد الأصناف: {orderItems.length}</p>
        </Modal>
    );
}
