import React, { useCallback, useEffect, useMemo, useReducer, useRef, useState } from "react";
import { usePage } from "@inertiajs/react";
import {
    App,
    Badge,
    Button,
    Col,
    Descriptions,
    Divider,
    Empty,
    Input,
    Row,
    Tag,
    Typography,
    message,
} from "antd";
import type { InputRef } from "antd";
import {
    BarcodeOutlined,
    CheckCircleOutlined,
    ClearOutlined,
    DesktopOutlined,
    EditOutlined,
    FolderOpenOutlined,
    PauseCircleOutlined,
    PercentageOutlined,
    PrinterOutlined,
    RollbackOutlined,
    UserAddOutlined,
} from "@ant-design/icons";
import axios from "axios";

import type {
    CashierTab,
    InstantCartCustomer,
    InstantCartDiscount,
    InstantLastSale,
    OrderItemAction,
    OrderItemData,
    Product,
    ProductGroupTile,
    User,
    VariantSku,
} from "@/types";
import { orderItemsReducer } from "@/utils/orderItemsReducer";
import { formatCurrency } from "@/utils/orderCalculations";
import Categories, { productsForOrder } from "@/Components/Orders/Categories";
import VariantPickerModal from "@/Components/Orders/VariantPickerModal";
import OrderItem from "@/Components/Orders/OrderItem";
import CustomerModal from "@/Components/Orders/CustomerModal";
import OrderDiscountModal from "@/Components/Orders/OrderDiscountModal";
import OrderNotesModal from "@/Components/Orders/OrderNotesModal";
import PaymentModal, { PaymentTenders } from "@/Components/Orders/PaymentModal";
import HeldSalesModal, { HeldSale } from "@/Components/Orders/Instant/HeldSalesModal";
import ReturnsModal, { ReturnResult } from "@/Components/Orders/Instant/ReturnsModal";
import {
    InstantAction,
    PAY_ON_EMPTY_SPACE,
    actionForKey,
    isFunctionKey,
    keyLabel,
    parseScan,
} from "@/Components/Orders/Instant/keymap";
import {
    anotherModalOpen,
    cartTotals,
    errorMessage,
    itemsForApi,
    lineFromProduct,
    loadCart,
    lookupProduct,
    newClientUuid,
    saveCart,
} from "@/Components/Orders/Instant/instantCart";
import { fetchProductsByIds, mergeProducts } from "@/Components/Orders/Instant/remoteCatalog";
import { publishCustomerDisplay } from "@/lib/customerDisplay";
import { printReceipt } from "@/helpers/printTemplate";

interface InstantSaleTabProps {
    tab: CashierTab;
    shiftId?: number;
    /** True while this tab is the visible one: the keyboard map listens only then. */
    active?: boolean;
}

interface SaleResult {
    order_number: string | number;
    subTotal: number;
    discount: number;
    total: number;
    paid: number;
    change: number;
}

interface InstantSaleResponse {
    order: {
        id: number;
        order_number: string | number;
        sub_total: number;
        discount: number;
        total: number;
        paid: number;
        change: number;
        payment_status: string;
    };
    duplicate: boolean;
}

const wait = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms));

const KeyHint = ({ action }: { action: InstantAction }) => (
    <Tag className="!m-0 !text-xs font-mono">{keyLabel(action)}</Tag>
);

