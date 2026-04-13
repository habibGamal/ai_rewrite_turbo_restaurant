import { Col, Empty, Row, Typography } from 'antd'
import { PhoneOutlined } from '@ant-design/icons'
import { OrderCard } from './OrderCard'
import { Order } from '@/types'

const getOrderStatus = (status: string) => {
    const statusConfig = {
        pending: { color: 'orange', text: 'في الإنتظار' },
        processing: { color: 'blue', text: 'قيد التشغيل' },
        out_for_delivery: { color: 'purple', text: 'في طريق التوصيل' },
        completed: { color: 'green', text: 'مكتمل' },
        cancelled: { color: 'red', text: 'ملغي' },
    }

    return statusConfig[status as keyof typeof statusConfig] || { color: 'gray', text: status }
}

interface WebTakeawayProps {
    orders: Order[]
}

export default function WebTakeawayTab({ orders }: WebTakeawayProps) {
    const sortedOrders = [...orders].sort((a, b) => {
        return a.order_number > b.order_number ? -1 : 1
    })

    if (sortedOrders.length === 0) {
        return (
            <Empty
                className="mt-8"
                image={Empty.PRESENTED_IMAGE_SIMPLE}
                description="لا يوجد طلبات"
            />
        )
    }

    return (
        <Row gutter={[16, 16]}>
            {sortedOrders.map((order) => (
                <OrderCard
                    key={order.id}
                    order={order}
                    headerTitle={`# طلب رقم ${order.order_number}`}
                    statusConfig={getOrderStatus(order.status)}
                    linkPrefix="/web-orders/manage-web-order/"
                    className="space-y-3 hover:shadow-lg transition-shadow"
                >
                    <Typography.Text className="flex items-center gap-2 text-amber-600">
                        <PhoneOutlined size={16} />
                        رقم العميل {order.customer?.phone || 'غير معروف'}
                    </Typography.Text>
                </OrderCard>
            ))}
        </Row>
    )
}
