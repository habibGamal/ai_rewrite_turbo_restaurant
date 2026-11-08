import type { KdsBroadcastReason, KdsSettings, KdsSound } from '@/types/kitchen';
import { router } from '@inertiajs/react';

export interface KitchenVisitHandlers {
    onSuccess?: () => void;
    onError?: (errors: Record<string, string>) => void;
    onFinish?: () => void;
}

const visit = {
    preserveScroll: true,
    preserveState: true,
    only: ['tickets'] as string[],
    async: true,
    showProgress: false,
};

export function postKitchen(
    url: string,
    data: Record<string, string | number | boolean> = {},
    handlers: KitchenVisitHandlers = {},
): void {
    router.post(url, data, {
        ...visit,
        onSuccess: () => handlers.onSuccess?.(),
        onError: (errors) => handlers.onError?.(errors),
        onFinish: () => handlers.onFinish?.(),
    });
}

export const kitchenUrls = {
    start: (itemId: number): string => `/kitchen/items/${itemId}/start`,
    done: (itemId: number): string => `/kitchen/items/${itemId}/done`,
    undo: (itemId: number): string => `/kitchen/items/${itemId}/undo`,
    ticketDone: (ticketId: number): string => `/kitchen/tickets/${ticketId}/done`,
    acknowledge: (ticketId: number): string => `/kitchen/tickets/${ticketId}/acknowledge`,
    rush: (ticketId: number): string => `/kitchen/tickets/${ticketId}/rush`,
};

export function soundForReason(reason: KdsBroadcastReason, settings: KdsSettings): KdsSound {
    if (reason === 'new') {
        return settings.new_order_sound;
    }
    if (reason === 'changed') {
        return settings.change_sound;
    }
    if (reason === 'cancelled') {
        return settings.cancel_sound;
    }
    return 'none';
}
