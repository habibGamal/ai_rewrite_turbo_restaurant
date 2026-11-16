import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { Alert, Button, Card, Form, Input, InputNumber, Modal, Popconfirm, Table, Tag } from 'antd';
import CashierLayout from '@/Layouts/CashierLayout';

interface DrawerSession {
    id: number;
    terminal: string;
    cashier: string | null;
    openedAt: string;
    closedAt: string | null;
    openingCash: number;
    expectedCash: number | null;
}

interface OpenDrawerProps {
    terminal: { id: number; name: string };
    hasShift: boolean;
    canOpen: boolean;
    canEndShift: boolean;
    canCloseOthers: boolean;
    sessions: DrawerSession[];
}

const time = (value: string | null) => (value ? new Date(value).toLocaleTimeString('ar-EG') : '—');
const money = (value: number | null) => (value === null ? '—' : value.toFixed(2));

const OpenDrawer: React.FC<OpenDrawerProps> = ({
    terminal,
    hasShift,
    canOpen,
    canEndShift,
    canCloseOthers,
    sessions,
}) => {
    const [closing, setClosing] = useState<DrawerSession | null>(null);
    const [closeForm] = Form.useForm();
    const openCount = sessions.filter((s) => s.closedAt === null).length;

    const openDrawer = (values: { opening_cash: number }) => {
        router.post(route('terminals.open.store'), values);
    };

    const closeOther = (values: { note: string }) => {
        if (!closing) return;
        router.post(route('terminals.sessions.close', closing.id), values, {
            onSuccess: () => {
                setClosing(null);
                closeForm.resetFields();
            },
        });
    };

    const endShift = () => {
        router.post(route('shifts.end'), { real_end_cash: 0 });
    };

    return (
        <CashierLayout>
            <Head title="فتح الدرج" />

            <div className="flex flex-col items-center gap-4 w-full p-4">
                <Card title={`فتح «${terminal.name}»`} className="w-full max-w-[500px]">
                    {!hasShift && (
                        <Alert
                            className="mb-4"
                            type="info"
                            showIcon
                            message="مفيش وردية شغالة. فتح الدرج ده هيبدأ وردية جديدة."
                        />
                    )}
                    {canOpen ? (
                        <Form layout="vertical" onFinish={openDrawer}>
                            <Form.Item
                                label="الفكة اللي في الدرج"
                                name="opening_cash"
                                rules={[{ required: true, message: 'هذا الحقل مطلوب' }]}
                            >
                                <InputNumber min={0} className="w-full" size="large" placeholder="0" />
                            </Form.Item>
                            <Button type="primary" htmlType="submit" size="large" className="w-full">
                                فتح الدرج
                            </Button>
                        </Form>
                    ) : (
                        <Alert type="warning" showIcon message="مش مسموح لك تفتح درج." />
                    )}
                </Card>

                {sessions.length > 0 && (
                    <Card title="أدراج الوردية" className="w-full max-w-[900px]">
                        <Table<DrawerSession>
                            rowKey="id"
                            size="small"
                            pagination={false}
                            dataSource={sessions}
                            columns={[
                                { title: 'الدرج', dataIndex: 'terminal' },
                                { title: 'الكاشير', dataIndex: 'cashier', render: (v) => v ?? '—' },
                                { title: 'فتح', dataIndex: 'openedAt', render: time },
                                {
                                    title: 'الحالة',
                                    dataIndex: 'closedAt',
                                    render: (v) =>
                                        v ? <Tag>اتقفل {time(v)}</Tag> : <Tag color="green">مفتوح</Tag>,
                                },
                                { title: 'الفكة', dataIndex: 'openingCash', render: money },
                                ...(canEndShift
                                    ? [{ title: 'النقدية المتوقعة', dataIndex: 'expectedCash', render: money }]
                                    : []),
                                ...(canCloseOthers
                                    ? [
                                          {
                                              title: '',
                                              key: 'actions',
                                              render: (_: unknown, row: DrawerSession) =>
                                                  row.closedAt === null ? (
                                                      <Button danger size="small" onClick={() => setClosing(row)}>
                                                          قفل نيابةً عنه
                                                      </Button>
                                                  ) : null,
                                          },
                                      ]
                                    : []),
                            ]}
                        />

                        {canEndShift && (
                            <div className="mt-4 flex items-center justify-between gap-4">
                                <span className="text-gray-500">
                                    {openCount > 0
                                        ? `لازم تتقفل كل الأدراج قبل إنهاء الوردية (${openCount} مفتوح).`
                                        : 'كل الأدراج اتقفلت.'}
                                </span>
                                <Popconfirm
                                    title="إنهاء الوردية؟"
                                    onConfirm={endShift}
                                    okText="نعم"
                                    cancelText="لا"
                                    disabled={openCount > 0}
                                >
                                    <Button type="primary" danger disabled={openCount > 0}>
                                        إنهاء الوردية
                                    </Button>
                                </Popconfirm>
                            </div>
                        )}
                    </Card>
                )}
            </div>

            <Modal
                open={closing !== null}
                onCancel={() => setClosing(null)}
                title={closing ? `قفل «${closing.terminal}» نيابةً عن ${closing.cashier ?? 'الكاشير'}` : ''}
                footer={null}
            >
                <Form form={closeForm} layout="vertical" onFinish={closeOther}>
                    <Form.Item
                        label="السبب"
                        name="note"
                        rules={[{ required: true, message: 'اكتب السبب' }]}
                    >
                        <Input.TextArea rows={3} maxLength={1000} />
                    </Form.Item>
                    <Button type="primary" danger htmlType="submit">
                        قفل الدرج
                    </Button>
                </Form>
            </Modal>
        </CashierLayout>
    );
};

export default OpenDrawer;
