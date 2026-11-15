import { OrderItemData, OrderItemAction, User, Product } from "@/types";

export const orderItemsReducer = (
    state: OrderItemData[],
    action: OrderItemAction
): OrderItemData[] => {
    let canChange = true;
    let limit = 0;

    if (
        action.type !== "add" &&
        action.type !== "init" &&
        action.type !== "addScanned"
    ) {
        const isAdmin = Boolean(action.user.isAdmin || action.user.roles?.includes("admin"));
        const canChangeItems = isAdmin || Boolean(action.user.permissions?.includes("order.change_items"));
        const orderItem = action.id
            ? state.find((item) => item.product_id === action.id!)
            : null;
        const itemSavedBefore =
            orderItem?.initial_quantity !== null &&
            orderItem?.initial_quantity !== undefined;

        if (!isAdmin && !canChangeItems && itemSavedBefore) {
            canChange = false;
            limit = orderItem.initial_quantity!;
        }
    }

    switch (action.type) {
        case "add": {
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

        case "remove":
            return canChange
                ? state.filter((item) => item.product_id !== action.id)
                : state;

        case "increment":
            return state.map((item) =>
                item.product_id === action.id
                    ? { ...item, quantity: item.quantity + 1 }
                    : item
            );

        case "decrement": {
            const orderItem = state.find(
                (item) => item.product_id === action.id
            );
            if (!canChange && orderItem && orderItem.quantity === limit)
                return state;

            return state.map((item) => {
                if (item.product_id !== action.id) return item;
                if (item.quantity === 1) {
                    return item;
                }
                return { ...item, quantity: item.quantity - 1 };
            });
        }

        case "changeQuantity": {
            if (!canChange && action.quantity < limit) {
                action.quantity = limit;
            }
            return state.map((item) => {
                if (item.product_id !== action.id) return item;
                // Remove Math.floor to allow decimal quantities
                return { ...item, quantity: action.quantity };
            });
        }

        case "changeNotes": {
            return state.map((item) => {
                if (item.product_id !== action.id) return item;
                return { ...item, notes: action.notes };
            });
        }

        case "delete":
            return canChange
                ? state.filter((item) => item.product_id !== action.id)
                : state;

        case "changeItemDiscount": {
            return state.map((item) => {
                if (item.product_id !== action.id) return item;
                return {
                    ...item,
                    item_discount: action.discount,
                    item_discount_type: action.discountType,
                    item_discount_percent: action.discountPercent,
                };
            });
        }

        case "init":
            return action.orderItems;

        case "addScanned": {
            // Quantity comes from the server lookup: 1, a pack size, or a scale weight in kg.
            const product = action.product;
            const existing = state.find((item) => item.product_id === product.id);
            if (existing) {
                return state.map((item) =>
                    item.product_id === product.id
                        ? { ...item, quantity: item.quantity + action.quantity }
                        : item
                );
            }
            const newItem: OrderItemData = {
                product_id: product.id,
                name: product.name,
                price: product.price,
                quantity: action.quantity,
                notes: ``,
                initial_quantity: undefined,
                product: product,
            };
            return [...state, newItem];
        }

        default:
            throw new Error("Action not found");
    }
};
