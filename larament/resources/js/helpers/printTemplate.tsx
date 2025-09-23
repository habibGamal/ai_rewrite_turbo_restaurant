import { Order } from '@/types';
import { message } from 'antd';
import axios from 'axios';
import { PartType } from '../Components/Print/PartialReceiptTemplate';

export { PartType };

export async function printOrder(order: Order) {
    axios.post(
        `/orders/print/${order.id}`
    );
    message.success('تم إرسال طلب الطباعة');
}
