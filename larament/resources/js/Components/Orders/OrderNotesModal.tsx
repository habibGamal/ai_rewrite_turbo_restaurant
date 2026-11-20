import React from 'react';
import { Modal, Form, Input, message } from 'antd';
import { router } from '@inertiajs/react';
import { Order } from '@/types';

const { TextArea } = Input;

interface OrderNotesModalProps {
    open: boolean;
    onCancel: () => void;
    /** Saved order: the note is posted to the server. */
    order?: Order;
    /** Cart mode (instant sale): the note stays in the cart and is sent with the sale. */
    cartNotes?: string;
    onApply?: (notes: string) => void;
}

export default function OrderNotesModal({ open, onCancel, order, cartNotes, onApply }: OrderNotesModalProps) {
    const [form] = Form.useForm();
    const cartMode = !order && onApply !== undefined;

    const onFinish = (values: { order_notes?: string }) => {
        if (cartMode) {
            onApply!((values.order_notes ?? '').trim());
            onCancel();
            return;
        }
        if (!order) return;
        router.post(`/orders/update-notes/${order.id}`, values, {
            onSuccess: () => {
                message.success('تم حفظ ملاحظات الطلب بنجاح');
                onCancel();
            },
            onError: () => {
                message.error('حدث خطأ أثناء حفظ ملاحظات الطلب');
            },
        });
    };

    const handleCancel = () => {
        form.resetFields();
        onCancel();
    };

    return (
        <Modal
            title="ملاحظات الطلب"
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
                preserve={false}
                initialValues={{
                    order_notes: (cartMode ? cartNotes : order?.order_notes) || '',
                }}
            >
                <Form.Item
                    name="order_notes"
                    label="ملاحظات الطلب"
                    rules={[{ max: 1000, message: 'الحد الأقصى 1000 حرف' }]}
                >
                    <TextArea
                        placeholder="اكتب ملاحظات الطلب هنا..."
                        rows={4}
                        autoFocus={cartMode}
                    />
                </Form.Item>
            </Form>
        </Modal>
    );
}
