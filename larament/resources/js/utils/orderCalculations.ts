import { Order, OrderItemData } from '@/types';

export interface OrderTotals {
  subTotal: number;
  equipment: number;
  tax: number;
  service: number;
  discount: number;
  /** Custom fee lines; added after service and discount, which stay on items. */
  fees: number;
  total: number;
}

export const calculateOrderTotals = (
  order: Order,
  orderItems: OrderItemData[]
): OrderTotals => {
  const subTotal = orderItems.reduce(
    (acc, item) => acc + item.price * item.quantity,
    0
  );

  const tax = subTotal * 0; // Currently no tax

  let service = 0;
  if (order.display?.chargesService) {
    service = subTotal * order.service_rate!;
  } else if (order.display?.chargesDeliveryFee) {
    service = Number(order.customer?.delivery_cost ?? 0);
  }

  let discount = 0;

  // Check if using item-level discounts
  const hasItemDiscounts = orderItems.some(item => (item.item_discount ?? 0) > 0);

  if (hasItemDiscounts) {
    // Calculate total discount from item-level discounts
    discount = orderItems.reduce((acc, item) => {
      const itemSubtotal = item.price * item.quantity;
      let itemDiscount = 0;

      if (item.item_discount_type === 'percent' && item.item_discount_percent) {
        itemDiscount = itemSubtotal * (item.item_discount_percent / 100);
      } else {
        itemDiscount = item.item_discount ?? 0;
      }

      // Ensure discount doesn't exceed item subtotal
      itemDiscount = Math.min(itemDiscount, itemSubtotal);

      return acc + itemDiscount;
    }, 0);
  } else {
    // Use order-level discount
    if (order.temp_discount_percent > 0) {
      discount = subTotal * (order.temp_discount_percent / 100);
    } else {
      discount = order.discount;
    }
  }

  // Custom fees and equipment are saved on the server as soon as they are
  // added; service and discount never apply to them.
  const fees = Number(order.fees_total ?? 0) || 0;
  const equipment = Number(order.equipment_total ?? 0) || 0;

  const total = Math.ceil(subTotal + tax + service - discount + fees + equipment);

  return {
    subTotal,
    equipment,
    tax,
    service,
    discount,
    fees,
    total,
  };
};

export const formatCurrency = (amount: number): string => {
    try {
        return `${Number(amount).toFixed(1)} ج.م`;
    } catch (error) {
        console.error('Error formatting currency:', error);
        return '0.0 ج.م';
    }
};

export const getOrderStatusConfig = (status: string) => {
  switch (status) {
    case 'processing':
      return {
        color: 'green',
        text: 'تحت التشغيل',
      };
    case 'completed':
      return {
        color: 'grey',
        text: 'مكتمل',
      };
    case 'cancelled':
      return {
        color: 'red',
        text: 'ملغي',
      };
    default:
      return {
        color: 'default',
        text: status,
      };
  }
};

export const getPaymentMethodLabel = (method: string): string => {
  const labels: Record<string, string> = {
    cash: 'نقدي',
    card: 'بطاقة',
    talabat_card: 'بطاقة طلبات',
  };
  return labels[method] || method;
};
