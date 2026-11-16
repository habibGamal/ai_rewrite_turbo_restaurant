import React from 'react';
import { Head, router } from '@inertiajs/react';
import { Alert, Button, Card, Form, Input } from 'antd';
import CashierLayout from '@/Layouts/CashierLayout';

interface HandoverProps {
    terminal: { id: number; name: string };
    holder: string | null;
    openedAt: string;
    canOpen: boolean;
}

const Handover: React.FC<HandoverProps> = ({ terminal, holder, openedAt, canOpen }) => {
    const onFinish = (values: { note?: string }) => {
        router.post(route('terminals.handover.store'), values);
    };

    return (
        <CashierLayout>
            <Head title="استلام الدرج" />

            <div className="grid place-items-center w-full min-h-[50vh] p-4">
                <Card title={`«${terminal.name}» مفتوح`} className="w-full max-w-[500px]">
                    <p className="mb-4">
                        الدرج مفتوح مع <strong>{holder ?? 'كاشير تاني'}</strong> من{' '}
                        {new Date(openedAt).toLocaleString('ar-EG')}.
                    </p>

                    {canOpen ? (
                        <Form layout="vertical" onFinish={onFinish}>
                            <Form.Item label="ملاحظة التسليم (اختياري)" name="note">
                                <Input.TextArea rows={2} maxLength={500} />
                            </Form.Item>
                            <Button type="primary" htmlType="submit" size="large" className="w-full">
                                استلام الدرج من {holder ?? 'الكاشير'}
                            </Button>
                            <p className="mt-2 text-xs text-gray-500">
                                الدرج بيكمل بنفس الفلوس، والتسليم بيتسجل كملاحظة في سجل الوردية.
                            </p>
                        </Form>
                    ) : (
                        <Alert type="warning" showIcon message="مش مسموح لك تستلم الدرج." />
                    )}
                </Card>
            </div>
        </CashierLayout>
    );
};

export default Handover;
