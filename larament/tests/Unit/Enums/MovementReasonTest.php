<?php

use App\Enums\MovementReason;

it('labels the new reasons in Arabic with colors icons and directions', function (string $value, string $label, bool $incoming) {
    $reason = MovementReason::from($value);
    expect($reason->getLabel())->toBe($label)
        ->and($reason->getColor())->not->toBeEmpty()
        ->and($reason->getIcon())->toStartWith('heroicon-o-')
        ->and($reason->isIncoming())->toBe($incoming)
        ->and($reason->isOutgoing())->toBe(! $incoming);
})->with([
    ['transfer_in', 'تحويل وارد', true],
    ['transfer_out', 'تحويل صادر', false],
    ['manufacturing_in', 'تصنيع وارد', true],
    ['manufacturing_out', 'تصنيع صادر', false],
    ['stock_issue', 'صرف', false],
]);

it('keeps the legacy transfer reason', function () {
    expect(MovementReason::from('transfer'))->toBe(MovementReason::TRANSFER);
});
