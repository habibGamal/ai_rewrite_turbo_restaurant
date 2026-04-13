import React from "react";
import { router } from "@inertiajs/react";
import { Button, Col, Empty, Row, Typography } from "antd";
import { PhoneOutlined } from "@ant-design/icons";
import { orderStatus } from "@/helpers/orderState";
import { OrderCard } from "./OrderCard";
import type { Order } from "@/types";

interface TakeawayTabProps {
    orders: Order[];
}

export const TakeawayTab: React.FC<TakeawayTabProps> = ({ orders }) => {
    const sortedOrders = [...orders].sort((a, b) => {
        if (a.status === b.status) {
            return 0;
        }
        return a.status > b.status ? -1 : 1;
    });

    const createOrder = () => {
        router.post(route("orders.store"), { type: "takeaway" });
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
                            إنشاء طلب تيك اواي
                        </div>
                    </Button>
                </Col>
                {sortedOrders.map((order) => (
                    <OrderCard
                        key={order.id}
                        order={order}
                        headerTitle={`# طلب رقم ${order.order_number}`}
                        statusConfig={orderStatus(order.status)}
                        className="space-y-3"
                    >
                        <Typography.Text className="flex items-center gap-2">
                            <PhoneOutlined style={{ color: "#d7a600" }} />
                            رقم العميل{" "}
                            {order.customer?.phone || "غير معروف"}
                        </Typography.Text>
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
