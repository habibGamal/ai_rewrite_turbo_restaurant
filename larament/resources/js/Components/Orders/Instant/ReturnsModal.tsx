import React, { useMemo, useReducer, useRef, useState } from "react";
import {
    Alert,
    Button,
    Descriptions,
    Divider,
    Empty,
    Input,
    InputNumber,
    Modal,
    Radio,
    Select,
    Switch,
    Table,
    Tag,
    Typography,
    message,
} from "antd";
import type { InputRef } from "antd";
import { BarcodeOutlined, SearchOutlined } from "@ant-design/icons";
import axios from "axios";
import type { Product, User } from "@/types";
import { orderItemsReducer } from "@/utils/orderItemsReducer";
import { formatCurrency } from "@/utils/orderCalculations";
import OrderItem from "@/Components/Orders/OrderItem";
import {
    Tenders,
    ZERO_TENDERS,
    cartTotals,
    errorMessage,
    itemsForApi,
    lineFromProduct,
    lookupProduct,
    newClientUuid,
} from "./instantCart";
import { parseScan } from "./keymap";

interface ReturnLine {
    id: number;
    product_id: number;
    name: string;
    quantity: number;
    price: number;
    returnable_quantity: number;
    unit_refund: number;
    /** What the customer paid for the whole line after discounts. */
    refundable: number;
    /** Already refunded on this line by earlier returns. */
    refunded: number;
    discounted: boolean;
    /** How refundable was reached, e.g. «100.00 × 2 = 200.00 − خصم الصنف 20.00 = 180.00». */
    explanation: string;
}

const round2 = (value: number) => Math.round(value * 100) / 100;

/** Same rule as the server: a share of the line, and the last units take what is left. */
const lineRefund = (line: ReturnLine, quantity: number): number => {
    if (quantity <= 0 || Number(line.quantity) <= 0) return 0;
    const left = Math.max(0, round2(Number(line.refundable) - Number(line.refunded)));
    if (quantity >= Number(line.returnable_quantity) - 0.0001) return left;
    return Math.min(left, round2((Number(line.refundable) * quantity) / Number(line.quantity)));
};

interface ReturnOrder {
    id: number;
    order_number: string | number;
    reference: string;
    created_at: string;
    age_days: number;
    too_old: boolean;
    age_blocked?: boolean;
    max_days?: number;
    total: number;
    items: ReturnLine[];
}

/** One of several receipts the typed number can mean (receipt numbers restart every shift). */
interface ReturnCandidate {
    id: number;
    order_number: number;
    reference: string;
    created_at: string;
    cashier: string | null;
    total: number;
    current_shift: boolean;
    too_old: boolean;
}

export interface ReturnResult {
    return: { id: number; total_refund: number };
    exchange_order: {
        id: number;
        order_number: string | number;
        sub_total: number;
        discount: number;
        total: number;
        paid: number;
        change: number;
        payment_status: string;
    } | null;
    net: number;
}

interface ReturnsModalProps {
    open: boolean;
    onClose: () => void;
    products: Product[];
    /** Big catalogues: fetches a scanned product the screen has not loaded yet. */
    resolveProduct?: (productId: number) => Promise<Product | null>;
    typeCode: string;
    user: User;
    /** order.return_without_restock: may mark a line as damaged (not back to stock). */
    canSkipRestock?: boolean;
    onDone: (result: ReturnResult) => void;
}

const formatDate = (value: string) =>
    new Date(value).toLocaleString("ar-EG", { dateStyle: "short", timeStyle: "short" });

