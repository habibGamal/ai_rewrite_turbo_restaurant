import React from "react";
import { router } from "@inertiajs/react";
import { Button, Col, Empty, Row, Typography } from "antd";
import { PhoneOutlined, CarOutlined } from "@ant-design/icons";
import { orderStatus } from "@/helpers/orderState";
import { OrderCard } from "./OrderCard";
import type { Order } from "@/types";

interface DeliveryTabProps {
    orders: Order[];
}

export const DeliveryTab: React.FC<DeliveryTabProps> = ({ orders }) => {
    const sortedOrders = [...orders].sort((a, b) => {
        if (a.status === b.status) {
            return 0;
        }
        return a.status > b.status ? -1 : 1;
    });

    const createOrder = () => {
        router.post(route("orders.store"), { type: "delivery" });
    };

    return (
        <div>
            <Row gutter={[16, 16]}>
                <Col span={6}>
                    <Button
                        onClick={createOrder}
                        className="h-36 w-full"
                        type="primary"
                        size="large"
                    >
                        <div
                            className="mt-2"
                            style={{
                                fontSize: "18px",
                                textAlign: "center",
                                width: "100%",
                            }}
                        >
                            إنشاء طلب ديلفري
                        </div>
                    </Button>
                </Col>

                {sortedOrders.map((order) => (
                    <OrderCard
                        key={order.id}
                        order={order}
                        headerTitle={`# طلب رقم ${order.order_number}`}
                        statusConfig={orderStatus(order.status)}
                        className="space-y-2"
                    >
                        <div className="space-y-1 text-sm">
                            {order.customer?.name && (
                                <Typography.Text className="block">
                                    اسم العميل: {order.customer.name}
                                </Typography.Text>
                            )}
                            <Typography.Text className="flex items-center gap-2">
                                <PhoneOutlined style={{ color: "#d7a600" }} />
                                {order.customer?.phone || "غير معروف"}
                            </Typography.Text>
                            <Typography.Text className="flex items-center gap-2">
                                <CarOutlined style={{ color: "#1890ff" }} />
                                السائق: {order.driver?.name || "غير معروف"}
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
