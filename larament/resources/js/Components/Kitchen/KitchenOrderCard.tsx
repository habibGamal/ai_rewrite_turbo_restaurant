import { Order, OrderItemChange } from '@/types';
import { orderStatus } from '@/helpers/orderState';
import { OrderTimer } from '@/Components/Orders/Index/OrderTimer';
import { Badge, Card, Table, Tag, Typography, Empty, Timeline } from 'antd';
import {
    ShoppingCartOutlined,
    FireOutlined,
    HomeOutlined,
    ShoppingOutlined,
    TruckOutlined,
    GlobalOutlined,
    PlusCircleOutlined,
    MinusCircleOutlined,
    ArrowUpOutlined,
    ArrowDownOutlined,
    DeleteOutlined,
    ClockCircleOutlined,
} from '@ant-design/icons';

const { Title, Text, Paragraph } = Typography;

interface KitchenOrderCardProps {
    order: Order;
}

const typeLabels: Record<string, { text: string; color: string; icon: React.ReactNode }> = {
    dine_in: { text: 'صالة', color: '#52c41a', icon: <HomeOutlined /> },
    takeaway: { text: 'تيك أواي', color: '#1890ff', icon: <ShoppingOutlined /> },
    delivery: { text: 'دليفري', color: '#ff4d4f', icon: <TruckOutlined /> },
    companies: { text: 'شركات', color: '#8c8c8c', icon: <ShoppingCartOutlined /> },
    talabat: { text: 'طلبات', color: '#faad14', icon: <ShoppingCartOutlined /> },
    web_delivery: { text: 'اونلاين دليفري', color: '#722ed1', icon: <GlobalOutlined /> },
    web_takeaway: { text: 'اونلاين تيك أواي', color: '#722ed1', icon: <GlobalOutlined /> },
};

