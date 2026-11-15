import React, { useState, useEffect } from 'react';
import { Modal, Form, Radio, message, InputNumber, Spin } from 'antd';
import { router } from '@inertiajs/react';
import { ChangeTypeOption, Order } from '@/types';
import useTableTypes from '@/hooks/useTableTypes';
import { useModule } from '@/hooks/useModule';
import { useTerms } from '@/lib/wording';

interface ChangeOrderTypeModalProps {
    open: boolean;
    onCancel: () => void;
    order: Order;
    options: ChangeTypeOption[];
}

export default function ChangeOrderTypeModal({ open, onCancel, order, options }: ChangeOrderTypeModalProps) {
    const [form] = Form.useForm();
    const [selectedType, setSelectedType] = useState<string>(order.type);
    const { tableTypes, loading, error } = useTableTypes();

    const selectedOption = options.find((option) => option.code === selectedType);
    const restaurantEnabled = useModule('restaurant');
    const terms = useTerms();
    const showTableFields = restaurantEnabled && (selectedOption?.showTable ?? false);

    // Reset the selected type when the modal opens or the order changes
    useEffect(() => {
        if (open) {
            setSelectedType(order.type);
        }
    }, [open, order.type]);

    // Set the first table type as default when a table type is selected and table types are loaded
    useEffect(() => {
        if (showTableFields && tableTypes.length > 0 && open) {
            const currentValues = form.getFieldsValue();
            if (!currentValues.tableType) {
                form.setFieldsValue({
                    tableType: tableTypes[0].name,
                });
            }
        }
    }, [showTableFields, tableTypes, open, form]);

    const tableOptions = tableTypes.map(tableType => ({
        label: tableType.name,
        value: tableType.name,
    }));

    const onFinish = (values: any) => {
        let submitData = { ...values };

        // Format the table number when the selected type shows a table
        if (showTableFields && values.tableType && values.tableNumber) {
            submitData.table_number = `${values.tableType} - ${values.tableNumber}`;
        }

        router.post(`/orders/update-type/${order.id}`, submitData, {
            onSuccess: () => {
                message.success('تم تغيير نوع الطلب بنجاح');
                onCancel();
            },
            onError: () => {
                message.error('حدث خطأ أثناء تغيير نوع الطلب');
            },
        });
    };

    const handleCancel = () => {
        form.resetFields();
        setSelectedType(order.type);
        onCancel();
    };

    return (
        <Modal
            title="تغيير نوع الطلب"
            open={open}
            onCancel={handleCancel}
            onOk={() => form.submit()}
            okText="تغيير"
            cancelText="إلغاء"
            destroyOnClose
        >
            <Form
                form={form}
                layout="vertical"
                onFinish={onFinish}
                initialValues={{
                    type: order.type,
                }}
            >
                <Form.Item
                    name="type"
                    label="نوع الطلب الجديد"
                    rules={[{ required: true, message: 'نوع الطلب مطلوب' }]}
                >
                    <Radio.Group
                        onChange={(e) => {
                            const newValue = e.target.value as string;
                            const previousOption = options.find((option) => option.code === selectedType);
                            setSelectedType(newValue);

                            // Clear table fields when switching away from a table type
                            if (!options.find((option) => option.code === newValue)?.showTable) {
                                form.setFieldsValue({
                                    tableType: undefined,
                                    tableNumber: undefined,
                                });
                            } else if (tableTypes.length > 0 && !previousOption?.showTable) {
                                // Set default table type when switching to a table type
                                form.setFieldsValue({
                                    tableType: tableTypes[0].name,
                                });
                            }
                        }}
                    >
                        {options.map((option) => (
                            <Radio key={option.code} value={option.code}>
                                {option.label}
                            </Radio>
                        ))}
                    </Radio.Group>
                </Form.Item>
                {showTableFields && (
                    <>
                        {loading ? (
                            <div className="flex justify-center items-center py-4">
                                <Spin />
                            </div>
                        ) : (
                            <>
                                <Form.Item
                                    label={terms.tableType}
                                    name="tableType"
                                    rules={[{ required: true, message: terms.chooseTableType }]}
                                >
                                    <Radio.Group
                                        options={tableOptions}
                                        optionType="button"
                                        buttonStyle="solid"
                                    />
                                </Form.Item>
                                <Form.Item
                                    label={terms.tableNumber}
                                    name="tableNumber"
                                    rules={[{ required: true, message: terms.chooseTableNumber }]}
                                >
                                    <InputNumber min={1} className="w-full" />
                                </Form.Item>
                                {error && (
                                    <div className="text-red-500 text-sm mt-2">
                                        {error}
                                    </div>
                                )}
                            </>
                        )}
                    </>
                )}
            </Form>
        </Modal>
    );
}
