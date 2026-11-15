import React, { useRef, useState } from "react";
import {
    Modal,
    Form,
    InputNumber,
    Radio,
    Button,
    Typography,
    Descriptions,
    Checkbox,
    message,
} from "antd";
import { router } from "@inertiajs/react";
import { Order, OrderItemData } from "@/types";
import {
    calculateOrderTotals,
    formatCurrency,
} from "@/utils/orderCalculations";
import useLoading from "@/hooks/useLoading";

export interface PaymentTenders {
    cash: number;
    card: number;
    talabat_card: number;
}

/** Cart mode: pay a client-side cart (instant sale) instead of a saved order. */
export interface PaymentCart {
    total: number;
    subTotal?: number;
    discount?: number;
    credit?: {
        allow_partial_payment: boolean;
        allow_pay_later: boolean;
        require_customer_for_credit: boolean;
    };
}

interface PaymentModalProps {
    open: boolean;
    onCancel: () => void;
    order?: Order;
    orderItems?: OrderItemData[];
    /** Cart mode: when set, nothing is posted here; onConfirm receives the tenders. */
    cart?: PaymentCart;
    /** Cart mode: resolve true to close the modal, false to keep it open (e.g. on 422). */
    onConfirm?: (tenders: PaymentTenders, print: boolean) => Promise<boolean> | boolean;
    mode?: "complete" | "collect";
    paidAmount?: number;
    remainingAmount?: number;
    guestDue?: number;
    credit?: {
        allow_partial_payment: boolean;
        allow_pay_later: boolean;
        require_customer_for_credit: boolean;
    };
}

