import { OrderItemData, OrderItemAction, User } from '@/types';

export const orderItemsReducer = (
    state: OrderItemData[],
    action: OrderItemAction
): OrderItemData[] => {
    let canChange = true;
    let limit = 0;

    if (action.type !== 'add' && action.type !== 'init') {
        const isAdmin = action.user.role === 'admin';
        const orderItem = action.id
            ? state.find((item) => item.product_id === action.id!)
            : null;
        const itemSavedBefore = orderItem?.initial_quantity !== null && orderItem?.initial_quantity !== undefined;

        if (!isAdmin && itemSavedBefore) {
            canChange = false;
            limit = orderItem.initial_quantity!;
        }
    }

    switch (action.type) {
        case 'add': {
            // Check if the order item already exists
            const existingItem = state.find(
                (item) => item.product_id === action.orderItem.product_id
            );
            if (existingItem) {
                // If it exists, increment the quantity
                return state.map((item) =>
                    item.product_id === action.orderItem.product_id
                        ? { ...item, quantity: item.quantity + 1 }
                        : item
                );
            }
            return [...state, action.orderItem];
        }

        case 'remove':
            return canChange
                ? state.filter((item) => item.product_id !== action.id)
                : state;

        case 'increment':
            return state.map((item) =>
                item.product_id === action.id
                    ? { ...item, quantity: item.quantity + 1 }
                    : item
            );

        case 'decrement': {
            const orderItem = state.find((item) => item.product_id === action.id);
            if (!canChange && orderItem && orderItem.quantity === limit) return state;

            return state.map((item) => {
                if (item.product_id !== action.id) return item;
                if (item.quantity === 1) {
                    return item;
                }
                return { ...item, quantity: item.quantity - 1 };
            });
        }

        case 'changeQuantity': {
            if (!canChange && action.quantity < limit) {
                action.quantity = limit;
            }
            return state.map((item) => {
                if (item.product_id !== action.id) return item;
                if (action.quantity !== null) {
                    action.quantity = Math.floor(action.quantity);
                }
                return { ...item, quantity: action.quantity };
            });
        }

        case 'changeNotes': {
            return state.map((item) => {
                if (item.product_id !== action.id) return item;
                return { ...item, notes: action.notes };
            });
        }

        case 'delete':
            return canChange
                ? state.filter((item) => item.product_id !== action.id)
                : state;

        case 'init':
            return action.orderItems;

        default:
            throw new Error('Action not found');
    }
};
