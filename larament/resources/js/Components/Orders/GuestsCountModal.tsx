import React, { useEffect, useState } from "react";
import { Button, InputNumber, Modal, Typography } from "antd";
import { TeamOutlined } from "@ant-design/icons";
import type { GuestsMode } from "@/types";

interface GuestsCountModalProps {
    open: boolean;
    mode: GuestsMode;
    initialValue?: number | null;
    title?: string;
    okText?: string;
    skipText?: string;
    loading?: boolean;
    onCancel: () => void;
    /** null = the cashier skipped (optional mode only). */
    onSubmit: (guests: number | null) => void;
}

const QUICK_COUNTS = [1, 2, 3, 4, 5, 6, 7, 8];

/**
 * Asks the cashier for «عدد الأفراد». Optional mode offers a skip button;
 * required mode only accepts a number from 1 to 999.
 */
export default function GuestsCountModal({
    open,
    mode,
    initialValue,
    title = "عدد الأفراد",
    okText = "تأكيد",
    skipText = "تخطي",
    loading = false,
    onCancel,
    onSubmit,
}: GuestsCountModalProps) {
    const [value, setValue] = useState<number | null>(initialValue ?? null);

    useEffect(() => {
        if (open) {
            setValue(initialValue ?? null);
        }
    }, [open, initialValue]);

    const valid = value !== null && value >= 1 && value <= 999;

    const footer = [
        <Button key="cancel" onClick={onCancel}>
            إلغاء
        </Button>,
        mode === "optional" && (
            <Button key="skip" onClick={() => onSubmit(null)} loading={loading}>
                {skipText}
            </Button>
        ),
        <Button
            key="ok"
            type="primary"
            disabled={!valid}
            loading={loading}
            onClick={() => valid && onSubmit(value)}
        >
            {okText}
        </Button>,
    ].filter(Boolean);

    return (
        <Modal
            title={
                <span className="flex items-center gap-2">
                    <TeamOutlined />
                    {title}
                </span>
            }
            open={open}
            onCancel={onCancel}
            footer={footer}
            destroyOnClose
            width={420}
        >
            <div dir="rtl" className="space-y-4">
                <div className="grid grid-cols-4 gap-2">
                    {QUICK_COUNTS.map((count) => (
                        <Button
                            key={count}
                            size="large"
                            type={value === count ? "primary" : "default"}
                            onClick={() => setValue(count)}
                        >
                            {count}
                        </Button>
                    ))}
                </div>
                <InputNumber
                    size="large"
                    className="w-full"
                    min={1}
                    max={999}
                    precision={0}
                    value={value}
                    placeholder="عدد آخر"
                    autoFocus
                    onChange={(next) => setValue(next === null ? null : Number(next))}
                    onPressEnter={() => valid && onSubmit(value)}
                />
                {mode === "required" && (
                    <Typography.Text type="secondary">
                        عدد الأفراد إجباري لهذا النوع من الطلبات.
                    </Typography.Text>
                )}
            </div>
        </Modal>
    );
}
