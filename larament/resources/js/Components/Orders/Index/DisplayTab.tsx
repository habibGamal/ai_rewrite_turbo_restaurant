import React from 'react';
import { Empty, Row, Typography } from 'antd';
import { PhoneOutlined } from '@ant-design/icons';
import { orderStatus } from '@/helpers/orderState';
import { OrderCard } from './OrderCard';
import type { Order } from '@/types';

interface DisplayTabProps {
    orders: Order[];
}

export const DisplayTab: React.FC<DisplayTabProps> = ({ orders }) => {
    const sortedOrders = [...orders].sort((a, b) => {
        if (a.status === b.status) {
            return 0;
        }
        return a.status > b.status ? -1 : 1;
    });

    return (
        <div>
            <Row gutter={[16, 16]}>
                {sortedOrders.map((order) => (
                    <OrderCard
                        key={order.id}
                        order={order}
                        headerTitle={`# طلب رقم ${order.order_number}`}
                        statusConfig={orderStatus(order.status)}
                        className="space-y-3"
                    >
                        <div className="space-y-1 text-sm">
                            {order.customer?.name && (
                                <Typography.Text className="block">
                                    اسم العميل: {order.customer.name}
                                </Typography.Text>
                            )}
                            <Typography.Text className="flex items-center gap-2">
                                <PhoneOutlined style={{ color: "#d7a600" }} />
                                رقم العميل {order.customer?.phone || 'غير معروف'}
                            </Typography.Text>
                        </div>
                    </OrderCard>
                ))}
            </Row>

            {sortedOrders.length === 0 && (
                <Empty
                    className="mx-auto mt-8"
                    image={Empty.PRESENTED_IMAGE_SIMPLE}
                    description="لا يوجد طلبات"
                />
            )}
        </div>
    );
};
