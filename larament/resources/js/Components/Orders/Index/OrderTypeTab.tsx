import React from "react";
import { router } from "@inertiajs/react";
import { Button, Col, Empty, Row, Typography } from "antd";
import {
    PlusOutlined,
    PhoneOutlined,
    CarOutlined,
} from "@ant-design/icons";
import { orderStatus } from "@/helpers/orderState";
import useModal from "@/hooks/useModal";
import ChooseTableForm from "@/Components/Orders/ChooseTableForm";
import { OrderCard } from "./OrderCard";
import type { CashierTab, Order, OrderDisplay } from "@/types";

const webOrderStatus = (status: string) => {
    const statusConfig: Record<string, { color: string; text: string }> = {
        pending: { color: "orange", text: "في الإنتظار" },
        processing: { color: "blue", text: "قيد التشغيل" },
        out_for_delivery: { color: "purple", text: "في طريق التوصيل" },
        completed: { color: "green", text: "مكتمل" },
        cancelled: { color: "red", text: "ملغي" },
    };

    return statusConfig[status] || { color: "gray", text: status };
};

interface OrderTypeTabProps {
    tab: CashierTab;
    orders: Order[];
}

export const OrderTypeTab: React.FC<OrderTypeTabProps> = ({ tab, orders }) => {
    const tableModal = useModal();

    const presentation = tab.presentation!;
    const creation = presentation.creation;

    const tabOrders = orders.filter(
        (order) =>
            order.type === tab.code &&
            presentation.statuses.includes(order.status)
    );

    const sortedOrders =
        creation === "none"
            ? [...tabOrders].sort((a, b) =>
                  a.order_number > b.order_number ? -1 : 1
              )
            : [...tabOrders].sort((a, b) => {
                  if (a.status === b.status) {
                      return 0;
                  }
                  return a.status > b.status ? -1 : 1;
              });

    const makeNewOrder = (values: any) => {
        const tableNumber = `${values.tableType} - ${values.tableNumber}`;
        router.post(route("orders.store"), {
            type: tab.code!,
            table_number: tableNumber,
        });
        tableModal.closeModal();
    };

    const createOrder = () => {
        router.post(route("orders.store"), { type: tab.code! });
    };

    const cardDetails = (display: OrderDisplay | undefined, order: Order) => {
        if (
            !display?.showCustomer &&
            !display?.showAddress &&
            !display?.showDriver
        ) {
            return null;
        }

        const phone = order.customer?.phone || "غير معروف";

        return (
            <div className="space-y-1 text-sm">
                {display.showCustomer &&
                    (display.showAddress || display.showDriver) &&
                    order.customer?.name && (
                        <Typography.Text className="block">
                            اسم العميل: {order.customer.name}
                        </Typography.Text>
                    )}
                {display.showCustomer && (
                    <Typography.Text className="flex items-center gap-2">
                        <PhoneOutlined style={{ color: "#d7a600" }} />
                        {display.showDriver ? (
                            phone
                        ) : (
                            <>رقم العميل {phone}</>
                        )}
                    </Typography.Text>
                )}
                {display.showAddress && (
                    <Typography.Text className="block">
                        العنوان: {order.customer?.address || "غير معروف"}
                    </Typography.Text>
                )}
                {display.showDriver && (
                    <Typography.Text className="flex items-center gap-2">
                        <CarOutlined style={{ color: "#1890ff" }} />
                        السائق: {order.driver?.name || "غير معروف"}
                    </Typography.Text>
                )}
            </div>
        );
    };

    if (creation === "none" && sortedOrders.length === 0) {
        return (
            <Empty
                className="mt-8"
                image={Empty.PRESENTED_IMAGE_SIMPLE}
                description="لا يوجد طلبات"
            />
        );
    }

    return (
        <div>
            {creation === "choose_table" && (
                <ChooseTableForm
                    tableModal={tableModal}
                    onFinish={makeNewOrder}
                />
            )}

            <Row gutter={[16, 16]}>
                {creation === "choose_table" && (
                    <Col span={6}>
                        <Button
                            onClick={() => tableModal.showModal()}
                            className="h-36 w-full"
                            icon={<PlusOutlined style={{ fontSize: "48px" }} />}
                            type="primary"
                            size="large"
                        >
                            <div className="mt-2">إضافة طاولة جديدة</div>
                        </Button>
                    </Col>
                )}

                {creation === "immediate" && (
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
                                إنشاء طلب {tab.label}
                            </div>
                        </Button>
                    </Col>
                )}

                {sortedOrders.map((order) => (
                    <OrderCard
                        key={order.id}
                        order={order}
                        headerTitle={
                            presentation.cardTitle === "table"
                                ? `طاولة ${order.dine_table_number || "غير محدد"}`
                                : `# طلب رقم ${order.order_number}`
                        }
                        subtitle={
                            presentation.cardTitle === "table"
                                ? `# طلب رقم ${order.order_number}`
                                : undefined
                        }
                        statusConfig={
                            creation === "none"
                                ? webOrderStatus(order.status)
                                : orderStatus(order.status)
                        }
                        linkPrefix={
                            creation === "none"
                                ? "/web-orders/manage-web-order/"
                                : "/orders/manage/"
                        }
                        className={
                            creation === "none"
                                ? "space-y-3 hover:shadow-lg transition-shadow"
                                : "space-y-3"
                        }
                    >
                        {creation === "none" ? (
                            <Typography.Text className="flex items-center gap-2 text-amber-600">
                                <PhoneOutlined size={16} />
                                رقم العميل {order.customer?.phone || "غير معروف"}
                            </Typography.Text>
                        ) : (
                            cardDetails(order.display ?? tab.display, order)
                        )}
                    </OrderCard>
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
