import React, { useMemo, useState } from 'react';
import { Head } from '@inertiajs/react';
import { Col, Empty, Row, Tabs, Typography, Tag, Badge } from 'antd';
import KitchenLayout from '@/Layouts/KitchenLayout';
import KitchenOrderCard from '@/Components/Kitchen/KitchenOrderCard';
import type { Order } from '@/types';

const { Title } = Typography;

interface FilterOption {
    value: string;
    label: string;
}

interface KitchenIndexProps {
    orders: Order[];
    hasActiveShift: boolean;
    canManageOrders: boolean;
    orderTypes: FilterOption[];
    orderStatuses: FilterOption[];
}

const KitchenIndex: React.FC<KitchenIndexProps> = ({
    orders,
    hasActiveShift,
    orderTypes,
}) => {
    const [typeFilter, setTypeFilter] = useState<string>('all');

    // Only show processing orders
    const processingOrders = useMemo(() => {
        return orders.filter((o) => o.status === 'processing');
    }, [orders]);

    const filteredOrders = useMemo(() => {
        let result = [...processingOrders];

        if (typeFilter && typeFilter !== 'all') {
            result = result.filter((order) => order.type === typeFilter);
        }

        // Sort: newest first
        result.sort(
            (a, b) => new Date(b.created_at).getTime() - new Date(a.created_at).getTime()
        );

        return result;
    }, [processingOrders, typeFilter]);

    // Build type tab items (based on processing orders only)
    const typeTabItems = [
        {
            label: (
                <span>
                    الكل
                    <Badge
                        count={processingOrders.length}
                        showZero
                        className="mr-2"
                        style={{ backgroundColor: '#7E57C2' }}
                    />
                </span>
            ),
            key: 'all',
        },
        ...orderTypes.map((type) => {
            const count = processingOrders.filter((o) => o.type === type.value).length;
            return {
                label: (
                    <span>
                        {type.label}
                        {count > 0 && (
                            <Badge
                                count={count}
                                className="mr-2"
                                style={{ backgroundColor: '#7E57C2' }}
                            />
                        )}
                    </span>
                ),
                key: type.value,
            };
        }),
    ];

    if (!hasActiveShift) {
        return (
            <KitchenLayout>
                <Head title="شاشة المطبخ" />
                <div className="flex items-center justify-center h-[80vh]">
                    <Empty
                        image={Empty.PRESENTED_IMAGE_SIMPLE}
                        description={
                            <div className="text-center">
                                <Title level={3}>لا يوجد وردية نشطة</Title>
                                <p className="text-gray-500">
                                    يرجى بدء وردية جديدة من شاشة الكاشير
                                </p>
                            </div>
                        }
                    />
                </div>
            </KitchenLayout>
        );
    }

    return (
        <KitchenLayout>
            <Head title="شاشة المطبخ" />

            {/* Type Filter Tabs */}
            <div className="mb-4">
                <Tabs
                    activeKey={typeFilter}
                    onChange={(key) => setTypeFilter(key)}
                    type="card"
                    size="small"
                    items={typeTabItems}
                />
            </div>

            {/* Summary Badges */}
            <div className="mb-4 flex items-center gap-4">
                <Badge count={processingOrders.length} offset={[6, 0]} color="#52c41a">
                    <Tag color="green" className="text-base px-3 py-1">
                        تحت التشغيل
                    </Tag>
                </Badge>
                <Tag className="text-base px-3 py-1">
                    الإجمالي: {filteredOrders.length}
                </Tag>
            </div>

            {/* Orders Grid */}
            {filteredOrders.length > 0 ? (
                <div className='grid grid-cols-3 gap-4'>
                    {filteredOrders.map((order) => (
                        <div>

                            <KitchenOrderCard order={order} key={order.id} />
                        </div>
                    ))}
                </div>
            ) : (
                <div className="flex items-center justify-center h-[60vh]">
                    <Empty
                        image={Empty.PRESENTED_IMAGE_SIMPLE}
                        description={
                            <span className="text-gray-500">
                                لا يوجد طلبات تحت التشغيل{' '}
                                {typeFilter !== 'all' ? ' تطابق الفلتر المحدد' : ' حالياً'}
                            </span>
                        }
                    />
                </div>
            )}
        </KitchenLayout>
    );
};

export default KitchenIndex;
