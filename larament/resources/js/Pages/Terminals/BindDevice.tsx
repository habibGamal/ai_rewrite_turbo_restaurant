import React from 'react';
import { Head, router } from '@inertiajs/react';
import { Alert, Button, Card, Empty, Form, Select } from 'antd';
import CashierLayout from '@/Layouts/CashierLayout';

interface BindDeviceProps {
    current: { id: number; name: string } | null;
    canBind: boolean;
    terminals: { id: number; name: string; devices: number }[];
}

const BindDevice: React.FC<BindDeviceProps> = ({ current, canBind, terminals }) => {
    const onFinish = (values: { terminal_id: number }) => {
        router.post(route('terminals.device.bind'), values);
    };

    return (
        <CashierLayout>
            <Head title="ربط الجهاز بدرج" />

            <div className="grid place-items-center w-full min-h-[50vh] p-4">
                <Card title="ربط الجهاز ده بدرج" className="w-full max-w-[500px]">
                    {current && (
                        <Alert
                            className="mb-4"
                            type="info"
                            showIcon
                            message={`الجهاز ده مربوط حالياً بـ «${current.name}»`}
                        />
                    )}

                    {!canBind ? (
                        <Alert
                            type="warning"
                            showIcon
                            message="الجهاز ده مش مربوط بدرج"
                            description="اطلب من المدير يدخل على الجهاز ده ويربطه بدرج مرة واحدة."
                        />
                    ) : terminals.length === 0 ? (
                        <Empty description="مفيش أدراج مفعلة. ضيف درج من لوحة التحكم ← الأدراج." />
                    ) : (
                        <Form layout="vertical" onFinish={onFinish} initialValues={{ terminal_id: current?.id }}>
                            <Form.Item
                                label="الدرج"
                                name="terminal_id"
                                rules={[{ required: true, message: 'اختار الدرج' }]}
                            >
                                <Select
                                    size="large"
                                    placeholder="اختار الدرج"
                                    options={terminals.map((t) => ({
                                        value: t.id,
                                        label: t.devices > 0 ? `${t.name} (${t.devices} جهاز)` : t.name,
                                    }))}
                                />
                            </Form.Item>
                            <Button type="primary" htmlType="submit" size="large" className="w-full">
                                ربط الجهاز
                            </Button>
                        </Form>
                    )}
                </Card>
            </div>
        </CashierLayout>
    );
};

export default BindDevice;
