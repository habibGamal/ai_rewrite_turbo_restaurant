import { Order } from '@/types';
import { message } from 'antd';
import axios from 'axios';
import { PartType } from '../Components/Print/PartialReceiptTemplate';

export { PartType };

export async function printOrder(order: Order) {
    const key = `print-${order.id}`;
    message.loading({ content: 'جاري الطباعة...', key, duration: 0 });
    try {
        const response = await axios.post(`/orders/print/${order.id}`);
        message.success({ content: response.data?.message || 'تم إرسال طلب الطباعة', key });
    } catch (error: any) {
        message.error({ content: error?.response?.data?.message || 'حدث خطأ أثناء طباعة الطلب', key });
    }
}
