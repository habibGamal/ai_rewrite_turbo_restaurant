import { ExternalOrder, ManageOrderProps } from "@/types";
import { formatCurrency } from "@/utils/orderCalculations";
import {
    DeleteOutlined,
    EditOutlined,
    MinusCircleOutlined,
    PlusCircleOutlined,
    ShopOutlined,
} from "@ant-design/icons";
import { router } from "@inertiajs/react";
import {
    Button,
    ConfigProvider,
    Divider,
    Empty,
    Input,
    InputNumber,
    message,
    Modal,
    Popconfirm,
    Select,
    Tag,
    Typography,
} from "antd";
import React, { useEffect, useState } from "react";

type Product = ManageOrderProps["sellers"][number]["products"][number];
type ExternalLine = ExternalOrder["items"][number];

const MAX_QUANTITY = 999999999;

function lineTotal(price: number | string, quantity: number): number {
    return Math.round(Number(price) * quantity * 100) / 100;
}

/** Quantity −/+ controls, the same look as a normal order line. */
function QuantityStepper({
    value,
    disabled,
    label,
    min,
    deferTyping = false,
    onChange,
}: {
    value: number;
    disabled?: boolean;
    label: string;
    min: number;
    /** Typed quantities are applied on blur / Enter instead of per keystroke. */
    deferTyping?: boolean;
    onChange: (quantity: number) => void;
}) {
    const [typed, setTyped] = useState<number | null>(null);
    const commitTyped = () => {
        if (typed !== null && typed >= min && typed !== value) onChange(typed);
        setTyped(null);
    };

    return (
        <div className="flex gap-2">
            <Button
                disabled={disabled || value - 1 < min}
                onClick={() => onChange(value - 1)}
                className="icon-button"
                icon={<MinusCircleOutlined />}
                size="small"
                aria-label={`تقليل كمية ${label}`}
            />
            <InputNumber
                disabled={disabled}
                aria-label={`كمية ${label}`}
                min={min}
                max={MAX_QUANTITY}
                value={deferTyping && typed !== null ? typed : value}
                onChange={(next) => {
                    if (next === null || next < min) return;
                    if (deferTyping) setTyped(next);
                    else onChange(next);
                }}
                onBlur={deferTyping ? commitTyped : undefined}
                onPressEnter={deferTyping ? commitTyped : undefined}
                style={{ width: 90 }}
            />
            <Button
                disabled={disabled || value + 1 > MAX_QUANTITY}
                onClick={() => onChange(value + 1)}
                className="icon-button"
                icon={<PlusCircleOutlined />}
                size="small"
                aria-label={`زيادة كمية ${label}`}
            />
        </div>
    );
}

/**
 * One outsourced line on the order, laid out like a normal order item and set
 * apart by the seller's orange accent.
 */
function ExternalLineItem({
    item,
    disabled,
    canEdit,
    canRemove,
    onChangeQuantity,
    onDelete,
}: {
    item: ExternalLine;
    disabled: boolean;
    canEdit: boolean;
    canRemove: boolean;
    onChangeQuantity: (quantity: number) => void;
    onDelete: () => void;
}) {
    const [draft, setDraft] = useState<number | null>(null);
    const quantity = draft ?? Number(item.quantity);

    return (
        <div className="isolate-3 flex flex-col gap-4 my-3 !border-orange-200 border-s-4 !border-s-orange-400">
            <div className="flex justify-between items-center">
                <Typography.Paragraph className="!my-0">{item.name}</Typography.Paragraph>
                <QuantityStepper
                    value={quantity}
                    disabled={disabled || !canEdit}
                    label={item.name}
                    min={0.001}
                    deferTyping
                    onChange={(next) => {
                        setDraft(next);
                        onChangeQuantity(next);
                    }}
                />
            </div>
            <div className="flex justify-between items-center">
                <Typography.Text>
                    السعر :
                    <Tag className="mx-4 text-lg" bordered={false} color="success">
                        {formatCurrency(Number(item.total))}
                    </Tag>
                </Typography.Text>
                {canRemove && (
                    <Popconfirm
                        title="حذف الصنف؟"
                        okText="نعم"
                        cancelText="لا"
                        disabled={disabled}
                        onConfirm={onDelete}
                    >
                        <Button
                            disabled={disabled}
                            danger
                            type="primary"
                            className="icon-button"
                            icon={<DeleteOutlined />}
                            size="small"
                            aria-label={`حذف ${item.name}`}
                        />
                    </Popconfirm>
                )}
            </div>
        </div>
    );
}

