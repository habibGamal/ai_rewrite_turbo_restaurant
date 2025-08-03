import React from 'react';
import { Modal, Form, Input, message } from 'antd';
import { router } from '@inertiajs/react';
import { Order } from '@/types';

interface DriverModalProps {
    open: boolean;
    onCancel: () => void;
    order: Order;
}

export default function DriverModal({ open, onCancel, order }: DriverModalProps) {
    const [form] = Form.useForm();

    const onFinish = (values: any) => {
        router.post(`/orders/update-driver/${order.id}`, values, {
            onSuccess: () => {
                message.success('تم حفظ بيانات السائق بنجاح');
                onCancel();
            },
            onError: () => {
                message.error('حدث خطأ أثناء حفظ بيانات السائق');
            },
        });
    };

    const handleCancel = () => {
        form.resetFields();
        onCancel();
    };

    return (
        <Modal
            title="بيانات السائق"
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
                    name: order.driver?.name || '',
                    phone: order.driver?.phone || '',
                }}
            >
                <Form.Item
                    name="name"
                    label="اسم السائق"
                    rules={[{ required: true, message: 'اسم السائق مطلوب' }]}
                >
                    <Input placeholder="ادخل اسم السائق" />
                </Form.Item>

                <Form.Item
                    name="phone"
                    label="رقم الهاتف"
                    rules={[{ required: true, message: 'رقم الهاتف مطلوب' }]}
                >
                    <Input placeholder="ادخل رقم الهاتف" />
                </Form.Item>
            </Form>
        </Modal>
    );
}
