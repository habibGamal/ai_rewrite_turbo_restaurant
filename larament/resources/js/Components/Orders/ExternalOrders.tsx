import { ExternalOrder, ManageOrderProps } from "@/types";
import { formatCurrency } from "@/utils/orderCalculations";
import { CheckOutlined, DeleteOutlined, EditOutlined } from "@ant-design/icons";
import { router } from "@inertiajs/react";
import {
    Button,
    Divider,
    Input,
    InputNumber,
    message,
    Modal,
    Popconfirm,
    Select,
    Tag,
    Typography,
} from "antd";
import React, { useState } from "react";

type Product = ManageOrderProps["sellers"][number]["products"][number];

function ProductRow({
    product,
    quantity,
    onChange,
}: {
    product: Product;
    quantity: number;
    onChange: (quantity: number) => void;
}) {
    return (
        <div className="flex flex-wrap items-center justify-between gap-2 border-b py-2">
            <span>
                {product.name} — {Number(product.price).toFixed(2)}
            </span>
            <div className="flex items-center gap-1" dir="ltr">
                <Button
                    aria-label={`تقليل كمية ${product.name}`}
                    onClick={() => onChange(Math.max(0, quantity - 1))}
                >
                    −
                </Button>
                <InputNumber
                    aria-label={`كمية ${product.name}`}
                    min={0}
                    max={999999999}
                    precision={3}
                    value={quantity}
                    onChange={(value) => onChange(value ?? 0)}
                />
                <Button
                    aria-label={`زيادة كمية ${product.name}`}
                    onClick={() => onChange(Math.min(999999999, quantity + 1))}
                >
                    +
                </Button>
            </div>
        </div>
    );
}

