<?php

namespace App\Sync;

use App\Enums\OrderStatus;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\ExpenceType;
use App\Models\Expense;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Models\Payment;
use App\Models\PlatformConnection;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductComponent;
use App\Models\ProductGroup;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Models\PurchaseInvoice;
use App\Models\ReturnPurchaseInvoice;
use App\Models\Revenue;
use App\Models\RevenueType;
use App\Models\Shift;
use App\Models\Stocktaking;
use App\Models\Supplier;
use App\Models\TerminalSession;
use App\Models\User;
use App\Models\Waste;
use Illuminate\Database\Eloquent\Model;

class SyncOutboxObserver
{
    /**
     * Handle the Model "created" event.
     */
    public function created(Model $model): void
    {
        if (! PlatformConnection::active()) {
            return;
        }

        if ($model instanceof AuditLog) {
            Outbox::record(DocumentType::AuditLog, $this->getModelUuid($model));

            return;
        }

        if ($model instanceof OrderReturn) {
            Outbox::record(DocumentType::OrderReturn, $this->getModelUuid($model));

            $order = $model->order;
            if (! $order instanceof Order && $model->order_id) {
                $order = Order::find($model->order_id);
            }
            if ($order instanceof Order) {
                Outbox::record(DocumentType::Order, $this->getModelUuid($order));
            }
        }
    }

    /**
     * Handle the Model "saved" event.
     */
    public function saved(Model $model): void
    {
        if (! PlatformConnection::active()) {
            return;
        }

        match ($model::class) {
            Order::class => $this->handleOrderSaved($model),
            OrderItem::class, Payment::class => $this->recordParentOrderIfFinal($model),
            Shift::class => Outbox::record(DocumentType::Shift, $this->getModelUuid($model)),
            // A drawer opening, closing or changing hands re-sends its shift.
            TerminalSession::class => $this->recordShiftOf($model),
            Expense::class => Outbox::record(DocumentType::Expense, $this->getModelUuid($model)),
            Revenue::class => Outbox::record(DocumentType::Revenue, $this->getModelUuid($model)),
            PurchaseInvoice::class => $model->closed_at !== null
                ? Outbox::record(DocumentType::PurchaseInvoice, $this->getModelUuid($model))
                : null,
            ReturnPurchaseInvoice::class => $model->closed_at !== null
                ? Outbox::record(DocumentType::PurchaseReturn, $this->getModelUuid($model))
                : null,
            Waste::class => $model->closed_at !== null
                ? Outbox::record(DocumentType::Waste, $this->getModelUuid($model))
                : null,
            Stocktaking::class => $model->closed_at !== null
                ? Outbox::record(DocumentType::Stocktaking, $this->getModelUuid($model))
                : null,
            Product::class => Outbox::record(DocumentType::Product, $this->getModelUuid($model)),
            Category::class => Outbox::record(DocumentType::Category, $this->getModelUuid($model)),
            Supplier::class => Outbox::record(DocumentType::Supplier, $this->getModelUuid($model)),
            ExpenceType::class => Outbox::record(DocumentType::ExpenseType, $this->getModelUuid($model)),
            RevenueType::class => Outbox::record(DocumentType::RevenueType, $this->getModelUuid($model)),
            // The vendor account never leaves the install.
            User::class => $model->is_vendor ? null : Outbox::record(DocumentType::Staff, $this->getModelUuid($model)),
            ProductComponent::class => $this->handleProductComponentSaved($model),
            ProductGroup::class => Outbox::record(DocumentType::ProductGroup, $this->getModelUuid($model)),
            ProductOption::class, ProductOptionValue::class => $this->recordParentGroup($model),
            ProductBarcode::class => $this->recordBarcodeProduct($model),
            default => null,
        };
    }

    /**
     * Handle the Model "deleting" event.
     *
     * Deleting a group nulls products.product_group_id in the database (no
     * model events), so its variants are recorded here, before the delete,
     * and are rebuilt at send time without the group reference.
     */
    public function deleting(Model $model): void
    {
        if (! PlatformConnection::active()) {
            return;
        }

        if ($model instanceof ProductGroup) {
            Product::query()
                ->where('product_group_id', $model->id)
                ->whereNotNull('uuid')
                ->pluck('uuid')
                ->each(fn ($uuid) => Outbox::record(DocumentType::Product, (string) $uuid));
        }
    }

