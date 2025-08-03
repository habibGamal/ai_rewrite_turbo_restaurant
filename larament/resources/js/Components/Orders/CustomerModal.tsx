import React from 'react';
import { Modal, Form, Input, InputNumber, message } from 'antd';
import { router } from '@inertiajs/react';
import { Order } from '@/types';

interface CustomerModalProps {
    open: boolean;
    onCancel: () => void;
    order: Order;
}

export default function CustomerModal({ open, onCancel, order }: CustomerModalProps) {
    const [form] = Form.useForm();

    const onFinish = (values: any) => {
        router.post(`/orders/update-customer/${order.id}`, values, {
            onSuccess: () => {
                message.success('تم حفظ بيانات العميل بنجاح');
                onCancel();
            },
            onError: () => {
                message.error('حدث خطأ أثناء حفظ بيانات العميل');
            },
        });
    };

    const handleCancel = () => {
        form.resetFields();
        onCancel();
    };

    return (
        <Modal
            title="بيانات العميل"
            open={open}
            onCancel={handleCancel}
            onOk={() => form.submit()}
            okText="حفظ"
            cancelText="إلغاء"
            destroyOnClose
        >
            <Form
                form={form}
                layout="vertical"
                onFinish={onFinish}
                initialValues={{
                    name: order.customer?.name || '',
                    phone: order.customer?.phone || '',
                    address: order.customer?.address || '',
                    delivery_cost: order.customer?.delivery_cost || 0,
                }}
            >
                <Form.Item
                    name="name"
                    label="اسم العميل"
                    rules={[{ required: true, message: 'اسم العميل مطلوب' }]}
                >
                    <Input placeholder="ادخل اسم العميل" />
                </Form.Item>

                <Form.Item
                    name="phone"
                    label="رقم الهاتف"
                    rules={[{ required: true, message: 'رقم الهاتف مطلوب' }]}
                >
                    <Input placeholder="ادخل رقم الهاتف" />
                </Form.Item>

                <Form.Item
                    name="address"
                    label="العنوان"
                >
                    <Input.TextArea placeholder="ادخل العنوان" rows={3} />
                </Form.Item>

                {order.type === 'delivery' && (
                    <Form.Item
                        name="delivery_cost"
                        label="تكلفة التوصيل"
                    >
                        <InputNumber
                            min={0}
                            style={{ width: '100%' }}
                            placeholder="ادخل تكلفة التوصيل"
                        />
                    </Form.Item>
                )}
            </Form>
        </Modal>
    );
}
