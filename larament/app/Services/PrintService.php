<?php

namespace App\Services;

use App\Enums\SettingKey;
use App\Jobs\PrintKitchenOrder;
use App\Jobs\PrintOrderReceipt;
use App\Models\Order;
use App\Models\Product;
use App\Orders\ReceiptPayment;
use App\Orders\ReceiptView;
use App\PrintAgent\AgentPrintConnector;
use App\PrintAgent\PrintAgentException;
use App\PrintAgent\PrintAgentTarget;
use App\Services\PrintStrategies\PrintStrategyFactory;
use App\Services\PrintStrategies\PrintStrategyInterface;
use App\Terminals\TerminalSessionResolver;
use Exception;
use Log;
use Mike42\Escpos\EscposImage;
use Mike42\Escpos\PrintConnectors\NetworkPrintConnector;
use Mike42\Escpos\PrintConnectors\PrintConnector;
use Mike42\Escpos\PrintConnectors\WindowsPrintConnector;
use Mike42\Escpos\Printer;

class PrintService
{
    private const USE_QUEUE = false;

    private PrintStrategyInterface $printStrategy;

    public function __construct()
    {
        $this->printStrategy = $this->createPrintStrategy();
    }

    /**
     * Create the appropriate print strategy
     * You can modify this method to switch between strategies programmatically
     */
    private function createPrintStrategy(): PrintStrategyInterface
    {
        // Use factory to get the best available strategy
        $strategy = PrintStrategyFactory::create('wkhtmltoimage');
        Log::info('Using print strategy: '.$strategy->getName());

        return $strategy;
    }

