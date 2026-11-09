// Contract between KitchenController (Inertia props / broadcast payload) and the KDS screen.
// Timestamps are ISO-8601 strings in server time; durations are whole seconds.

export type KdsTicketStatus = 'active' | 'done' | 'cancelled';
export type KdsItemStatus = 'pending' | 'done' | 'voided';
export type KdsItemKind = 'original' | 'added' | 'increased';
export type KdsSound = 'none' | 'chime' | 'bell' | 'beep' | 'alert';
export type KdsBroadcastReason = 'new' | 'changed' | 'cancelled' | 'done';

export interface KdsStation {
    id: number;
    name: string;
}

export interface KdsRound {
    id: number;
    number: number;
    started_at: string;
    ended_at: string | null;
}

export interface KdsItem {
    id: number;
    round_id: number;
    product_id: number | null;
    product_name: string;
    station_id: number | null;
    quantity: number;
    original_quantity: number;
    notes: string | null;
    kind: KdsItemKind;
    status: KdsItemStatus;
    target_minutes: number;
    sent_at: string;
    done_at: string | null;
    voided_at: string | null;
    voided_after_done: boolean;
    needs_ack: boolean;
    auto_finished: boolean;
}

export interface KdsTicket {
    id: number;
    order_id: number;
    order_number: number;
    order_type: string;
    order_type_label: string;
    table_number: string | null;
    customer_name: string | null;
    kitchen_notes: string | null;
    status: KdsTicketStatus;
    is_rush: boolean;
    first_sent_at: string;
    done_at: string | null;
    cancelled_at: string | null;
    seen_at: string | null;
    cancel_acknowledged_at: string | null;
    auto_finished: boolean;
    /** Sum of CLOSED round durations. The client adds live time of the open round. */
    closed_rounds_seconds: number;
    /** Largest target_minutes among unfinished lines of the open round; null when no round is open. */
    open_round_target_minutes: number | null;
    rounds: KdsRound[];
    items: KdsItem[];
}

export interface KdsSettings {
    item_tracking: boolean;
    warning_percent: number;
    recall_minutes: number;
    new_order_sound: KdsSound;
    change_sound: KdsSound;
    cancel_sound: KdsSound;
    sound_volume: number;
    reminder_seconds: number;
}

export interface KitchenPageProps {
    tickets: KdsTicket[];
    stations: KdsStation[];
    kdsSettings: KdsSettings;
    serverNow: string;
    hasActiveShift: boolean;
}

/** Payload of the `.KitchenTicketUpdated` event on private channel `kitchen`. */
export interface KdsBroadcastPayload {
    ticket_id: number;
    order_number: number;
    reason: KdsBroadcastReason;
    station_ids: number[];
}
