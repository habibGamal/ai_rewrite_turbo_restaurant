import React from 'react';
import { Button, Form, InputNumber, Modal, Radio } from 'antd';
import useModal from '@/hooks/useModal';

interface ChooseTableFormProps {
    tableModal: ReturnType<typeof useModal>;
    onFinish: (values: any) => void;
}

export default function ChooseTableForm({ onFinish, tableModal }: ChooseTableFormProps) {
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
    ];

    return (
        <Modal
            title="الطاولة"
            open={tableModal.open}
            onCancel={tableModal.onCancel}
            footer={null}
            destroyOnClose
        >
            <Form onFinish={onFinish} name="tableNumber" layout="vertical">
                <Form.Item
                    label="نوع الطاولة"
                    name="tableType"
                    rules={[{ required: true, message: 'يرجى اختيار نوع الطاولة' }]}
                >
                    <Radio.Group
                        options={options}
                        optionType="button"
                        buttonStyle="solid"
                    />
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
    );
}