    /**
     * Create appropriate print connector based on printer IP format
     */
    private function createConnector(string $printerIp): NetworkPrintConnector|WindowsPrintConnector
    {
        // Check if it's a UNC path (\\hostname\printername or \\ip\sharename)
        if (preg_match('/^\\\\\\\\[^\\\\]+\\\\[^\\\\]+/', $printerIp)) {
            return new WindowsPrintConnector($printerIp);
        }

        // Check if it's just a printer name (e.g., share_cash)
        if (! filter_var($printerIp, FILTER_VALIDATE_IP)) {
            return new WindowsPrintConnector($printerIp);
        }

        // Check if it's an IP address format (xxx.xxx.xxx.xxx)
        if (filter_var($printerIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return new NetworkPrintConnector($printerIp, 9100);
        }

        // Default to network connector if format is unclear
        return new NetworkPrintConnector($printerIp, 9100);
    }

    /**
     * Print order receipt
     */
    public function printOrderReceipt(Order $order): void
    {
        if (self::USE_QUEUE) {
            Log::info("Dispatching order receipt to queue for order {$order->id}");
            PrintOrderReceipt::dispatch($order);
        } else {
            Log::info("Printing order receipt directly for order {$order->id}");
            $this->printOrderProcess($order);
        }
    }

    /**
     * Print kitchen receipt
     */
    public function printKitchenReceipt($orderId, $items): void
    {
        if (self::USE_QUEUE) {
            Log::info("Dispatching kitchen receipt to queue for order {$orderId}");
            $this->printKitchenQueued($orderId, $items);
        } else {
            Log::info("Printing kitchen receipt directly for order {$orderId}");
            $this->printKitchenDirect($orderId, $items);
        }
    }

    /**
     * Print kitchen changes receipt (increments/decrements on save)
     */
    public function printKitchenChangesReceipt($orderId, $items): void
    {
        if (self::USE_QUEUE) {
            Log::info("Dispatching kitchen changes receipt to queue for order {$orderId}");
            $this->printKitchenChangesQueued($orderId, $items);
        } else {
            Log::info("Printing kitchen changes receipt directly for order {$orderId}");
            $this->printKitchenChangesDirect($orderId, $items);
        }
    }

    /**
     * The receipt printer of this device's drawer (multiple terminals on and
     * the drawer has its own printer), else the Settings cashier printer.
     * Any drawer may print any order: the receipt goes to the printer of the
     * device that asked for it.
     */
    public function cashierPrinter(): string
    {
        return app(TerminalSessionResolver::class)->terminal()?->receiptPrinter()
            ?? (string) setting(SettingKey::CASHIER_PRINTER_IP);
    }

    /**
     * Where this device's receipts and drawer kicks go: the Turbo Label app
     * on the drawer's computer when the drawer prints via it, else the
     * printer address. Agent bytes are sent by sendIfAgent() once complete.
     */
    /**
     * The printer of the device that pressed print; from the admin panel (no
     * device) the order's sales point; with a single sales point the default
     * one. A sales point set to print through Turbo Label gets an agent job,
     * anything else prints from the server.
     */
    private function cashierConnector(string $kind, ?Terminal $fallback = null): PrintConnector
    {
        $terminal = app(TerminalSessionResolver::class)->terminal()
            ?? $fallback
            ?? (app(FeatureService::class)->multiTerminalEnabled() ? null : Terminal::default());

        if ($terminal !== null && $terminal->printsViaAgent()) {
            return new AgentPrintConnector($terminal, $kind);
        }

        return $this->createConnector($this->cashierPrinter());
    }

    private function printerConnector(\App\Models\Printer $printer, string $kind): PrintConnector
    {
        if ($printer->printsViaAgent()) {
            return new AgentPrintConnector($printer, $kind);
        }

        return $this->createConnector((string) $printer->ip_address);
    }

    private function sendIfAgent(PrintConnector $connector): void
    {
        if ($connector instanceof AgentPrintConnector) {
            $connector->send();
        }
    }

    /**
     * Open the cashier drawer
     */
    public function openCashierDrawer(): void
    {
        try {
            $connector = $this->cashierConnector('drawer');
            $printer = new Printer($connector);

            Log::info('Opening cashier drawer');

            // Send pulse to open the drawer
            $printer->pulse();

        } catch (Exception $e) {
            Log::error('Error opening cashier drawer: '.$e->getMessage());
            throw $e;
        } finally {
            if (isset($printer)) {
                $printer->close();
            }
        }

        $this->sendIfAgent($connector);
    }

    /**
     * Print kitchen order directly (synchronous)
     */
    private function printKitchenDirect($orderId, $items): void
    {
        try {
            Log::info("Starting direct kitchen printing for order {$orderId}");

            // Load order with relationships
            $order = Order::with(['user', 'customer', 'driver', 'table'])->findOrFail($orderId);

            // Validate and prepare items data
            $preparedItems = $this->prepareKitchenItems($items);

            if (empty($preparedItems)) {
                throw new Exception('لا توجد منتجات للطباعة');
            }

            // Get product IDs from items to find their printers
            $productIds = collect($preparedItems)->pluck('product_id')->unique()->values()->toArray();

            // Get products with their printers
            $products = Product::with('printers:id')
                ->whereIn('id', $productIds)
                ->get(['id']);

            // Map items to printers
            $itemsByPrinterMap = [];

            foreach ($preparedItems as $item) {
                $product = $products->firstWhere('id', $item['product_id']);

                if ($product && $product->printers->isNotEmpty()) {
                    foreach ($product->printers as $printer) {
                        if (! isset($itemsByPrinterMap[$printer->id])) {
                            $itemsByPrinterMap[$printer->id] = [];
                        }

                        // Add item to this printer's list
                        $itemsByPrinterMap[$printer->id][] = $item;
                    }
                }
            }

            // Print directly to each printer
            $errors = [];
            foreach ($itemsByPrinterMap as $printerId => $printerItems) {
                try {
                    $this->printKitchenProcess($order, $printerItems, $printerId);
                } catch (Exception $e) {
                    $errors[] = $e->getMessage();
                }
            }

            if ($errors !== []) {
                throw new PrintAgentException(implode(' — ', $errors));
            }

            if (empty($itemsByPrinterMap)) {
                Log::warning("No printers found for order {$orderId} items");
                throw new Exception('لا توجد طابعات مخصصة للمنتجات المحددة');
            }

            Log::info("Kitchen printing completed directly for order {$orderId}");

        } catch (Exception $e) {
            Log::error("Error in direct kitchen printing for order {$orderId}: ".$e->getMessage());
            throw $e;
        }
    }

    /**
     * Print kitchen order via queue (asynchronous)
     */
    private function printKitchenQueued($orderId, $items): void
    {
        try {
            Log::info("Starting kitchen printing via queue for order {$orderId}");

            // Load order with relationships
            $order = Order::with(['user', 'customer', 'driver', 'table'])->findOrFail($orderId);

            // Validate and prepare items data
            $preparedItems = $this->prepareKitchenItems($items);

            if (empty($preparedItems)) {
                throw new Exception('لا توجد منتجات للطباعة');
            }

            // Get product IDs from items to find their printers
            $productIds = collect($preparedItems)->pluck('product_id')->unique()->values()->toArray();

            // Get products with their printers
            $products = Product::with('printers:id')
                ->whereIn('id', $productIds)
                ->get(['id']);

            // Map items to printers
            $itemsByPrinterMap = [];

            foreach ($preparedItems as $item) {
                $product = $products->firstWhere('id', $item['product_id']);

                if ($product && $product->printers->isNotEmpty()) {
                    foreach ($product->printers as $printer) {
                        if (! isset($itemsByPrinterMap[$printer->id])) {
                            $itemsByPrinterMap[$printer->id] = [];
                        }

                        // Add item to this printer's list
                        $itemsByPrinterMap[$printer->id][] = $item;
                    }
                }
            }

            // Dispatch print jobs to queue for each printer
            foreach ($itemsByPrinterMap as $printerId => $printerItems) {
                PrintKitchenOrder::dispatch($order, $printerItems, $printerId);
            }

            if (empty($itemsByPrinterMap)) {
                Log::warning("No printers found for order {$orderId} items");
                throw new Exception('لا توجد طابعات مخصصة للمنتجات المحددة');
            }

            Log::info("Kitchen printing jobs dispatched successfully for order {$orderId}");

        } catch (Exception $e) {
            Log::error("Error dispatching kitchen printing jobs for order {$orderId}: ".$e->getMessage());
            throw $e;
        }
    }

    /**
     * Prepare and validate kitchen items data
     */
    private function prepareKitchenItems(array $items): array
    {
        $preparedItems = [];

        foreach ($items as $item) {
            // Validate required fields
            if (! isset($item['product_id']) || ! isset($item['quantity'])) {
                Log::warning('Invalid item data: missing product_id or quantity', $item);

                continue;
            }

            $preparedItem = [
                'product_id' => (int) $item['product_id'],
                'quantity' => (int) $item['quantity'],
                'notes' => $item['notes'] ?? null,
            ];

            // Get product name if not provided
            if (isset($item['name'])) {
                $preparedItem['name'] = $item['name'];
            } else {
                $product = Product::find($item['product_id']);
                $preparedItem['name'] = $product ? $product->name : "المنتج رقم {$item['product_id']}";
            }

            $preparedItems[] = $preparedItem;
        }

        return $preparedItems;
    }

    /**
     * Print kitchen order to a specific printer
     */
    public function printKitchenProcess(Order $order, array $orderItems, int $printerId): void
    {
        $this->printToKitchenPrinter(
            $printerId,
            'kitchen',
            fn (): string => $this->generateKitchenHtml($order, $orderItems),
            'kitchen order',
        );
    }

    /**
     * @param  callable(): string  $renderHtml
     */
    private function printToKitchenPrinter(int $printerId, string $kind, callable $renderHtml, string $context): void
    {
        $printer = null;
        $escposPrinter = null;
        $tempImagePath = null;

        try {
            $printer = \App\Models\Printer::findOrFail($printerId);

            if (! $printer->printsViaAgent() && ! $printer->ipAddress()) {
                Log::warning("Printer {$printer->name} has no IP address configured");

                return;
            }

            $targetLabel = $printer->printsViaAgent() ? 'Turbo Label' : $printer->ipAddress();
            Log::info("Printing {$context} to printer {$printer->name} ({$targetLabel}) using {$this->printStrategy->getName()}");

            // ---------- 1. Generate HTML content using kitchen template ----------
            $html = $renderHtml();

            // ---------- 2. Convert HTML to image using current strategy ----------
            $tempImagePath = $this->printStrategy->generateImageFromHtml($html, 572, 100);

            // ---------- 3. Print via escpos-php ----------
            $connector = $this->printerConnector($printer, $kind);
            $escposPrinter = new Printer($connector);
            $escposPrinter->setJustification(Printer::JUSTIFY_CENTER);

            // Load and print image
            $escposImage = EscposImage::load($tempImagePath);
            $escposPrinter->bitImage($escposImage);
            $escposPrinter->feed(3);
            $escposPrinter->cut();

            Log::info("Kitchen ticket ({$context}) printed successfully to printer {$printer->name}");
        } catch (Exception $e) {
            Log::error("Error printing {$context} to printer {$printerId}: ".$e->getMessage());
            if ($printer?->printsViaAgent()) {
                throw $e;
            }

            return;
        } finally {
            if ($tempImagePath !== null && file_exists($tempImagePath)) {
                unlink($tempImagePath);
            }
            if ($escposPrinter !== null) {
                $escposPrinter->close();
            }
        }

        $this->sendIfAgent($connector);
    }

    /**
     * Generate HTML content for kitchen receipt printing
     */
    private function generateKitchenHtml(Order $order, array $orderItems): string
    {
        // Use Blade view to render the kitchen receipt
        return view('print.kitchen-template', [
            'order' => $order,
            'orderItems' => $orderItems,
        ])->render();
    }

    /**
     * Test cashier printer connection with sample text
     */
    public function testCashierPrinter(): void
    {
        try {
            $connector = $this->cashierConnector('test');
            $printerIp = $connector instanceof AgentPrintConnector ? 'Turbo Label' : $this->cashierPrinter();
            $printer = new Printer($connector);

            Log::info('Testing cashier printer connection');

            // Print test text
            $printer->setJustification(Printer::JUSTIFY_CENTER);
            $printer->selectPrintMode(Printer::MODE_DOUBLE_WIDTH);
            $printer->text("Printer Test\n");
            $printer->selectPrintMode();
            $printer->text("------------------------\n");
            $printer->text('Date: '.now()->format('Y-m-d H:i:s')."\n");
            $printer->text("IP: {$printerIp}\n");
            $printer->text("------------------------\n");
            $printer->text("If you see this text, the printer is working correctly\n");
            $printer->feed(3);
            $printer->cut();

            Log::info('Test print sent successfully to cashier printer');

        } catch (Exception $e) {
            Log::error('Error testing cashier printer: '.$e->getMessage());
            throw $e;
        } finally {
            if (isset($printer)) {
                $printer->close();
            }
        }

        $this->sendIfAgent($connector);
    }

    /**
     * A test page on a drawer's or kitchen printer's printer through its Turbo Label app.
     */
    public function testThroughAgent(PrintAgentTarget $target): void
    {
        $connector = new AgentPrintConnector($target, 'test');
        $printer = new Printer($connector);
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->selectPrintMode(Printer::MODE_DOUBLE_WIDTH);
        $printer->text("Turbo Label\n");
        $printer->selectPrintMode();
        $printer->text("------------------------\n");
        $printer->text('Date: '.now()->format('Y-m-d H:i:s')."\n");
        $printer->text("Printer test OK\n");
        $printer->feed(3);
        $printer->cut();
        $printer->close();

        $connector->send();
    }

    /**
     * Print order receipt using the current strategy
     */
    public function printOrderProcess(Order $order): void
    {
        try {

            $connector = $this->cashierConnector('receipt', $order->terminal_id !== null ? Terminal::query()->find($order->terminal_id) : null);
            $printer = new Printer($connector);

            Log::info("Printing order receipt for order {$order->id} using {$this->printStrategy->getName()}");

            // Load order with relationships
            $order->load(['user', 'customer', 'driver', 'items.product', 'table', 'payments']);
            $receiptView = ReceiptView::forOrder($order);

            $itemCount = $order->items->count();

            // If order has 5 or fewer items, use existing single-image logic
            if ($itemCount <= 5) {
                Log::info("Order {$order->id} has {$itemCount} items, using single receipt printing");

                // ---------- 1. Generate HTML content using generateReceiptHtml method ----------
                $html = $this->generateReceiptHtml($order, $receiptView);

                // ---------- 2. Convert HTML to image using current strategy ----------
                $tempImagePath = $this->printStrategy->generateImageFromHtml($html, 567, 1200);

                // ---------- 3. Print via escpos-php ----------
                $printer->setJustification(Printer::JUSTIFY_CENTER);

                // Load and print image
                $escposImage = EscposImage::load($tempImagePath);
                $printer->bitImage($escposImage);
                $printer->feed(3);
                $printer->cut();

                // Clean up
                unlink($tempImagePath);

            } else {
                // Split printing for orders with more than 5 items
                Log::info("Order {$order->id} has {$itemCount} items, using split receipt printing");

                $printer->setJustification(Printer::JUSTIFY_CENTER);

                // ---------- 1. Print header with first 5 items ----------
                $headerItems = $order->items->take(5);
                $html = $this->generateReceiptHeaderHtml($order, $headerItems, $receiptView);
                $tempImagePath = $this->printStrategy->generateImageFromHtml($html, 567, 1200);
                $escposImage = EscposImage::load($tempImagePath);
                $printer->bitImage($escposImage);
                unlink($tempImagePath);
                Log::info("Printed header with first 5 items for order {$order->id}");

                // ---------- 2. Print remaining items in chunks of 5 ----------
                $remainingItems = $order->items->skip(5);
                $chunks = $remainingItems->chunk(5);

                foreach ($chunks as $index => $chunk) {
                    $html = $this->generateReceiptItemsHtml($chunk, $receiptView);
                    $tempImagePath = $this->printStrategy->generateImageFromHtml($html, 567, 1200);
                    $escposImage = EscposImage::load($tempImagePath);
                    $printer->bitImage($escposImage);
                    unlink($tempImagePath);
                    Log::info('Printed items chunk '.($index + 1)." for order {$order->id}");
                }

                // ---------- 3. Print footer with totals ----------
                $html = $this->generateReceiptFooterHtml($order, $receiptView);
                $tempImagePath = $this->printStrategy->generateImageFromHtml($html, 567, 1200);
                $escposImage = EscposImage::load($tempImagePath);
                $printer->bitImage($escposImage);
                $printer->feed(3);
                $printer->cut();
                unlink($tempImagePath);
                Log::info("Printed footer for order {$order->id}");
            }

            Log::info("Order receipt printing completed successfully for order {$order->id}");

        } catch (Exception $e) {
            Log::error("Error printing order receipt for order {$order->id}: ".$e->getMessage());
            throw $e;
        } finally {
            if (isset($printer)) {
                $printer->close();
            }
        }

        $this->sendIfAgent($connector);
    }

    /**
     * Generate HTML content for receipt printing
     */
    private function generateReceiptHtml(Order $order, array $receiptView): string
    {
        // Use Blade view to render the receipt with minimal data
        return view('print.receipt-template', [
            'order' => $order,
            'receiptView' => $receiptView,
            'receiptPayment' => ReceiptPayment::forOrder($order),
        ])->render();
    }

    /**
     * Set the print strategy programmatically by name
     */
    public function setPrintStrategyByName(string $strategyName): void
    {
        $this->printStrategy = PrintStrategyFactory::create($strategyName);
        Log::info('Print strategy changed to: '.$this->printStrategy->getName());
    }

    /**
     * Set the print strategy programmatically
     */
    public function setPrintStrategy(PrintStrategyInterface $strategy): void
    {
        $this->printStrategy = $strategy;
        Log::info('Print strategy changed to: '.$strategy->getName());
    }

    /**
     * Get the current print strategy
     */
    public function getPrintStrategy(): PrintStrategyInterface
    {
        return $this->printStrategy;
    }

    /**
     * Get all available print strategies
     */
    public function getAvailableStrategies(): array
    {
        return PrintStrategyFactory::getAvailableStrategies();
    }

    /**
     * Check if a specific strategy is available
     */
    public function isStrategyAvailable(string $strategyName): bool
    {
        try {
            $strategy = PrintStrategyFactory::create($strategyName);

            return $strategy->isAvailable();
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Get the current queue usage setting
     */
    public function isUsingQueue(): bool
    {
        return self::USE_QUEUE;
    }

    /**
     * Generate HTML content for receipt header with specified items
     */
    private function generateReceiptHeaderHtml(Order $order, $items, array $receiptView): string
    {
        return view('print.receipt-header', [
            'order' => $order,
            'receiptView' => $receiptView,
            'items' => $items,
        ])->render();
    }

    /**
     * Generate HTML content for receipt items continuation
     */
    private function generateReceiptItemsHtml($items, array $receiptView): string
    {
        return view('print.receipt-items', [
            'receiptView' => $receiptView,
            'items' => $items,
        ])->render();
    }

    /**
     * Generate HTML content for receipt footer
     */
    private function generateReceiptFooterHtml(Order $order, array $receiptView): string
    {
        return view('print.receipt-footer', [
            'order' => $order,
            'receiptView' => $receiptView,
            'receiptPayment' => ReceiptPayment::forOrder($order),
        ])->render();
    }

    private function printKitchenChangesDirect($orderId, $items): void
    {
        try {
            Log::info("Starting direct kitchen changes printing for order {$orderId}");

            $order = Order::with(['user', 'customer', 'driver', 'table'])->findOrFail($orderId);
            $preparedItems = $this->prepareKitchenChangesItems($items);

            if (empty($preparedItems)) {
                throw new Exception('لا توجد تغييرات للطباعة');
            }

            $productIds = collect($preparedItems)->pluck('product_id')->unique()->values()->toArray();
            $products = Product::with('printers:id')->whereIn('id', $productIds)->get(['id']);

            $itemsByPrinterMap = [];
            foreach ($preparedItems as $item) {
                $product = $products->firstWhere('id', $item['product_id']);
                if ($product && $product->printers->isNotEmpty()) {
                    foreach ($product->printers as $printer) {
                        if (! isset($itemsByPrinterMap[$printer->id])) {
                            $itemsByPrinterMap[$printer->id] = [];
                        }
                        $itemsByPrinterMap[$printer->id][] = $item;
                    }
                }
            }

            $errors = [];
            foreach ($itemsByPrinterMap as $printerId => $printerItems) {
                try {
                    $this->printKitchenChangesProcess($order, $printerItems, $printerId);
                } catch (Exception $e) {
                    $errors[] = $e->getMessage();
                }
            }

            if ($errors !== []) {
                throw new PrintAgentException(implode(' — ', $errors));
            }

            if (empty($itemsByPrinterMap)) {
                Log::warning("No printers found for order {$orderId} changes items");
                throw new Exception('لا توجد طابعات مخصصة للمنتجات المحددة');
            }

            Log::info("Kitchen changes printing completed directly for order {$orderId}");

        } catch (Exception $e) {
            Log::error("Error in direct kitchen changes printing for order {$orderId}: ".$e->getMessage());
            throw $e;
        }
    }

    private function printKitchenChangesQueued($orderId, $items): void
    {
        try {
            Log::info("Starting kitchen changes printing via queue for order {$orderId}");

            $order = Order::with(['user', 'customer', 'driver', 'table'])->findOrFail($orderId);
            $preparedItems = $this->prepareKitchenChangesItems($items);

            if (empty($preparedItems)) {
                throw new Exception('لا توجد تغييرات للطباعة');
            }

            $productIds = collect($preparedItems)->pluck('product_id')->unique()->values()->toArray();
            $products = Product::with('printers:id')->whereIn('id', $productIds)->get(['id']);

            $itemsByPrinterMap = [];
            foreach ($preparedItems as $item) {
                $product = $products->firstWhere('id', $item['product_id']);
                if ($product && $product->printers->isNotEmpty()) {
                    foreach ($product->printers as $printer) {
                        if (! isset($itemsByPrinterMap[$printer->id])) {
                            $itemsByPrinterMap[$printer->id] = [];
                        }
                        $itemsByPrinterMap[$printer->id][] = $item;
                    }
                }
            }

            foreach ($itemsByPrinterMap as $printerId => $printerItems) {
                PrintKitchenOrder::dispatch($order, $printerItems, $printerId);
            }

            if (empty($itemsByPrinterMap)) {
                Log::warning("No printers found for order {$orderId} changes items");
                throw new Exception('لا توجد طابعات مخصصة للمنتجات المحددة');
            }

            Log::info("Kitchen changes printing jobs dispatched successfully for order {$orderId}");

        } catch (Exception $e) {
            Log::error("Error dispatching kitchen changes printing jobs for order {$orderId}: ".$e->getMessage());
            throw $e;
        }
    }

    private function prepareKitchenChangesItems(array $items): array
    {
        $preparedItems = [];

        foreach ($items as $item) {
            if (! isset($item['product_id']) || ! isset($item['new_quantity'])) {
                Log::warning('Invalid changes item data: missing product_id or new_quantity', $item);

                continue;
            }

            $preparedItem = [
                'product_id' => (int) $item['product_id'],
                'old_quantity' => (int) ($item['old_quantity'] ?? 0),
                'new_quantity' => (int) $item['new_quantity'],
                'delta' => (int) ($item['delta'] ?? ($item['new_quantity'] - ($item['old_quantity'] ?? 0))),
                'notes' => $item['notes'] ?? null,
            ];

            if (isset($item['name'])) {
                $preparedItem['name'] = $item['name'];
            } else {
                $product = Product::find($item['product_id']);
                $preparedItem['name'] = $product ? $product->name : "المنتج رقم {$item['product_id']}";
            }

            $preparedItems[] = $preparedItem;
        }

        return $preparedItems;
    }

    public function printKitchenChangesProcess(Order $order, array $orderItems, int $printerId): void
    {
        $this->printToKitchenPrinter(
            $printerId,
            'kitchen_changes',
            fn (): string => $this->generateKitchenChangesHtml($order, $orderItems),
            'kitchen changes',
        );
    }

    private function generateKitchenChangesHtml(Order $order, array $orderItems): string
    {
        return view('print.kitchen-changes-template', [
            'order' => $order,
            'orderItems' => $orderItems,
        ])->render();
    }

    /**
     * Get queue and strategy status information
     */
    public function getStatusInfo(): array
    {
        return [
            'using_queue' => self::USE_QUEUE,
            'current_strategy' => $this->printStrategy->getName(),
            'strategy_available' => $this->printStrategy->isAvailable(),
            'available_strategies' => array_keys($this->getAvailableStrategies()),
        ];
    }
}
