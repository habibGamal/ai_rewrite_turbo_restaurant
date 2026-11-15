export interface User {
  id: number;
  name: string;
  email: string;
  email_verified_at?: string;
  roles?: string[];
  permissions?: string[];
  isAdmin?: boolean;
}

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
  legacy: boolean;
  barcode?: string;
}

export interface Customer {
  id: number;
  name: string;
  phone: string;
  address?: string;
  delivery_cost?: number;
  hasWhatsapp?: boolean;
  region?: string;
  deliveryCost?: number;
}

export interface Driver {
  id: number;
  name: string;
  phone: string;
}

export interface Region {
  id: number;
  name: string;
  deliveryCost: number;
}

export interface OrderItem {
  id: number;
  order_id: number;
  product_id: number;
  quantity: number;
  price: number;
  notes?: string;
  item_discount: number;
  item_discount_type?: string;
  item_discount_percent?: number;
  kitchen_status?: 'ready' | 'in_progress' | null;
  kitchen_progress?: {
    done: number;
    total: number;
    status: 'ready' | 'in_progress';
  } | null;
  product: Product;
}

export interface Payment {
  id: number;
  order_id: number;
  method: 'cash' | 'card' | 'talabat_card';
  amount: number;
  user_id: number;
}

export interface OrderItemChange {
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

export type TableShape = 'square' | 'round';

export interface FloorRect {
  col: number;
  row: number;
  colSpan: number;
  rowSpan: number;
  [key: string]: any;
}

export interface FloorTable extends FloorRect {
  number: string;
  capacity: number;
  shape?: TableShape;
  zone: string;
  active: boolean;
}

export interface FloorSection extends FloorRect {
  name: string;
  zone: string;
  color: string;
}

export interface TableLabelConfig {
  prefix: string;
  showCapacity: boolean;
  showOrderNumber: boolean;
  showTimer: boolean;
}

export interface FloorLayout {
  version: number;
  enabled: boolean;
  columns: number;
  rows: number;
  tables: FloorTable[];
  sections: FloorSection[];
  tableLabel?: TableLabelConfig;
  [key: string]: any;
}
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
}

export interface ChangeTypeOption {
  code: string;
  label: string;
  showTable: boolean;
}

export interface OrderCreditFlags {
  allow_partial_payment: boolean;
  allow_pay_later: boolean;
  require_customer_for_credit: boolean;
}

export interface Order {
  id: number;
  customer_id?: number;
  service_rate?: number;
  driver_id?: number;
  user_id: number;
  shift_id: number;
  type: string;
  status: 'pending' | 'processing' | 'ready' | 'out_for_delivery' | 'completed' | 'cancelled';
  sub_total: number;
  tax: number;
  service: number;
  discount: number;
  temp_discount_percent: number;
  total: number;
  profit: number;
  payment_status: 'pending' | 'partial_paid' | 'full_paid';
  dine_table_number?: string;
  kitchen_notes?: string;
  order_notes?: string;
  order_number: string;
  web_preferences?: {
    payment_method?: 'cash' | 'card' | 'talabat_card';
  };
  created_at: string;
  updated_at: string;
  customer?: Customer;
  driver?: Driver;
  credit?: OrderCreditFlags;
  items: OrderItem[];
  item_changes?: OrderItemChange[];
  payments: Payment[];
  user?: User;
  display?: OrderDisplay;
  kitchen_status?: 'ready' | 'in_progress' | null;
  kitchen_progress?: {
    done: number;
    total: number;
    status: 'ready' | 'in_progress';
  } | null;
  receiptPayment?: ReceiptPayment;
  receiptView?: ReceiptView;
}

export interface ReceiptView {
  restaurant_total: number;
  external_total: number;
  guest_total: number;
  external_orders: { seller_name: string; total: number; items: { name: string; quantity: number; price: number; total: number }[] }[];
}

export interface ExternalOrder {
  id: number;
  external_seller_id: number;
  seller_name: string;
  status: string;
  total: number;
  items: { id: number; external_product_id: number | null; name: string; quantity: number; price: number; total: number }[];
}

export interface ReceiptPayment {
  paid: number;
  remaining: number;
  partial: boolean;
}

export interface Receivable {
  id: number;
  order_number: string;
  created_at: string;
  type: string;
  payment_status: Order['payment_status'];
  customer: {
    id: number;
    name: string;
    phone: string;
  } | null;
  display: OrderDisplay;
  total: number;
  paid: number;
  remaining: number;
}

export interface OrderItemData {
  product_id: number;
  name: string;
  price: number;
  quantity: number;
  notes?: string;
  initial_quantity?: number;
  item_discount?: number;
  item_discount_type?: string;
  item_discount_percent?: number;
  kitchen_status?: 'ready' | 'in_progress' | null;
  kitchen_progress?: {
    done: number;
    total: number;
    status: 'ready' | 'in_progress';
  } | null;
  product: Product;
}

export interface KitchenItemForPrint {
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
  | { type: 'changeItemDiscount'; id: number; discount: number; discountType: string; discountPercent?: number; user: User }
  | { type: 'delete'; id: number; user: User }
  | { type: 'addByBarcode'; barcode: string; products: Product[]; scalePrefix?: string; user: User }
  | { type: 'init'; orderItems: OrderItemData[]; user: User };

export interface FeatureFlags {
  revenues: boolean;
  custom_fees: boolean;
  outsourced_orders: boolean;
  equipment: boolean;
}

export type ModuleKey =
  | 'restaurant'
  | 'delivery'
  | 'web_orders'
  | 'manufacturing'
  | 'external_sellers'
  | 'retail';

export type BusinessType =
  | 'restaurant'
  | 'cafe'
  | 'bakery'
  | 'fashion'
  | 'grocery'
  | 'general_retail';

export type PageProps<T = {}> = T & {
  auth: {
    user: User;
  };
  features?: FeatureFlags;
  modules?: ModuleKey[];
  businessType?: BusinessType;
  flash?: {
    success?: string;
    error?: string;
  };
  receiptFooter?: string;
  scaleBarcodePrefix?: string;
  tabsOrientation?: 'horizontal' | 'vertical';
  [key: string]: unknown;
};

export interface ManageOrderProps extends PageProps {
  order: Order;
  orderDisplay: OrderDisplay;
  changeTypeOptions: ChangeTypeOption[];
  categories: Category[];
  drivers: Driver[];
  regions: Region[];
  externalOrders: ExternalOrder[];
  sellers: { id: number; name: string; products: { id: number; name: string; price: number | string }[] }[];
  guestDue: number;
  canAddExternalOrder: boolean;
  credit?: OrderCreditFlags;
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
  expence_type?: ExpenseType;
}
