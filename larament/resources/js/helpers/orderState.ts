export const orderStatus = (status: string) => {
    switch (status) {
        case 'processing':
            return { text: 'تحت التشغيل', color: 'blue' };
        case 'completed':
            return { text: 'مكتمل', color: 'green' };
        case 'cancelled':
            return { text: 'ملغي', color: 'red' };
        default:
            return { text: status, color: 'default' };
    }
};
