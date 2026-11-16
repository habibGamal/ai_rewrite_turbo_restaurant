import React, { useEffect, useState } from 'react';
import { Head, usePage, router } from '@inertiajs/react';
import { Button, Descriptions, Empty, Form, Input, InputNumber, Modal, Popconfirm, Switch, Tabs, notification } from 'antd';
import { CheckCircleOutlined } from '@ant-design/icons';
import CashierLayout from '@/Layouts/CashierLayout';
import { OrderTypeTab } from '@/Components/Orders/Index/OrderTypeTab';
import { DisplayTab } from '@/Components/Orders/Index/DisplayTab';
import { ShiftExpensesTab } from '@/Components/Orders/Index/ShiftExpensesTab';
import { ReceivablesTab } from '@/Components/Orders/Index/ReceivablesTab';
import { orderTypeIcon } from '@/helpers/orderTypeIcon';
import type { CashierTab, Order, Receivable, User } from '@/types';
import { useModule } from '@/hooks/useModule';
import { useTerms } from '@/lib/wording';

interface IndexProps {
    orders: Order[];
    tabs: CashierTab[];
    receivables: Receivable[];
    currentShift: { id: number };
    defaultTab?: string | null;
    kdsCashierStatusEnabled?: boolean;
    auth: {
        user: User;
    };
    tabsOrientation?: 'horizontal' | 'vertical';
}

interface TerminalInfo {
    id: number;
    name: string;
    sessionId: number | null;
    cashier: string | null;
    openedAt: string | null;
}

const OrdersIndex: React.FC<IndexProps> = ({
    orders,
    tabs,
    receivables,
    currentShift,
    defaultTab,
    kdsCashierStatusEnabled = true,
    auth,
    tabsOrientation,
}) => {
    const pageProps = usePage().props as {
        tabsOrientation?: 'horizontal' | 'vertical';
        terminal?: TerminalInfo | null;
    };
    const terminal = pageProps.terminal ?? null;
    const [deviceOnly, setDeviceOnly] = useState(false);
    const [closeDrawerOpen, setCloseDrawerOpen] = useState(false);
    const visibleOrders =
        terminal && deviceOnly ? orders.filter((order) => order.terminal_id === terminal.id) : orders;
    const effectiveOrientation = tabsOrientation ?? pageProps.tabsOrientation ?? 'horizontal';
    const isVertical = effectiveOrientation === 'vertical';
    const { user } = auth;
    const restaurantEnabled = useModule('restaurant');
    const terms = useTerms();
    const hasFloor = tabs.some((item) => item.presentation?.body === 'floor');
    useEffect(() => {
        if (!window.Echo) {
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

        const handleKitchenUpdate = (data: { ticket_id: number; order_number: number; reason: string }) => {
            router.reload({
                only: ['orders'],
            });
            if (data.reason === 'done' && kdsCashierStatusEnabled && restaurantEnabled) {
                const audio = new Audio('/audio/web-notification.wav');
                audio.play().catch(() => {});
                notification.success({
                    message: `طلب رقم ${data.order_number} جاهز`,
                    description: terms.kitchenItemsReady,
                    icon: <CheckCircleOutlined style={{ color: '#52c41a' }} />,
                    placement: 'topLeft',
                });
            }
        };

        channel.listen('.KitchenTicketUpdated', handleKitchenUpdate);

        return () => {
            events.forEach((event) => channel.stopListening(`.${event}`, refresh));
            channel.stopListening('.KitchenTicketUpdated', handleKitchenUpdate);
        };
    }, [currentShift.id, hasFloor, kdsCashierStatusEnabled, restaurantEnabled]);
    const renderedTabs = tabs;

    const initialTab = () => {
        const hash = window.location.hash.replace('#', '') || defaultTab || '';
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

    const closeDrawer = (values: { note?: string }) => {
        router.post(route('terminals.close'), values);
        setCloseDrawerOpen(false);
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

    const activeTabKey = tab;
    const tabItems = renderedTabs.map((tab) => {
        if (tab.kind === 'type') {
            return {
                label: tabLabel(tab),
                children: (
                    <OrderTypeTab
                        tab={tab}
                        orders={visibleOrders}
                        shiftId={currentShift.id}
                        active={activeTabKey === tab.key}
                    />
                ),
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
                    orders={visibleOrders.filter((order) => order.status === 'cancelled')}
                />
            ) : (
                <DisplayTab
                    orders={visibleOrders.filter((order) => order.status === 'completed')}
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

                    {terminal ? (
                        <div className="flex items-center gap-4" data-testid="drawer-bar">
                            <span>
                                <strong>{terminal.name}</strong>
                                {terminal.cashier ? ` · ${terminal.cashier}` : ''}
                                {terminal.openedAt
                                    ? ` · من ${new Date(terminal.openedAt).toLocaleTimeString('ar-EG')}`
                                    : ''}
                            </span>
                            <span className="inline-flex items-center gap-2">
                                <Switch size="small" checked={deviceOnly} onChange={setDeviceOnly} />
                                طلبات الجهاز ده
                            </span>
                            <Button onClick={() => setCloseDrawerOpen(true)}>قفل الدرج</Button>
                        </div>
                    ) : (
                        <Popconfirm
                            title="هل انت متأكد؟"
                            onConfirm={() => endShiftWithZero()}
                            okText="نعم"
                            cancelText="لا"
                        >
                            <Button>انهاء الشيفت</Button>
                        </Popconfirm>
                    )}

                    <Modal
                        open={closeDrawerOpen}
                        onCancel={() => setCloseDrawerOpen(false)}
                        title={terminal ? `قفل «${terminal.name}»` : ''}
                        footer={null}
                        destroyOnHidden
                    >
                        <Form onFinish={closeDrawer} layout="vertical">
                            <Form.Item name="note" label="ملاحظة (اختياري)">
                                <Input.TextArea rows={2} maxLength={1000} />
                            </Form.Item>
                            <Button htmlType="submit" type="primary" danger>
                                قفل الدرج
                            </Button>
                        </Form>
                    </Modal>

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

                {tabItems.length === 0 ? (
                    <Empty
                        data-testid="no-tabs"
                        description="لا توجد تبويبات متاحة لك"
                        className="py-16"
                    />
                ) : (
                    <Tabs
                        className={isVertical ? 'cashier-vertical-tabs' : undefined}
                        tabPosition={isVertical ? 'right' : 'top'}
                        onChange={(key) => {
                            setTab(key);
                            window.location.hash = key;
                        }}
                        activeKey={tab}
                        type="card"
                        size="large"
                        items={tabItems}
                    />
                )}
            </div>
        </CashierLayout>
    );
};

export default OrdersIndex;
