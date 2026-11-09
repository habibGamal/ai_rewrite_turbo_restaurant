<?php

namespace App\Http\Controllers;

use App\DTOs\Orders\CreateOrderDTO;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\ProductType;
use App\Http\Requests\CompleteOrderRequest;
use App\Http\Requests\SaveOrderRequest;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Driver;
use App\Models\ExpenceType;
use App\Models\Expense;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Region;
use App\Models\Shift;
use App\Models\TableType;
use App\Orders\CashierTabLayout;
use App\Orders\OrderOpening;
use App\Orders\OrderTypeCatalog;
use App\Services\Orders\OrderService;
use App\Services\PrintService;
use App\Services\ShiftLoggingService;
use App\Services\ShiftService;
use Exception;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class OrderController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private OrderService $orderService,
        private PrintService $printService,
        private ShiftService $shiftService,
        private ShiftLoggingService $loggingService,
        private OrderTypeCatalog $catalog,
        private CashierTabLayout $cashierTabs,
    ) {}

    /**
     * Display the orders index page
     */
    public function index()
    {
        $currentShift = $this->shiftService->getCurrentShift();

        if (! $currentShift) {
            return redirect()->route('shifts.start');
        }

        $orders = [];
        foreach ($this->orderService->getShiftOrders($currentShift->id) as $order) {
            if (! $order instanceof Order) {
                continue;
            }

            $orders[] = $this->orderPayload($order);
        }

        // Get previous partial paid orders for companies
        $previousPartialPaidOrders = Order::with(['customer', 'driver', 'items.product', 'payments'])
            ->where('payment_status', PaymentStatus::PARTIAL_PAID)
            ->where('type', OrderType::COMPANIES)
            ->get();

        // Get current shift expenses
        $expenses = Expense::with('expenceType')
            ->where('shift_id', $currentShift->id)
            ->orderBy('created_at', 'desc')
            ->get();

        // Get expense types
        $expenseTypes = ExpenceType::all();

        return Inertia::render('Orders/Index', [
            'orders' => $orders,
            'tabs' => $this->tabsPayload($currentShift),
            'previousPartialPaidOrders' => $previousPartialPaidOrders,
            'currentShift' => $currentShift,
            'expenses' => $expenses,
            'expenseTypes' => $expenseTypes,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tabsPayload(Shift $shift): array
    {
        $tabs = [];

        foreach ($this->cashierTabs->resolve($shift) as $tab) {
            if (! $tab['visible'] && ! $tab['forcedVisible']) {
                continue;
            }

            if ($tab['kind'] === 'type' && $tab['code'] !== null) {
                $tab['presentation'] = $this->catalog->behaviorFor($tab['code'])->presentation()->toArray();
                $tab['display'] = $this->catalog->display($tab['code'])->toArray();
            }

            $tabs[] = $tab;
        }

        return $tabs;
    }

    /**
     * @return array<string, mixed>
     */
    private function orderPayload(Order $order): array
    {
        return array_merge($order->toArray(), [
            'display' => $order->display()->toArray(),
        ]);
    }

    /**
     * Display the order management interface
     */
    public function manage(Order $order)
    {
        $order = $this->orderService->getOrderDetails($order->id);

        $categories = Category::with([
            'products' => function ($query) {
                $query
                    ->whereNot('type', ProductType::RawMaterial)
                    ->where('legacy', false)->orderBy('name');
            },
        ])->orderBy('name')->get();

        // Get all drivers for the dropdown
        $drivers = Driver::orderBy('name')->get();

        // Get all regions for the dropdown
        $regions = Region::orderBy('name')->get();

        return Inertia::render('Orders/ManageOrder', [
            'order' => $this->orderPayload($order),
            'orderDisplay' => $order->display()->toArray(),
            'changeTypeOptions' => $this->changeTypeOptions(),
            'categories' => $categories,
            'drivers' => $drivers,
            'regions' => $regions,
        ]);
    }

    /**
     * @return list<array{code: string, label: string, showTable: bool}>
     */
    private function changeTypeOptions(): array
    {
        $creatable = $this->catalog->cashierCreatableCodes();
        $options = [];

        foreach ($this->cashierTabs->visibleTypeCodes() as $code) {
            if (! in_array($code, $creatable, true)) {
                continue;
            }

            $row = $this->catalog->find($code);

            $options[] = [
                'code' => $row->code,
                'label' => $row->label,
                'showTable' => $this->catalog->display($code)->showTable,
            ];
        }

        return $options;
    }

    /**
     * Save order items
     */
    public function saveOrder(SaveOrderRequest $request, Order $order)
    {
        try {
            $this->orderService->updateOrderItems($order->id, $request->validated('items') ?: []);

            return back()->with('success', 'تم حفظ الطلب بنجاح');
        } catch (Exception $e) {
            $this->loggingService->logAction('فشل في حفظ الطلب', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ], 'error');

            return back()->withErrors(['error' => 'حدث خطأ أثناء حفظ الطلب: '.$e->getMessage()]);
        }
    }

    /**
     * Complete order with payment
     */
    public function completeOrder(CompleteOrderRequest $request, Order $order)
    {
        try {
            $validatedData = $request->validated();
            $shouldPrint = $request->boolean('print');

            // Remove print from payments data
            $paymentsData = collect($validatedData)->except('print')->toArray();

            $completedOrder = $this->orderService->completeOrder($order->id, $paymentsData, $shouldPrint);

            // Log order completion
            $this->loggingService->logOrderCompletion($order->id, $paymentsData, $completedOrder->total);

            return redirect()->to(route('orders.index').'#'.$order->type)
                ->with('success', 'تم إنهاء الطلب بنجاح');
        } catch (Exception $e) {
            $this->loggingService->logAction('فشل في إتمام الطلب', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'payment_data' => $request->validated(),
            ], 'error');

            return back()->withErrors(['error' => 'حدث خطأ أثناء إنهاء الطلب: '.$e->getMessage()]);
        }
    }

    /**
     * Cancel a completed order (admin only)
     */
    public function cancelOrder(Order $order)
    {
        if (! auth()->user()->canCancelOrders()) {
            abort(403, 'غير مسموح لك بإلغاء الطلبات');
        }

        try {
            $this->orderService->cancelOrder($order->id);

            // Log order cancellation
            $this->loggingService->logOrderCancellation($order->id, 'إلغاء من قبل المدير');

            return back()->with('success', 'تم إلغاء الطلب بنجاح');
        } catch (Exception $e) {
            $this->loggingService->logAction('فشل في إلغاء الطلب', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ], 'error');

            return back()->withErrors(['error' => 'حدث خطأ أثناء إلغاء الطلب: '.$e->getMessage()]);
        }
    }

    /**
     * Update customer information
     */
    public function updateCustomer(Request $request, Order $order)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'address' => 'nullable|string|max:500',
            'delivery_cost' => 'nullable|numeric|min:0',
        ]);

        try {
            DB::beginTransaction();

            if ($order->customer) {
                $order->customer->update($validated);
                $this->orderService->linkCustomer($order->id, (int) $order->getAttribute('customer_id'));
                $actionType = 'update';
            } else {
                $customer = Customer::create($validated);
                $this->orderService->linkCustomer($order->id, $customer->id);
                $actionType = 'create';
            }

            // Log customer action
            $this->loggingService->logCustomerAction($actionType, $validated, $order->id);

            DB::commit();

            return back()->with('success', 'تم حفظ بيانات العميل بنجاح');
        } catch (Exception $e) {
            DB::rollBack();

            $this->loggingService->logAction('فشل في حفظ بيانات العميل', [
                'order_id' => $order->id,
                'customer_data' => $validated,
                'error' => $e->getMessage(),
            ], 'error');

            return back()->withErrors(['error' => 'حدث خطأ أثناء حفظ بيانات العميل']);
        }
    }

    /**
     * Update driver information
     */
    public function updateDriver(Request $request, Order $order)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
        ]);

        try {
            DB::beginTransaction();

            if ($order->driver) {
                $order->driver->update($validated);
                $driver = $order->driver;
                $actionType = 'update';
            } else {
                $driver = Driver::create($validated);
                $this->orderService->linkDriver($order->id, $driver->id);
                $actionType = 'create';
            }

            // Log driver action
            $this->loggingService->logDriverAction($actionType, $validated, $order->id);

            DB::commit();

            return back()->with('success', 'تم حفظ بيانات السائق بنجاح');
        } catch (Exception $e) {
            DB::rollBack();

            $this->loggingService->logAction('فشل في حفظ بيانات السائق', [
                'order_id' => $order->id,
                'driver_data' => $validated,
                'error' => $e->getMessage(),
            ], 'error');

            return back()->withErrors(['error' => 'حدث خطأ أثناء حفظ بيانات السائق']);
        }
    }

    /**
     * Quick create customer
     */
    public function quickCustomer(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'address' => 'nullable|string|max:500',
            'hasWhatsapp' => 'nullable|string|in:0,1',
            'region' => 'nullable|string|max:255',
            'deliveryCost' => 'nullable|numeric|min:0',
        ]);

        try {
            $customer = Customer::create([
                'name' => $validated['name'],
                'phone' => $validated['phone'],
                'address' => $validated['address'] ?? null,
                'has_whatsapp' => $validated['hasWhatsapp'] === '1',
                'region' => $validated['region'] ?? null,
                'delivery_cost' => $validated['deliveryCost'] ?? null,
            ]);

            // Log customer creation
            $this->loggingService->logCustomerAction('create', [
                'name' => $customer->name,
                'phone' => $customer->phone,
                'address' => $customer->address,
                'delivery_cost' => $customer->delivery_cost,
            ]);

            return response()->json($customer);
        } catch (Exception $e) {
            $this->loggingService->logAction('فشل في إنشاء عميل جديد', [
                'customer_data' => $validated,
                'error' => $e->getMessage(),
            ], 'error');

            return response()->json(['error' => 'حدث خطأ أثناء إنشاء العميل'], 500);
        }
    }

    /**
     * Quick create driver
     */
    public function quickDriver(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
        ]);

        try {
            $driver = Driver::create($validated);

            // Log driver creation
            $this->loggingService->logDriverAction('create', [
                'name' => $driver->name,
                'phone' => $driver->phone,
            ]);

            return response()->json($driver);
        } catch (Exception $e) {
            $this->loggingService->logAction('فشل في إنشاء سائق جديد', [
                'driver_data' => $validated,
                'error' => $e->getMessage(),
            ], 'error');

            return response()->json(['error' => 'حدث خطأ أثناء إنشاء السائق'], 500);
        }
    }

    /**
     * Fetch customer info by phone
     */
    public function fetchCustomerInfo(Request $request)
    {
        $validated = $request->validate([
            'phone' => 'required|string|max:20',
        ]);

        try {
            $customer = Customer::where('phone', $validated['phone'])->first();

            if (! $customer) {
                return response()->json(['error' => 'لم يتم العثور على العميل'], 404);
            }

            return response()->json($customer);
        } catch (Exception $e) {
            return response()->json(['error' => 'حدث خطأ أثناء البحث عن العميل'], 500);
        }
    }

    /**
     * Search customers by name
     */
    public function searchCustomersByName(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        try {
            $customers = Customer::where('name', 'LIKE', '%'.$validated['name'].'%')
                ->limit(10)
                ->get();

            return response()->json($customers);
        } catch (Exception $e) {
            return response()->json(['error' => 'حدث خطأ أثناء البحث عن العميل'], 500);
        }
    }

    /**
     * Fetch driver info by phone
     */
    public function fetchDriverInfo(Request $request)
    {
        $validated = $request->validate([
            'phone' => 'required|string|max:20',
        ]);

        try {
            $driver = Driver::where('phone', $validated['phone'])->first();

            if (! $driver) {
                return response()->json(['error' => 'لم يتم العثور على السائق'], 404);
            }

            return response()->json($driver);
        } catch (Exception $e) {
            return response()->json(['error' => 'حدث خطأ أثناء البحث عن السائق'], 500);
        }
    }

    /**
     * Link customer to order
     */
    public function linkCustomer(Request $request, Order $order)
    {
        $validated = $request->validate([
            'customerId' => 'required|integer|exists:customers,id',
        ]);

        try {
            $customer = Customer::find($validated['customerId']);
            $this->orderService->linkCustomer($order->id, $validated['customerId']);

            // Log customer linking
            $this->loggingService->logCustomerAction('link', [
                'name' => $customer->name,
                'phone' => $customer->phone,
                'address' => $customer->address,
            ], $order->id);

            // Return in Inertia page props format
            $updatedOrder = $this->orderService->getOrderDetails($order->id);

            return back()->with([
                'success' => 'تم ربط العميل بالطلب بنجاح',
                'order' => $updatedOrder,
            ]);
        } catch (Exception $e) {
            $this->loggingService->logAction('فشل في ربط العميل بالطلب', [
                'order_id' => $order->id,
                'customer_id' => $validated['customerId'],
                'error' => $e->getMessage(),
            ], 'error');

            return back()->withErrors(['error' => 'حدث خطأ أثناء ربط العميل']);
        }
    }

    /**
     * Link driver to order
     */
    public function linkDriver(Request $request, Order $order)
    {
        $validated = $request->validate([
            'driverId' => 'required|integer|exists:drivers,id',
        ]);

        try {
            $driver = Driver::find($validated['driverId']);
            $this->orderService->linkDriver($order->id, $validated['driverId']);

            // Log driver linking
            $this->loggingService->logDriverAction('link', [
                'name' => $driver->name,
                'phone' => $driver->phone,
            ], $order->id);

            // Return in Inertia page props format
            $updatedOrder = $this->orderService->getOrderDetails($order->id);

            return back()->with([
                'success' => 'تم ربط السائق بالطلب بنجاح',
                'order' => $updatedOrder,
            ]);
        } catch (Exception $e) {
            $this->loggingService->logAction('فشل في ربط السائق بالطلب', [
                'order_id' => $order->id,
                'driver_id' => $validated['driverId'],
                'error' => $e->getMessage(),
            ], 'error');

            return back()->withErrors(['error' => 'حدث خطأ أثناء ربط السائق']);
        }
    }

    /**
     * Update order type
     */
    public function updateOrderType(Request $request, Order $order)
    {
        $validated = $request->validate([
            'type' => ['required', 'string', Rule::in($this->catalog->cashierCreatableCodes())],
            'table_number' => 'nullable|string|max:50',
        ]);

        if (! in_array($validated['type'], $this->cashierTabs->visibleTypeCodes(), true)) {
            return back()->withErrors(['error' => 'نوع الطلب غير ظاهر في تبويبات الكاشير']);
        }

        try {
            $this->orderService->changeOrderType(
                $order->id,
                $validated['type'],
                $validated['table_number'] ?? null
            );

            return back()->with('success', 'تم تغيير نوع الطلب بنجاح');
        } catch (Exception $e) {
            return back()->withErrors(['error' => 'حدث خطأ أثناء تغيير نوع الطلب: '.$e->getMessage()]);
        }
    }

    /**
     * Update order notes
     */
    public function updateOrderNotes(Request $request, Order $order)
    {
        $validated = $request->validate([
            'order_notes' => 'nullable|string|max:1000',
        ]);

        try {
            $this->orderService->updateNotes($order->id, null, $validated['order_notes']);

            return back()->with('success', 'تم حفظ ملاحظات الطلب بنجاح');
        } catch (Exception $e) {
            return back()->withErrors(['error' => 'حدث خطأ أثناء حفظ ملاحظات الطلب: '.$e->getMessage()]);
        }
    }

    /**
     * Apply discount to order
     */
    public function applyDiscount(Request $request, Order $order)
    {
        if (! auth()->user()->canApplyDiscounts()) {
            abort(403, 'غير مسموح لك بتطبيق خصم');
        }

        $validated = $request->validate([
            'discount' => 'required|numeric|min:0',
            'discount_type' => 'required|in:percent,value',
        ]);

        try {
            $this->orderService->applyDiscount($order->id, $validated['discount'], $validated['discount_type']);

            // Log discount application
            $this->loggingService->logDiscountApplication($order->id, $validated['discount'], $validated['discount_type']);

            return back()->with('success', 'تم تطبيق الخصم بنجاح');
        } catch (Exception $e) {
            $this->loggingService->logAction('فشل في تطبيق الخصم', [
                'order_id' => $order->id,
                'discount' => $validated['discount'],
                'discount_type' => $validated['discount_type'],
                'error' => $e->getMessage(),
            ], 'error');

            return back()->withErrors(['error' => 'حدث خطأ أثناء تطبيق الخصم: '.$e->getMessage()]);
        }
    }

    /**
     * Print order receipt
     */
    public function printReceipt(Request $request, Order $order)
    {
        $order = $this->orderService->getOrderDetails($order->id);

        try {
            $this->printService->printOrderReceipt($order);

            return back()->with('success', 'تم إرسال طباعة الطلب بنجاح');
        } catch (Exception $e) {
            return back()->withErrors(['error' => 'حدث خطأ أثناء طباعة طلب ']);
        }

    }

    /**
     * Get printers for products
     */
    public function getPrintersOfProducts(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'required|integer|exists:products,id',
        ]);

        try {
            $products = Product::with('printers:id')
                ->whereIn('id', $validated['ids'])
                ->get(['id']);

            $result = $products->map(function ($product) {
                return [
                    'id' => $product->id,
                    'printers' => $product->printers->map(function ($printer) {
                        return ['id' => $printer->id];
                    })->toArray(),
                ];
            });

            return response()->json($result);
        } catch (Exception $e) {
            return response()->json(['error' => 'حدث خطأ أثناء جلب بيانات الطابعات'], 500);
        }
    }

    /**
     * Print kitchen order with multiple printers
     */
    public function printInKitchen(Request $request)
    {
        $validated = $request->validate([
            'orderId' => 'required|integer|exists:orders,id',
            'type' => 'nullable|string|in:normal,changes',
            'items' => 'nullable|array',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.name' => 'required|string',
            'items.*.quantity' => 'nullable|numeric',
            'items.*.old_quantity' => 'nullable|numeric',
            'items.*.new_quantity' => 'nullable|numeric',
            'items.*.delta' => 'nullable|numeric',
            'items.*.notes' => 'nullable|string|max:500',
        ]);

        try {
            $type = $validated['type'] ?? 'normal';

            if ($type === 'changes') {
                $this->printService->printKitchenChangesReceipt(
                    $validated['orderId'],
                    $validated['items'] ?? []
                );
            } else {
                $this->printService->printKitchenReceipt(
                    $validated['orderId'],
                    $validated['items'] ?? []
                );
            }

            return back()->with('success', 'تم إرسال الطلب للمطبخ بنجاح');
        } catch (Exception $e) {
            return back()->withErrors(['error' => 'حدث خطأ أثناء طباعة طلب المطبخ: '.$e->getMessage()]);
        }
    }

    /**
     * Open the cashier drawer
     */
    public function openCashierDrawer()
    {
        try {
            $this->printService->openCashierDrawer();

            return back()->with('success', 'تم فتح درج الكاشير بنجاح');
        } catch (Exception $e) {
            return back()->withErrors(['error' => 'حدث خطأ أثناء فتح درج الكاشير: '.$e->getMessage()]);
        }
    }

    /**
     * Create a new order
     */
    public function createOrder(Request $request)
    {
        $validated = $request->validate([
            'type' => ['required', 'string', Rule::in($this->catalog->cashierCreatableCodes())],
            'table_number' => 'nullable|string|max:50',
        ]);

        try {
            $currentShift = $this->shiftService->getCurrentShift();

            if (! $currentShift) {
                return back()->withErrors(['error' => 'لا يوجد شيفت نشط']);
            }

            $createOrderDTO = new CreateOrderDTO(
                typeCode: $validated['type'],
                shiftId: $currentShift->id,
                userId: auth()->id(),
                opening: new OrderOpening($validated['table_number'] ?? null),
            );

            $order = $this->orderService->createOrder($createOrderDTO);

            // Log order creation
            $this->loggingService->logOrderCreation(
                $order->id,
                $validated['type'],
                $validated['table_number'] ?? null
            );

            return redirect()->route('orders.manage', $order);
        } catch (Exception $e) {
            logger()->error('Error creating order: '.$e->getMessage());

            return back()->withErrors(['error' => 'حدث خطأ أثناء إنشاء الطلب: '.$e->getMessage()]);
        }
    }

    /**
     * Get table types
     */
    public function getTableTypes()
    {
        try {
            $tableTypes = TableType::orderBy('name')->get();

            // If no table types exist, return default option
            if ($tableTypes->isEmpty()) {
                $tableTypes = collect([
                    (object) [
                        'id' => null,
                        'name' => 'صالة',
                        'created_at' => null,
                        'updated_at' => null,
                    ],
                ]);
            }

            return response()->json($tableTypes);
        } catch (Exception $e) {
            return response()->json(['error' => 'حدث خطأ أثناء جلب أنواع الطاولات'], 500);
        }
    }
}
