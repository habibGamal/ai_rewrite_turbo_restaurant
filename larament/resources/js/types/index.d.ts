export interface User {
    id: number;
    name: string;
    email: string;
    email_verified_at?: string;
    roles?: string[];
    permissions?: string[];
    isAdmin?: boolean;
}

export type OrderStatus = 'processing' | 'completed' | 'cancelled';
export type OrderType = string;
export type PaymentMethod = 'cash' | 'card' | 'talabat_card';
export type PaymentStatus = 'pending' | 'partial_paid' | 'full_paid';

export interface Category {
    id: number;
    name: string;
    parent_id?: number | null;
    blocked_order_type_codes?: string[];
    products: Product[];
    children?: Category[];
}

export interface Product {
    id: number;
    category_id: number;
    name: string;
    price: number;
    cost: number;
    type: string;
    unit: string;
    printer_id?: number;
    legacy: boolean;
}

export interface Customer {
    id: number;
    name: string;
    phone: string;
    address?: string;
    delivery_cost?: number;
}

export interface Driver {
    id: number;
    name: string;
    phone: string;
}

export interface Table {
    id: number;
    name: string;
    capacity: number;
    is_active: boolean;
}

export interface OrderItem {
    id: number;
    order_id: number;
    product_id: number;
    quantity: number;
    price: number;
    notes?: string;
    product: Product;
}

export interface Payment {
    id: number;
    order_id: number;
    method: string;
    amount: number;
    user_id: number;
}

export interface ItemChange {
    id: number;
    order_id: number;
    product_id: number | null;
    product_name: string;
    change_type: 'added' | 'removed' | 'quantity_changed';
    old_quantity: number | null;
    new_quantity: number | null;
    delta: number | null;
    created_at: string;
    updated_at: string;
}

export interface Order {
    id: number;
    table_id?: number;
    customer_name?: string;
    phone?: string;
    status: OrderStatus;
    type: string;
    total: number;
    subtotal: number;
    tax: number;
    discount: number;
    notes?: string;
    created_at: string;
    updated_at: string;
    items?: OrderItem[];
    item_changes?: ItemChange[];
    table?: Table;
    payments?: Payment[];
    display?: OrderDisplay;
    credit?: {
        allow_partial_payment: boolean;
        allow_pay_later: boolean;
        require_customer_for_credit: boolean;
    };
}

export interface OrderDisplay {
    code: string;
    label: string;
    color: string;
    icon: string;
    showTable: boolean;
    showCustomer: boolean;
    showAddress: boolean;
    showDriver: boolean;
    chargesService: boolean;
    chargesDeliveryFee: boolean;
}

export interface TabPresentation {
    body: 'cards' | 'inbox' | 'floor';
    creation: 'immediate' | 'choose_table' | 'none';
    statuses: string[];
    cardTitle: 'table' | 'order_number';
}

export interface FloorRect { col: number; row: number; colSpan: number; rowSpan: number; [key: string]: any }
export interface FloorTable extends FloorRect { number: string; capacity: number; zone: string; active: boolean }
export interface FloorSection extends FloorRect { name: string; zone: string; color: string }
export interface FloorLayout { version: number; enabled: boolean; columns: number; rows: number; tables: FloorTable[]; sections: FloorSection[]; [key: string]: any }
export interface CashierTab {
    key: string;
    kind: 'type' | 'system';
    code: string | null;
    label: string;
    color: string;
    icon: string;
    visible: boolean;
    forcedVisible: boolean;
    layout?: FloorLayout;
  occupancy?: Record<string, { id: number; order_number: number; created_at: string }>;
  presentation?: TabPresentation;
    display?: OrderDisplay;
    credit?: {
        allow_partial_payment: boolean;
        allow_pay_later: boolean;
        require_customer_for_credit: boolean;
    };
}

export interface ChangeTypeOption {
    code: string;
    label: string;
    showTable: boolean;
}

export interface OrderItemData {
    product_id: number;
    name: string;
    price: number;
    quantity: number;
    notes?: string;
    initial_quantity?: number;
}

export type OrderItemAction =
    | { type: 'add'; orderItem: OrderItemData; user: User }
    | { type: 'remove'; id: number; user: User }
    | { type: 'increment'; id: number; user: User }
    | { type: 'decrement'; id: number; user: User }
    | { type: 'changeQuantity'; id: number; quantity: number; user: User }
    | { type: 'changeNotes'; id: number; notes: string; user: User }
    | { type: 'delete'; id: number; user: User }
    | { type: 'init'; orderItems: OrderItemData[]; user: User };

export interface ManageOrderProps {
    order: Order;
    categories: Category[];
    receiptFooter?: string;
}

export interface PageProps {
    auth: {
        user: User;
    };
    receiptFooter: string;
}

export interface OrdersPageProps extends PageProps {
    orders?: Order[];
    categories?: Category[];
    products?: Product[];
    tables?: Table[];
}

export interface Shift {
    id: number;
    user_id: number;
    start_at: string;
    end_at?: string;
    start_cash: number;
    end_cash?: number;
    losses_amount?: number;
    real_cash?: number;
    has_deficit: boolean;
    closed: boolean;
    is_active: boolean;
    duration: string;
    total_sales: number;
    total_cash: number;
    deficit: number;
    user?: User;
    orders?: Order[];
}

export interface OrderFilters {
    status?: OrderStatus;
    type?: OrderType;
    table_id?: number;
    date_from?: string;
    date_to?: string;
}

export interface ExpenseType {
    id: number;
    name: string;
}

export interface Expense {
    id: number;
    shift_id: number;
    expence_type_id: number;
    amount: number;
    notes?: string;
    created_at: string;
    updated_at: string;
    expenceType?: ExpenseType;
}
