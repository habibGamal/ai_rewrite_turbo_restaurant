import React, { useEffect, useState } from "react";
import { Button, Empty, List, Modal, Popconfirm, Spin, Tag, Typography, message } from "antd";
import { DeleteOutlined, PlayCircleOutlined } from "@ant-design/icons";
import axios from "axios";
import { formatCurrency } from "@/utils/orderCalculations";
import type { InstantCartCustomer, InstantCartDiscount } from "@/types";
import { errorMessage } from "./instantCart";

export interface HeldSaleLine {
    product_id: number;
    quantity: number;
    notes?: string | null;
    item_discount?: number | null;
    item_discount_type?: "percent" | "value" | null;
    item_discount_percent?: number | null;
}

export interface HeldSale {
    id: number;
    label: string | null;
    lines: HeldSaleLine[];
    meta: { discount?: InstantCartDiscount | null; customer?: InstantCartCustomer | null } | null;
    total: number;
    user: { id: number; name: string } | null;
    created_at: string;
}

interface HeldSalesModalProps {
    open: boolean;
    onClose: () => void;
    onResume: (held: HeldSale) => void;
    onCountChange: (count: number) => void;
}

export default function HeldSalesModal({ open, onClose, onResume, onCountChange }: HeldSalesModalProps) {
    const [loading, setLoading] = useState(false);
    const [held, setHeld] = useState<HeldSale[]>([]);

    useEffect(() => {
        if (!open) return;
        setLoading(true);
        axios
            .get<{ data: HeldSale[] }>(route("pos.held-sales.index"))
            .then(({ data }) => {
                setHeld(data.data ?? []);
                onCountChange((data.data ?? []).length);
            })
            .catch((error) => message.error(errorMessage(error, "تعذر تحميل الفواتير المعلقة")))
            .finally(() => setLoading(false));
    }, [open]);

    const remove = async (sale: HeldSale) => {
        try {
            await axios.delete(route("pos.held-sales.destroy", sale.id));
            const next = held.filter((item) => item.id !== sale.id);
            setHeld(next);
            onCountChange(next.length);
            message.success("تم حذف الفاتورة المعلقة");
        } catch (error) {
            message.error(errorMessage(error, "تعذر حذف الفاتورة المعلقة"));
        }
    };

    return (
        <Modal title="الفواتير المعلقة" open={open} onCancel={onClose} footer={null} width={700} destroyOnClose>
            <Spin spinning={loading}>
                {held.length === 0 && !loading ? (
                    <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="لا توجد فواتير معلقة" />
                ) : (
                    <List
                        dataSource={held}
                        renderItem={(sale) => (
                            <List.Item
                                actions={[
                                    <Button
                                        key="resume"
                                        type="primary"
                                        icon={<PlayCircleOutlined />}
                                        onClick={() => onResume(sale)}
                                    >
                                        استكمال
                                    </Button>,
                                    <Popconfirm
                                        key="delete"
                                        title="حذف الفاتورة المعلقة؟"
                                        okText="نعم"
                                        cancelText="لا"
                                        onConfirm={() => remove(sale)}
                                    >
                                        <Button danger icon={<DeleteOutlined />} />
                                    </Popconfirm>,
                                ]}
                            >
                                <List.Item.Meta
                                    title={
                                        <span className="flex items-center gap-2">
                                            {sale.label || sale.meta?.customer?.name || `معلقة #${sale.id}`}
                                            <Tag>{sale.lines?.length ?? 0} صنف</Tag>
                                        </span>
                                    }
                                    description={
                                        <Typography.Text type="secondary">
                                            {formatCurrency(Number(sale.total))}
                                            {sale.user?.name ? ` · ${sale.user.name}` : ""}
                                            {sale.created_at
                                                ? ` · ${new Date(sale.created_at).toLocaleTimeString("ar-EG", { hour: "2-digit", minute: "2-digit" })}`
                                                : ""}
                                        </Typography.Text>
                                    }
                                />
                            </List.Item>
                        )}
                    />
                )}
            </Spin>
        </Modal>
    );
}