function formatQuantity(quantity: number | string): string {
    const num = Number(quantity);
    if (isNaN(num)) return "0";
    return Number(num.toFixed(3)).toString();
}

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
    const picked =
        seller?.products.filter((product) => (quantities[product.id] ?? 0) > 0) ?? [];
    const total = picked.reduce(
        (sum, product) =>
            sum +
            Math.round(Number(product.price) * quantities[product.id] * 100) / 100,
        0
    );

    const isModalOpen = Boolean(open) || internalOpen;

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

    const handleEditSeller = (sellerIdToEdit: number) => {
        selectSeller(sellerIdToEdit);
        setInternalOpen(true);
    };

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
                                <div
                                    key={external.id}
                                    className="isolate-3 flex flex-col gap-3"
                                >
                                    {/* Seller Header */}
                                    <div className="flex items-center justify-between gap-2 border-b border-gray-100 pb-3">
                                        <div className="flex items-center gap-2 flex-wrap">
                                            <Typography.Text
                                                strong
                                                className="text-base text-gray-900"
                                            >
                                                {external.seller_name}
                                            </Typography.Text>
                                            <Tag
                                                color={
                                                    external.status === "fulfilled"
                                                        ? "green"
                                                        : "orange"
                                                }
                                                className="!m-0"
                                            >
                                                {external.status === "fulfilled"
                                                    ? "تم التنفيذ"
                                                    : "قيد التنفيذ"}
                                            </Tag>
                                        </div>
                                        <Tag
                                            className="!m-0 text-base font-semibold"
                                            bordered={false}
                                            color="success"
                                        >
                                            {formatCurrency(Number(external.total))}
                                        </Tag>
                                    </div>

                                    {/* Order Lines */}
                                    <div className="grid grid-cols-[minmax(0,1fr)_auto_auto_auto_auto] items-center gap-x-3 gap-y-2 text-sm">
                                        <div className="text-xs text-gray-400 font-medium">
                                            الصنف
                                        </div>
                                        <div className="text-xs text-gray-400 font-medium text-center">
                                            الكمية
                                        </div>
                                        <div className="text-xs text-gray-400 font-medium text-end">
                                            السعر
                                        </div>
                                        <div className="text-xs text-gray-400 font-medium text-end">
                                            الإجمالي
                                        </div>
                                        <div />

                                        {external.items.map((item) => (
                                            <React.Fragment key={item.id}>
                                                <div
                                                    className="truncate font-medium text-gray-800"
                                                    title={item.name}
                                                >
                                                    {item.name}
                                                </div>
                                                <div className="text-center text-gray-600 whitespace-nowrap">
                                                    {formatQuantity(item.quantity)}
                                                </div>
                                                <div className="text-end text-gray-500 whitespace-nowrap text-xs">
                                                    {formatCurrency(Number(item.price))}
                                                </div>
                                                <div className="text-end font-semibold text-gray-800 whitespace-nowrap text-xs">
                                                    {formatCurrency(Number(item.total))}
                                                </div>
                                                <div className="text-center">
                                                    <Popconfirm
                                                        title="حذف الصنف؟"
                                                        okText="نعم"
                                                        cancelText="لا"
                                                        disabled={disabled}
                                                        onConfirm={() =>
                                                            beforeAction(() => {
                                                                router.delete(
                                                                    route(
                                                                        "orders.external.removeLine",
                                                                        [
                                                                            orderId,
                                                                            external.id,
                                                                            item.id,
                                                                        ]
                                                                    )
                                                                );
                                                            })
                                                        }
                                                    >
                                                        <Button
                                                            disabled={disabled}
                                                            size="small"
                                                            danger
                                                            icon={<DeleteOutlined />}
                                                            aria-label={`حذف ${item.name}`}
                                                        />
                                                    </Popconfirm>
                                                </div>
                                            </React.Fragment>
                                        ))}
                                    </div>

                                    {/* Card Actions */}
                                    <div className="flex items-center justify-between border-t border-gray-100 pt-3">
                                        <div className="flex items-center gap-2">
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
                                            {external.status !== "fulfilled" ? (
                                                <Button
                                                    size="small"
                                                    type="primary"
                                                    icon={<CheckOutlined />}
                                                    disabled={disabled}
                                                    onClick={() =>
                                                        beforeAction(() => {
                                                            router.post(
                                                                route(
                                                                    "orders.external.fulfill",
                                                                    [
                                                                        orderId,
                                                                        external.id,
                                                                    ]
                                                                )
                                                            );
                                                        })
                                                    }
                                                >
                                                    تم التنفيذ
                                                </Button>
                                            ) : (
                                                <Button
                                                    size="small"
                                                    icon={<CheckOutlined />}
                                                    disabled
                                                >
                                                    تم التنفيذ
                                                </Button>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                    <Divider />
                </>
            )}

            <Modal
                title="إضافة طلب خارجي"
                open={isModalOpen}
                onCancel={closeModal}
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
                <Select
                    className="mb-3 w-full"
                    placeholder="اختر البائع"
                    value={sellerId}
                    onChange={selectSeller}
                    options={sellers.map((entry) => ({
                        value: entry.id,
                        label: entry.name,
                    }))}
                />
                {seller && seller.products.length === 0 && (
                    <p>
                        لا توجد أصناف لهذا البائع — أضفها من إدارة البائعين
                        الخارجيين
                    </p>
                )}
                {seller && seller.products.length > 0 && (
                    <>
                        <Input.Search
                            placeholder="ابحث عن صنف"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                        />
                        <div className="max-h-64 overflow-y-auto">
                            {seller.products
                                .filter((product) =>
                                    product.name
                                        .toLocaleLowerCase()
                                        .includes(search.toLocaleLowerCase())
                                )
                                .map((product) => (
                                    <ProductRow
                                        key={product.id}
                                        product={product}
                                        quantity={quantities[product.id] ?? 0}
                                        onChange={(quantity) =>
                                            setQuantities((current) => ({
                                                ...current,
                                                [product.id]: quantity,
                                            }))
                                        }
                                    />
                                ))}
                        </div>
                        {picked.length > 0 && (
                            <div className="mt-4 space-y-1">
                                {picked.map((product) => (
                                    <div key={product.id}>
                                        {product.name} × {quantities[product.id]} ={" "}
                                        {(
                                            Math.round(
                                                Number(product.price) *
                                                    quantities[product.id] *
                                                    100
                                            ) / 100
                                        ).toFixed(2)}
                                    </div>
                                ))}
                                <Typography.Text strong>
                                    الإجمالي {total.toFixed(2)}
                                </Typography.Text>
                            </div>
                        )}
                    </>
                )}
            </Modal>
        </>
    );
}
