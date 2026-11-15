import React from 'react';
import { Modal, Form, InputNumber, Radio, message } from 'antd';
import { router } from '@inertiajs/react';
import { InstantCartDiscount, Order } from '@/types';

interface OrderDiscountModalProps {
    open: boolean;
    onCancel: () => void;
    order?: Order;
    /** Cart mode (instant sale): the discount is handed back instead of posted to an order. */
    cartDiscount?: InstantCartDiscount | null;
    onApply?: (discount: InstantCartDiscount | null) => void;
}

export default function OrderDiscountModal({ open, onCancel, order, cartDiscount, onApply }: OrderDiscountModalProps) {
    const [form] = Form.useForm();

    const onFinish = (values: any) => {
        if (onApply) {
            const value = Number(values.discount || 0);
            onApply(value > 0 ? { value, type: values.discount_type === 'percent' ? 'percent' : 'value' } : null);
            message.success('تم تطبيق الخصم بنجاح');
            onCancel();
            return;
        }
        router.post(`/orders/apply-discount/${order!.id}`, values, {
            onSuccess: () => {
                message.success('تم تطبيق الخصم بنجاح');
                onCancel();
            },
            onError: () => {
                message.error('حدث خطأ أثناء تطبيق الخصم');
            },
        });
    };

    const handleCancel = () => {
        form.resetFields();
        onCancel();
    };

    return (
        <Modal
            title="تطبيق خصم"
            open={open}
            onCancel={handleCancel}
            onOk={() => form.submit()}
            okText="تطبيق"
            cancelText="إلغاء"
            destroyOnClose
        >
            <Form
                form={form}
                layout="vertical"
                onFinish={onFinish}
                initialValues={{
                    discount: cartDiscount?.value ?? 0,
                    discount_type: cartDiscount?.type ?? 'value',
                }}
            >
                <Form.Item
                    name="discount_type"
                    label="نوع الخصم"
                >
                    <Radio.Group>
                        <Radio value="value">مبلغ ثابت</Radio>
                        <Radio value="percent">نسبة مئوية</Radio>
                    </Radio.Group>
                </Form.Item>

                <Form.Item
                    name="discount"
                    label="قيمة الخصم"
                    rules={[{ required: true, message: 'قيمة الخصم مطلوبة' }]}
                >
                    <InputNumber
                        min={0}
                        style={{ width: '100%' }}
                        placeholder="ادخل قيمة الخصم"
                    />
                </Form.Item>
            </Form>
        </Modal>
    );
}
