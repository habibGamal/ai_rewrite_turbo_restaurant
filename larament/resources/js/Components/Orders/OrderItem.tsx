import React, { useState } from "react";
import { Button, InputNumber, Tag, Typography, Modal, Input, Radio, Form } from "antd";
import {
    MinusCircleOutlined,
    PlusCircleOutlined,
    DeleteOutlined,
    EditOutlined,
    PercentageOutlined,
} from "@ant-design/icons";

import { OrderItemData, OrderItemAction, User } from "@/types";
import { formatCurrency } from "@/utils/orderCalculations";

const { TextArea } = Input;

interface OrderItemProps {
    orderItem: OrderItemData;
    dispatch: React.Dispatch<OrderItemAction>;
    disabled?: boolean;
    user: User;
    forWeb?: boolean; // New prop for web orders
}

export default function OrderItem({
    orderItem,
    dispatch,
    disabled,
    user,
    forWeb,
}: OrderItemProps) {
    const [isNotesModalOpen, setIsNotesModalOpen] = useState(false);
    const [isDiscountModalOpen, setIsDiscountModalOpen] = useState(false);
    const [notes, setNotes] = useState(orderItem.notes || "");
    const [discountForm] = Form.useForm();

    // For web orders, disable quantity changes but allow notes editing
    const quantityDisabled = disabled || forWeb;

    const onChangeQuantity = (quantity: number | null) => {
        if (quantity !== null) {
            dispatch({
                type: "changeQuantity",
                id: orderItem.product_id,
                quantity,
                user,
            });
        }
    };

    const onIncrement = () => {
        dispatch({ type: "increment", id: orderItem.product_id, user });
    };

    const onDecrement = () => {
        dispatch({ type: "decrement", id: orderItem.product_id, user });
    };

    const onDelete = () => {
        dispatch({ type: "delete", id: orderItem.product_id, user });
    };

    const onSaveNotes = () => {
        dispatch({
            type: "changeNotes",
            id: orderItem.product_id,
            notes,
            user,
        });
        setIsNotesModalOpen(false);
    };

    const onOpenNotesModal = () => {
        setNotes(orderItem.notes || "");
        setIsNotesModalOpen(true);
    };

    const onOpenDiscountModal = () => {
        discountForm.setFieldsValue({
            discount_type: orderItem.item_discount_type || 'value',
            discount: orderItem.item_discount_type === 'percent'
                ? orderItem.item_discount_percent || 0
                : orderItem.item_discount || 0,
        });
        setIsDiscountModalOpen(true);
    };

    const onSaveDiscount = () => {
        discountForm.validateFields().then((values) => {
            const discountType = values.discount_type;
            const discountValue = values.discount || 0;

            let itemDiscount = 0;
            let itemDiscountPercent = undefined;

            if (discountType === 'percent') {
                itemDiscountPercent = discountValue;
                // Calculate actual discount amount for display
                const itemSubtotal = orderItem.price * orderItem.quantity;
                itemDiscount = itemSubtotal * (discountValue / 100);
            } else {
                itemDiscount = discountValue;
            }

            dispatch({
                type: 'changeItemDiscount',
                id: orderItem.product_id,
                discount: itemDiscount,
                discountType: discountType,
                discountPercent: itemDiscountPercent,
                user,
            });
            setIsDiscountModalOpen(false);
        });
    };

    const itemSubtotal = orderItem.price * orderItem.quantity;
    const itemDiscount = orderItem.item_discount || 0;
    const itemTotal = itemSubtotal - itemDiscount;

    // Parse JSON notes for web orders
    const parseWebNotes = (notes: string | null | undefined) => {
        if (!notes || !forWeb) return null;
        
        const jsonPrefix = "json::";
        if (notes.startsWith(jsonPrefix)) {
            try {
                const jsonString = notes.substring(jsonPrefix.length);
                const parsed = JSON.parse(jsonString);
                return parsed;
            } catch (e) {
                console.error("Failed to parse order item notes:", e);
                return null;
            }
        }
        return null;
    };

    const parsedNotes = parseWebNotes(orderItem.notes);
    const displayAsPlainText = forWeb && orderItem.notes && !parsedNotes;

    console.log("Rendering OrderItem:", orderItem);
    const quantityCanBeFraction = ["kg", "كجم", "كيلوجرام"].includes(
        orderItem.product.unit
    );
    return (
        <>
            <div className="isolate-3 flex flex-col gap-4 my-4">
                <div className="flex justify-between items-center">
                    <div className="flex flex-col gap-1">
                        <Typography.Paragraph className="!my-0">
                            {orderItem.name}
                        </Typography.Paragraph>
                        {parsedNotes && (
                            <div className="flex flex-wrap gap-1">
                                {Object.entries(parsedNotes).map(([key, value]) => (
                                    <Tag key={key} color="blue">
                                        {key}: {String(value)}
                                    </Tag>
                                ))}
                            </div>
                        )}
                        {displayAsPlainText && (
                            <Typography.Paragraph
                                className="!my-0 !text-sm text-gray-500 ltr"
                                ellipsis={{ rows: 2 }}
                            >
                                {orderItem.notes}
                            </Typography.Paragraph>
                        )}
                    </div>
                    <div className="flex gap-2">
                        <Button
                            disabled={quantityDisabled}
                            onClick={onDecrement}
                            className="icon-button"
                            icon={<MinusCircleOutlined />}
                            size="small"
                        />
                        <InputNumber
                            disabled={quantityDisabled}
                            min={0.001}
                            step={1}
                            precision={quantityCanBeFraction ? 3 : 0}
                            value={orderItem.quantity}
                            onChange={onChangeQuantity}
                            style={{ width: 80 }}
                        />
                        <Button
                            disabled={quantityDisabled}
                            onClick={onIncrement}
                            className="icon-button"
                            icon={<PlusCircleOutlined />}
                            size="small"
                        />
                    </div>
                </div>
                <div className="flex justify-between items-center">
                    <div className="flex flex-col gap-1">
                        <Typography.Text>
                            السعر :
                            <Tag
                                className="mx-4 text-lg"
                                bordered={false}
                                color="success"
                            >
                                {formatCurrency(itemSubtotal)}
                            </Tag>
                        </Typography.Text>
                        {itemDiscount > 0 && (
                            <>
                                <Typography.Text>
                                    الخصم :
                                    <Tag
                                        className="mx-4 text-lg"
                                        bordered={false}
                                        color="error"
                                    >
                                        {formatCurrency(itemDiscount)}
                                        {orderItem.item_discount_type === 'percent' &&
                                            ` (${orderItem.item_discount_percent}%)`
                                        }
                                    </Tag>
                                </Typography.Text>
                                <Typography.Text strong>
                                    الإجمالي :
                                    <Tag
                                        className="mx-4 text-lg"
                                        bordered={false}
                                        color="blue"
                                    >
                                        {formatCurrency(itemTotal)}
                                    </Tag>
                                </Typography.Text>
                            </>
                        )}
                    </div>
                    <div className="flex gap-4">
                        <Button
                            disabled={quantityDisabled}
                            onClick={onDelete}
                            danger
                            type="primary"
                            className="icon-button"
                            icon={<DeleteOutlined />}
                            size="small"
                        />
                        {user.canApplyDiscounts && (
                            <Button
                                disabled={disabled}
                                onClick={onOpenDiscountModal}
                                type="default"
                                className="icon-button"
                                icon={<PercentageOutlined />}
                                size="small"
                            />
                        )}
                        <Button
                            disabled={disabled} // Notes editing should follow the general disabled state
                            onClick={onOpenNotesModal}
                            type="primary"
                            className="icon-button"
                            icon={<EditOutlined />}
                            size="small"
                        />
                    </div>
                </div>
            </div>

            <Modal
                title="ملاحظات الصنف"
                open={isNotesModalOpen}
                onOk={onSaveNotes}
                onCancel={() => setIsNotesModalOpen(false)}
                okText="حفظ"
                cancelText="إلغاء"
            >
                <TextArea
                    value={notes}
                    onChange={(e) => setNotes(e.target.value)}
                    placeholder="اكتب ملاحظات الصنف هنا..."
                    rows={4}
                />
            </Modal>

            <Modal
                title="خصم الصنف"
                open={isDiscountModalOpen}
                onOk={onSaveDiscount}
                onCancel={() => setIsDiscountModalOpen(false)}
                okText="تطبيق"
                cancelText="إلغاء"
            >
                <Form
                    form={discountForm}
                    layout="vertical"
                    initialValues={{
                        discount_type: 'value',
                        discount: 0,
                    }}
                >
                    <Form.Item name="discount_type" label="نوع الخصم">
                        <Radio.Group>
                            <Radio value="value">مبلغ ثابت</Radio>
                            <Radio value="percent">نسبة مئوية</Radio>
                        </Radio.Group>
                    </Form.Item>

                    <Form.Item
                        name="discount"
                        label="قيمة الخصم"
                        rules={[
                            { required: true, message: 'قيمة الخصم مطلوبة' },
                            {
                                validator: (_, value) => {
                                    const discountType = discountForm.getFieldValue('discount_type');
                                    if (discountType === 'percent' && value > 100) {
                                        return Promise.reject('نسبة الخصم يجب ألا تتجاوز 100%');
                                    }
                                    if (discountType === 'value' && value > itemSubtotal) {
                                        return Promise.reject('قيمة الخصم يجب ألا تتجاوز المجموع');
                                    }
                                    return Promise.resolve();
                                },
                            },
                        ]}
                    >
                        <InputNumber
                            min={0}
                            style={{ width: '100%' }}
                            placeholder="0"
                        />
                    </Form.Item>

                    <Typography.Text type="secondary">
                        مجموع الصنف: {formatCurrency(itemSubtotal)}
                    </Typography.Text>
                </Form>
            </Modal>
        </>
    );
}
