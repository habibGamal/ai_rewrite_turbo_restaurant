import { OrderFee } from "@/types";
import { formatCurrency } from "@/utils/orderCalculations";
import { DeleteOutlined, PlusOutlined } from "@ant-design/icons";
import { router } from "@inertiajs/react";
import {
    AutoComplete,
    Button,
    Divider,
    InputNumber,
    Modal,
    Popconfirm,
    Typography,
} from "antd";
import React, { useMemo, useState } from "react";
import CanAccess from "@/Components/CanAccess";

type FeeRow = { key: number; name: string; amount: number | null };

let nextKey = 1;
const emptyRow = (): FeeRow => ({ key: nextKey++, name: "", amount: null });

const normalise = (name: string) => name.trim().replace(/\s+/g, " ");

/**
 * «رسوم إضافية»: saved fee lines under the order items, and the modal that
 * adds new ones (free-text name with suggestions from earlier fees, and an
 * amount). Fees are added to the bill after service and discount.
 */
export default function OrderFees({
    orderId,
    fees,
    suggestions,
    disabled,
    open,
    onClose,
    beforeAction,
}: {
    orderId: number;
    fees: OrderFee[];
    suggestions: string[];
    disabled: boolean;
    open: boolean;
    onClose: () => void;
    beforeAction: (action: () => void) => void;
}) {
    const [rows, setRows] = useState<FeeRow[]>([emptyRow()]);
    const [saving, setSaving] = useState(false);

    const filled = rows.filter(
        (row) => normalise(row.name) !== "" && (row.amount ?? 0) > 0
    );
    const incomplete = rows.some(
        (row) =>
            (normalise(row.name) !== "") !== ((row.amount ?? 0) > 0)
    );
    const newTotal = filled.reduce((sum, row) => sum + Number(row.amount), 0);

    const close = () => {
        setRows([emptyRow()]);
        onClose();
    };

    const update = (key: number, patch: Partial<FeeRow>) =>
        setRows((current) =>
            current.map((row) => (row.key === key ? { ...row, ...patch } : row))
        );

    const submit = () => {
        if (filled.length === 0 || incomplete) return;
        setSaving(true);
        beforeAction(() =>
            router.post(
                route("orders.fees.store", orderId),
                {
                    fees: filled.map((row) => ({
                        name: normalise(row.name),
                        amount: Number(row.amount),
                    })),
                },
                {
                    preserveScroll: true,
                    onSuccess: (page) => {
                        if (Object.keys(page.props.errors ?? {}).length === 0) {
                            close();
                        }
                    },
                    onFinish: () => setSaving(false),
                }
            )
        );
    };

    const remove = (feeId: number) =>
        beforeAction(() =>
            router.delete(route("orders.fees.destroy", [orderId, feeId]), {
                preserveScroll: true,
            })
        );

    return (
        <>
            {fees.length > 0 && (
                <>
                    <div className="my-4">
                        <Typography.Title level={5} className="!mb-3">
                            رسوم إضافية
                        </Typography.Title>
                        <div className="flex flex-col gap-2">
                            {fees.map((fee) => (
                                <div
                                    key={fee.id}
                                    className="flex items-center justify-between gap-2 rounded-lg border p-3"
                                >
                                    <span>{fee.name}</span>
                                    <div className="flex items-center gap-2">
                                        <span className="font-bold">
                                            {formatCurrency(Number(fee.amount))}
                                        </span>
                                        <CanAccess permission="order.remove_fees">
                                            <Popconfirm
                                                title="حذف هذه الرسوم؟"
                                                okText="نعم"
                                                cancelText="لا"
                                                disabled={disabled}
                                                onConfirm={() => remove(fee.id)}
                                            >
                                                <Button
                                                    aria-label={`حذف ${fee.name}`}
                                                    size="small"
                                                    danger
                                                    disabled={disabled}
                                                    icon={<DeleteOutlined />}
                                                />
                                            </Popconfirm>
                                        </CanAccess>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                    <Divider />
                </>
            )}

            <Modal
                title="رسوم إضافية"
                open={open}
                onCancel={close}
                onOk={submit}
                okText="إضافة"
                cancelText="إلغاء"
                confirmLoading={saving}
                okButtonProps={{
                    disabled: disabled || filled.length === 0 || incomplete,
                }}
                destroyOnClose
            >
                <div className="flex flex-col gap-3">
                    {rows.map((row, index) => (
                        <FeeRowInput
                            key={row.key}
                            row={row}
                            index={index}
                            suggestions={suggestions}
                            canRemove={rows.length > 1}
                            onChange={(patch) => update(row.key, patch)}
                            onRemove={() =>
                                setRows((current) =>
                                    current.filter((r) => r.key !== row.key)
                                )
                            }
                            onEnter={submit}
                        />
                    ))}
                    <Button
                        icon={<PlusOutlined />}
                        onClick={() => setRows((current) => [...current, emptyRow()])}
                        disabled={rows.length >= 20}
                    >
                        إضافة بند
                    </Button>
                    {incomplete && (
                        <Typography.Text type="warning">
                            اكتب اسم الرسوم وقيمتها في كل بند
                        </Typography.Text>
                    )}
                    {newTotal > 0 && (
                        <Typography.Text strong>
                            إجمالي الرسوم الجديدة: {formatCurrency(newTotal)}
                        </Typography.Text>
                    )}
                </div>
            </Modal>
        </>
    );
}

function FeeRowInput({
    row,
    index,
    suggestions,
    canRemove,
    onChange,
    onRemove,
    onEnter,
}: {
    row: FeeRow;
    index: number;
    suggestions: string[];
    canRemove: boolean;
    onChange: (patch: Partial<FeeRow>) => void;
    onRemove: () => void;
    onEnter: () => void;
}) {
    const options = useMemo(() => {
        const typed = normalise(row.name).toLowerCase();
        return suggestions
            .filter((name) => typed === "" || name.toLowerCase().includes(typed))
            .slice(0, 10)
            .map((name) => ({ value: name }));
    }, [row.name, suggestions]);

    return (
        <div className="flex items-center gap-2">
            <AutoComplete
                aria-label={`اسم الرسوم ${index + 1}`}
                className="flex-1"
                size="large"
                autoFocus={index === 0}
                placeholder="اسم الرسوم (مثال: رسوم دخول تورتة)"
                value={row.name}
                options={options}
                maxLength={100}
                onChange={(value) => onChange({ name: value })}
            />
            <InputNumber
                aria-label={`قيمة الرسوم ${index + 1}`}
                size="large"
                className="w-36"
                min={0}
                max={999999999}
                precision={2}
                placeholder="القيمة"
                value={row.amount}
                onChange={(value) => onChange({ amount: value })}
                onPressEnter={onEnter}
            />
            <Button
                aria-label={`حذف البند ${index + 1}`}
                size="large"
                danger
                disabled={!canRemove}
                onClick={onRemove}
                icon={<DeleteOutlined />}
            />
        </div>
    );
}
