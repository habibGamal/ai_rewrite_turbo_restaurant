import React, { useEffect, useState } from 'react';
import { Head, usePage, router } from '@inertiajs/react';
import { Button, Descriptions, Form, InputNumber, Modal, Popconfirm, Tabs } from 'antd';
import CashierLayout from '@/Layouts/CashierLayout';
import { OrderTypeTab } from '@/Components/Orders/Index/OrderTypeTab';
import { DisplayTab } from '@/Components/Orders/Index/DisplayTab';
import { ShiftExpensesTab } from '@/Components/Orders/Index/ShiftExpensesTab';
import { ReceivablesTab } from '@/Components/Orders/Index/ReceivablesTab';
import { orderTypeIcon } from '@/helpers/orderTypeIcon';
import type { CashierTab, Order, Receivable, User } from '@/types';

interface IndexProps {
    orders: Order[];
    tabs: CashierTab[];
    receivables: Receivable[];
    currentShift: { id: number };
    auth: {
        user: User;
    };
}

const OrdersIndex: React.FC<IndexProps> = ({
    orders,
    tabs,
    receivables,
    currentShift,
    auth
}) => {
    const { user } = auth;
    const hasFloor = tabs.some((item) => item.presentation?.body === 'floor');
    useEffect(() => {
        if (!window.Echo || !hasFloor) {
            return;
        }

        const channel = window.Echo.private(`shift.${currentShift.id}`);
        const refresh = () => router.reload({ only: ['orders', 'tabs'] });
        const events = [
            'order.created',
            'order.completed',
            'order.cancelled',
            'order.type_changed',
        ];
        events.forEach((event) => channel.listen(`.${event}`, refresh));

        return () => {
            events.forEach((event) => channel.stopListening(`.${event}`, refresh));
        };
    }, [currentShift.id, hasFloor]);
    const renderedTabs = tabs;

    const initialTab = () => {
        const hash = window.location.hash.replace('#', '');
        const found = renderedTabs.find(
            (candidate) =>
                candidate.key === hash ||
                candidate.code === hash ||
                candidate.key === `type:${hash}` ||
                candidate.key === `system:${hash}`
        );
        return found?.key ?? renderedTabs[0]?.key ?? '';
    };

    const [tab, setTab] = useState(initialTab);
    const [endShiftModalVisible, setEndShiftModalVisible] = useState(false);
    const [form] = Form.useForm();

    const endShift = (values: any) => {
        router.post(route('shifts.end'), { ...values });
        setEndShiftModalVisible(false);
    };

    const endShiftWithZero = () => {
        router.post(route('shifts.end'), { real_end_cash: 0 });
    };

    const tabLabel = (tab: CashierTab) => (
        <span className="inline-flex items-center gap-2">
            {orderTypeIcon(tab.icon)}
            <span>{tab.label}</span>
            {tab.forcedVisible && (
                <span className="text-xs text-gray-400">مخفي</span>
            )}
        </span>
    );

    const tabItems = renderedTabs.map((tab) => {
        if (tab.kind === 'type') {
            return {
                label: tabLabel(tab),
                children: <OrderTypeTab tab={tab} orders={orders} />,
                key: tab.key,
            };
        }

        const children =
            tab.key === 'system:receivables' ? (
                <ReceivablesTab orders={receivables} />
            ) : tab.key === 'system:expenses' ? (
                <ShiftExpensesTab />
            ) : tab.key === 'system:cancelled' ? (
                <DisplayTab
                    orders={orders.filter((order) => order.status === 'cancelled')}
                />
            ) : (
                <DisplayTab
                    orders={orders.filter((order) => order.status === 'completed')}
                />
            );

        return {
            label: tabLabel(tab),
            children,
            key: tab.key,
        };
    });

    return (
        <CashierLayout>
            <Head title="إدارة الطلبات" />

            <div className="p-4">
                <div className="flex justify-between">
                    <Descriptions data-testid="user-info">
                        <Descriptions.Item label="الموظف">{user?.email}</Descriptions.Item>
                    </Descriptions>

                    <Popconfirm
                        title="هل انت متأكد؟"
                        onConfirm={() => endShiftWithZero()}
                        okText="نعم"
                        cancelText="لا"
                    >
                        <Button>انهاء الشيفت</Button>
                    </Popconfirm>

                    <Modal
                        open={endShiftModalVisible}
                        onCancel={() => setEndShiftModalVisible(false)}
                        title="انهاء الشيفت"
                        footer={null}
                    >
                        <Form onFinish={endShift} layout="vertical" form={form}>
                            <Form.Item
                                name="real_end_cash"
                                label="النقدية في الدرج"
                                rules={[
                                    {
                                        required: true,
                                        message: 'النقدية في الدرج مطلوبة',
                                    },
                                ]}
                            >
                                <InputNumber min={0} className="w-full" />
                            </Form.Item>
                            <Form.Item>
                                <Button htmlType="submit" type="primary">
                                    تم
                                </Button>
                            </Form.Item>
                        </Form>
                    </Modal>
                </div>

                <Tabs
                    onChange={(key) => {
                        setTab(key);
                        window.location.hash = key;
                    }}
                    activeKey={tab}
                    type="card"
                    size="large"
                    items={tabItems}
                />
            </div>
        </CashierLayout>
    );
};

export default OrdersIndex;
