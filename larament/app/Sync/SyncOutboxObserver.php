<?php

namespace App\Sync;

use App\Enums\OrderStatus;
use App\Models\Category;
use App\Models\ExpenceType;
use App\Models\Expense;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Models\Payment;
use App\Models\PlatformConnection;
use App\Models\Product;
use App\Models\ProductComponent;
use App\Models\PurchaseInvoice;
use App\Models\ReturnPurchaseInvoice;
use App\Models\Shift;
use App\Models\Stocktaking;
use App\Models\Supplier;
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
            Expense::class => Outbox::record(DocumentType::Expense, $this->getModelUuid($model)),
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
            User::class => Outbox::record(DocumentType::Staff, $this->getModelUuid($model)),
            ProductComponent::class => $this->handleProductComponentSaved($model),
            default => null,
        };
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
            User::class => Outbox::record(DocumentType::Staff, $this->getModelUuid($model), 'delete'),
            ProductComponent::class => $this->handleProductComponentDeleted($model),
            default => null,
        };
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
}
