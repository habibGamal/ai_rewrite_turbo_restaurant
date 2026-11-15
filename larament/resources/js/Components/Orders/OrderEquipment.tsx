import { EquipmentOption, OrderEquipmentLine } from "@/types";
import { formatCurrency } from "@/utils/orderCalculations";
import { DeleteOutlined } from "@ant-design/icons";
import { router } from "@inertiajs/react";
import {
    Button,
    Divider,
    Empty,
    Input,
    InputNumber,
    message,
    Modal,
    Popconfirm,
    Typography,
} from "antd";
import React, { useEffect, useState } from "react";

/**
 * Equipment on the order (شيشة، بلايستيشن، ...): a flat price per use. Lines
 * are listed under the items; the «إضافة معدات» modal sets the quantity of
 * each equipment the order type allows and saves at once.
 */
export default function OrderEquipment({
    orderId,
    lines,
    options,
    disabled,
    canEdit,
    canRemove,
    beforeAction,
    open,
    onClose,
}: {
    orderId: number;
    lines: OrderEquipmentLine[];
    options: EquipmentOption[];
    disabled: boolean;
    canEdit: boolean;
    canRemove: boolean;
    beforeAction: (action: () => void) => void;
    open: boolean;
    onClose: () => void;
}) {
    const [quantities, setQuantities] = useState<Record<number, number>>({});
    const [search, setSearch] = useState("");

    const current = (equipmentId: number) =>
        lines.find((line) => line.equipment_id === equipmentId)?.quantity ?? 0;

    useEffect(() => {
        if (!open) return;
        const initial: Record<number, number> = {};
        lines.forEach((line) => {
            if (line.equipment_id != null) initial[line.equipment_id] = Number(line.quantity);
        });
        setQuantities(initial);
        setSearch("");
    }, [open]);

    // Equipment already on the order stays editable even if the type no
    // longer offers it (it can only go down then).
    const rows: EquipmentOption[] = [
        ...options,
        ...lines
            .filter(
                (line) =>
                    line.equipment_id != null &&
                    !options.some((option) => option.id === line.equipment_id)
            )
            .map((line) => ({
                id: line.equipment_id as number,
                name: line.name,
                price: Number(line.unit_price),
            })),
    ];

    const changed = rows.filter(
        (row) => (quantities[row.id] ?? 0) !== current(row.id)
    );
    const reducesAny = changed.some((row) => (quantities[row.id] ?? 0) < current(row.id));
    const total = rows.reduce(
        (sum, row) => sum + (quantities[row.id] ?? 0) * Number(row.price),
        0
    );

    const save = () => {
        if (changed.length === 0) {
            onClose();
            return;
        }
        beforeAction(() =>
            router.post(
                route("orders.equipment.save", orderId),
                {
                    lines: changed.map((row) => ({
                        equipment_id: row.id,
                        quantity: quantities[row.id] ?? 0,
                    })),
                },
                {
                    preserveScroll: true,
                    onSuccess: (page) => {
                        const errors = (page.props as any)?.errors ?? {};
                        if (Object.keys(errors).length === 0) onClose();
                    },
                    onError: (errors) =>
                        message.error(Object.values(errors)[0] || "تعذر حفظ المعدات"),
                }
            )
        );
    };

    return (
        <>
            {lines.length > 0 && (
                <>
                    <div className="my-4">
                        <Typography.Title level={5} className="!mb-3">
                            المعدات
                        </Typography.Title>
                        <div className="grid grid-cols-[minmax(0,1fr)_auto_auto_auto_auto] items-center gap-x-3 gap-y-2 text-sm">
                            <div className="text-xs text-gray-400 font-medium">المعدة</div>
                            <div className="text-xs text-gray-400 font-medium text-center">العدد</div>
                            <div className="text-xs text-gray-400 font-medium text-end">السعر</div>
                            <div className="text-xs text-gray-400 font-medium text-end">الإجمالي</div>
                            <div />
                            {lines.map((line) => (
                                <React.Fragment key={line.id}>
                                    <div className="truncate font-medium text-gray-800" title={line.name}>
                                        {line.name}
                                    </div>
                                    <div className="text-center text-gray-600">{line.quantity}</div>
                                    <div className="text-end text-gray-500 whitespace-nowrap text-xs">
                                        {formatCurrency(Number(line.unit_price))}
                                    </div>
                                    <div className="text-end font-semibold text-gray-800 whitespace-nowrap text-xs">
                                        {formatCurrency(Number(line.total))}
                                    </div>
                                    <div className="text-center">
                                        {canEdit && canRemove && (
                                            <Popconfirm
                                                title="حذف المعدة من الطلب؟"
                                                okText="نعم"
                                                cancelText="لا"
                                                disabled={disabled}
                                                onConfirm={() =>
                                                    beforeAction(() =>
                                                        router.delete(
                                                            route("orders.equipment.remove", [orderId, line.id]),
                                                            {
                                                                preserveScroll: true,
                                                                onError: (errors) =>
                                                                    message.error(
                                                                        Object.values(errors)[0] ||
                                                                            "تعذر حذف المعدة"
                                                                    ),
                                                            }
                                                        )
                                                    )
                                                }
                                            >
                                                <Button
                                                    disabled={disabled}
                                                    size="small"
                                                    danger
                                                    icon={<DeleteOutlined />}
                                                    aria-label={`حذف ${line.name}`}
                                                />
                                            </Popconfirm>
                                        )}
                                    </div>
                                </React.Fragment>
                            ))}
                        </div>
                    </div>
                    <Divider />
                </>
            )}

            {canEdit && (
                <Modal
                    title="إضافة معدات"
                    open={open}
                    onCancel={onClose}
                    onOk={save}
                    okText="حفظ"
                    cancelText="إلغاء"
                    okButtonProps={{ disabled: disabled || (reducesAny && !canRemove) }}
                >
                    {rows.length === 0 ? (
                        <Empty description="لا توجد معدات متاحة لهذا النوع من الطلبات" />
                    ) : (
                        <>
                            <Input.Search
                                placeholder="ابحث عن معدة"
                                value={search}
                                onChange={(event) => setSearch(event.target.value)}
                            />
                            <div className="max-h-72 overflow-y-auto">
                                {rows
                                    .filter((row) =>
                                        row.name.toLocaleLowerCase().includes(search.toLocaleLowerCase())
                                    )
                                    .map((row) => {
                                        const quantity = quantities[row.id] ?? 0;
                                        const offered = options.some((option) => option.id === row.id);
                                        const min = canRemove ? 0 : current(row.id);
                                        const max = offered ? 9999 : current(row.id);
                                        const set = (value: number) =>
                                            setQuantities((prev) => ({
                                                ...prev,
                                                [row.id]: Math.min(max, Math.max(min, Math.floor(value))),
                                            }));
                                        return (
                                            <div
                                                key={row.id}
                                                className="flex flex-wrap items-center justify-between gap-2 border-b py-2"
                                            >
                                                <span>
                                                    {row.name} — {formatCurrency(Number(row.price))}
                                                </span>
                                                <div className="flex items-center gap-1" dir="ltr">
                                                    <Button
                                                        aria-label={`تقليل ${row.name}`}
                                                        disabled={quantity <= min}
                                                        onClick={() => set(quantity - 1)}
                                                    >
                                                        −
                                                    </Button>
                                                    <InputNumber
                                                        aria-label={`عدد ${row.name}`}
                                                        min={min}
                                                        max={max}
                                                        precision={0}
                                                        value={quantity}
                                                        onChange={(value) => set(Number(value ?? 0))}
                                                    />
                                                    <Button
                                                        aria-label={`زيادة ${row.name}`}
                                                        disabled={quantity >= max}
                                                        onClick={() => set(quantity + 1)}
                                                    >
                                                        +
                                                    </Button>
                                                </div>
                                            </div>
                                        );
                                    })}
                            </div>
                            <div className="mt-4">
                                <Typography.Text strong>
                                    إجمالي المعدات {formatCurrency(total)}
                                </Typography.Text>
                                {!canRemove && (
                                    <div className="text-xs text-gray-500 mt-1">
                                        تقليل أو حذف المعدات بعد إضافتها يحتاج صلاحية.
                                    </div>
                                )}
                            </div>
                        </>
                    )}
                </Modal>
            )}
        </>
    );
}
