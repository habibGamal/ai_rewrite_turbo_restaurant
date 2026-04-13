import React from "react";
import { router } from "@inertiajs/react";
import { Button, Col, Empty, Row } from "antd";
import { PlusOutlined } from "@ant-design/icons";
import { orderStatus } from "@/helpers/orderState";
import useModal from "@/hooks/useModal";
import ChooseTableForm from "@/Components/Orders/ChooseTableForm";
import { OrderCard } from "./OrderCard";
import type { Order } from "@/types";

interface DineInTabProps {
    orders: Order[];
}

export const DineInTab: React.FC<DineInTabProps> = ({ orders }) => {
    const tableModal = useModal();

    const sortedOrders = [...orders].sort((a, b) => {
        if (a.status === b.status) {
            return 0;
        }
        return a.status > b.status ? -1 : 1;
    });

    const addTable = () => {
        tableModal.showModal();
    };

    const makeNewOrder = (values: any) => {
        const tableNumber = `${values.tableType} - ${values.tableNumber}`;
        router.post(route("orders.store"), {
            type: "dine_in",
            table_number: tableNumber,
        });
        tableModal.closeModal();
    };

    return (
        <div>
            <ChooseTableForm tableModal={tableModal} onFinish={makeNewOrder} />

            <Row gutter={[16, 16]}>
                <Col span={6}>
                    <Button
                        onClick={addTable}
                        className="h-36 w-full"
                        icon={<PlusOutlined style={{ fontSize: "48px" }} />}
                        type="primary"
                        size="large"
                    >
                        <div className="mt-2">إضافة طاولة جديدة</div>
                    </Button>
                </Col>

                {sortedOrders.map((order) => (
                    <OrderCard
                        key={order.id}
                        order={order}
                        headerTitle={`طاولة ${order.dine_table_number || "غير محدد"}`}
                        subtitle={`# طلب رقم ${order.order_number}`}
                        statusConfig={orderStatus(order.status)}
                        className="space-y-3"
                    />
                ))}
            </Row>
            {sortedOrders.length === 0 && (
                <div className="mx-auto">
                    <Empty
                        className="mt-8"
                        image={Empty.PRESENTED_IMAGE_SIMPLE}
                        description="لا يوجد طلبات"
                    />
                </div>
            )}
        </div>
    );
};