    /**
     * Handle the Model "deleted" event.
     */
    public function deleted(Model $model): void
    {
        if (! PlatformConnection::active()) {
            return;
        }

        match ($model::class) {
            Order::class => $this->handleOrderDeleted($model),
            OrderItem::class, Payment::class => $this->recordParentOrderIfFinal($model),
            Expense::class => Outbox::record(DocumentType::Expense, $this->getModelUuid($model), 'delete'),
            Revenue::class => Outbox::record(DocumentType::Revenue, $this->getModelUuid($model), 'delete'),
            PurchaseInvoice::class => $model->closed_at !== null
                ? Outbox::record(DocumentType::PurchaseInvoice, $this->getModelUuid($model), 'delete')
                : null,
            ReturnPurchaseInvoice::class => $model->closed_at !== null
                ? Outbox::record(DocumentType::PurchaseReturn, $this->getModelUuid($model), 'delete')
                : null,
            Waste::class => $model->closed_at !== null
                ? Outbox::record(DocumentType::Waste, $this->getModelUuid($model), 'delete')
                : null,
            Stocktaking::class => $model->closed_at !== null
                ? Outbox::record(DocumentType::Stocktaking, $this->getModelUuid($model), 'delete')
                : null,
            Product::class => Outbox::record(DocumentType::Product, $this->getModelUuid($model), 'delete'),
            Category::class => Outbox::record(DocumentType::Category, $this->getModelUuid($model), 'delete'),
            Supplier::class => Outbox::record(DocumentType::Supplier, $this->getModelUuid($model), 'delete'),
            ExpenceType::class => Outbox::record(DocumentType::ExpenseType, $this->getModelUuid($model), 'delete'),
            RevenueType::class => Outbox::record(DocumentType::RevenueType, $this->getModelUuid($model), 'delete'),
            User::class => $model->is_vendor ? null : Outbox::record(DocumentType::Staff, $this->getModelUuid($model), 'delete'),
            ProductComponent::class => $this->handleProductComponentDeleted($model),
            ProductGroup::class => Outbox::record(DocumentType::ProductGroup, $this->getModelUuid($model), 'delete'),
            ProductOption::class, ProductOptionValue::class => $this->recordParentGroup($model),
            ProductBarcode::class => $this->recordBarcodeProduct($model),
            default => null,
        };
    }

    /**
     * An option or option value changed: the group document carries the full
     * option tree, so re-send the group.
     */
    protected function recordParentGroup(ProductOption|ProductOptionValue $model): void
    {
        $groupId = $model instanceof ProductOption
            ? $model->product_group_id
            : ProductOption::query()->whereKey($model->product_option_id)->value('product_group_id');

        if ($groupId === null) {
            return;
        }

        $uuid = ProductGroup::query()->whereKey($groupId)->value('uuid');
        if (is_string($uuid)) {
            Outbox::record(DocumentType::ProductGroup, $uuid);
        }
    }

    /**
     * A barcode changed: the product document carries the barcode list.
     * Skipped when the product itself is already gone.
     */
    protected function recordBarcodeProduct(ProductBarcode $barcode): void
    {
        $uuid = Product::query()->whereKey($barcode->product_id)->value('uuid');
        if (is_string($uuid)) {
            Outbox::record(DocumentType::Product, $uuid);
        }
    }

    protected function handleOrderSaved(Order $order): void
    {
        if ($this->isOrderFinal($order)) {
            Outbox::record(DocumentType::Order, $this->getModelUuid($order));
        }
    }

    protected function handleOrderDeleted(Order $order): void
    {
        if ($this->isOrderFinal($order)) {
            Outbox::record(DocumentType::Order, $this->getModelUuid($order), 'delete');
        }
    }

    protected function handleProductComponentSaved(ProductComponent $component): void
    {
        $productUuid = $this->getParentProductUuid($component);
        if ($productUuid !== null) {
            Outbox::record(DocumentType::Recipe, $productUuid);
        }
    }

    protected function handleProductComponentDeleted(ProductComponent $component): void
    {
        $productUuid = $this->getParentProductUuid($component);
        if ($productUuid !== null) {
            Outbox::record(DocumentType::Recipe, $productUuid);
        }
    }

    protected function getParentProductUuid(ProductComponent $component): ?string
    {
        if ($component->product) {
            return $this->getModelUuid($component->product);
        }

        $uuid = Product::where('id', $component->product_id)->value('uuid');

        return is_string($uuid) ? $uuid : null;
    }

    protected function recordParentOrderIfFinal(Model $itemOrPayment): void
    {
        $order = $itemOrPayment->getAttribute('order');
        if (! $order instanceof Order) {
            $orderId = $itemOrPayment->getAttribute('order_id');
            $order = is_numeric($orderId) ? Order::find($orderId) : null;
        }

        if ($order instanceof Order && $this->isOrderFinal($order)) {
            Outbox::record(DocumentType::Order, $this->getModelUuid($order));
        }
    }

    protected function isOrderFinal(Order $order): bool
    {
        return in_array($order->status, [OrderStatus::COMPLETED, OrderStatus::CANCELLED], true);
    }

    protected function getModelUuid(Model $model): string
    {
        return (string) $model->getAttribute('uuid');
    }

    private function recordShiftOf(Model $session): void
    {
        $uuid = Shift::query()->whereKey($session->getAttribute('shift_id'))->value('uuid');

        if (is_string($uuid)) {
            Outbox::record(DocumentType::Shift, $uuid);
        }
    }
}
