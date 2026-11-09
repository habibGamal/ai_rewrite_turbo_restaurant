import React from "react";
import { router } from "@inertiajs/react";
import { Button, Col, Empty, Row, Typography, message } from "antd";
import {
    PlusOutlined,
    PhoneOutlined,
    CarOutlined,
} from "@ant-design/icons";
import { orderStatus } from "@/helpers/orderState";
import useModal from "@/hooks/useModal";
import ChooseTableForm from "@/Components/Orders/ChooseTableForm";
import { OrderCard } from "./OrderCard";
import { OrderTimer } from "./OrderTimer";
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
        if (
            presentation.body === "floor" &&
            tab.layout?.tables.some((table) => table.number === tableNumber)
        ) {
            message.error("الطاولة موجودة في المخطط");
            return;
        }
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

    if (presentation.body === "floor" && tab.layout) {
        const layout = tab.layout;
        return (
            <div className="space-y-3" dir="rtl">
                <ChooseTableForm tableModal={tableModal} onFinish={makeNewOrder} />

                <div className="flex flex-wrap items-center justify-between gap-3 bg-white border border-slate-200 rounded-lg px-3 py-2">
                    <div className="flex items-center gap-4 text-xs text-slate-700 h-7">
                        <span className="inline-flex items-center gap-1.5">
                            <span
                                className="w-3.5 h-3.5 rounded-xs inline-block"
                                style={{
                                    backgroundColor: "#ffffff",
                                    border: "1px solid #cbd5e1",
                                }}
                            />
                            <span>متاحة</span>
                        </span>
                        <span className="inline-flex items-center gap-1.5">
                            <span
                                className="w-3.5 h-3.5 rounded-xs inline-block"
                                style={{
                                    backgroundColor: "#ea580c",
                                    border: "1px solid #c2410c",
                                }}
                            />
                            <span>مشغولة</span>
                        </span>
                        <span className="inline-flex items-center gap-1.5">
                            <span
                                className="w-3.5 h-3.5 rounded-xs inline-block"
                                style={{
                                    backgroundColor: "#e2e8f0",
                                    border: "1px dashed #94a3b8",
                                }}
                            />
                            <span>غير نشطة</span>
                        </span>
                    </div>

                    <Button
                        size="small"
                        icon={<PlusOutlined />}
                        onClick={() => tableModal.showModal()}
                    >
                        طاولة خارج المخطط
                    </Button>
                </div>

                <div
                    dir="rtl"
                    style={{
                        display: "grid",
                        gridTemplateColumns: `repeat(${layout.columns}, minmax(96px, 1fr))`,
                        gridTemplateRows: `repeat(${layout.rows}, 96px)`,
                        gap: 6,
                        overflowX: "auto",
                    }}
                >
                    {layout.sections.map((section, index) => (
                        <div
                            key={index}
                            style={{
                                gridColumn: `${section.col + 1} / span ${section.colSpan}`,
                                gridRow: `${section.row + 1} / span ${section.rowSpan}`,
                                background: section.color,
                                padding: 8,
                                borderRadius: 10,
                                border: "1px solid rgba(0, 0, 0, 0.08)",
                                fontWeight: 600,
                                fontSize: 13,
                                color: "#334155",
                            }}
                        >
                            {section.name}
                        </div>
                    ))}
                    {layout.tables.map((table) => {
                        const occupied = tab.occupancy?.[table.number];
                        const isRound = table.shape === "round";
                        const isInactive = !table.active && !occupied;

                        const bgColor = occupied
                            ? "#ea580c"
                            : table.active
                              ? "#ffffff"
                              : "#e2e8f0";
                        const borderColor = occupied
                            ? "#c2410c"
                            : table.active
                              ? "#cbd5e1"
                              : "#94a3b8";
                        const textColor = occupied
                            ? "#ffffff"
                            : table.active
                              ? "#1e293b"
                              : "#64748b";

                        return (
                            <Button
                                key={table.number}
                                disabled={isInactive}
                                onClick={() =>
                                    occupied
                                        ? router.visit(`/orders/manage/${occupied.id}`)
                                        : router.post(route("orders.store"), {
                                              type: tab.code,
                                              table_number: table.number,
                                          })
                                }
                                style={{
                                    height: "100%",
                                    width: "100%",
                                    zIndex: 1,
                                    gridColumn: `${table.col + 1} / span ${table.colSpan}`,
                                    gridRow: `${table.row + 1} / span ${table.rowSpan}`,
                                    borderRadius: isRound ? "9999px" : 12,
                                    backgroundColor: bgColor,
                                    borderColor,
                                    borderStyle: isInactive ? "dashed" : "solid",
                                    color: textColor,
                                    opacity: isInactive ? 0.65 : 1,
                                }}
                            >
                                <span
                                    className="flex flex-col items-center justify-center leading-tight"
                                    style={{ color: textColor }}
                                >
                                    <span className="font-bold">
                                        طاولة {table.number} · {table.capacity} أفراد
                                    </span>
                                    {occupied ? (
                                        <span
                                            className="text-xs mt-1 font-medium flex flex-col items-center"
                                            style={{ color: "#ffffff" }}
                                        >
                                            <span>طلب #{occupied.order_number}</span>
                                            <OrderTimer createdAt={occupied.created_at} />
                                        </span>
                                    ) : (
                                        <span
                                            className="text-xs mt-1"
                                            style={{
                                                color: table.active
                                                    ? "#047857"
                                                    : "#64748b",
                                            }}
                                        >
                                            {table.active ? "متاحة" : "غير نشطة"}
                                        </span>
                                    )}
                                </span>
                            </Button>
                        );
                    })}
                </div>
            </div>
        );
    }

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
