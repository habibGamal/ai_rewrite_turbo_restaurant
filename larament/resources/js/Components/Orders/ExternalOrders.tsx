import { ExternalOrder } from "@/types";
import { router } from "@inertiajs/react";
import { Button, Input, InputNumber, message, Modal, Select, Space, Typography } from "antd";
import { useState } from "react";

type Line = { name: string; quantity: number; price: number };

export default function ExternalOrders({
    orderId,
    orders,
    sellers,
    disabled,
    beforeAction,
    open,
    onClose,
}: {
    orderId: number;
    orders: ExternalOrder[];
    sellers: { id: number; name: string }[];
    disabled: boolean;
    beforeAction: (action: () => void) => void;
    open?: boolean;
    onClose?: () => void;
}) {
    const [internalOpen, setInternalOpen] = useState(false);
    const [sellerId, setSellerId] = useState<number>();
    const [lines, setLines] = useState<Line[]>([{ name: "", quantity: 1, price: 0 }]);

    const isModalOpen = open ?? internalOpen;
    const closeModal = () => {
        if (onClose) {
            onClose();
        } else {
            setInternalOpen(false);
        }
    };

    const selectSeller = (id: number) => {
        setSellerId(id);
        const existing = orders.find((order) => order.external_seller_id === id);
        setLines(existing?.items.map((item) => ({
            name: item.name,
            quantity: Number(item.quantity),
            price: Number(item.price),
        })) ?? [{ name: "", quantity: 1, price: 0 }]);
    };
    const updateLine = (index: number, change: Partial<Line>) => {
        setLines((current) => current.map((line, i) => (
            i === index ? { ...line, ...change } : line
        )));
    };

    return (
        <>
            {orders.length > 0 && (
                <div className="my-4 space-y-2">
                    {orders.map((external) => (
                        <div key={external.id} className="rounded border p-3">
                            <Typography.Text strong>
                                طلب خارجي — {external.seller_name}: {Number(external.total).toFixed(2)}
                            </Typography.Text>
                            {external.items.map((item) => (
                                <div key={item.id} className="flex justify-between">
                                    <span>{item.name} × {item.quantity} — {Number(item.total).toFixed(2)}</span>
                                    <Button disabled={disabled} size="small" danger onClick={() => beforeAction(() => {
                                        router.delete(route("orders.external.removeLine", [orderId, external.id, item.id]));
                                    })}>
                                        حذف
                                    </Button>
                                </div>
                            ))}
                            <Button disabled={disabled || external.status === "fulfilled"} size="small" onClick={() => beforeAction(() => {
                                router.post(route("orders.external.fulfill", [orderId, external.id]));
                            })}>
                                تم التنفيذ
                            </Button>
                        </div>
                    ))}
                </div>
            )}
            <Modal
                title="إضافة طلب خارجي"
                open={isModalOpen}
                onCancel={() => {
                    closeModal();
                    setSellerId(undefined);
                }}
                onOk={() => {
                    if (!sellerId || lines.some((line) => !line.name.trim() || line.quantity <= 0 || line.price < 0)) {
                        message.error("اختر البائع وأكمل بيانات الأصناف");
                        return;
                    }
                    beforeAction(() => router.post(route("orders.external.save", orderId), {
                        seller_id: sellerId,
                        items: lines,
                    }, {
                        onSuccess: () => {
                            closeModal();
                            setSellerId(undefined);
                        },
                        onError: (errors) => message.error(Object.values(errors)[0] || "تعذر حفظ الطلب الخارجي"),
                    }));
                }}
            >
                <Select
                    className="w-full mb-3"
                    placeholder="اختر البائع"
                    value={sellerId}
                    onChange={selectSeller}
                    options={sellers.map((seller) => ({ value: seller.id, label: seller.name }))}
                />
                {lines.map((line, index) => (
                    <Space key={index} className="mb-2">
                        <Input placeholder="الصنف" value={line.name}
                            onChange={(event) => updateLine(index, { name: event.target.value })} />
                        <InputNumber min={0.001} value={line.quantity}
                            onChange={(value) => updateLine(index, { quantity: value ?? 0 })} />
                        <InputNumber min={0} value={line.price}
                            onChange={(value) => updateLine(index, { price: value ?? 0 })} />
                        <Button danger disabled={lines.length === 1}
                            onClick={() => setLines(lines.filter((_, i) => i !== index))}>
                            حذف
                        </Button>
                    </Space>
                ))}
                <Button onClick={() => setLines([...lines, { name: "", quantity: 1, price: 0 }])}>
                    إضافة صنف
                </Button>
            </Modal>
        </>
    );
}
