import { OrderItemData, OrderItemAction, User, Product } from '@/types';

// Barcode parser function
const parseBarcode = (barcode: string): { productBarcode: string; weightKg: number; checksum: number } | null => {
    // Remove any whitespace and validate length
    const cleanBarcode = barcode.trim();

    if (cleanBarcode.length !== 13) {
        return null;
    }

    // Validate that all characters are digits
    if (!/^\d+$/.test(cleanBarcode)) {
        return null;
    }

    // Extract components
    const productBarcode = cleanBarcode.substr(0, 7);
    const weightGrams = cleanBarcode.substr(7, 5);
    const checksum = cleanBarcode.substr(12, 1);

    // Convert weight from grams to kilograms
    const weightKg = parseInt(weightGrams) / 1000;

    return {
        productBarcode,
        weightKg,
        checksum: parseInt(checksum)
    };
};

export const orderItemsReducer = (
    state: OrderItemData[],
    action: OrderItemAction
): OrderItemData[] => {
    let canChange = true;
    let limit = 0;

    if (action.type !== 'add' && action.type !== 'init' && action.type !== 'addByBarcode') {
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
                // Remove Math.floor to allow decimal quantities
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

        case 'addByBarcode': {
            const parsedBarcode = parseBarcode(action.barcode);
            if (!parsedBarcode) {
                // Invalid barcode format
                return state;
            }

            // Find product by barcode
            const product = action.products.find(p => p.barcode === parsedBarcode.productBarcode);
            if (!product) {
                // Product not found
                return state;
            }

            // Check if the order item already exists
            const existingItemIndex = state.findIndex(item => item.product_id === product.id);

            if (existingItemIndex !== -1) {
                // If it exists, add the weight to the existing quantity
                return state.map((item, index) =>
                    index === existingItemIndex
                        ? { ...item, quantity: item.quantity + parsedBarcode.weightKg }
                        : item
                );
            } else {
                // Add new item
                const newItem: OrderItemData = {
                    product_id: product.id,
                    name: product.name,
                    price: product.price,
                    quantity: parsedBarcode.weightKg,
                    notes: ``,
                    initial_quantity: undefined,
                };
                return [...state, newItem];
            }
        }

        default:
            throw new Error('Action not found');
    }
};
