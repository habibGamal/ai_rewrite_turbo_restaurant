import React, { useState, useEffect } from 'react';
import { Modal, Button, message, Checkbox, Divider, InputNumber } from 'antd';
import { router } from '@inertiajs/react';
import { CheckboxChangeEvent } from 'antd/es/checkbox';
import axios from 'axios';
import { Order, OrderItemData } from '@/types';
import printTemplate from '@/helpers/printTemplate';
import KitchenTemplate, { KitchenItemForPrint } from '../Print/KitchenTemplate';

type CheckboxValueType = string | number | boolean;

interface PrintInKitchenModalProps {
    open: boolean;
    onCancel: () => void;
    order: Order;
    orderItems: OrderItemData[];
}

export default function PrintInKitchenModal({
    open,
    onCancel,
    order,
    orderItems
}: PrintInKitchenModalProps) {
    const [itemsQuantity, setItemsQuantity] = useState<KitchenItemForPrint[]>([]);

    useEffect(() => {
        // Convert OrderItemData to KitchenItemForPrint format
        const convertedItems = orderItems.map(item => ({
            product_id: item.product_id,
            name: item.name,
            price: item.price,
            quantity: item.quantity,
            notes: item.notes,
            initial_quantity: item.quantity
        }));
        setItemsQuantity(JSON.parse(JSON.stringify(convertedItems)));
    }, [orderItems]);

    const defaultList = orderItems.map((item) => ({
        id: item.product_id.toString(),
        label: (
            <div className="my-2">
                {item.name}
                <InputNumber
                    className="mr-2"
                    defaultValue={item.quantity}
                    min={1}
                    onChange={(value) =>
                        setItemsQuantity((state) => {
                            const index = state.findIndex((i) => i.product_id === item.product_id);
                            if (index !== -1) {
                                state[index].quantity = value || 1;
                            }
                            return [...state];
                        })
                    }
                />
            </div>
        ),
        value: item.product_id,
    }));

    const [checkedList, setCheckedList] = useState<CheckboxValueType[]>(
        defaultList.map((item) => item.value)
    );

    const checkAll = defaultList.length === checkedList.length;
    const indeterminate = checkedList.length > 0 && checkedList.length < defaultList.length;

    const onChange = (list: CheckboxValueType[]) => {
        setCheckedList(list);
    };

    const onCheckAllChange = (e: CheckboxChangeEvent) => {
        setCheckedList(e.target.checked ? defaultList.map((item) => item.value) : []);
    };

    const disablePrint = checkedList.length === 0;
    const itemsToPrint = itemsQuantity.filter((item) => checkedList.includes(item.product_id));

    const mappingItemsToPrinters = async () => {
        onCancel();
        message.loading('جاري الطباعة');

        try {
            const result = await axios.post<{
                id: number; // product id
                printers: {
                    id: number;
                }[];
            }[]>('/printers-of-products', {
                ids: itemsToPrint.map((item) => item.product_id),
            });

            const itemsByPrinterMap: {
                [key: string]: {
                    items: typeof itemsToPrint;
                };
            } = {};

            for (const item of itemsToPrint) {
                const productPrinters = result.data.find((product) => product.id === item.product_id);
                if (productPrinters) {
                    for (const printer of productPrinters.printers) {
                        if (!itemsByPrinterMap[printer.id]) {
                            itemsByPrinterMap[printer.id] = {
                                items: [],
                            };
                        }
                        itemsByPrinterMap[printer.id].items.push(item);
                    }
                }
            }

            const images: {
                printerId: string;
                image: string;
            }[] = [];

            for (const [printerId, printer] of Object.entries(itemsByPrinterMap)) {
                const image = await printTemplate(
                    'printer_' + printerId,
                    <KitchenTemplate
                        key={printerId}
                        printerId={printerId}
                        order={order}
                        orderItems={printer.items}
                    />
                );
                images.push({
                    printerId,
                    image,
                });
            }
            console.log(images);
            axios.post('/print-in-kitchen', {
                images,
            });
        } catch (error) {
            message.error('حدث خطأ أثناء إرسال الطلب للمطبخ');
        }
    };

    return (
        <Modal
            title="طباعة في المطبخ"
            open={open}
            onCancel={onCancel}
            footer={
                <Button
                    disabled={disablePrint}
                    onClick={mappingItemsToPrinters}
                    className="my-4"
                    htmlType="submit"
                    type="primary"
                >
                    طباعة
                </Button>
            }
            destroyOnClose
        >
            <Checkbox
                indeterminate={indeterminate}
                onChange={onCheckAllChange}
                checked={checkAll}
            >
                طباعة الكل
            </Checkbox>
            <Divider />
            <Checkbox.Group
                className="flex-col text-xl"
                options={defaultList}
                value={checkedList}
                onChange={onChange}
            />
        </Modal>
    );
}
