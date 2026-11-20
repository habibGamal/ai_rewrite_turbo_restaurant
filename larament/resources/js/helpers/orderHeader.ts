import { Order } from '@/types';

export const orderHeader = (order: Order) => {
    return [
        {
            title: 'الطلبات',
        },
        {
            title: order.display?.label ?? order.type,
        },
        {
            title: `طلب رقم ${order.order_number}`,
        },
    ];
};
