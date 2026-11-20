import axios from "axios";

import { Product } from "@/types";

/**
 * Server-side catalogue for cashier screens whose product list is too big to
 * load at once (the server sends `remoteCatalog: true` and categories only).
 */

export const searchProducts = async (
    type: string,
    params: { q?: string; categoryId?: number | null },
): Promise<Product[]> => {
    const { data } = await axios.get<{ products: Product[] }>(
        route("pos.products.search"),
        {
            params: {
                type,
                q: params.q || undefined,
                category_id: params.categoryId ?? undefined,
            },
        },
    );

    return data.products ?? [];
};

export const fetchProductsByIds = async (
    type: string,
    ids: number[],
): Promise<Product[]> => {
    if (ids.length === 0) return [];

    const { data } = await axios.get<{ products: Product[] }>(
        route("pos.products.index"),
        {
            params: { type, ids },
        },
    );

    return data.products ?? [];
};

/** Adds products to a list, replacing any with the same id. */
export const mergeProducts = (
    known: Product[],
    incoming: Product[],
): Product[] => {
    if (incoming.length === 0) return known;

    const byId = new Map(known.map((product) => [product.id, product]));
    incoming.forEach((product) => byId.set(product.id, product));

    return Array.from(byId.values());
};
