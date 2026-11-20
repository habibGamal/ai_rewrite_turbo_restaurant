import React, { useState } from "react";
import { Col, Empty, Row, Typography } from "antd";
import { PhoneOutlined } from "@ant-design/icons";
import PaymentModal from "@/Components/Orders/PaymentModal";
import { formatCurrency } from "@/utils/orderCalculations";
import type { Order, Receivable } from "@/types";

interface ReceivablesTabProps {
    orders: Receivable[];
}

export const ReceivablesTab: React.FC<ReceivablesTabProps> = ({ orders }) => {
    const [selected, setSelected] = useState<Receivable | null>(null);

    return (
        <div>
            <Row gutter={[16, 16]}>
                {orders.map((order) => (
                    <Col span={6} key={order.id}>
                        <button
                            type="button"
                            className="w-full rounded border border-gray-200 p-4 text-right space-y-2"
                            onClick={() => setSelected(order)}
                        >
                            <Typography.Title level={5} className="mb-0">
                                {order.customer?.name || "غير معروف"}
                            </Typography.Title>
                            <Typography.Text className="flex items-center gap-2">
                                <PhoneOutlined style={{ color: "#d7a600" }} />
                                {order.customer?.phone || "غير معروف"}
                            </Typography.Text>
                            <Typography.Text className="block">
                                طلب رقم {order.order_number}
                            </Typography.Text>
                            <Typography.Text className="block">
                                {new Date(order.created_at).toLocaleString("ar-EG", {
                                    hour12: true,
                                })}
                            </Typography.Text>
                            <Typography.Text className="block">
                                الإجمالي: {formatCurrency(order.total)}
                            </Typography.Text>
                            <Typography.Text className="block">
                                المدفوع: {formatCurrency(order.paid)}
                            </Typography.Text>
                            <Typography.Text className="block">
                                المتبقي: {formatCurrency(order.remaining)}
                            </Typography.Text>
                        </button>
                    </Col>
                ))}
            </Row>

            {orders.length === 0 && (
                <Empty
                    className="mx-auto mt-8"
                    image={Empty.PRESENTED_IMAGE_SIMPLE}
                    description="لا يوجد طلبات"
                />
            )}

            {selected && (
                <PaymentModal
                    open
                    onCancel={() => setSelected(null)}
                    order={selected as unknown as Order}
                    orderItems={[]}
                    mode="collect"
                    paidAmount={selected.paid}
                    remainingAmount={selected.remaining}
                />
            )}
        </div>
    );
};
