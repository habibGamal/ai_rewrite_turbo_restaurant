import { Order } from '@/types';
import { message } from 'antd';
import axios from 'axios';
import { PartType } from '../Components/Print/PartialReceiptTemplate';

export { PartType };

/**
 * Send the receipt to the printer without waiting: the cashier keeps working
 * while the server prints, and only a failure is shown when the answer comes.
 */
export function printReceipt(orderId: number): void {
    axios.post(`/orders/print/${orderId}`).catch((error: any) => {
        message.error(error?.response?.data?.message || 'حدث خطأ أثناء طباعة الطلب');
    });
}

export function printOrder(order: Order): void {
    printReceipt(order.id);
}
