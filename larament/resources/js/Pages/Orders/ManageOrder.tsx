import React, { useEffect, useReducer, useState } from "react";
import { Head, router, usePage } from "@inertiajs/react";
import {
    App,
    Badge,
    Breadcrumb,
    Button,
    Col,
    Descriptions,
    Divider,
    Empty,
    Popconfirm,
    Row,
    Typography,
} from "antd";
import {
    CheckCircleOutlined,
    EditOutlined,
    PercentageOutlined,
    PrinterOutlined,
    RightOutlined,
    SaveOutlined,
    UserAddOutlined,
} from "@ant-design/icons";

import { ManageOrderProps, OrderItemData, User } from "@/types";
import { orderItemsReducer } from "@/utils/orderItemsReducer";
import {
    calculateOrderTotals,
    getOrderStatusConfig,
    formatCurrency,
} from "@/utils/orderCalculations";
import CashierLayout from "@/Layouts/CashierLayout";
import { printOrder } from "../../helpers/printTemplate";

// Components
import Categories from "@/Components/Orders/Categories";
import OrderItem from "@/Components/Orders/OrderItem";
import CustomerModal from "@/Components/Orders/CustomerModal";
import DriverModal from "@/Components/Orders/DriverModal";
import OrderNotesModal from "@/Components/Orders/OrderNotesModal";
import OrderDiscountModal from "@/Components/Orders/OrderDiscountModal";
import ChangeOrderTypeModal from "@/Components/Orders/ChangeOrderTypeModal";
import PaymentModal from "@/Components/Orders/PaymentModal";
import PrintInKitchenModal from "@/Components/Orders/PrintInKitchenModal";
import IsAdmin from "@/Components/IsAdmin";

