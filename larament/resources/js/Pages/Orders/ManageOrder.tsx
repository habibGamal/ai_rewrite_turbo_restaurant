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
    message,
    Popconfirm,
    Row,
    Tag,
    Tooltip,
    Typography,
} from "antd";
import {
    CheckCircleOutlined,
    ClockCircleOutlined,
    EditOutlined,
    PercentageOutlined,
    PlusCircleOutlined,
    PrinterOutlined,
    RightOutlined,
    SaveOutlined,
    ShopOutlined,
    TeamOutlined,
    ToolOutlined,
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
import Categories, { productsForOrder } from "@/Components/Orders/Categories";
import OrderItem from "@/Components/Orders/OrderItem";
import CustomerModal from "@/Components/Orders/CustomerModal";
import DriverModal from "@/Components/Orders/DriverModal";
import OrderNotesModal from "@/Components/Orders/OrderNotesModal";
import OrderDiscountModal from "@/Components/Orders/OrderDiscountModal";
import ChangeOrderTypeModal from "@/Components/Orders/ChangeOrderTypeModal";
import PaymentModal from "@/Components/Orders/PaymentModal";
import GuestsCountModal from "@/Components/Orders/GuestsCountModal";
import ExternalOrders from "@/Components/Orders/ExternalOrders";
import OrderEquipment from "@/Components/Orders/OrderEquipment";
import OrderFees from "@/Components/Orders/OrderFees";
import PrintInKitchenModal from "@/Components/Orders/PrintInKitchenModal";
import PrintChangesModal, { detectChangedItems } from "@/Components/Orders/PrintChangesModal";
import CanAccess from "@/Components/CanAccess";
import IsAdmin from "@/Components/IsAdmin";
import LoadingButton from "@/Components/LoadingButton";
import IfModule from "@/Components/IfModule";
import { useModule } from "@/hooks/useModule";
import { useTerms } from "@/lib/wording";
import axios from "axios";
import { useBarcodeScanner } from "@/hooks/useBarcodeScanner";

export default function ManageOrder({
    order,
    orderDisplay,
    changeTypeOptions,
    categories,
    drivers,
    regions,
    externalOrders,
    sellers,
    guestDue,
    canAddExternalOrder: typeAllowsExternalOrder,
    canAddFees: typeAllowsFees = false,
    feeSuggestions = [],
    credit,
    equipmentLines = [],
    equipmentOptions = [],
}: ManageOrderProps) {
    const { auth, features, receiptFooter } =
        usePage<ManageOrderProps>().props;
    const restaurantEnabled = useModule("restaurant");
    const deliveryEnabled = useModule("delivery");
    const externalSellersEnabled = useModule("external_sellers");
    const terms = useTerms();
    const outsourcedFeatureEnabled = externalSellersEnabled && Boolean(features?.outsourced_orders);
    const equipmentFeatureEnabled = Boolean(features?.equipment);
    const user = auth.user;
    const hasPermission = (permission: string) =>
        Boolean(
            user?.isAdmin ||
                user?.roles?.includes("admin") ||
                user?.permissions?.includes(permission)
        );
    const { modal } = App.useApp();
    // The server resolves the code (extra barcodes, pack sizes, scale labels);
    // the item is only added if it is on this order type's menu.
    const barcodeScanner = async (symbol: string) => {
        const code = symbol.trim();
        if (!code) return;
        try {
            const { data } = await axios.get<{
                found: boolean;
                product_id: number;
                quantity: number;
            }>(route("products.lookup"), { params: { code } });
            const product = scannableProducts.find(
                (p) => p.id === data.product_id
            );
            if (!product) {
                message.warning("هذا المنتج غير متاح لهذا النوع من الطلبات");
                return;
            }
            dispatch({
                type: "addScanned",
                product,
                quantity: Number(data.quantity) || 1,
                user,
            });
        } catch (error: any) {
            if (error?.response?.status === 404) {
                message.warning(`لا يوجد منتج بالباركود ${code}`);
            } else {
                message.error("تعذر البحث عن الباركود");
            }
        }
    };
    // Only fast scanner bursts on the order screen itself; typing in fields
    // or dialogs (fees, notes, discounts...) is never a scan.
    useBarcodeScanner(barcodeScanner, { maxDelay: 50 });

    // Names of items already on the order still resolve after a type change hides their category.
    const products = productsForOrder(categories, order.type, true);
    const scannableProducts = productsForOrder(categories, order.type, false);
    // A product deactivated after it was added (e.g. a retired variant SKU) is no
    // longer on the menu, so fall back to the order item's own product row.
    const initOrderItems: OrderItemData[] = order.items.map((orderItem) => {
        const product =
            products.find((p) => p.id === orderItem.product_id) ??
            orderItem.product;

        return {
            product_id: orderItem.product_id,
            name: product?.name || "",
            price: parseFloat(orderItem.price.toString()),
            quantity: orderItem.quantity,
            notes: orderItem.notes,
            initial_quantity: orderItem.quantity,
            item_discount: orderItem.item_discount || 0,
            item_discount_type: orderItem.item_discount_type,
            item_discount_percent: orderItem.item_discount_percent,
            product: product!,
            kitchen_status: orderItem.kitchen_status,
        };
    });

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
    const [isExternalOrderModalOpen, setIsExternalOrderModalOpen] =
        useState(false);
    const [isEquipmentModalOpen, setIsEquipmentModalOpen] = useState(false);
    const [isFeesModalOpen, setIsFeesModalOpen] = useState(false);
    const [isPaymentModalOpen, setIsPaymentModalOpen] = useState(false);
    const [isGuestsModalOpen, setIsGuestsModalOpen] = useState(false);
    const [payAfterGuests, setPayAfterGuests] = useState(false);
    const [isGuestsSaving, setIsGuestsSaving] = useState(false);
    const guestsMode = order.guests_mode ?? "off";
    const [isPrintInKitchenModalOpen, setIsPrintInKitchenModalOpen] =
        useState(false);
    const [isPrintChangesModalOpen, setIsPrintChangesModalOpen] =
        useState(false);
    const [pendingChangedItems, setPendingChangedItems] = useState<ReturnType<typeof detectChangedItems>>([]);
    const [isChangesLoading, setIsChangesLoading] = useState(false);

    useEffect(() => {
        dispatch({ type: "init", orderItems: initOrderItems, user });
    }, [order.items]);

    useEffect(() => {
        if (!window.Echo || !order.shift_id) {
            return;
        }

        const channel = window.Echo.private(`shift.${order.shift_id}`);
        const handleKitchenUpdate = (data: { ticket_id: number; order_number: number; reason: string }) => {
            if (String(data.order_number) === String(order.order_number)) {
                router.reload({ only: ['order'] });
            }
        };

        channel.listen('.KitchenTicketUpdated', handleKitchenUpdate);

        return () => {
            channel.stopListening('.KitchenTicketUpdated', handleKitchenUpdate);
        };
    }, [order.shift_id, order.order_number]);

    // Order state checks
    const disableAllControls = order.status !== "processing";
    const orderCancelled = order.status === "cancelled";
    const orderInProcess = order.status === "processing";
    const orderCompleted = order.status === "completed";
    const canAddExternalOrder =
        outsourcedFeatureEnabled &&
        !disableAllControls &&
        typeAllowsExternalOrder;
    const canEditEquipment =
        equipmentFeatureEnabled && hasPermission("order.add_equipment");
    const canAddEquipment =
        canEditEquipment &&
        !disableAllControls &&
        (equipmentOptions.length > 0 || equipmentLines.length > 0);
    const canAddFees = typeAllowsFees && !disableAllControls;
    const orderFees = order.fees ?? [];
    const showDriver = deliveryEnabled && (orderDisplay?.showDriver ?? false);
    const orderWithDisplay = {
        ...order,
        display: order.display ?? orderDisplay,
    };

    // Calculate totals
    const totals = calculateOrderTotals(orderWithDisplay, orderItems);

    // Check if using item-level discounts
    const hasItemDiscounts = orderItems.some(item => (item.item_discount ?? 0) > 0);
    const hasOrderDiscount = order.discount > 0 || order.temp_discount_percent > 0;

    const save = (
        callback: (page: any) => void = () => {},
        finish: () => void
    ) => {
        const itemsForApi = orderItems.map((item) => ({
            product_id: item.product_id,
            quantity: item.quantity,
            price: item.price,
            notes: item.notes || null,
            item_discount: item.item_discount || 0,
            item_discount_type: item.item_discount_type || null,
            item_discount_percent: item.item_discount_percent || null,
        }));

        router.post(
            `/orders/save-order/${order.id}`,
            { items: itemsForApi },
            {
                onSuccess: (page) => {
                    message.success("تم حفظ الطلب بنجاح");
                    callback(page);
                },
                onFinish: () => finish(),
            }
        );
    };

    const cancelOrder = () => {
        router.post(`/orders/cancel-order/${order.id}`);
    };

    const handleSave = (finish: () => void) => {
        const changed = detectChangedItems(orderItems, initOrderItems);

        if (restaurantEnabled && changed.length > 0) {
            setPendingChangedItems(changed);
            setIsPrintChangesModalOpen(true);
            finish();
        } else {
            save(undefined, finish);
        }
    };

    const handleSaveOnly = () => {
        setIsChangesLoading(true);
        save(() => setIsPrintChangesModalOpen(false), () => setIsChangesLoading(false));
    };

    const handleSaveAndPrint = () => {
        setIsChangesLoading(true);
        save(() => {
            // Sent without waiting: the cashier keeps working; a failure is shown when the answer comes.
            setIsPrintChangesModalOpen(false);
            message.info(terms.changesSentToKitchen);
            axios
                .post("/print-in-kitchen", {
                    orderId: order.id,
                    type: "changes",
                    items: pendingChangedItems.map((item) => ({
                        product_id: item.product_id,
                        name: item.name,
                        old_quantity: item.old_quantity,
                        new_quantity: item.new_quantity,
                        delta: item.delta,
                        notes: item.notes || null,
                    })),
                })
                .catch((error) => {
                    const serverMessage = axios.isAxiosError<{ message?: string }>(error)
                        ? error.response?.data?.message
                        : undefined;
                    message.error(serverMessage || terms.sendChangesFailed);
                });
        }, () => setIsChangesLoading(false));
    };

    const askForCustomerInfo = (finish: () => void) => {
        modal.confirm({
            title: "هل تريد اضافة بيانات العميل؟",
            icon: <UserAddOutlined />,
            content: 'اضغط على "نعم" لاضافة بيانات العميل',
            okText: "نعم",
            cancelText: "لا",
            onOk: () => setIsCustomerModalOpen(true),
            onCancel: () => skipCustomerInfo(finish),
        });
    };

    const tryCompleteOrder = (finish: () => void) => {
        // if (!order.customer && !customerInfoSkip) {
        //     return save(() => askForCustomerInfo(finish), finish);
        // }
        if (guestsMode === "required" && !order.guests_count) {
            message.warning("يجب إدخال عدد الأفراد قبل إنهاء الطلب");
            setPayAfterGuests(true);
            save(() => setIsGuestsModalOpen(true), finish);
            return;
        }
        payment(finish);
    };

    const openGuestsModal = (finish: () => void) => {
        setPayAfterGuests(false);
        save(() => setIsGuestsModalOpen(true), finish);
    };

    const saveGuests = (guests: number | null) => {
        setIsGuestsSaving(true);
        router.post(
            route("orders.updateGuests", order.id),
            { guests_count: guests },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setIsGuestsModalOpen(false);
                    if (payAfterGuests && guests) {
                        setIsPaymentModalOpen(true);
                    }
                    setPayAfterGuests(false);
                },
                onError: (errors) => {
                    message.error(
                        errors.guests_count || "حدث خطأ أثناء حفظ عدد الأفراد"
                    );
                },
                onFinish: () => setIsGuestsSaving(false),
            }
        );
    };

    const payment = (finish: () => void) => {
        save(() => setIsPaymentModalOpen(true), finish);
    };

    const skipCustomerInfo = (finish: () => void) => {
        setCustomerInfoSkip(true);
        setIsCustomerModalOpen(false);
        payment(finish);
    };

    const printInKitchen = (finish: () => void) => {
        save(() => setIsPrintInKitchenModalOpen(true), finish);
    };

    const printWithCanvas = async (finish: () => void) => {
        if (orderInProcess) {
            save(async (page) => {
                printOrder(page.props.order);
            }, finish);
        } else {
            printOrder(order);
            finish();
        }
    };

    // Keyboard shortcuts
    useEffect(() => {
        const handleKeyDown = (e: KeyboardEvent) => {
            if (e.key === "F8") {
                e.preventDefault();
                // save(undefined,()=>{});
            }
            if (e.key === "F9") {
                e.preventDefault();
                // printWithCanvas(() => {});
            }
        };
        window.addEventListener("keydown", handleKeyDown);
        return () => window.removeEventListener("keydown", handleKeyDown);
    }, [orderItems]);

    const orderStatusConfig = getOrderStatusConfig(order.status);
    const breadcrumbItems = [
        // {
        //     title: "الطلبات",
        // },
        {
            title: `${orderDisplay?.label ?? order.type}${
                orderDisplay?.showTable && order.dine_table_number
                    ? ` ${order.dine_table_number}`
                    : ""
            }`,
        },
        {
            title: `طلب رقم ${order.order_number} `,
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
        ...(totals.equipment > 0 || equipmentLines.length > 0
            ? [
                  {
                      key: "equipment",
                      label: "المعدات",
                      children: formatCurrency(totals.equipment),
                  },
              ]
            : []),
        ...(totals.fees > 0
            ? [
                  {
                      key: "fees",
                      label: "رسوم إضافية",
                      children: formatCurrency(totals.fees),
                  },
              ]
            : []),
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
                    <div className="isolate flex gap-4 items-center flex-wrap">
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
                        {restaurantEnabled && order.kitchen_status && (
                            <Tooltip title={order.kitchen_status === 'ready' ? 'جاهز' : 'قيد التحضير'}>
                                <Tag
                                    color={order.kitchen_status === 'ready' ? 'success' : 'processing'}
                                    icon={order.kitchen_status === 'ready' ? <CheckCircleOutlined /> : <ClockCircleOutlined />}
                                    className="text-sm py-0.5 px-2 !m-0 inline-flex items-center gap-1 cursor-pointer"
                                >
                                    {order.kitchen_progress ? `(${order.kitchen_progress.done} من ${order.kitchen_progress.total})` : ''}
                                </Tag>
                            </Tooltip>
                        )}
                    </div>
                </Badge.Ribbon>

                <Row gutter={[16, 16]} className="mt-8">
                    <Col span={8}>
                        <div className="isolate grid grid-cols-2 gap-4">
                            <LoadingButton
                                onCustomClick={printWithCanvas}
                                size="large"
                                icon={<PrinterOutlined />}
                            >
                                طباعة الفاتورة
                            </LoadingButton>
                            <IfModule name="restaurant">
                                <LoadingButton
                                    disabled={orderCancelled}
                                    onCustomClick={printInKitchen}
                                    size="large"
                                    icon={<PrinterOutlined />}
                                >
                                    {terms.printInKitchen}
                                </LoadingButton>
                            </IfModule>
                            <Button
                                onClick={() => setIsCustomerModalOpen(true)}
                                disabled={orderCancelled}
                                size="large"
                                className={showDriver ? "" : "col-span-2"}
                                icon={<UserAddOutlined />}
                            >
                                بيانات العميل
                            </Button>
                            {showDriver && (
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
                            {guestsMode !== "off" && (
                                <LoadingButton
                                    disabled={disableAllControls}
                                    onCustomClick={openGuestsModal}
                                    size="large"
                                    className="col-span-2"
                                    icon={<TeamOutlined />}
                                >
                                    {order.guests_count
                                        ? `عدد الأفراد: ${order.guests_count}`
                                        : "عدد الأفراد"}
                                </LoadingButton>
                            )}
                            {canAddExternalOrder && (
                                <Button
                                    disabled={disableAllControls}
                                    onClick={() =>
                                        setIsExternalOrderModalOpen(true)
                                    }
                                    size="large"
                                    className="col-span-2"
                                    icon={<ShopOutlined />}
                                >
                                    إضافة طلب خارجي
                                </Button>
                            )}
                            {canAddEquipment && (
                                <Button
                                    disabled={disableAllControls}
                                    onClick={() => setIsEquipmentModalOpen(true)}
                                    size="large"
                                    className="col-span-2"
                                    icon={<ToolOutlined />}
                                >
                                    إضافة معدات
                                </Button>
                            )}
                            {canAddFees && (
                                <CanAccess permission="order.add_fees">
                                    <Button
                                        onClick={() => setIsFeesModalOpen(true)}
                                        size="large"
                                        className="col-span-2"
                                        icon={<PlusCircleOutlined />}
                                    >
                                        رسوم إضافية
                                    </Button>
                                </CanAccess>
                            )}
                            <CanAccess permission="order.apply_discount">
                                <LoadingButton
                                    disabled={disableAllControls || hasItemDiscounts}
                                    onCustomClick={(finish) => {
                                        if (hasItemDiscounts) {
                                            modal.warning({
                                                title: 'تنبيه',
                                                content: 'لا يمكن تطبيق خصم على الطلب عند وجود خصومات على الأصناف. يرجى إزالة خصومات الأصناف أولاً.',
                                            });
                                            finish();
                                        } else {
                                            save(
                                                () =>
                                                    setIsOrderDiscountModalOpen(
                                                        true
                                                    ),
                                                finish
                                            );
                                        }
                                    }}
                                    size="large"
                                    icon={<PercentageOutlined />}
                                    className="col-span-2"
                                >
                                    خصم الطلب
                                </LoadingButton>
                            </CanAccess>
                            <LoadingButton
                                disabled={disableAllControls}
                                onCustomClick={handleSave}
                                size="large"
                                icon={<SaveOutlined />}
                                type="primary"
                            >
                                حفظ
                            </LoadingButton>
                            <LoadingButton
                                disabled={disableAllControls}
                                onCustomClick={tryCompleteOrder}
                                size="large"
                                icon={<CheckCircleOutlined />}
                                type="primary"
                            >
                                انهاء الطلب
                            </LoadingButton>
                            {orderCompleted && (
                                <CanAccess permission="order.cancel">
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
                                </CanAccess>
                            )}
                        </div>

                        <div className="isolate mt-4">
                            <Typography.Title className="mt-0" level={5}>
                                تفاصيل الطلب
                                {hasItemDiscounts && (
                                    <Tag color="orange" className="mr-2">
                                        خصومات على الأصناف
                                    </Tag>
                                )}
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
                            {externalSellersEnabled && (
                            <ExternalOrders
                                orderId={order.id}
                                orders={externalOrders}
                                sellers={sellers}
                                disabled={
                                    disableAllControls ||
                                    !outsourcedFeatureEnabled
                                }
                                featureEnabled={outsourcedFeatureEnabled}
                                open={
                                    outsourcedFeatureEnabled &&
                                    isExternalOrderModalOpen
                                }
                                onClose={() => setIsExternalOrderModalOpen(false)}
                                beforeAction={(action) => save(action, () => {})}
                            />
                            )}
                            <OrderEquipment
                                orderId={order.id}
                                lines={equipmentLines}
                                options={equipmentOptions}
                                disabled={disableAllControls}
                                canEdit={canEditEquipment && !disableAllControls}
                                canRemove={hasPermission("order.remove_equipment")}
                                open={isEquipmentModalOpen}
                                onClose={() => setIsEquipmentModalOpen(false)}
                                beforeAction={(action) => save(action, () => {})}
                            />
                            {(typeAllowsFees || orderFees.length > 0) && (
                                <OrderFees
                                    orderId={order.id}
                                    fees={orderFees}
                                    suggestions={feeSuggestions}
                                    disabled={disableAllControls}
                                    open={canAddFees && isFeesModalOpen}
                                    onClose={() => setIsFeesModalOpen(false)}
                                    beforeAction={(action) => save(action, () => {})}
                                />
                            )}
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
                            orderType={order.type}
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
                <IfModule name="delivery">
                    <DriverModal
                        open={isDriverModalOpen}
                        onCancel={() => setIsDriverModalOpen(false)}
                        order={order}
                    />
                </IfModule>
                <OrderNotesModal
                    open={isOrderNotesModalOpen}
                    onCancel={() => setIsOrderNotesModalOpen(false)}
                    order={order}
                />
                <ChangeOrderTypeModal
                    open={isChangeOrderTypeModalOpen}
                    onCancel={() => setIsChangeOrderTypeModalOpen(false)}
                    order={order}
                    options={changeTypeOptions}
                />
                <OrderDiscountModal
                    open={isOrderDiscountModalOpen}
                    onCancel={() => setIsOrderDiscountModalOpen(false)}
                    order={order}
                />
                {guestsMode !== "off" && (
                    <GuestsCountModal
                        open={isGuestsModalOpen}
                        mode={payAfterGuests ? "required" : guestsMode}
                        initialValue={order.guests_count}
                        okText={payAfterGuests ? "متابعة الدفع" : "حفظ"}
                        skipText="بدون عدد"
                        loading={isGuestsSaving}
                        onCancel={() => {
                            setIsGuestsModalOpen(false);
                            setPayAfterGuests(false);
                        }}
                        onSubmit={saveGuests}
                    />
                )}
                <PaymentModal
                    open={isPaymentModalOpen}
                    onCancel={() => setIsPaymentModalOpen(false)}
                    order={orderWithDisplay}
                    orderItems={orderItems}
                    guestDue={externalOrders.length > 0 ? guestDue : undefined}
                    credit={credit}
                />
                <IfModule name="restaurant">
                    <PrintInKitchenModal
                        open={isPrintInKitchenModalOpen}
                        onCancel={() => setIsPrintInKitchenModalOpen(false)}
                        order={order}
                        orderItems={orderItems}
                    />
                    <PrintChangesModal
                        open={isPrintChangesModalOpen}
                        onCancel={() => setIsPrintChangesModalOpen(false)}
                        onSaveOnly={handleSaveOnly}
                        onSaveAndPrint={handleSaveAndPrint}
                        loading={isChangesLoading}
                        changedItems={pendingChangedItems}
                    />
                </IfModule>
            </div>
        </CashierLayout>
    );
}
