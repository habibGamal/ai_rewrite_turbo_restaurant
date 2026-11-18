<?php

namespace App\Http\Controllers;

use App\DTOs\Orders\CreateOrderDTO;
use App\Enums\OptionalFeature;
use App\Enums\OrderStatus;
use App\Enums\ProductType;
use App\Enums\SettingKey;
use App\Http\Requests\CompleteOrderRequest;
use App\Http\Requests\SaveOrderRequest;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Driver;
use App\Models\ExpenceType;
use App\Models\Expense;
use App\Models\ExternalSeller;
use App\Models\HeldSale;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Region;
use App\Models\Shift;
use App\Models\User;
use App\Modules\BusinessType;
use App\Orders\Behaviors\ProvidesTabData;
use App\Orders\CashierTabLayout;
use App\Orders\GuestBill;
use App\Orders\OrderOpening;
use App\Orders\OrderTypeCatalog;
use App\Orders\ReceiptPayment;
use App\Orders\ReceiptView;
use App\Orders\Tenders;
use App\Repositories\OrderRepository;
use App\Services\FeatureService;
use App\Services\Orders\OrderCompletionService;
use App\Services\Orders\OrderEquipmentService;
use App\Services\Orders\OrderFeeService;
use App\Services\Orders\OrderService;
use App\Services\Orders\ReturnRules;
use App\Services\PrintService;
use App\Services\Products\PosProductSearchService;
use App\Services\Products\PosVariantCatalogService;
use App\Services\SettingsService;
use App\Services\ShiftLoggingService;
use App\Services\ShiftService;
use App\Terminals\TerminalSeparation;
use App\Terminals\TerminalSessionResolver;
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
        private OrderRepository $orderRepository,
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

        $user = $this->deskUser();
        $separation = app(TerminalSeparation::class);

        $orders = [];
        foreach ($this->orderService->getShiftOrders($currentShift->id) as $order) {
            if (! $order instanceof Order || ! $this->orderVisibleOnDesk($order, $user) || ! $separation->canAct($order)) {
                continue;
            }

            $orders[] = $this->orderPayload($order);
        }

        $receivables = [];
        if ($user->canSeeTab('system:receivables')) {
            foreach ($this->orderRepository->receivablesForTypes($this->catalog->creditCodes()) as $order) {
                if (! $separation->canAct($order)) {
                    continue;
                }
                $receivables[] = $this->receivablePayload($order);
            }
        }

        $expenses = [];
        $expenseTypes = [];
        if ($user->canSeeTab('system:expenses')) {
            $drawer = app(TerminalSessionResolver::class)->current();
            $expenses = Expense::with('expenceType')
                ->where('shift_id', $currentShift->id)
                // With several drawers each one lists its own expenses.
                ->when($drawer !== null && app(FeatureService::class)->multiTerminalEnabled(), fn ($query) => $query->where('terminal_session_id', $drawer->getKey()))
                ->orderBy('created_at', 'desc')
                ->get();

            $expenseTypes = ExpenceType::all();
        }

        $tabs = $this->tabsPayload($currentShift);

        return Inertia::render('Orders/Index', [
            'orders' => $orders,
            'tabs' => $tabs,
            'defaultTab' => $this->defaultTab($tabs),
            'tabsOrientation' => app(SettingsService::class)->getCashierTabsOrientation(),
            'receivables' => $receivables,
            'currentShift' => $currentShift,
            'expenses' => $expenses,
            'expenseTypes' => $expenseTypes,
            'kdsCashierStatusEnabled' => setting(SettingKey::KDS_CASHIER_STATUS_ENABLED) === 'true',
        ]);
    }

    private function deskUser(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    /**
     * An order is sent to the desk when the user can see its type tab and the order is open
     * or in one of that tab's statuses, or when it is completed / cancelled and the user can
     * see the matching system tab.
     */
    private function orderVisibleOnDesk(Order $order, User $user): bool
    {
        $status = $order->status->value;

        if ($status === OrderStatus::COMPLETED->value && $user->canSeeTab('system:completed')) {
            return true;
        }

        if ($status === OrderStatus::CANCELLED->value && $user->canSeeTab('system:cancelled')) {
            return true;
        }

        $code = (string) $order->type;

        if (! $user->canSeeTab('type:'.$code)) {
            return false;
        }

        if (in_array($order->status, [OrderStatus::PROCESSING, OrderStatus::PENDING, OrderStatus::OUT_FOR_DELIVERY], true)) {
            return true;
        }

        $statuses = $this->catalog->behaviorFor($code)->presentation($this->catalog->find($code))->statuses;

        return in_array($status, $statuses, true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tabsPayload(Shift $shift): array
    {
        $tabs = [];

        foreach ($this->cashierTabs->resolveFor($shift, $this->deskUser()) as $tab) {
            if (! $tab['visible'] && ! $tab['forcedVisible']) {
                continue;
            }

            if ($tab['kind'] === 'type' && $tab['code'] !== null) {
                $behavior = $this->catalog->behaviorFor($tab['code']);
                $type = $this->catalog->find($tab['code']);
                $tab['presentation'] = $behavior->presentation($type)->toArray();
                if ($behavior instanceof ProvidesTabData) {
                    $tab = [...$tab, ...$behavior->tabData($type)];
                }
                $tab['display'] = $this->catalog->display($tab['code'])->toArray();
                $tab['guests_mode'] = $type->guestsMode()->value;
                if ($tab['presentation']['body'] === 'instant') {
                    $tab['instant'] = $this->instantPayload($shift, $tab['code']);
                }
            }

            $tabs[] = $tab;
        }

        return $tabs;
    }

    /**
     * What the instant-sale tab needs to sell without an order: the product
     * tiles, the cashier's last receipt and the type's payment rules.
     *
     * @return array<string, mixed>
     */
    private function instantPayload(Shift $shift, string $code): array
    {
        $user = $this->deskUser();
        $row = $this->catalog->find($code);
        $last = Order::query()
            ->where('shift_id', $shift->id)
            ->where('type', $code)
            ->where('user_id', $user->id)
            ->where('status', OrderStatus::COMPLETED)
            ->latest('id')
            ->first();

        $remoteCatalog = app(PosProductSearchService::class)->usesRemoteCatalog();

        return [
            'categories' => $this->categoryTree(withProducts: ! $remoteCatalog),
            'remoteCatalog' => $remoteCatalog,
            'lastSale' => $last instanceof Order ? [
                'id' => $last->id,
                'order_number' => (int) $last->order_number,
                'total' => (float) $last->total,
                'change' => null,
            ] : null,
            'heldCount' => HeldSale::query()->where('shift_id', $shift->id)
                ->when(app(TerminalSessionResolver::class)->current()?->id, fn ($query, int $session) => $query->where('terminal_session_id', $session))
                ->count(),
            'credit' => $row->creditFlags(),
            'can' => [
                'discount' => $user->can('order.apply_discount'),
                'changeItems' => $user->can('order.change_items'),
                'returns' => $user->can(ReturnRules::PERMISSION),
                'returnWithoutRestock' => $user->can(ReturnRules::WITHOUT_RESTOCK),
            ],
        ];
    }

    /**
     * The tab the desk opens on when the URL names none: instant sale for
     * retail profiles, the first tab otherwise.
     *
     * @param  list<array<string, mixed>>  $tabs
     */
    private function defaultTab(array $tabs): ?string
    {
        $type = app(FeatureService::class)->businessType();

        if (! in_array($type, [BusinessType::FASHION, BusinessType::GROCERY, BusinessType::GENERAL_RETAIL], true)) {
            return null;
        }

        foreach ($tabs as $tab) {
            if (($tab['presentation']['body'] ?? null) === 'instant') {
                return (string) $tab['key'];
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function orderPayload(Order $order, bool $withReceiptView = false): array
    {
        $payload = array_merge($order->toArray(), [
            'display' => $order->display()->toArray(),
            'receiptPayment' => ReceiptPayment::forOrder($order)->toArray(),
            'kitchen_status' => $order->getKitchenStatus(),
            'kitchen_progress' => $order->getKitchenProgress(),
            'credit' => $order->typeRow()->creditFlags(),
            'guests_mode' => $order->typeRow()->guestsMode()->value,
        ]);

        if ($order->relationLoaded('items')) {
            $payload['items'] = $order->items->map(function ($item) use ($order) {
                $productId = (int) $item->getAttribute('product_id');
                $itemArray = $item->toArray();
                $itemArray['kitchen_status'] = $order->getItemKitchenStatus($productId);

                return $itemArray;
            })->all();
        }

        if ($withReceiptView) {
            $payload['receiptView'] = ReceiptView::forOrder($order);
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function receivablePayload(Order $order): array
    {
        $payment = ReceiptPayment::forOrder($order);
        $customer = $order->customer;

        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'created_at' => $order->created_at,
            'type' => $order->type,
            'payment_status' => $order->payment_status->value,
            'customer' => $customer instanceof Customer ? [
                'id' => $customer->id,
                'name' => $customer->name,
                'phone' => $customer->phone,
            ] : null,
            'display' => $order->display()->toArray(),
            'total' => round((float) $order->total, 2),
            'paid' => $payment->paid,
            'remaining' => $payment->remaining,
        ];
    }

    /**
     * Display the order management interface
     */
    public function manage(Order $order)
    {
        $order = $this->orderService->getOrderDetails($order->id);

        $categories = $this->categoryTree();

        // Get all drivers for the dropdown
        $drivers = Driver::orderBy('name')->get();

        // Get all regions for the dropdown
        $regions = Region::orderBy('name')->get();
        $outsourcedEnabled = app(FeatureService::class)->outsourcedOrdersEnabled();
        $feeService = app(OrderFeeService::class);
        $feesEnabled = app(FeatureService::class)->customFeesEnabled() && $feeService->orderAcceptsFees($order);

        return Inertia::render('Orders/ManageOrder', [
            'order' => $this->orderPayload($order, true),
            'credit' => $order->typeRow()->creditFlags(),
            'orderDisplay' => $order->display()->toArray(),
            'changeTypeOptions' => $this->changeTypeOptions(),
            'categories' => $categories,
            'drivers' => $drivers,
            'regions' => $regions,
            'externalOrders' => $order->externalOrders()->with('items')->where('status', '!=', 'cancelled')->orderBy('id')->get(),
            'sellers' => $outsourcedEnabled
                ? ExternalSeller::where('is_active', true)
                    ->with(['products' => fn ($query) => $query->where('is_active', true)->orderBy('name')->select('id', 'external_seller_id', 'name', 'price')])
                    ->orderBy('name')->get(['id', 'name'])
                    ->map(fn ($seller) => ['id' => $seller->id, 'name' => $seller->name, 'products' => $seller->products->map(fn ($product) => ['id' => $product->id, 'name' => $product->name, 'price' => $product->price])])
                : [],
            'canAddExternalOrder' => $outsourcedEnabled && ! $order->typeRow()->allowsCredit(),
            'guestDue' => app(GuestBill::class)->due($order),
            ...$this->equipmentProps($order),
            'canAddFees' => $feesEnabled,
            'feeSuggestions' => $feesEnabled ? $feeService->suggestions() : [],
            'kdsCashierStatusEnabled' => setting(SettingKey::KDS_CASHIER_STATUS_ENABLED) === 'true',
        ]);
    }

    /**
     * Equipment lines on the order and the equipment its type allows. Lines
     * are always sent (the money is on the bill even after the vendor turns
     * the feature off); the options only while it is on.
     *
     * @return array<string, mixed>
     */
    private function equipmentProps(Order $order): array
    {
        $enabled = app(FeatureService::class)->equipmentEnabled();
        $options = $enabled ? app(OrderEquipmentService::class)->allowedFor($order) : collect();

        return [
            'equipmentLines' => $order->equipmentLines()->get(['id', 'equipment_id', 'name', 'quantity', 'unit_price', 'total']),
            'equipmentOptions' => $options->map(fn ($equipment): array => [
                'id' => $equipment->getKey(),
                'name' => $equipment->getAttribute('name'),
                'price' => (float) $equipment->getAttribute('usage_price'),
            ])->values()->all(),
        ];
    }

    /**
     * The POS menu. Inactive products are never sold. With retail variants on,
     * each active product group is one tile (in its category's `groups`) and its
     * SKUs are not sent as individual product tiles.
     *
     * @return list<array<string, mixed>>
     */
    /**
     * @param  bool  $withProducts  false sends the categories only (big catalogues
     *                              search on the server instead)
     */
    private function categoryTree(bool $withProducts = true): array
    {
        $variantsEnabled = app(FeatureService::class)->variantsEnabled();

        $productConstraint = function ($query) use ($variantsEnabled, $withProducts) {
            $query
                ->when(! $withProducts, fn ($query) => $query->whereRaw('1 = 0'))
                ->whereNot('type', ProductType::RawMaterial)
                ->where('legacy', false)
                ->where('is_active', true)
                ->when($variantsEnabled, fn ($query) => $query->whereNull('product_group_id'))
                ->orderBy('name');
        };

        $groups = $variantsEnabled && $withProducts ? app(PosVariantCatalogService::class)->tilesByCategory() : null;

        return Category::query()
            ->whereNull('parent_id')
            ->with([
                'products' => $productConstraint,
                'blockedOrderTypes:id,code',
                'children' => fn ($query) => $query->orderBy('name'),
                'children.products' => $productConstraint,
                'children.blockedOrderTypes:id,code',
                'children.parent.blockedOrderTypes:id,code',
            ])
            ->orderBy('name')
            ->get()
            ->map(fn (Category $category): array => $this->categoryNode($category, $groups))
            ->all();
    }

    /**
     * @param  array<int, list<array<string, mixed>>>|null  $groups  group tiles by category id; null = variants off
     * @return array<string, mixed>
     */
    private function categoryNode(Category $category, ?array $groups = null): array
    {
        $node = [
            'id' => $category->id,
            'name' => $category->name,
            'parent_id' => $category->parent_id,
            'blocked_order_type_codes' => $category->blockedOrderTypeCodes(),
            'products' => $category->products->values(),
            'children' => $category->children
                ->map(function (mixed $child) use ($groups): array {
                    if (! $child instanceof Category) {
                        return [];
                    }

                    return $this->categoryNode($child, $groups);
                })
                ->values()
                ->all(),
        ];

        if ($groups !== null) {
            $node['groups'] = $groups[$category->id] ?? [];
        }

        return $node;
    }

    /**
     * @return list<array{code: string, label: string, showTable: bool}>
     */
    private function changeTypeOptions(): array
    {
        $creatable = $this->catalog->cashierCreatableCodes();
        $options = [];

        $user = $this->deskUser();

        foreach ($this->cashierTabs->visibleTypeCodes() as $code) {
            if (! in_array($code, $creatable, true) || ! $user->canSeeTab('type:'.$code)) {
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
        $items = $request->validated('items') ?: [];
        $this->assertItemChangesAllowed($request->user(), $order, $items);

        try {
            $this->orderService->updateOrderItems($order->id, $items);

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
     * The POS hides these controls, but the server must hold the same rules:
     * without order.change_items a saved line can't be lowered or removed, and
     * without order.apply_discount no line discount can be added or changed.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function assertItemChangesAllowed(?User $user, Order $order, array $items): void
    {
        abort_unless($user instanceof User, 403);

        $saved = $order->items()->get(['product_id', 'quantity', 'item_discount', 'item_discount_type', 'item_discount_percent'])->keyBy('product_id');
        $incoming = collect($items)->groupBy(fn (array $item): int => (int) $item['product_id']);

        if (! $user->canChangeOrderItems()) {
            foreach ($saved as $productId => $line) {
                $quantity = (float) ($incoming->get($productId)?->sum(fn (array $item): float => (float) $item['quantity']) ?? 0);
                abort_if($quantity + 0.0001 < (float) $line->quantity, 403, 'غير مسموح لك بتقليل أو حذف أصناف محفوظة');
            }
        }

        if (! $user->canApplyDiscounts()) {
            foreach ($incoming as $productId => $lines) {
                $line = $lines->first();
                $was = $saved->get($productId);
                $discount = round((float) $lines->sum(fn (array $item): float => (float) ($item['item_discount'] ?? 0)), 2);
                // A percent discount set earlier by a manager may scale with the quantity.
                $samePercent = $was !== null
                    && ($line['item_discount_type'] ?? null) === 'percent'
                    && $was->item_discount_type === 'percent'
                    && abs((float) ($line['item_discount_percent'] ?? 0) - (float) $was->item_discount_percent) < 0.001;
                abort_if($discount > round((float) ($was->item_discount ?? 0), 2) + 0.01 && ! $samePercent, 403, 'غير مسموح لك بتطبيق خصم');
            }
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

            if ($e->getMessage() === OrderCompletionService::GUESTS_REQUIRED_MESSAGE) {
                return back()->withErrors(['guests_count' => $e->getMessage()]);
            }

            return back()->withErrors(['error' => 'حدث خطأ أثناء إنهاء الطلب: '.$e->getMessage()]);
        }
    }

    /**
     * Collect the remaining balance of a partly paid order into the current shift.
     */
    public function collectRemaining(CompleteOrderRequest $request, Order $order)
    {
        app(FeatureService::class)->abortUnlessEnabled(OptionalFeature::DEFERRED_PAYMENT, 404);

        try {
            $paymentsData = collect($request->validated())->except('print')->toArray();
            $paidBefore = (float) $order->payments()->sum('amount');

            $collected = $this->orderService->collectRemaining($order->id, Tenders::fromArray($paymentsData));
            $paidAfter = (float) $collected->payments()->sum('amount');

            $this->loggingService->logAction("تحصيل آجل للطلب رقم #{$collected->id}", [
                'order_id' => $collected->id,
                'order_shift_id' => $collected->shift_id,
                'collected_in_shift_id' => $this->shiftService->getCurrentShift()?->id,
                'payments' => $paymentsData,
                'collected_amount' => round($paidAfter - $paidBefore, 2),
                'remaining' => round(max(0, (float) $collected->total - $paidAfter), 2),
            ]);

            if ($request->boolean('print')) {
                try {
                    $this->printService->printOrderReceipt($collected->fresh(['payments', 'items', 'customer']));
                } catch (Exception $printError) {
                    // The money is already recorded; a printer problem must not undo or hide that.
                    $this->loggingService->logAction('فشل في طباعة إيصال التحصيل', [
                        'order_id' => $collected->id,
                        'error' => $printError->getMessage(),
                    ], 'warning');
                }
            }

            return redirect()->to(route('orders.index').'#receivables')
                ->with('success', 'تم تحصيل المتبقي بنجاح');
        } catch (Exception $e) {
            $this->loggingService->logAction('فشل في تحصيل المتبقي', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ], 'error');

            return back()->withErrors(['error' => 'حدث خطأ أثناء تحصيل المتبقي: '.$e->getMessage()]);
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
     * Update order type
     */
    public function updateOrderType(Request $request, Order $order)
    {
        $validated = $request->validate([
            'type' => ['required', 'string', Rule::in($this->catalog->cashierCreatableCodes())],
            'table_number' => 'nullable|string|max:50',
        ]);

        if (! $this->deskUser()->canSeeTab('type:'.$validated['type'])) {
            return back()->withErrors(['error' => 'غير مسموح لك بهذا النوع من الطلبات']);
        }

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
     * Set or clear the number of guests on an open order.
     */
    public function updateGuestsCount(Request $request, Order $order)
    {
        $validated = $request->validate([
            'guests_count' => 'nullable|integer|min:1|max:999',
        ]);

        try {
            $guests = isset($validated['guests_count']) ? (int) $validated['guests_count'] : null;
            $this->orderService->updateGuestsCount($order->id, $guests);

            return back()->with('success', 'تم حفظ عدد الأفراد');
        } catch (Exception $e) {
            return back()->withErrors(['guests_count' => $e->getMessage()]);
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
            'guests_count' => 'nullable|integer|min:1|max:999',
        ]);

        abort_unless($this->deskUser()->canSeeTab('type:'.$validated['type']), 403, 'غير مسموح لك بهذا النوع من الطلبات');

        try {
            $currentShift = $this->shiftService->getCurrentShift();

            if (! $currentShift) {
                return back()->withErrors(['error' => 'لا يوجد شيفت نشط']);
            }

            $createOrderDTO = new CreateOrderDTO(
                typeCode: $validated['type'],
                shiftId: $currentShift->id,
                userId: auth()->id(),
                opening: new OrderOpening(
                    $validated['table_number'] ?? null,
                    isset($validated['guests_count']) ? (int) $validated['guests_count'] : null,
                ),
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
}