export default function ReturnsModal({ open, onClose, products, resolveProduct, typeCode, user, canSkipRestock = false, onDone }: ReturnsModalProps) {
    const [number, setNumber] = useState("");
    const [order, setOrder] = useState<ReturnOrder | null>(null);
    const [candidates, setCandidates] = useState<ReturnCandidate[]>([]);
    const [quantities, setQuantities] = useState<Record<number, number>>({});
    const [damaged, setDamaged] = useState<Record<number, boolean>>({});
    const [reason, setReason] = useState("");
    const [exchangeItems, dispatchExchange] = useReducer(orderItemsReducer, []);
    const [scan, setScan] = useState("");
    const [payMethod, setPayMethod] = useState<keyof Tenders>("cash");
    const [refundMethod, setRefundMethod] = useState<"cash" | "card">("cash");
    const [looking, setLooking] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const exchangeUuid = useRef<string>(newClientUuid());
    const numberRef = useRef<InputRef>(null);

    const reset = () => {
        setNumber("");
        setOrder(null);
        setCandidates([]);
        setQuantities({});
        setDamaged({});
        setReason("");
        dispatchExchange({ type: "init", orderItems: [], user });
        setScan("");
        setPayMethod("cash");
        setRefundMethod("cash");
        exchangeUuid.current = newClientUuid();
    };

    const close = () => {
        reset();
        onClose();
    };

    const lookup = async (reference?: string) => {
        const value = (reference ?? number).trim();
        if (!value) return;
        setLooking(true);
        try {
            const { data } = await axios.get<{ order?: ReturnOrder; candidates?: ReturnCandidate[] }>(
                route("pos.instant-returns.lookup"),
                { params: { number: value } }
            );
            setOrder(data.order ?? null);
            setCandidates(data.candidates ?? []);
            setQuantities({});
            setDamaged({});
        } catch (error: any) {
            setOrder(null);
            setCandidates([]);
            message.error(
                error?.response?.status === 404
                    ? errorMessage(error, "لم يتم العثور على الفاتورة")
                    : errorMessage(error, "تعذر البحث عن الفاتورة")
            );
        } finally {
            setLooking(false);
        }
    };

    const addExchange = (product: Product, quantity: number) => {
        dispatchExchange({ type: "addScanned", product, quantity, user });
    };

    const onScan = async () => {
        const { quantity, code } = parseScan(scan);
        if (!code) return;
        setScan("");
        const result = await lookupProduct(code, products, resolveProduct);
        if (!result.ok) {
            if (result.message) message.warning(result.message);
            return;
        }
        addExchange(result.product, result.quantity * quantity);
    };

    const refund = useMemo(
        () =>
            (order?.items ?? []).reduce(
                (sum, line) => sum + lineRefund(line, quantities[line.id] ?? 0),
                0
            ),
        [order, quantities]
    );
    const exchangeTotal = exchangeItems.length > 0 ? cartTotals(exchangeItems, null).total : 0;
    const net = exchangeTotal - refund;
    const returnedLines = (order?.items ?? []).filter((line) => (quantities[line.id] ?? 0) > 0);

    const submit = async () => {
        if (!order) return;
        if (returnedLines.length === 0) {
            message.warning("اختر الكميات المرتجعة");
            return;
        }
        const tenders: Tenders = { ...ZERO_TENDERS };
        if (net > 0) {
            tenders[payMethod] = Math.ceil(net);
        }
        setSubmitting(true);
        try {
            const { data } = await axios.post<ReturnResult>(route("pos.instant-returns.store"), {
                order_id: order.id,
                reason: reason || undefined,
                items: returnedLines.map((line) => ({
                    order_item_id: line.id,
                    quantity: quantities[line.id],
                    ...(canSkipRestock && damaged[line.id] ? { restocked: false } : {}),
                })),
                exchange:
                    exchangeItems.length > 0
                        ? { client_uuid: exchangeUuid.current, type: typeCode, items: itemsForApi(exchangeItems) }
                        : undefined,
                tenders,
                refund_method: net < 0 ? refundMethod : undefined,
            });
            onDone(data);
            message.success(
                data.net < 0
                    ? `تم المرتجع، يُرد للعميل ${formatCurrency(-data.net)}`
                    : data.net > 0
                      ? `تم الاستبدال، دفع العميل ${formatCurrency(data.net)}`
                      : "تم المرتجع"
            );
            close();
        } catch (error: any) {
            message.error(errorMessage(error, "تعذر تسجيل المرتجع"));
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <Modal
            title="مرتجع / استبدال"
            open={open}
            onCancel={close}
            width={1000}
            destroyOnClose
            afterOpenChange={(visible) => visible && numberRef.current?.focus()}
            okText="تأكيد"
            cancelText="إلغاء"
            onOk={submit}
            okButtonProps={{ loading: submitting, disabled: !order || order.age_blocked || returnedLines.length === 0 }}
        >
            <div className="flex gap-2 mb-4">
                <Input
                    ref={numberRef}
                    size="large"
                    prefix={<BarcodeOutlined />}
                    placeholder="رقم الفاتورة أو امسح باركود الإيصال"
                    value={number}
                    onChange={(e) => setNumber(e.target.value)}
                    onPressEnter={() => lookup()}
                />
                <Button size="large" type="primary" icon={<SearchOutlined />} loading={looking} onClick={() => lookup()}>
                    بحث
                </Button>
            </div>

            {!order && candidates.length > 0 ? (
                <>
                    <Alert
                        className="mb-2"
                        type="info"
                        showIcon
                        message="يوجد أكثر من فاتورة بهذا الرقم، اختر الفاتورة الصحيحة"
                    />
                    <Table<ReturnCandidate>
                        size="small"
                        rowKey="id"
                        pagination={false}
                        dataSource={candidates}
                        onRow={(candidate) => ({
                            onClick: () => lookup(candidate.reference),
                            style: { cursor: "pointer" },
                        })}
                        columns={[
                            {
                                title: "المرجع",
                                dataIndex: "reference",
                                render: (value: string, candidate) => (
                                    <span dir="ltr">
                                        {value}
                                        {candidate.current_shift && (
                                            <Tag color="green" className="ms-2">
                                                الوردية الحالية
                                            </Tag>
                                        )}
                                    </span>
                                ),
                            },
                            { title: "رقم الفاتورة", dataIndex: "order_number" },
                            { title: "التاريخ", dataIndex: "created_at", render: formatDate },
                            { title: "الكاشير", dataIndex: "cashier", render: (value) => value ?? "-" },
                            {
                                title: "الإجمالي",
                                dataIndex: "total",
                                render: (value: number, candidate) => (
                                    <>
                                        {formatCurrency(Number(value))}
                                        {candidate.too_old && (
                                            <Tag color="orange" className="ms-2">
                                                أقدم من الحد
                                            </Tag>
                                        )}
                                    </>
                                ),
                            },
                            {
                                title: "",
                                render: (_, candidate) => (
                                    <Button size="small" type="link" onClick={() => lookup(candidate.reference)}>
                                        اختيار
                                    </Button>
                                ),
                            },
                        ]}
                    />
                </>
            ) : !order ? (
                <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="ابحث برقم الفاتورة أو امسح باركود الإيصال" />
            ) : (
                <>
                    <Typography.Title level={5} className="mt-0">
                        فاتورة #{order.order_number} · <span dir="ltr">{order.reference}</span> ·{" "}
                        {formatCurrency(Number(order.total))} · {formatDate(order.created_at)}
                    </Typography.Title>
                    {order.too_old && (
                        <Alert
                            className="mb-2"
                            type={order.age_blocked ? "error" : "warning"}
                            showIcon
                            message={
                                order.age_blocked
                                    ? `الطلب عمره ${order.age_days} يوم وأقصى مدة للمرتجع ${order.max_days} يوم، ولا تملك صلاحية مرتجع الطلبات القديمة`
                                    : `الطلب عمره ${order.age_days} يوم، أقدم من أقصى مدة للمرتجع (${order.max_days} يوم)`
                            }
                        />
                    )}
                    <Table<ReturnLine>
                        size="small"
                        rowKey="id"
                        pagination={false}
                        dataSource={order.items}
                        columns={[
                            { title: "الصنف", dataIndex: "name" },
                            { title: "الكمية", dataIndex: "quantity" },
                            { title: "قابل للرد", dataIndex: "returnable_quantity" },
                            {
                                title: "سعر الرد للوحدة",
                                dataIndex: "unit_refund",
                                render: (value: number, line) => (
                                    <>
                                        {formatCurrency(Number(value))}
                                        {line.discounted && (
                                            <Tag color="gold" className="ms-1">
                                                بعد الخصم
                                            </Tag>
                                        )}
                                        <div className="text-xs text-gray-500" dir="rtl">
                                            {line.explanation}
                                        </div>
                                    </>
                                ),
                            },
                            {
                                title: "كمية المرتجع",
                                render: (_, line) => (
                                    <InputNumber
                                        min={0}
                                        max={Number(line.returnable_quantity)}
                                        disabled={Number(line.returnable_quantity) <= 0}
                                        value={quantities[line.id] ?? 0}
                                        onChange={(value) =>
                                            setQuantities((prev) => ({ ...prev, [line.id]: Number(value || 0) }))
                                        }
                                    />
                                ),
                            },
                            {
                                title: "قيمة الرد",
                                render: (_, line) => formatCurrency(lineRefund(line, quantities[line.id] ?? 0)),
                            },
                            ...(canSkipRestock
                                ? [
                                      {
                                          title: "يرجع للمخزن",
                                          render: (_: unknown, line: ReturnLine) => (
                                              <Switch
                                                  checked={!damaged[line.id]}
                                                  checkedChildren="نعم"
                                                  unCheckedChildren="تالف"
                                                  disabled={(quantities[line.id] ?? 0) <= 0}
                                                  onChange={(checked) =>
                                                      setDamaged((prev) => ({ ...prev, [line.id]: !checked }))
                                                  }
                                              />
                                          ),
                                      },
                                  ]
                                : []),
                        ]}
                    />

                    <Typography.Text type="secondary" className="block mt-1 text-xs">
                        قيمة الرد = ما دفعه العميل في الصنف: خصم الصنف يخص الصنف وحده، وخصم الفاتورة يوزع على الأصناف بنسبة قيمتها.
                    </Typography.Text>

                    <Divider orientation="right">أصناف الاستبدال (اختياري)</Divider>
                    <div className="grid grid-cols-2 gap-2 mb-2">
                        <Input
                            prefix={<BarcodeOutlined />}
                            placeholder="امسح الباركود (3* للكمية)"
                            value={scan}
                            onChange={(e) => setScan(e.target.value)}
                            onPressEnter={onScan}
                        />
                        <Select<number>
                            showSearch
                            placeholder="بحث عن صنف"
                            value={null as unknown as number}
                            optionFilterProp="label"
                            options={products.map((product) => ({
                                value: product.id,
                                label: `${product.name}${product.barcode ? ` - ${product.barcode}` : ""}`,
                            }))}
                            onSelect={(id: number) => {
                                const product = products.find((p) => p.id === id);
                                if (product) addExchange(product, 1);
                            }}
                        />
                    </div>
                    {exchangeItems.map((item) => (
                        <OrderItem
                            key={item.product_id}
                            orderItem={item}
                            dispatch={dispatchExchange}
                            user={user}
                        />
                    ))}

                    <Input.TextArea
                        className="mt-4"
                        placeholder="سبب المرتجع (اختياري)"
                        value={reason}
                        onChange={(e) => setReason(e.target.value)}
                        autoSize={{ minRows: 1, maxRows: 3 }}
                    />

                    <Descriptions
                        className="mt-4"
                        bordered
                        column={1}
                        items={[
                            { key: "refund", label: "قيمة المرتجع", children: formatCurrency(refund) },
                            { key: "exchange", label: "قيمة الاستبدال", children: formatCurrency(exchangeTotal) },
                            {
                                key: "net",
                                label: net > 0 ? "يدفع العميل" : net < 0 ? "يُرد للعميل" : "الصافي",
                                children: formatCurrency(Math.abs(net)),
                            },
                        ]}
                    />

                    {net > 0 && (
                        <div className="mt-4">
                            <Typography.Text className="ml-2">طريقة الدفع:</Typography.Text>
                            <Radio.Group value={payMethod} onChange={(e) => setPayMethod(e.target.value)}>
                                <Radio value="cash">نقدي</Radio>
                                <Radio value="card">فيزا</Radio>
                                <Radio value="talabat_card">فيزا طلبات</Radio>
                            </Radio.Group>
                        </div>
                    )}
                    {net < 0 && (
                        <div className="mt-4">
                            <Typography.Text className="ml-2">طريقة الرد:</Typography.Text>
                            <Radio.Group value={refundMethod} onChange={(e) => setRefundMethod(e.target.value)}>
                                <Radio value="cash">نقدي</Radio>
                                <Radio value="card">فيزا</Radio>
                            </Radio.Group>
                        </div>
                    )}
                    {exchangeItems.length > 0 && (
                        <Alert
                            className="mt-4"
                            type="info"
                            showIcon
                            message="أصناف الاستبدال تُباع بسعرها الحالي في نفس العملية"
                        />
                    )}
                </>
            )}
        </Modal>
    );
}