export default function PaymentModal({
    open,
    onCancel,
    order,
    orderItems,
    mode = "complete",
    paidAmount = 0,
    remainingAmount = 0,
    guestDue,
    credit,
    cart,
    onConfirm,
}: PaymentModalProps) {
    const isCart = Boolean(cart);
    const paidInputRef = useRef<HTMLInputElement>(null);
    const [printReceipt, setPrintReceipt] = useState<boolean>(true);
    const [onePaymentForm] = Form.useForm();
    const [multiPaymentForm] = Form.useForm();
    const [paymentMethod, setPaymentMethod] = useState<
        "one_payment" | "multi_payment"
    >("one_payment");
    const [remaining, setRemaining] = useState<number>(0);
    const [printCollection, setPrintCollection] = useState<boolean>(false);

    const creditSettings = credit ?? cart?.credit ?? order?.credit;
    const allowPartial = Boolean(creditSettings?.allow_partial_payment);
    const allowPayLater = Boolean(creditSettings?.allow_pay_later);

    const totals = cart
        ? {
              subTotal: cart.subTotal ?? cart.total,
              tax: 0,
              service: 0,
              discount: cart.discount ?? 0,
              total: cart.total,
          }
        : calculateOrderTotals(order!, orderItems ?? []);
    const due = mode === "collect" ? remainingAmount : guestDue ?? Math.ceil(totals.total);

    const { loading, finish, start } = useLoading();

    const confirmCart = async (tenders: PaymentTenders, print: boolean) => {
        if (!onConfirm) return;
        start();
        try {
            const done = await onConfirm(tenders, print);
            if (done) {
                onePaymentForm.resetFields();
                multiPaymentForm.resetFields();
                onCancel();
            }
        } finally {
            finish();
        }
    };

    // Cart mode opens with cash preselected for the exact amount, ready for Enter.
    const handleAfterOpenChange = (visible: boolean) => {
        if (!visible || !isCart) return;
        setPaymentMethod("one_payment");
        onePaymentForm.setFieldsValue({ payment_method: "cash", paid: due });
        setRemaining(0);
        setTimeout(() => {
            paidInputRef.current?.focus();
            paidInputRef.current?.select();
        }, 0);
    };

    const handlePayLater = () => {
        Modal.confirm({
            title: "تأكيد الإغلاق والدفع لاحقاً",
            content: "هل أنت متأكد من إغلاق الطلب دون دفع أي مبلغ وتسجيله كآجل؟",
            okText: "تأكيد",
            cancelText: "إلغاء",
            onOk: () => {
                if (isCart) {
                    return confirmCart({ cash: 0, card: 0, talabat_card: 0 }, printReceipt);
                }
                start();
                router.post(
                    `/orders/complete-order/${order!.id}`,
                    {
                        cash: 0,
                        card: 0,
                        talabat_card: 0,
                        print: false,
                    },
                    {
                        onSuccess: () => {
                            message.success("تم إنهاء الطلب بنجاح");
                            onCancel();
                            router.get(route("orders.index") + `#${order!.type}`);
                        },
                        onFinish: () => {
                            finish();
                        },
                        onError: (errors: any) => {
                            const msg = errors?.error || "حدث خطأ أثناء إنهاء الطلب";
                            message.error(msg);
                        },
                    }
                );
            },
        });
    };

    const onFinish = (values: any) => {
        if (mode === "complete") {
            if (!allowPartial) {
                if (paymentMethod === "one_payment") {
                    if (Number(values.paid) < due) {
                        message.error("يجب أن يكون المبلغ المدفوع مساويًا للإجمالي");
                        return;
                    }
                } else {
                    const sum = Number(values.cash || 0) + Number(values.card || 0) + Number(values.talabat_card || 0);
                    if (isCart ? sum < due : sum !== due) {
                        message.error("يجب أن يكون مجموع المبالغ المدفوعة مساويًا للإجمالي");
                        return;
                    }
                }
            } else {
                if (paymentMethod === "one_payment") {
                    if (Number(values.paid || 0) <= 0 && !allowPayLater) {
                        message.error("يجب إدخال المبلغ المدفوع");
                        return;
                    }
                } else {
                    const sum = Number(values.cash || 0) + Number(values.card || 0) + Number(values.talabat_card || 0);
                    if (sum > due) {
                        message.error("مجموع المبالغ المدفوعة يتجاوز الإجمالي");
                        return;
                    }
                    if (sum <= 0 && !allowPayLater) {
                        message.error("يجب إدخال المبلغ المدفوع");
                        return;
                    }
                }
            }
        } else if (paymentMethod === "multi_payment") {
            const sum = Number(values.cash || 0) + Number(values.card || 0) + Number(values.talabat_card || 0);
            if (sum > due) {
                message.error("مجموع المبالغ المدفوعة يتجاوز المتبقي");
                return;
            }
        }
        if (isCart) {
            const tenders: PaymentTenders = { cash: 0, card: 0, talabat_card: 0 };
            if (paymentMethod === "one_payment") {
                tenders[values.payment_method as keyof PaymentTenders] = Number(values.paid || 0);
            } else {
                tenders.cash = Number(values.cash || 0);
                tenders.card = Number(values.card || 0);
                tenders.talabat_card = Number(values.talabat_card || 0);
            }
            confirmCart(tenders, printReceipt);
            return;
        }
        start();
        const print = mode === "collect" && printCollection;
        let finalValues = { ...values, print };

        if (paymentMethod === "one_payment") {
            finalValues = {
                cash: 0,
                card: 0,
                talabat_card: 0,
                print,
            };
            finalValues[values.payment_method] = values.paid;
        }

        const url = mode === "collect"
            ? route("orders.collectRemaining", order!.id)
            : `/orders/complete-order/${order!.id}`;

        router.post(url, finalValues, {
            onSuccess: () => {
                message.success(mode === "collect" ? "تم تحصيل المتبقي بنجاح" : "تم إنهاء الطلب بنجاح");
                onCancel();
                if (mode === "complete") {
                    router.get(route("orders.index") + `#${order!.type}`);
                }
            },
            onFinish: () => {
                finish();
            },
            onError: () => {
                message.error("حدث خطأ أثناء إنهاء الطلب");
            },
        });
    };

    const fullPay = () => {
        onePaymentForm.setFieldsValue({
            paid: due,
        });
        setRemaining(0);
    };

    const handleCancel = () => {
        onePaymentForm.resetFields();
        multiPaymentForm.resetFields();
        onCancel();
    };

    const paymentItems = mode === "collect"
        ? [
            {
                key: "total",
                label: "الإجمالي",
                children: formatCurrency(Number(order?.total ?? 0)),
            },
            {
                key: "paid",
                label: "المدفوع",
                children: formatCurrency(paidAmount),
            },
            {
                key: "remaining",
                label: "المتبقي",
                children: formatCurrency(remainingAmount),
            },
        ]
        : isCart
        ? [
            { key: "1", label: "المجموع", children: formatCurrency(totals.subTotal) },
            { key: "4", label: "الخصم", children: formatCurrency(Number(totals.discount)) },
            { key: "5", label: "الاجمالي", children: formatCurrency(totals.total) },
        ]
        : [
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
            ...(guestDue === undefined ? [] : [{ key: "guestDue", label: "المطلوب من العميل", children: formatCurrency(guestDue) }]),
        ];


    return (
        <Modal
            title="الحساب"
            open={open}
            onCancel={handleCancel}
            footer={null}
            width={800}
            destroyOnClose
            afterOpenChange={handleAfterOpenChange}
        >
            <div className="grid grid-cols-2 gap-4">
                <Descriptions bordered column={1} items={paymentItems} />
                <div>
                    {mode === "complete" && allowPartial && (
                        <div className="mb-4 p-2.5 bg-blue-50 border border-blue-200 rounded text-blue-800 text-sm">
                            مسموح بالدفع الجزئي لهذا النوع من الطلبات.
                        </div>
                    )}

                    <Radio.Group
                        onChange={(e) => setPaymentMethod(e.target.value)}
                        value={paymentMethod}
                        className="mb-4"
                    >
                        <Radio value="one_payment">الدفع بطريقة واحدة</Radio>
                        <Radio value="multi_payment">
                            الدفع باكثر من طريقة
                        </Radio>
                    </Radio.Group>

                    {paymentMethod === "one_payment" && (
                        <Form
                            form={onePaymentForm}
                            onFinish={onFinish}
                            initialValues={{
                                payment_method: "cash",
                                paid: 0,
                            }}
                            layout="vertical"
                        >
                            <Form.Item
                                name="paid"
                                label="المبلغ المدفوع"
                                rules={[
                                    {
                                        required: true,
                                        message: "المبلغ المدفوع مطلوب",
                                    },
                                ]}
                            >
                                <InputNumber
                                    ref={paidInputRef}
                                    className="w-full"
                                    min={0}
                                    onChange={(value) =>
                                        setRemaining(
                                            due - (value || 0)
                                        )
                                    }
                                />
                            </Form.Item>
                            <Form.Item>
                                <Button onClick={fullPay}>المبلغ كامل</Button>
                            </Form.Item>
                            <Typography.Text className="block mb-4">
                                {isCart && remaining < 0
                                    ? <>الباقي للعميل : {formatCurrency(-remaining)}</>
                                    : <>المبلغ المتبقي : {formatCurrency(remaining)}</>}
                            </Typography.Text>
                            <Form.Item name="payment_method">
                                <Radio.Group>
                                    <Radio value="cash">نقدي</Radio>
                                    <Radio value="card">فيزا</Radio>
                                    <Radio value="talabat_card">
                                        فيزا طلبات
                                    </Radio>
                                </Radio.Group>
                            </Form.Item>
                            <Form.Item>
                                <div className="flex items-center gap-2">
                                    <Button loading={loading} disabled={loading} htmlType="submit" type="primary">
                                        تم
                                    </Button>
                                    {mode === "collect" && (
                                        <Checkbox
                                            checked={printCollection}
                                            onChange={(e) => setPrintCollection(e.target.checked)}
                                        >
                                            طباعة إيصال التحصيل
                                        </Checkbox>
                                    )}
                                    {isCart && (
                                        <Checkbox
                                            checked={printReceipt}
                                            onChange={(e) => setPrintReceipt(e.target.checked)}
                                        >
                                            طباعة الفاتورة
                                        </Checkbox>
                                    )}
                                    {mode === "complete" && allowPayLater && (
                                        <Button
                                            danger
                                            onClick={handlePayLater}
                                            loading={loading}
                                            disabled={loading}
                                        >
                                            إغلاق والدفع لاحقاً
                                        </Button>
                                    )}
                                </div>
                            </Form.Item>
                        </Form>
                    )}

                    {paymentMethod === "multi_payment" && (
                        <Form
                            form={multiPaymentForm}
                            onFinish={onFinish}
                            initialValues={{
                                cash: 0,
                                card: 0,
                                talabat_card: 0,
                            }}
                            layout="vertical"
                        >
                            <Form.Item name="cash" label="المبلغ المدفوع كاش">
                                <InputNumber className="w-full" min={0} />
                            </Form.Item>
                            <Form.Item name="card" label="المبلغ المدفوع فيزا">
                                <InputNumber className="w-full" min={0} />
                            </Form.Item>
                            <Form.Item
                                name="talabat_card"
                                label="المبلغ المدفوع فيزا طلبات"
                            >
                                <InputNumber className="w-full" min={0} />
                            </Form.Item>
                            <Form.Item>
                                <div className="flex items-center gap-2">
                                    <Button loading={loading} disabled={loading} htmlType="submit" type="primary">
                                        تم
                                    </Button>
                                    {mode === "collect" && (
                                        <Checkbox
                                            checked={printCollection}
                                            onChange={(e) => setPrintCollection(e.target.checked)}
                                        >
                                            طباعة إيصال التحصيل
                                        </Checkbox>
                                    )}
                                    {isCart && (
                                        <Checkbox
                                            checked={printReceipt}
                                            onChange={(e) => setPrintReceipt(e.target.checked)}
                                        >
                                            طباعة الفاتورة
                                        </Checkbox>
                                    )}
                                    {mode === "complete" && allowPayLater && (
                                        <Button
                                            danger
                                            onClick={handlePayLater}
                                            loading={loading}
                                            disabled={loading}
                                        >
                                            إغلاق والدفع لاحقاً
                                        </Button>
                                    )}
                                </div>
                            </Form.Item>
                        </Form>
                    )}
                </div>
            </div>
        </Modal>
    );
}