const KitchenOrderCard: React.FC<KitchenOrderCardProps> = ({ order }) => {
    const statusConfig = orderStatus(order.status);
    const typeConfig = typeLabels[order.type] || { text: order.type, color: '#666', icon: null };

    // Calculate elapsed time for urgency coloring
    const elapsedMs = Date.now() - new Date(order.created_at).getTime();
    const elapsedMinutes = Math.floor(elapsedMs / 60000);

    // Border color based on age: green → orange → red
    const getUrgencyBorder = () => {
        if (order.status === 'completed' || order.status === 'cancelled') return '#d9d9d9';
        if (elapsedMinutes < 10) return '#52c41a'; // Green - fresh
        if (elapsedMinutes < 20) return '#faad14'; // Orange - getting old
        return '#ff4d4f'; // Red - urgent
    };

    const tableColumns = [
        {
            title: 'الصنف',
            dataIndex: 'productName',
            key: 'productName',
            render: (text: string, record: any) => (
                <div>
                    <Text strong className="text-base">
                        {text}
                    </Text>
                    {record.notes && (
                        <div className="mt-1">
                            <Tag
                                color="orange"
                                className="text-sm"
                                icon={<FireOutlined />}
                            >
                                {record.notes}
                            </Tag>
                        </div>
                    )}
                </div>
            ),
        },
        {
            title: 'الكمية',
            dataIndex: 'quantity',
            key: 'quantity',
            width: 80,
            align: 'center' as const,
            render: (qty: number) => (
                <Text strong className="text-lg">
                    {qty}
                </Text>
            ),
        },
    ];

    const tableData = order.items?.map((item, idx) => ({
        key: idx,
        productName: item.product?.name || 'غير معروف',
        quantity: item.quantity,
        notes: item.notes,
    })) || [];

    const totalItems = order.items?.reduce((sum, item) => sum + item.quantity, 0) || 0;

    const getChangeColor = (change: OrderItemChange): string => {
        switch (change.change_type) {
            case 'added':
                return 'green';
            case 'removed':
                return 'red';
            case 'quantity_changed':
                return (change.delta ?? 0) > 0 ? 'green' : 'red';
            default:
                return 'gray';
        }
    };

    const formatChangeText = (change: OrderItemChange): string => {
        const name = change.product_name;
        switch (change.change_type) {
            case 'added':
                return `تم إضافة ${change.new_quantity} × ${name}`;
            case 'removed':
                return `تم حذف ${change.old_quantity} × ${name}`;
            case 'quantity_changed': {
                const delta = change.delta ?? 0;
                const abs = Math.abs(delta);
                if (delta > 0) {
                    return `${name} زادت بـ ${abs} (من ${change.old_quantity} إلى ${change.new_quantity})`;
                }
                return `${name} نقصت بـ ${abs} (من ${change.old_quantity} إلى ${change.new_quantity})`;
            }
            default:
                return change.product_name;
        }
    };

    const formatRelativeTime = (dateStr: string): string => {
        const now = Date.now();
        const then = new Date(dateStr).getTime();
        const diffMs = now - then;
        const diffSec = Math.floor(diffMs / 1000);
        const diffMin = Math.floor(diffSec / 60);
        const diffHour = Math.floor(diffMin / 60);

        if (diffSec < 60) return 'أقل من دقيقة';
        if (diffMin < 60) return `${diffMin} دقيقة`;
        if (diffHour < 24) return `${diffHour} ساعة`;
        return `${Math.floor(diffHour / 24)} يوم`;
    };

    return (
        <Card
            className=""
            style={{
                borderColor: getUrgencyBorder(),
                borderWidth: 2,
            }}
            styles={{
                body: { padding: '16px' },
            }}
        >
            {/* Header */}
            <div className="flex items-start justify-between mb-3">
                <div className="flex items-center gap-2">
                    <Title level={4} className="!mb-0">
                        #{order.order_number}
                    </Title>
                    <Tag
                        color={typeConfig.color}
                        className="text-sm flex items-center gap-1"
                    >
                        {typeConfig.icon}
                        {typeConfig.text}
                    </Tag>
                    <Badge {...statusConfig} />
                </div>
                <OrderTimer createdAt={order.created_at} />
            </div>

            {/* Table number for dine-in */}
            {order.dine_table_number && (
                <div className="mb-3">
                    <Tag
                        color="cyan"
                        className="text-base px-3 py-1"
                        icon={<HomeOutlined />}
                    >
                        طاولة {order.dine_table_number}
                    </Tag>
                </div>
            )}

            {/* Items Table */}
            {tableData.length > 0 ? (
                <Table
                    dataSource={tableData}
                    columns={tableColumns}
                    pagination={false}
                    size="small"
                    showHeader={true}
                />
            ) : (
                <Empty
                    image={Empty.PRESENTED_IMAGE_SIMPLE}
                    description="لا يوجد أصناف"
                    className="my-4"
                />
            )}

            {/* Kitchen Notes */}
            {order.kitchen_notes && (
                <div className="mt-3 p-3 bg-orange-50 border border-orange-200 rounded-lg">
                    <div className="flex items-center gap-2 mb-1">
                        <FireOutlined className="text-orange-500" />
                        <Text strong className="!text-orange-600">
                            ملاحظات المطبخ
                        </Text>
                    </div>
                    <Paragraph className="!mb-0 !text-orange-700 text-base">
                        {order.kitchen_notes}
                    </Paragraph>
                </div>
            )}

            {/* Order Notes */}
            {order.order_notes && (
                <div className="mt-2 p-3 bg-blue-50 border border-blue-200 rounded-lg">
                    <Text strong className="!text-blue-600">
                        ملاحظات الطلب
                    </Text>
                    <Paragraph className="!mb-0 !text-blue-700 text-base mt-1">
                        {order.order_notes}
                    </Paragraph>
                </div>
            )}

            {/* Change Log */}
            {order.item_changes && order.item_changes.length > 0 && (
                <div className="mt-3 p-3 bg-gray-50 border border-gray-200 rounded-lg">
                    <div className="flex items-center gap-2 mb-2">
                        <ClockCircleOutlined className="text-gray-500" />
                        <Text strong className="!text-gray-600">
                            سجل التغييرات
                        </Text>
                    </div>
                    <Timeline
                        items={order.item_changes.slice(0, 10).map((change) => ({
                            color: getChangeColor(change),
                            children: (
                                <div className="text-sm">
                                    <Text>{formatChangeText(change)}</Text>
                                    <Text type="secondary" className="text-xs mr-2">
                                        منذ {formatRelativeTime(change.created_at)}
                                    </Text>
                                </div>
                            ),
                        }))}
                    />
                </div>
            )}

            {/* Footer */}
            <div className="mt-3 pt-2 border-t border-gray-200 flex items-center justify-between">
                <span className="flex items-center gap-1 text-gray-500">
                    <ShoppingCartOutlined />
                    {totalItems} صنف
                </span>
                <span className="text-gray-400 text-xs">
                    {new Date(order.created_at).toLocaleTimeString('ar-EG', {
                        hour: '2-digit',
                        minute: '2-digit',
                    })}
                </span>
            </div>
        </Card>
    );
};

export default KitchenOrderCard;