export default function ExternalOrders({
    orderId,
    orders,
    sellers,
    disabled,
    featureEnabled = true,
    beforeAction,
    open,
    onClose,
}: {
    orderId: number;
    orders: ExternalOrder[];
    sellers: ManageOrderProps["sellers"];
    disabled: boolean;
    featureEnabled?: boolean;
    beforeAction: (action: () => void) => void;
    open?: boolean;
    onClose?: () => void;
}) {
    const [internalOpen, setInternalOpen] = useState(false);
    const [sellerId, setSellerId] = useState<number>();
    const [quantities, setQuantities] = useState<Record<number, number>>({});
    const [search, setSearch] = useState("");
    const [busy, setBusy] = useState(false);
    const seller = sellers.find((entry) => entry.id === sellerId);
    const picked =
        seller?.products.filter((product) => (quantities[product.id] ?? 0) > 0) ?? [];
    const total = picked.reduce(
        (sum, product) => sum + lineTotal(product.price, quantities[product.id]),
        0
    );
    const visibleProducts =
        seller?.products.filter((product) =>
            product.name.toLocaleLowerCase().includes(search.toLocaleLowerCase())
        ) ?? [];

    const isModalOpen = featureEnabled && (Boolean(open) || internalOpen);

    // With a single seller there is nothing to choose: open straight on its items.
    useEffect(() => {
        if (isModalOpen && sellerId === undefined && sellers.length === 1) {
            selectSeller(sellers[0].id);
        }
    }, [isModalOpen]);

    const closeModal = () => {
        if (onClose) {
            onClose();
        }
        setInternalOpen(false);
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
                initial[item.external_product_id] =
                    (initial[item.external_product_id] ?? 0) + Number(item.quantity);
            }
        });
        setQuantities(initial);
    };

    const setQuantity = (productId: number, quantity: number) =>
        setQuantities((current) => ({ ...current, [productId]: quantity }));

    const addOne = (product: Product) =>
        setQuantity(
            product.id,
            Math.min(MAX_QUANTITY, (quantities[product.id] ?? 0) + 1)
        );

    const handleEditSeller = (sellerIdToEdit: number) => {
        selectSeller(sellerIdToEdit);
        setInternalOpen(true);
    };

    const runLineRequest = (send: (finish: () => void) => void) => {
        if (busy) return;
        setBusy(true);
        beforeAction(() => send(() => setBusy(false)));
    };

    /** Saves the seller's lines again with one line's quantity changed. */
    const changeLineQuantity = (
        external: ExternalOrder,
        itemId: number,
        quantity: number
    ) => {
        const lines = external.items
            .filter((item) => item.external_product_id != null)
            .map((item) => ({
                product_id: item.external_product_id as number,
                quantity: item.id === itemId ? quantity : Number(item.quantity),
            }));
        runLineRequest((finish) =>
            router.post(
                route("orders.external.save", orderId),
                { seller_id: external.external_seller_id, items: lines },
                {
                    preserveScroll: true,
                    onError: (errors) =>
                        message.error(
                            Object.values(errors)[0] || "تعذر حفظ الطلب الخارجي"
                        ),
                    onFinish: finish,
                }
            )
        );
    };

    const removeLine = (external: ExternalOrder, itemId: number) =>
        runLineRequest((finish) =>
            router.delete(
                route("orders.external.removeLine", [orderId, external.id, itemId]),
                { preserveScroll: true, onFinish: finish }
            )
        );

    return (
        <>
            {orders.length > 0 && (
                <>
                    <div className="my-4">
                        <Typography.Title level={5} className="!mb-3">
                            الطلبات الخارجية
                        </Typography.Title>
                        <div className="flex flex-col gap-4">
                            {orders.map((external) => (
                                <section
                                    key={external.id}
                                    aria-label={`طلب خارجي — ${external.seller_name}`}
                                    className="rounded-xl border-2 border-orange-300 bg-orange-50 p-3"
                                >
                                    <div className="flex items-center justify-between gap-2">
                                        <div className="flex items-center gap-2 flex-wrap">
                                            <Tag
                                                color="orange"
                                                icon={<ShopOutlined />}
                                                className="!m-0 !text-base !py-0.5"
                                            >
                                                طلب خارجي — {external.seller_name}
                                            </Tag>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <Tag
                                                className="!m-0 text-base font-semibold"
                                                bordered={false}
                                                color="success"
                                            >
                                                {formatCurrency(Number(external.total))}
                                            </Tag>
                                            {featureEnabled && (
                                                <Button
                                                    size="small"
                                                    icon={<EditOutlined />}
                                                    disabled={disabled}
                                                    onClick={() =>
                                                        handleEditSeller(
                                                            external.external_seller_id
                                                        )
                                                    }
                                                >
                                                    تعديل
                                                </Button>
                                            )}
                                        </div>
                                    </div>

                                    {external.items.map((item) => (
                                        <ExternalLineItem
                                            key={`${item.id}-${item.quantity}`}
                                            item={item}
                                            disabled={disabled || busy}
                                            canEdit={
                                                featureEnabled &&
                                                item.external_product_id != null
                                            }
                                            canRemove={featureEnabled}
                                            onChangeQuantity={(quantity) =>
                                                changeLineQuantity(
                                                    external,
                                                    item.id,
                                                    quantity
                                                )
                                            }
                                            onDelete={() => removeLine(external, item.id)}
                                        />
                                    ))}
                                </section>
                            ))}
                        </div>
                    </div>
                    <Divider />
                </>
            )}

            {featureEnabled && (
                <Modal
                    title="إضافة طلب خارجي"
                    open={isModalOpen}
                    width={900}
                    onCancel={closeModal}
                    okText="تأكيد"
                    cancelText="إلغاء"
                    okButtonProps={{ disabled: disabled || picked.length === 0 }}
                    onOk={() => {
                        if (!sellerId || picked.length === 0) return;
                        beforeAction(() =>
                            router.post(
                                route("orders.external.save", orderId),
                                {
                                    seller_id: sellerId,
                                    items: picked.map((product) => ({
                                        product_id: product.id,
                                        quantity: quantities[product.id],
                                    })),
                                },
                                {
                                    preserveScroll: true,
                                    onSuccess: closeModal,
                                    onError: (errors) =>
                                        message.error(
                                            Object.values(errors)[0] ||
                                                "تعذر حفظ الطلب الخارجي"
                                        ),
                                }
                            )
                        );
                    }}
                >
                    <div className="flex flex-wrap gap-3 mb-4">
                        <Select
                            className="flex-1 min-w-[200px]"
                            size="large"
                            placeholder="اختر البائع"
                            value={sellerId}
                            onChange={selectSeller}
                            options={sellers.map((entry) => ({
                                value: entry.id,
                                label: entry.name,
                            }))}
                        />
                        {seller && seller.products.length > 0 && (
                            <Input.Search
                                style={{ width: 220 }}
                                size="large"
                                placeholder="بحث"
                                allowClear
                                value={search}
                                onChange={(event) => setSearch(event.target.value)}
                            />
                        )}
                    </div>

                    {seller && seller.products.length === 0 && (
                        <Empty
                            image={Empty.PRESENTED_IMAGE_SIMPLE}
                            description="لا توجد أصناف لهذا البائع — أضفها من إدارة البائعين الخارجيين"
                        />
                    )}

                    {seller && seller.products.length > 0 && (
                        <>
                            <ConfigProvider theme={{ token: { borderRadius: 4 } }}>
                                <div className="grid grid-cols-3 gap-4 max-h-[45vh] overflow-y-auto">
                                    {visibleProducts.map((product) => {
                                        const quantity = quantities[product.id] ?? 0;
                                        return (
                                            <Button
                                                key={product.id}
                                                type="primary"
                                                disabled={disabled}
                                                onClick={() => addOne(product)}
                                                aria-label={`إضافة ${product.name}`}
                                                className={`relative w-full h-full min-h-[100px] whitespace-normal ${
                                                    quantity > 0
                                                        ? "!bg-orange-600"
                                                        : "bg-gray-800"
                                                }`}
                                                style={{ height: "auto", minHeight: "100px" }}
                                            >
                                                {quantity > 0 && (
                                                    <span className="absolute top-1 start-1 rounded-full bg-white px-2 text-sm font-bold text-orange-600">
                                                        × {Number(quantity.toFixed(3))}
                                                    </span>
                                                )}
                                                <span className="flex flex-col items-center gap-1">
                                                    <span className="text-xl font-bold">
                                                        {product.name}
                                                    </span>
                                                    <span className="text-sm opacity-80">
                                                        {formatCurrency(Number(product.price))}
                                                    </span>
                                                </span>
                                            </Button>
                                        );
                                    })}
                                </div>
                            </ConfigProvider>
                            {visibleProducts.length === 0 && (
                                <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} />
                            )}

                            {picked.length > 0 && (
                                <div className="mt-4 border-t border-gray-200 pt-3">
                                    {picked.map((product) => (
                                        <div
                                            key={product.id}
                                            className="flex flex-wrap items-center justify-between gap-2 py-2"
                                        >
                                            <span className="font-medium">
                                                {product.name}
                                                <Tag
                                                    className="mx-3"
                                                    bordered={false}
                                                    color="success"
                                                >
                                                    {formatCurrency(
                                                        lineTotal(
                                                            product.price,
                                                            quantities[product.id]
                                                        )
                                                    )}
                                                </Tag>
                                            </span>
                                            <div className="flex items-center gap-3">
                                                <QuantityStepper
                                                    value={quantities[product.id]}
                                                    label={product.name}
                                                    min={0}
                                                    onChange={(quantity) =>
                                                        setQuantity(product.id, quantity)
                                                    }
                                                />
                                                <Button
                                                    danger
                                                    type="primary"
                                                    className="icon-button"
                                                    size="small"
                                                    icon={<DeleteOutlined />}
                                                    aria-label={`حذف ${product.name}`}
                                                    onClick={() => setQuantity(product.id, 0)}
                                                />
                                            </div>
                                        </div>
                                    ))}
                                    <div className="flex justify-end pt-2">
                                        <Typography.Text strong className="text-base">
                                            الإجمالي
                                            <Tag
                                                className="mx-3 text-lg"
                                                bordered={false}
                                                color="success"
                                            >
                                                {formatCurrency(total)}
                                            </Tag>
                                        </Typography.Text>
                                    </div>
                                </div>
                            )}
                        </>
                    )}
                </Modal>
            )}
        </>
    );
}
