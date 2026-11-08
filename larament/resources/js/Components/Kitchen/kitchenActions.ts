import type { KdsBroadcastReason, KdsSettings, KdsSound } from '@/types/kitchen';
import { router } from '@inertiajs/react';
import { message } from 'antd';

const partialReload = {
    preserveScroll: true,
    preserveState: true,
    only: ['tickets'] as string[],
    async: true,
    onError: (errors: Record<string, string>) => {
        if (Object.keys(errors).length === 0) {
            message.error('تعذر تنفيذ الإجراء');
        }
    },
};

function post(url: string, data: Record<string, string | number | boolean> = {}): void {
    router.post(url, data, partialReload);
}

export function startItem(itemId: number): void {
    post(`/kitchen/items/${itemId}/start`);
}

export function doneItem(itemId: number): void {
    post(`/kitchen/items/${itemId}/done`);
}

export function undoItem(itemId: number): void {
    post(`/kitchen/items/${itemId}/undo`);
}

export function finishTicket(ticketId: number, stationId: number | null): void {
    post(
        `/kitchen/tickets/${ticketId}/done`,
        stationId == null ? {} : { station_id: stationId },
    );
}

export function acknowledgeTicket(ticketId: number): void {
    post(`/kitchen/tickets/${ticketId}/acknowledge`);
}

export function setTicketRush(ticketId: number, isRush: boolean): void {
    post(`/kitchen/tickets/${ticketId}/rush`, { is_rush: isRush });
}

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