export const InstantSaleTab: React.FC<InstantSaleTabProps> = ({ tab, shiftId, active = true }) => {
    const user = (usePage().props as unknown as { auth: { user: User } }).auth.user;
    const { modal } = App.useApp();
    const instant = tab.instant!;
    const typeCode = tab.code ?? "";
    const categories = instant.categories ?? [];
    const can = instant.can ?? { discount: false, changeItems: false, returns: false };
    const canReturn = Boolean(can.returns);

    // A big catalogue arrives as categories only: products found by searching or scanning
    // are kept here so held sales, returns and the cart can use them.
    const remote = Boolean(instant.remoteCatalog);
    const [knownProducts, setKnownProducts] = useState<Product[]>([]);
    const rememberProducts = useCallback(
        (products: Product[]) => setKnownProducts((known) => mergeProducts(known, products)),
        []
    );
    const resolveProduct = useMemo(
        () =>
            remote
                ? async (productId: number): Promise<Product | null> => {
                      const [product] = await fetchProductsByIds(typeCode, [productId]);
                      if (product) rememberProducts([product]);
                      return product ?? null;
                  }
                : undefined,
        [remote, typeCode, rememberProducts]
    );

    const scannableProducts = useMemo(
        () => (remote ? knownProducts : productsForOrder(categories, typeCode, false)),
        [remote, knownProducts, categories, typeCode]
    );
    const allProducts = useMemo(
        () => (remote ? knownProducts : productsForOrder(categories, typeCode, true)),
        [remote, knownProducts, categories, typeCode]
    );

    // ---- Cart state (client side only; no order exists until pay) ----
    const storageKey = `instant-cart:${shiftId ?? "none"}:${tab.key}`;
    const [initialCart] = useState(() => loadCart(storageKey));
    const [items, dispatch] = useReducer(orderItemsReducer, initialCart.items);
    const [discount, setDiscount] = useState<InstantCartDiscount | null>(initialCart.discount);
    const [customer, setCustomer] = useState<InstantCartCustomer | null>(initialCart.customer);
    const [notes, setNotes] = useState<string>(initialCart.notes);
    const [clientUuid, setClientUuid] = useState<string>(initialCart.clientUuid);
    const [heldSaleId, setHeldSaleId] = useState<number | null>(initialCart.heldSaleId);
    const [selectedId, setSelectedId] = useState<number | null>(
        initialCart.items.length ? initialCart.items[initialCart.items.length - 1].product_id : null
    );

    useEffect(() => {
        saveCart(storageKey, { items, discount, customer, notes, clientUuid, heldSaleId });
    }, [storageKey, items, discount, customer, notes, clientUuid, heldSaleId]);

    // ---- Server-fed state, refreshed by Index's partial reloads ----
    const [lastSale, setLastSale] = useState<InstantLastSale | null>(instant.lastSale ?? null);
    const [heldCount, setHeldCount] = useState<number>(instant.heldCount ?? 0);
    useEffect(() => {
        if (instant.lastSale) setLastSale(instant.lastSale);
    }, [instant.lastSale?.id]);
    useEffect(() => {
        setHeldCount(instant.heldCount ?? 0);
    }, [instant.heldCount]);

    const [lastResult, setLastResult] = useState<SaleResult | null>(null);

    // ---- Modals ----
    const [customerOpen, setCustomerOpen] = useState(false);
    const [notesOpen, setNotesOpen] = useState(false);
    const [discountOpen, setDiscountOpen] = useState(false);
    const [heldOpen, setHeldOpen] = useState(false);
    const [returnsOpen, setReturnsOpen] = useState(false);
    const [paymentOpen, setPaymentOpen] = useState(false);

    // ---- Scan field ----
    const scanRef = useRef<InputRef>(null);
    const categoriesRef = useRef<HTMLDivElement>(null);
    const listRef = useRef<HTMLDivElement>(null);
    const [scan, setScan] = useState("");

    const focusScan = useCallback(() => {
        setTimeout(() => scanRef.current?.focus(), 0);
    }, []);

    useEffect(() => {
        if (active) focusScan();
    }, [active]);

    // Keep a valid selection.
    useEffect(() => {
        if (items.length === 0) {
            if (selectedId !== null) setSelectedId(null);
            return;
        }
        if (selectedId === null || !items.some((item) => item.product_id === selectedId)) {
            setSelectedId(items[items.length - 1].product_id);
        }
    }, [items, selectedId]);

    useEffect(() => {
        if (selectedId === null) return;
        listRef.current
            ?.querySelector(`[data-line="${selectedId}"]`)
            ?.scrollIntoView({ block: "nearest" });
    }, [selectedId]);

    const totals = cartTotals(items, discount);
    const hasItemDiscounts = items.some((item) => (item.item_discount ?? 0) > 0);

    /** The one place products enter the cart: tiles, scans and any variant picker. */
    const addProductToCart = useCallback(
        (product: Product, quantity: number = 1) => {
            dispatch({ type: "addScanned", product, quantity, user });
            setSelectedId(product.id);
            setLastResult(null);
            focusScan();
        },
        [user, focusScan]
    );

    // Categories (and whatever picker it grows) dispatch "add"; route it through addProductToCart.
    const categoriesDispatch = useCallback<React.Dispatch<OrderItemAction>>(
        (action) => {
            if (action.type === "add") {
                addProductToCart(action.orderItem.product, action.orderItem.quantity || 1);
                return;
            }
            dispatch(action);
        },
        [addProductToCart]
    );

    // ---- Swap a variant on a cart line (other size or colour, same quantity) ----
    const groupTiles = useMemo<ProductGroupTile[]>(
        () => categories.flatMap((category) => [
            ...(category.groups ?? []),
            ...(category.children ?? []).flatMap((child) => child.groups ?? []),
        ]),
        [categories]
    );
    const groupOf = useCallback(
        (productId: number) => groupTiles.find((group) => group.skus.some((sku) => sku.id === productId)) ?? null,
        [groupTiles]
    );
    const [swapLine, setSwapLine] = useState<OrderItemData | null>(null);
    const swapVariant = (sku: VariantSku) => {
        if (!swapLine) return;
        if (sku.id !== swapLine.product_id) {
            dispatch({ type: "swap", id: swapLine.product_id, product: sku, user });
            setSelectedId(sku.id);
        }
        setSwapLine(null);
        focusScan();
    };

    const submitScan = async () => {
        const { quantity, code } = parseScan(scan);
        if (!code) return;
        setScan("");
        const result = await lookupProduct(code, scannableProducts, resolveProduct);
        if (!result.ok) {
            if (result.message) message.warning(result.message);
            focusScan();
            return;
        }
        addProductToCart(result.product, result.quantity * quantity);
    };

    const resetCart = () => {
        dispatch({ type: "init", orderItems: [], user });
        setDiscount(null);
        setCustomer(null);
        setNotes("");
        setHeldSaleId(null);
        setClientUuid(newClientUuid());
        setSelectedId(null);
        setScan("");
    };

    // ---- Actions ----
    const reprintLast = () => {
        if (!lastSale) {
            message.warning("لا توجد فاتورة سابقة");
            return;
        }
        printReceipt(lastSale.id);
        focusScan();
    };

    const holdSale = async () => {
        if (items.length === 0) {
            message.warning("السلة فارغة");
            return;
        }
        try {
            await axios.post(route("pos.held-sales.store"), {
                label: customer?.name || undefined,
                type: typeCode,
                items: itemsForApi(items),
                meta: { discount, customer, notes: notes || null },
            });
            let count = heldCount + 1;
            if (heldSaleId) {
                // Re-holding a resumed sale replaces the old copy.
                await axios.delete(route("pos.held-sales.destroy", heldSaleId)).then(
                    () => (count -= 1),
                    () => undefined
                );
            }
            setHeldCount(count);
            resetCart();
            message.success("تم تعليق البيع");
        } catch (error) {
            message.error(errorMessage(error, "تعذر تعليق البيع"));
        } finally {
            focusScan();
        }
    };

    const loadHeld = async (held: HeldSale) => {
        let available = allProducts;
        if (remote) {
            const missingIds = (held.lines ?? [])
                .map((line) => Number(line.product_id))
                .filter((id) => !available.some((p) => p.id === id));
            try {
                const fetched = await fetchProductsByIds(typeCode, missingIds);
                rememberProducts(fetched);
                available = mergeProducts(available, fetched);
            } catch (error) {
                message.error(errorMessage(error, "تعذر تحميل أصناف البيع المعلق"));
                return;
            }
        }

        const lines: OrderItemData[] = [];
        let missing = 0;
        (held.lines ?? []).forEach((line) => {
            const product = available.find((p) => p.id === Number(line.product_id));
            if (!product) {
                missing += 1;
                return;
            }
            lines.push(
                lineFromProduct(product, Number(line.quantity), {
                    notes: line.notes ?? "",
                    item_discount: Number(line.item_discount ?? 0) || 0,
                    item_discount_type: line.item_discount_type ?? undefined,
                    item_discount_percent: line.item_discount_percent ?? undefined,
                })
            );
        });
        dispatch({ type: "init", orderItems: lines, user });
        setDiscount(held.meta?.discount ?? null);
        setCustomer(held.meta?.customer ?? null);
        setNotes(held.meta?.notes ?? "");
        setHeldSaleId(held.id);
        setClientUuid(newClientUuid());
        setSelectedId(lines.length ? lines[lines.length - 1].product_id : null);
        setLastResult(null);
        setHeldOpen(false);
        if (missing > 0) {
            message.warning(`تعذر تحميل ${missing} صنف غير متاح حالياً`);
        }
        focusScan();
    };

    const resumeHeld = (held: HeldSale) => {
        if (items.length === 0) {
            loadHeld(held);
            return;
        }
        modal.confirm({
            title: "استبدال السلة الحالية؟",
            content: "السلة الحالية ستُمسح. علّقها أولاً إذا أردت الاحتفاظ بها.",
            okText: "استبدال",
            cancelText: "إلغاء",
            onOk: () => loadHeld(held),
            afterClose: focusScan,
        });
    };

    const openDiscount = () => {
        if (!can.discount) return;
        if (hasItemDiscounts) {
            modal.warning({
                title: "تنبيه",
                content: "لا يمكن تطبيق خصم على الطلب عند وجود خصومات على الأصناف. يرجى إزالة خصومات الأصناف أولاً.",
                afterClose: focusScan,
            });
            return;
        }
        setDiscountOpen(true);
    };

    const clearCart = () => {
        if (items.length === 0) {
            resetCart();
            focusScan();
            return;
        }
        modal.confirm({
            title: "مسح السلة؟",
            content: "سيتم حذف كل الأصناف من السلة.",
            okText: "مسح",
            okButtonProps: { danger: true },
            cancelText: "إلغاء",
            onOk: resetCart,
            afterClose: focusScan,
        });
    };

    const openPayment = () => {
        if (items.length === 0) {
            message.warning("السلة فارغة");
            return;
        }
        setPaymentOpen(true);
    };

    const confirmPayment = async (tenders: PaymentTenders, print: boolean): Promise<boolean> => {
        const paid = tenders.cash + tenders.card + tenders.talabat_card;
        if (paid < totals.total && instant.credit?.require_customer_for_credit && !customer) {
            message.error("يجب اختيار العميل للبيع الآجل");
            return false;
        }
        const body = {
            client_uuid: clientUuid,
            type: typeCode,
            items: itemsForApi(items),
            discount: discount ?? undefined,
            customer_id: customer?.id ?? null,
            order_notes: notes || null,
            tenders,
            // The receipt is sent separately after the sale, so the cashier never waits for the printer.
            print: false,
            held_sale_id: heldSaleId,
        };
        // Network failures retry with the same client_uuid, so the server never books twice.
        for (let attempt = 0; attempt < 3; attempt++) {
            try {
                const { data } = await axios.post<InstantSaleResponse>(route("pos.instant-sales.store"), body);
                const order = data.order;
                setLastSale({
                    id: order.id,
                    order_number: Number(order.order_number),
                    total: Number(order.total),
                    change: Number(order.change),
                });
                setLastResult({
                    order_number: order.order_number,
                    subTotal: Number(order.sub_total),
                    discount: Number(order.discount),
                    total: Number(order.total),
                    paid: Number(order.paid),
                    change: Number(order.change),
                });
                if (heldSaleId) setHeldCount((count) => Math.max(0, count - 1));
                resetCart();
                if (data.duplicate) {
                    message.info(`الفاتورة #${order.order_number} مسجلة بالفعل`);
                } else {
                    message.success(`تم البيع - فاتورة #${order.order_number}`);
                    if (print) printReceipt(order.id);
                }
                focusScan();
                return true;
            } catch (error: any) {
                if (error?.response) {
                    message.error(errorMessage(error, "تعذر إتمام البيع"));
                    return false;
                }
                if (attempt < 2) {
                    await wait(700 * (attempt + 1));
                    continue;
                }
                message.error("تعذر الاتصال بالخادم، السلة محفوظة. حاول مرة أخرى");
                return false;
            }
        }
        return false;
    };

    const onReturnDone = (result: ReturnResult) => {
        if (result.exchange_order) {
            setLastSale({
                id: result.exchange_order.id,
                order_number: Number(result.exchange_order.order_number),
                total: Number(result.exchange_order.total),
                change: Number(result.exchange_order.change),
            });
        }
        focusScan();
    };

    const moveSelection = (delta: number) => {
        if (items.length === 0) return;
        const index = items.findIndex((item) => item.product_id === selectedId);
        const next = Math.min(items.length - 1, Math.max(0, (index < 0 ? items.length - 1 : index) + delta));
        setSelectedId(items[next].product_id);
    };

    const runAction = (action: InstantAction) => {
        switch (action) {
            case "focusSearch": {
                const search = categoriesRef.current?.querySelector<HTMLInputElement>("input.ant-input");
                search?.focus();
                search?.select();
                return;
            }
            case "discount":
                return openDiscount();
            case "customer":
                return setCustomerOpen(true);
            case "notes":
                return setNotesOpen(true);
            case "hold":
                return void holdSale();
            case "heldList":
                return setHeldOpen(true);
            case "returns":
                if (canReturn) setReturnsOpen(true);
                return;
            case "pay":
                return openPayment();
            case "clearCart":
                return clearCart();
            case "incrementLine":
                if (selectedId !== null) dispatch({ type: "increment", id: selectedId, user });
                return;
            case "decrementLine":
                if (selectedId !== null) dispatch({ type: "decrement", id: selectedId, user });
                return;
            case "selectPrev":
                return moveSelection(-1);
            case "selectNext":
                return moveSelection(1);
            case "deleteLine":
                if (selectedId !== null) dispatch({ type: "delete", id: selectedId, user });
                return;
        }
    };

    // ---- Keyboard map: one window listener, reading the latest state through a ref ----
    const keyHandler = useRef<(e: KeyboardEvent) => void>(() => undefined);
    keyHandler.current = (e: KeyboardEvent) => {
        if (!active || anotherModalOpen()) return;

        const target = e.target as HTMLElement | null;
        const scanInput = scanRef.current?.input ?? null;
        const inScan = target !== null && target === scanInput;
        const inOtherField =
            !inScan &&
            target !== null &&
            (target.tagName === "INPUT" ||
                target.tagName === "TEXTAREA" ||
                target.tagName === "SELECT" ||
                target.isContentEditable);
        const scanEmpty = scan.trim() === "";
        const action = actionForKey(e.key);

        if (isFunctionKey(e.key)) {
            e.preventDefault();
            if (action) runAction(action);
            return;
        }

        if (inOtherField) {
            // e.g. the product search: Esc hands focus back to the scan field.
            if (e.key === "Escape") {
                e.preventDefault();
                focusScan();
            }
            return;
        }

        const onScanOrPage = inScan || target === document.body || target === null;

        if (e.key === " " && PAY_ON_EMPTY_SPACE && onScanOrPage && scanEmpty) {
            e.preventDefault();
            openPayment();
            return;
        }

        switch (action) {
            case "clearCart":
                e.preventDefault();
                if (!scanEmpty) {
                    setScan("");
                } else {
                    clearCart();
                }
                return;
            case "selectPrev":
            case "selectNext":
                e.preventDefault();
                runAction(action);
                return;
            case "incrementLine":
            case "decrementLine":
            case "deleteLine":
                if (scanEmpty) {
                    e.preventDefault();
                    runAction(action);
                }
                return;
        }

        // Typing while focus sits on a button or the page goes to the scan field.
        if (!inScan && e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) {
            scanRef.current?.focus();
        }
    };

    useEffect(() => {
        if (!active) return;
        const listener = (e: KeyboardEvent) => keyHandler.current(e);
        window.addEventListener("keydown", listener);
        return () => window.removeEventListener("keydown", listener);
    }, [active]);

    // ---- Customer display (second monitor): what the customer sees right now ----
    useEffect(() => {
        if (!active) return;
        if (items.length > 0) {
            publishCustomerDisplay({
                status: "cart",
                lines: items.map((item) => ({
                    name: item.name,
                    quantity: Number(item.quantity),
                    price: Number(item.price),
                    total: Number(item.price) * Number(item.quantity) - Number(item.item_discount ?? 0),
                })),
                subTotal: Number(totals.subTotal),
                discount: Number(totals.discount),
                total: Number(totals.total),
            });
        } else if (lastResult) {
            publishCustomerDisplay({
                status: "paid",
                orderNumber: Number(lastResult.order_number),
                total: lastResult.total,
                paid: lastResult.paid,
                change: lastResult.change,
            });
        } else {
            publishCustomerDisplay({ status: "idle" });
        }
    }, [active, items, totals.subTotal, totals.discount, totals.total, lastResult]);

    const openCustomerDisplay = () => {
        window.open(route("pos.customer-display"), "pos-customer-display", "popup,width=1024,height=768");
        focusScan();
    };

    // ---- Render ----
    const showLastResult = items.length === 0 && lastResult !== null;
    const paymentItems = showLastResult
        ? [
              { key: "1", label: "المجموع", children: formatCurrency(lastResult!.subTotal) },
              { key: "2", label: "الخصم", children: formatCurrency(lastResult!.discount) },
              { key: "3", label: "الاجمالي", children: formatCurrency(lastResult!.total) },
              { key: "4", label: "المدفوع", children: formatCurrency(lastResult!.paid) },
              {
                  key: "5",
                  label: "الباقي",
                  children: (
                      <Typography.Text strong className="text-lg">
                          {formatCurrency(lastResult!.change)}
                      </Typography.Text>
                  ),
              },
          ]
        : [
              { key: "1", label: "المجموع", children: formatCurrency(totals.subTotal) },
              {
                  key: "2",
                  label: discount?.type === "percent" ? `الخصم (${discount.value}%)` : "الخصم",
                  children: formatCurrency(Number(totals.discount)),
              },
              { key: "3", label: "الاجمالي", children: formatCurrency(totals.total) },
          ];

    const buttonLabel = (label: string, action?: InstantAction) => (
        <span className="inline-flex items-center gap-2">
            {label}
            {action && <KeyHint action={action} />}
        </span>
    );

    return (
        <div>
            <Badge.Ribbon color="green" text="بيع مباشر">
                <div className="isolate flex gap-4 items-center flex-wrap min-h-[40px]">
                    <Typography.Title level={4} className="!m-0">
                        {tab.label}
                    </Typography.Title>
                    <Typography.Text type="secondary" data-testid="instant-last-receipt">
                        آخر فاتورة: {lastSale ? `#${lastSale.order_number}` : "—"}
                    </Typography.Text>
                    {customer && (
                        <Tag
                            color="blue"
                            closable
                            onClose={(e) => {
                                e.preventDefault();
                                setCustomer(null);
                                focusScan();
                            }}
                        >
                            العميل: {customer.name} {customer.phone}
                        </Tag>
                    )}
                    {notes && (
                        <Tag
                            color="gold"
                            closable
                            className="max-w-[320px] truncate"
                            title={notes}
                            onClose={(e) => {
                                e.preventDefault();
                                setNotes("");
                                focusScan();
                            }}
                        >
                            ملاحظات: {notes}
                        </Tag>
                    )}
                    {heldSaleId && <Tag color="orange">فاتورة معلقة مستكملة</Tag>}
                    <Button type="link" icon={<DesktopOutlined />} onClick={openCustomerDisplay}>
                        شاشة العميل
                    </Button>
                </div>
            </Badge.Ribbon>

            <Row gutter={[16, 16]} className="mt-8">
                <Col span={8}>
                    <div className="isolate grid grid-cols-2 gap-4">
                        <Button size="large" icon={<PrinterOutlined />} disabled={!lastSale} onClick={reprintLast}>
                            إعادة طباعة آخر فاتورة
                        </Button>
                        <Button size="large" icon={<PauseCircleOutlined />} disabled={items.length === 0} onClick={holdSale}>
                            {buttonLabel("تعليق البيع", "hold")}
                        </Button>
                        <Button size="large" icon={<UserAddOutlined />} onClick={() => setCustomerOpen(true)}>
                            {buttonLabel("بيانات العميل", "customer")}
                        </Button>
                        <Button size="large" icon={<EditOutlined />} onClick={() => setNotesOpen(true)}>
                            {buttonLabel("ملاحظات الطلب", "notes")}
                        </Button>
                        {/* Salesperson slot (future) will sit beside the customer and notes buttons. */}
                        <Button
                            size="large"
                            className={canReturn ? "" : "col-span-2"}
                            icon={<FolderOpenOutlined />}
                            onClick={() => setHeldOpen(true)}
                        >
                            {buttonLabel(`الفواتير المعلقة${heldCount ? ` (${heldCount})` : ""}`, "heldList")}
                        </Button>
                        {canReturn && (
                            <Button size="large" icon={<RollbackOutlined />} onClick={() => setReturnsOpen(true)}>
                                {buttonLabel("مرتجع / استبدال", "returns")}
                            </Button>
                        )}
                        {can.discount && (
                            <Button
                                size="large"
                                className="col-span-2"
                                icon={<PercentageOutlined />}
                                disabled={hasItemDiscounts}
                                onClick={openDiscount}
                            >
                                {buttonLabel("خصم", "discount")}
                            </Button>
                        )}
                        <Button size="large" type="primary" icon={<ClearOutlined />} onClick={clearCart}>
                            {buttonLabel("مسح السلة", "clearCart")}
                        </Button>
                        <Button
                            size="large"
                            type="primary"
                            icon={<CheckCircleOutlined />}
                            disabled={items.length === 0}
                            onClick={openPayment}
                        >
                            {buttonLabel("دفع", "pay")}
                        </Button>
                    </div>

                    <div className="isolate mt-4">
                        <Input
                            ref={scanRef}
                            size="large"
                            className="mb-4"
                            prefix={<BarcodeOutlined />}
                            placeholder="امسح الباركود أو اكتب الكود (3* للكمية)"
                            value={scan}
                            onChange={(e) => setScan(e.target.value)}
                            onPressEnter={(e) => {
                                e.preventDefault();
                                void submitScan();
                            }}
                            autoComplete="off"
                            data-testid="instant-scan"
                        />
                        <Typography.Title className="mt-0" level={5}>
                            السلة
                            {hasItemDiscounts && (
                                <Tag color="orange" className="mr-2">
                                    خصومات على الأصناف
                                </Tag>
                            )}
                        </Typography.Title>
                        {items.length === 0 && <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} />}
                        <div ref={listRef}>
                            {items.map((item) => (
                                <div
                                    key={item.product_id}
                                    data-line={item.product_id}
                                    onClick={() => setSelectedId(item.product_id)}
                                    className={
                                        item.product_id === selectedId
                                            ? "rounded-md outline outline-2 outline-blue-400"
                                            : "rounded-md"
                                    }
                                >
                                    <OrderItem orderItem={item} dispatch={dispatch} user={user} />
                                    {groupOf(item.product_id) && (
                                        <Button
                                            type="link"
                                            size="small"
                                            onClick={(e) => {
                                                e.stopPropagation();
                                                setSwapLine(item);
                                            }}
                                        >
                                            تغيير المقاس / اللون
                                        </Button>
                                    )}
                                </div>
                            ))}
                        </div>
                        <Divider />
                        <Descriptions
                            bordered
                            title={showLastResult ? `الحساب - فاتورة #${lastResult!.order_number}` : "الحساب"}
                            column={1}
                            items={paymentItems}
                        />
                    </div>
                </Col>
                <Col span={16}>
                    <div ref={categoriesRef}>
                        <Categories
                            categories={categories}
                            orderType={typeCode}
                            dispatch={categoriesDispatch}
                            user={user}
                            remote={remote}
                            onProductsLoaded={rememberProducts}
                        />
                    </div>
                </Col>
            </Row>

            <VariantPickerModal
                group={swapLine ? groupOf(swapLine.product_id) : null}
                onClose={() => {
                    setSwapLine(null);
                    focusScan();
                }}
                onPick={swapVariant}
            />
            <CustomerModal
                open={customerOpen}
                onCancel={() => {
                    setCustomerOpen(false);
                    focusScan();
                }}
                cartCustomer={customer}
                onSelectCustomer={setCustomer}
            />
            <OrderNotesModal
                open={notesOpen}
                onCancel={() => {
                    setNotesOpen(false);
                    focusScan();
                }}
                cartNotes={notes}
                onApply={setNotes}
            />
            <OrderDiscountModal
                open={discountOpen}
                onCancel={() => {
                    setDiscountOpen(false);
                    focusScan();
                }}
                cartDiscount={discount}
                onApply={setDiscount}
            />
            <PaymentModal
                open={paymentOpen}
                onCancel={() => {
                    setPaymentOpen(false);
                    focusScan();
                }}
                cart={{
                    total: totals.total,
                    subTotal: totals.subTotal,
                    discount: Number(totals.discount),
                    credit: instant.credit,
                }}
                onConfirm={confirmPayment}
            />
            <HeldSalesModal
                open={heldOpen}
                onClose={() => {
                    setHeldOpen(false);
                    focusScan();
                }}
                onResume={resumeHeld}
                onCountChange={setHeldCount}
            />
            <ReturnsModal
                open={returnsOpen && canReturn}
                onClose={() => {
                    setReturnsOpen(false);
                    focusScan();
                }}
                products={scannableProducts}
                resolveProduct={resolveProduct}
                typeCode={typeCode}
                user={user}
                canSkipRestock={Boolean(can.returnWithoutRestock)}
                onDone={onReturnDone}
            />
        </div>
    );
};

export default InstantSaleTab;
