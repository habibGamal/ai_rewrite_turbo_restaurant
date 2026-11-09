<?php

use App\Enums\OrderType;

describe('OrderType Enum', function () {
    it('has correct values', function () {
        expect(OrderType::DINE_IN->value)->toBe('dine_in')
            ->and(OrderType::TAKEAWAY->value)->toBe('takeaway')
            ->and(OrderType::DELIVERY->value)->toBe('delivery')
            ->and(OrderType::COMPANIES->value)->toBe('companies')
            ->and(OrderType::TALABAT->value)->toBe('talabat');
    });

    it('returns correct labels', function () {
        expect(OrderType::DINE_IN->label())->toBe('صالة')
            ->and(OrderType::TAKEAWAY->label())->toBe('تيك أواي')
            ->and(OrderType::DELIVERY->label())->toBe('دليفري')
            ->and(OrderType::COMPANIES->label())->toBe('شركات')
            ->and(OrderType::TALABAT->label())->toBe('طلبات');
    });

    it('can be created from string value', function () {
        expect(OrderType::from('dine_in'))->toBe(OrderType::DINE_IN)
            ->and(OrderType::from('takeaway'))->toBe(OrderType::TAKEAWAY)
            ->and(OrderType::from('delivery'))->toBe(OrderType::DELIVERY);
    });
});
