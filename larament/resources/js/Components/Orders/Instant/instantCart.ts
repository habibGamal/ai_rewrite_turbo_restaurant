import axios from "axios";
import type {
    InstantCartCustomer,
    InstantCartDiscount,
    Order,
    OrderItemData,
    Product,
} from "@/types";
import { calculateOrderTotals } from "@/utils/orderCalculations";

export type Tenders = { cash: number; card: number; talabat_card: number };

export const ZERO_TENDERS: Tenders = { cash: 0, card: 0, talabat_card: 0 };

export const newClientUuid = (): string => {
    try {
        if (typeof crypto !== "undefined" && typeof crypto.randomUUID === "function") {
            return crypto.randomUUID();
        }
    } catch {
        // fall through to the manual generator
    }
    const bytes = new Uint8Array(16);
    if (typeof crypto !== "undefined" && typeof crypto.getRandomValues === "function") {
        crypto.getRandomValues(bytes);
    } else {
        for (let i = 0; i < 16; i++) bytes[i] = Math.floor(Math.random() * 256);
    }
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, "0")).join("");
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
};

/** Items in the shape the instant-sale and held-sale endpoints take. */
export const itemsForApi = (items: OrderItemData[]) =>
    items.map((item) => ({
        product_id: item.product_id,
        quantity: item.quantity,
        notes: item.notes || null,
        item_discount: item.item_discount || 0,
        item_discount_type: (item.item_discount_type as "percent" | "value" | undefined) || null,
        item_discount_percent: item.item_discount_percent || null,
    }));

/** Same arithmetic as ManageOrder, fed from cart state instead of a saved order. */
export const cartTotals = (items: OrderItemData[], discount: InstantCartDiscount | null) => {
    const pseudoOrder = {
        discount: discount?.type === "value" ? discount.value : 0,
        temp_discount_percent: discount?.type === "percent" ? discount.value : 0,
        service_rate: 0,
        display: undefined,
    } as unknown as Order;
    return calculateOrderTotals(pseudoOrder, items);
};

export const lineFromProduct = (
    product: Product,
    quantity: number,
    extra: Partial<OrderItemData> = {}
): OrderItemData => ({
    product_id: product.id,
    name: product.name,
    price: Number(product.price),
    quantity,
    notes: "",
    initial_quantity: undefined,
    product,
    ...extra,
});

export type LookupResult =
    | { ok: true; product: Product; quantity: number }
    | { ok: false; message: string };

/**
 * Resolves a scanned/typed code: the server lookup first (extra barcodes, pack sizes,
 * scale labels), then the loaded menu by barcode or name.
 */
export const lookupProduct = async (
    code: string,
    products: Product[]
): Promise<LookupResult> => {
    const trimmed = code.trim();
    if (!trimmed) return { ok: false, message: "" };

    let serverMiss = false;
    try {
        const { data } = await axios.get<{ found: boolean; product_id: number; quantity: number }>(
            route("products.lookup"),
            { params: { code: trimmed } }
        );
        if (data?.found !== false && data?.product_id) {
            const product = products.find((p) => p.id === data.product_id);
            if (!product) {
                return { ok: false, message: "هذا المنتج غير متاح لهذا النوع من الطلبات" };
            }
            return { ok: true, product, quantity: Number(data.quantity) || 1 };
        }
        serverMiss = true;
    } catch (error: any) {
        serverMiss = error?.response?.status === 404;
    }

    const lower = trimmed.toLowerCase();
    const byBarcode = products.find((p) => p.barcode && p.barcode === trimmed);
    if (byBarcode) return { ok: true, product: byBarcode, quantity: 1 };
    const exactName = products.find((p) => p.name.toLowerCase() === lower);
    if (exactName) return { ok: true, product: exactName, quantity: 1 };
    const partial = products.filter((p) => p.name.toLowerCase().includes(lower));
    if (partial.length === 1) return { ok: true, product: partial[0], quantity: 1 };

    return {
        ok: false,
        message: serverMiss || partial.length === 0
            ? `لا يوجد منتج بالكود ${trimmed}`
            : `يوجد أكثر من منتج يطابق "${trimmed}"`,
    };
};

export interface StoredCart {
    items: OrderItemData[];
    discount: InstantCartDiscount | null;
    customer: InstantCartCustomer | null;
    clientUuid: string;
    heldSaleId: number | null;
}

export const emptyCart = (): StoredCart => ({
    items: [],
    discount: null,
    customer: null,
    clientUuid: newClientUuid(),
    heldSaleId: null,
});

export const loadCart = (key: string): StoredCart => {
    try {
        const raw = window.localStorage.getItem(key);
        if (!raw) return emptyCart();
        const parsed = JSON.parse(raw) as Partial<StoredCart>;
        return {
            items: Array.isArray(parsed.items) ? parsed.items : [],
            discount: parsed.discount ?? null,
            customer: parsed.customer ?? null,
            clientUuid: typeof parsed.clientUuid === "string" && parsed.clientUuid ? parsed.clientUuid : newClientUuid(),
            heldSaleId: typeof parsed.heldSaleId === "number" ? parsed.heldSaleId : null,
        };
    } catch {
        return emptyCart();
    }
};

export const saveCart = (key: string, cart: StoredCart) => {
    try {
        if (cart.items.length === 0 && !cart.discount && !cart.customer && !cart.heldSaleId) {
            window.localStorage.removeItem(key);
            return;
        }
        window.localStorage.setItem(key, JSON.stringify(cart));
    } catch {
        // storage unavailable (private mode, quota): the cart still lives in memory
    }
};

/** True when an Ant Design modal or drawer (ours or another component's) is showing. */
export const anotherModalOpen = (): boolean => {
    if (typeof document === "undefined") return false;
    const wraps = document.querySelectorAll<HTMLElement>(".ant-modal-wrap, .ant-drawer-open");
    return Array.from(wraps).some((el) => el.offsetParent !== null || getComputedStyle(el).display !== "none");
};

export const errorMessage = (error: any, fallback: string): string =>
    error?.response?.data?.message || error?.response?.data?.error || fallback;