export default function ManageOrder({
    order,
    categories,
    receiptFooter,
}: ManageOrderProps) {
    const { auth } = usePage().props;
    const user = auth.user as User;
    const { modal } = App.useApp();

    // Create initial order items from the order
    const products = categories.flatMap((category) => category.products);
    const initOrderItems: OrderItemData[] = order.items.map((orderItem) => ({
        product_id: orderItem.product_id,
        name:
            products.find((product) => product.id === orderItem.product_id)
                ?.name || "",
        price: parseFloat(orderItem.price.toString()),
        quantity: orderItem.quantity,
        notes: orderItem.notes,
        initial_quantity: orderItem.quantity,
    }));

    const [orderItems, dispatch] = useReducer(orderItemsReducer, []);
    const [customerInfoSkip, setCustomerInfoSkip] = useState<boolean>(false);

    // Modal states
    const [isCustomerModalOpen, setIsCustomerModalOpen] = useState(false);
    const [isDriverModalOpen, setIsDriverModalOpen] = useState(false);
    const [isOrderNotesModalOpen, setIsOrderNotesModalOpen] = useState(false);
    const [isOrderDiscountModalOpen, setIsOrderDiscountModalOpen] =
        useState(false);
    const [isChangeOrderTypeModalOpen, setIsChangeOrderTypeModalOpen] =
        useState(false);
    const [isPaymentModalOpen, setIsPaymentModalOpen] = useState(false);
    const [isPrintInKitchenModalOpen, setIsPrintInKitchenModalOpen] =
        useState(false);

    useEffect(() => {
        dispatch({ type: "init", orderItems: initOrderItems, user });
    }, [order.items]);

    // Order state checks
    const disableAllControls = order.status !== "processing";
    const orderCancelled = order.status === "cancelled";
    const orderInProcess = order.status === "processing";
    const orderCompleted = order.status === "completed";
    const isDineIn = order.type === "dine_in";
    const isTakeAway = order.type === "takeaway";
    const isDelivery = order.type === "delivery";

    // Calculate totals
    const totals = calculateOrderTotals(order, orderItems);

    const save = (callback: (page: any) => void = () => {}) => {
        const itemsForApi = orderItems.map((item) => ({
            product_id: item.product_id,
            quantity: item.quantity,
            price: item.price,
            notes: item.notes || null,
        }));

        router.post(
            `/orders/save-order/${order.id}`,
            { items: itemsForApi },
            {
                onSuccess: (page) => callback(page),
            }
        );
    };

    const cancelOrder = () => {
        router.post(`/orders/cancel-order/${order.id}`);
    };

    const askForCustomerInfo = () => {
        modal.confirm({
            title: "هل تريد اضافة بيانات العميل؟",
            icon: <UserAddOutlined />,
            content: 'اضغط على "نعم" لاضافة بيانات العميل',
            okText: "نعم",
            cancelText: "لا",
            onOk: () => setIsCustomerModalOpen(true),
            onCancel: () => skipCustomerInfo(),
        });
    };

    const tryCompleteOrder = () => {
        if (!order.customer && !customerInfoSkip) {
            return save(() => askForCustomerInfo());
        }
        payment();
    };

    const payment = () => {
        save(() => setIsPaymentModalOpen(true));
    };

    const skipCustomerInfo = () => {
        setCustomerInfoSkip(true);
        setIsCustomerModalOpen(false);
        payment();
    };

    const printInKitchen = () => {
        save(() => setIsPrintInKitchenModalOpen(true));
    };

    const printReceipt = () => {
        save(() =>
            router.post(`/orders/print/${order.id}`)
        );
    };

    const printWithCanvas = async () => {
        save(async (page) => {
             printOrder(page.props.order, orderItems, page.props.receiptFooter || '');
        });
    };

    // Keyboard shortcuts
    useEffect(() => {
        const handleKeyDown = (e: KeyboardEvent) => {
            if (e.key === "F8") {
                e.preventDefault();
                save();
            }
            if (e.key === "F9") {
                e.preventDefault();
                printWithCanvas();
            }
        };
        window.addEventListener("keydown", handleKeyDown);
        return () => window.removeEventListener("keydown", handleKeyDown);
    }, [orderItems]);

    const orderStatusConfig = getOrderStatusConfig(order.status);
    const breadcrumbItems = [
        {
            title: "الطلبات",
        },
        {
            title: `طلب رقم ${order.order_number}`,
        },
    ];

    const paymentItems = [
        {
            key: "1",
            label: "المجموع",
            children: formatCurrency(totals.subTotal),
        },
        {
            key: "2",
            label: "الضريبة",
            children: formatCurrency(totals.tax),
        },
        {
            key: "3",
            label: "الخدمة",
            children: formatCurrency(totals.service),
        },
        {
            key: "4",
            label: "الخصم",
            children: formatCurrency(Number(totals.discount)),
        },
        {
            key: "5",
            label: "الاجمالي",
            children: formatCurrency(totals.total),
        },
    ];

    return (
        <CashierLayout title={`إدارة الطلب ${order.order_number}`}>
            <Head title={`إدارة الطلب ${order.order_number}`} />

            <div className="p-4">
                <Badge.Ribbon
                    color={orderStatusConfig.color}
                    text={orderStatusConfig.text}
                >
                    <div className="isolate flex gap-4 items-center">
                        <Button
                            onClick={() => router.get("/orders#" + order.type)}
                            size="large"
                            type="primary"
                            icon={<RightOutlined />}
                        />
                        <Breadcrumb
                            className="text-2xl"
                            separator=">"
                            items={breadcrumbItems}
                        />
                    </div>
                </Badge.Ribbon>

                <Row gutter={[16, 16]} className="mt-8">
                    <Col span={8}>
                        <div className="isolate grid grid-cols-2 gap-4">
                            <Button
                                onClick={printWithCanvas}
                                size="large"
                                icon={<PrinterOutlined />}
                            >
                                طباعة الفاتورة
                            </Button>
                            <Button
                                disabled={orderCancelled}
                                onClick={printInKitchen}
                                size="large"
                                icon={<PrinterOutlined />}
                            >
                                طباعة في المطبخ
                            </Button>
                            <Button
                                onClick={() => setIsCustomerModalOpen(true)}
                                disabled={orderCancelled}
                                size="large"
                                className={isDelivery ? "" : "col-span-2"}
                                icon={<UserAddOutlined />}
                            >
                                بيانات العميل
                            </Button>
                            {isDelivery && (
                                <Button
                                    onClick={() => setIsDriverModalOpen(true)}
                                    disabled={orderCancelled}
                                    size="large"
                                    icon={<UserAddOutlined />}
                                >
                                    بيانات السائق
                                </Button>
                            )}
                            <Button
                                onClick={() => setIsOrderNotesModalOpen(true)}
                                disabled={orderCancelled}
                                size="large"
                                icon={<EditOutlined />}
                            >
                                ملاحظات الطلب
                            </Button>
                            <Button
                                disabled={disableAllControls}
                                onClick={() =>
                                    setIsChangeOrderTypeModalOpen(true)
                                }
                                size="large"
                                icon={<EditOutlined />}
                            >
                                تغيير الطلب الى
                            </Button>
                            <IsAdmin>
                                <Button
                                    disabled={disableAllControls}
                                    onClick={() =>
                                        save(() =>
                                            setIsOrderDiscountModalOpen(true)
                                        )
                                    }
                                    size="large"
                                    icon={<PercentageOutlined />}
                                    className="col-span-2"
                                >
                                    خصم
                                </Button>
                            </IsAdmin>
                            <Button
                                disabled={disableAllControls}
                                onClick={() => save()}
                                size="large"
                                icon={<SaveOutlined />}
                                type="primary"
                            >
                                حفظ
                            </Button>
                            <Button
                                disabled={disableAllControls}
                                onClick={tryCompleteOrder}
                                size="large"
                                icon={<CheckCircleOutlined />}
                                type="primary"
                            >
                                انهاء الطلب
                            </Button>
                            {orderCompleted && (
                                <IsAdmin>
                                    <Popconfirm
                                        title="هل انت متأكد من الغاء الطلب؟"
                                        okText="نعم"
                                        cancelText="لا"
                                        onConfirm={cancelOrder}
                                    >
                                        <Button
                                            className="col-span-2"
                                            disabled={orderCancelled}
                                            size="large"
                                            danger
                                        >
                                            الغاء
                                        </Button>
                                    </Popconfirm>
                                </IsAdmin>
                            )}
                        </div>

                        <div className="isolate mt-4">
                            <Typography.Title className="mt-0" level={5}>
                                تفاصيل الطلب
                            </Typography.Title>
                            {orderItems.length === 0 && (
                                <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} />
                            )}
                            {orderItems.map((orderItem) => (
                                <OrderItem
                                    key={orderItem.product_id}
                                    orderItem={orderItem}
                                    dispatch={dispatch}
                                    disabled={disableAllControls}
                                    user={user}
                                />
                            ))}
                            <Divider />
                            <Descriptions
                                bordered
                                title="الحساب"
                                column={1}
                                items={paymentItems}
                            />
                        </div>
                    </Col>
                    <Col span={16}>
                        <Categories
                            disabled={disableAllControls}
                            categories={categories}
                            dispatch={dispatch}
                            user={user}
                        />
                    </Col>
                </Row>

                {/* Modals */}
                <CustomerModal
                    open={isCustomerModalOpen}
                    onCancel={() => setIsCustomerModalOpen(false)}
                    order={order}
                />
                <DriverModal
                    open={isDriverModalOpen}
                    onCancel={() => setIsDriverModalOpen(false)}
                    order={order}
                />
                <OrderNotesModal
                    open={isOrderNotesModalOpen}
                    onCancel={() => setIsOrderNotesModalOpen(false)}
                    order={order}
                />
                <ChangeOrderTypeModal
                    open={isChangeOrderTypeModalOpen}
                    onCancel={() => setIsChangeOrderTypeModalOpen(false)}
                    order={order}
                />
                <OrderDiscountModal
                    open={isOrderDiscountModalOpen}
                    onCancel={() => setIsOrderDiscountModalOpen(false)}
                    order={order}
                />
                <PaymentModal
                    open={isPaymentModalOpen}
                    onCancel={() => setIsPaymentModalOpen(false)}
                    order={order}
                    orderItems={orderItems}
                    onSkipCustomerInfo={skipCustomerInfo}
                />
                <PrintInKitchenModal
                    open={isPrintInKitchenModalOpen}
                    onCancel={() => setIsPrintInKitchenModalOpen(false)}
                    order={order}
                    orderItems={orderItems}
                />
            </div>
        </CashierLayout>
    );
}
