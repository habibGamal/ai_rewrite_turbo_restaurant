import { ExternalOrder, ManageOrderProps } from "@/types";
import { router } from "@inertiajs/react";
import { Button, Input, InputNumber, message, Modal, Select, Typography } from "antd";
import { useState } from "react";

type Product = ManageOrderProps["sellers"][number]["products"][number];

function ProductRow({ product, quantity, onChange }: {
    product: Product;
    quantity: number;
    onChange: (quantity: number) => void;
}) {
    return (
        <div className="flex flex-wrap items-center justify-between gap-2 border-b py-2">
            <span>{product.name} — {Number(product.price).toFixed(2)}</span>
            <div className="flex items-center gap-1" dir="ltr">
                <Button aria-label={`تقليل كمية ${product.name}`} onClick={() => onChange(Math.max(0, quantity - 1))}>−</Button>
                <InputNumber aria-label={`كمية ${product.name}`} min={0} max={999999999} precision={3} value={quantity} onChange={(value) => onChange(value ?? 0)} />
                <Button aria-label={`زيادة كمية ${product.name}`} onClick={() => onChange(Math.min(999999999, quantity + 1))}>+</Button>
            </div>
        </div>
    );
}

export default function ExternalOrders({ orderId, orders, sellers, disabled, beforeAction, open, onClose }: {
    orderId: number;
    orders: ExternalOrder[];
    sellers: ManageOrderProps["sellers"];
    disabled: boolean;
    beforeAction: (action: () => void) => void;
    open?: boolean;
    onClose?: () => void;
}) {
    const [internalOpen, setInternalOpen] = useState(false);
    const [sellerId, setSellerId] = useState<number>();
    const [quantities, setQuantities] = useState<Record<number, number>>({});
    const [search, setSearch] = useState("");
    const seller = sellers.find((entry) => entry.id === sellerId);
    const picked = seller?.products.filter((product) => (quantities[product.id] ?? 0) > 0) ?? [];
    const total = picked.reduce((sum, product) => sum + Math.round(Number(product.price) * quantities[product.id] * 100) / 100, 0);

    const closeModal = () => {
        onClose ? onClose() : setInternalOpen(false);
        setSellerId(undefined);
        setQuantities({});
        setSearch("");
    };
    const selectSeller = (id: number) => {
        setSellerId(id);
        setSearch("");
        const existing = orders.find((order) => order.external_seller_id === id);
        const initial: Record<number, number> = {};
        existing?.items.forEach((item) => {
            if (item.external_product_id != null) {
                initial[item.external_product_id] = (initial[item.external_product_id] ?? 0) + Number(item.quantity);
            }
        });
        setQuantities(initial);
    };

    return (
        <>
            {orders.length > 0 && (
                <div className="my-4 space-y-2">
                    {orders.map((external) => (
                        <div key={external.id} className="rounded border p-3">
                            <Typography.Text strong>طلب خارجي — {external.seller_name}: {Number(external.total).toFixed(2)}</Typography.Text>
                            {external.items.map((item) => (
                                <div key={item.id} className="flex justify-between">
                                    <span>{item.name} × {item.quantity} — {Number(item.total).toFixed(2)}</span>
                                    <Button disabled={disabled} size="small" danger onClick={() => beforeAction(() => {
                                        router.delete(route("orders.external.removeLine", [orderId, external.id, item.id]));
                                    })}>حذف</Button>
                                </div>
                            ))}
                            <Button disabled={disabled || external.status === "fulfilled"} size="small" onClick={() => beforeAction(() => {
                                router.post(route("orders.external.fulfill", [orderId, external.id]));
                            })}>تم التنفيذ</Button>
                        </div>
                    ))}
                </div>
            )}
            <Modal title="إضافة طلب خارجي" open={open ?? internalOpen} onCancel={closeModal}
                okButtonProps={{ disabled: disabled || picked.length === 0 }}
                onOk={() => {
                    if (!sellerId || picked.length === 0) return;
                    beforeAction(() => router.post(route("orders.external.save", orderId), {
                        seller_id: sellerId,
                        items: picked.map((product) => ({ product_id: product.id, quantity: quantities[product.id] })),
                    }, {
                        onSuccess: closeModal,
                        onError: (errors) => message.error(Object.values(errors)[0] || "تعذر حفظ الطلب الخارجي"),
                    }));
                }}>
                <Select className="mb-3 w-full" placeholder="اختر البائع" value={sellerId}
                    onChange={selectSeller} options={sellers.map((entry) => ({ value: entry.id, label: entry.name }))} />
                {seller && seller.products.length === 0 && <p>لا توجد أصناف لهذا البائع — أضفها من إدارة البائعين الخارجيين</p>}
                {seller && seller.products.length > 0 && (
                    <>
                        <Input.Search placeholder="ابحث عن صنف" value={search} onChange={(event) => setSearch(event.target.value)} />
                        <div className="max-h-64 overflow-y-auto">
                            {seller.products.filter((product) => product.name.toLocaleLowerCase().includes(search.toLocaleLowerCase())).map((product) => (
                                <ProductRow key={product.id} product={product} quantity={quantities[product.id] ?? 0}
                                    onChange={(quantity) => setQuantities((current) => ({ ...current, [product.id]: quantity }))} />
                            ))}
                        </div>
                        {picked.length > 0 && (
                            <div className="mt-4 space-y-1">
                                {picked.map((product) => (
                                    <div key={product.id}>{product.name} × {quantities[product.id]} = {(Math.round(Number(product.price) * quantities[product.id] * 100) / 100).toFixed(2)}</div>
                                ))}
                                <Typography.Text strong>الإجمالي {total.toFixed(2)}</Typography.Text>
                            </div>
                        )}
                    </>
                )}
            </Modal>
        </>
    );
}
