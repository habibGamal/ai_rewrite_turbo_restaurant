import React from 'react';
import { Modal, Button, Table, Tag } from 'antd';
import { OrderItemData } from '@/types';

interface ChangedItem {
    product_id: number;
    name: string;
    old_quantity: number;
    new_quantity: number;
    delta: number;
    notes?: string;
}

interface PrintChangesModalProps {
    open: boolean;
    onCancel: () => void;
    onSaveOnly: () => void;
    onSaveAndPrint: () => void;
    loading: boolean;
    changedItems: ChangedItem[];
}

export default function PrintChangesModal({
    open,
    onCancel,
    onSaveOnly,
    onSaveAndPrint,
    loading,
    changedItems,
}: PrintChangesModalProps) {
    const columns = [
        {
            title: 'المنتج',
            dataIndex: 'name',
            key: 'name',
        },
        {
            title: 'الكمية السابقة',
            dataIndex: 'old_quantity',
            key: 'old_quantity',
            align: 'center' as const,
        },
        {
            title: 'الكمية الجديدة',
            dataIndex: 'new_quantity',
            key: 'new_quantity',
            align: 'center' as const,
        },
        {
            title: 'التغيير',
            dataIndex: 'delta',
            key: 'delta',
            align: 'center' as const,
            render: (delta: number) => (
                <Tag color={delta > 0 ? 'green' : 'red'}>
                    {delta > 0 ? `+${delta}` : delta}
                </Tag>
            ),
        },
    ];

    return (
        <Modal
            title="تغييرات الطلب"
            open={open}
            onCancel={onCancel}
            footer={[
                <Button key="save-only" onClick={onSaveOnly} loading={loading}>
                    حفظ فقط
                </Button>,
                <Button
                    key="save-print"
                    type="primary"
                    onClick={onSaveAndPrint}
                    loading={loading}
                >
                    حفظ وطباعة في المطبخ
                </Button>,
            ]}
            destroyOnClose
        >
            <Table
                columns={columns}
                dataSource={changedItems}
                rowKey="product_id"
                pagination={false}
                size="middle"
            />
        </Modal>
    );
}

export function detectChangedItems(orderItems: OrderItemData[], initOrderItems: OrderItemData[]): ChangedItem[] {
    const changes: ChangedItem[] = [];

    const currentItemIds = new Set(orderItems.map((item) => item.product_id));

    for (const item of orderItems) {
        const initial = item.initial_quantity ?? 0;
        if (item.quantity !== initial) {
            changes.push({
                product_id: item.product_id,
                name: item.name,
                old_quantity: initial,
                new_quantity: item.quantity,
                delta: item.quantity - initial,
                notes: item.notes,
            });
        }
    }

    for (const item of initOrderItems) {
        if (!currentItemIds.has(item.product_id)) {
            changes.push({
                product_id: item.product_id,
                name: item.name,
                old_quantity: item.quantity,
                new_quantity: 0,
                delta: -item.quantity,
                notes: item.notes,
            });
        }
    }

    return changes;
}
