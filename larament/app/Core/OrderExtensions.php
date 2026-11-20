<?php

namespace App\Core;

use App\Core\Contracts\OrderTypeChangeGuard;
use App\Core\Contracts\TenderSplitter;

/**
 * Extension points of the order flow that must return a value or run in a
 * fixed place, so they cannot be plain event listeners. Modules register
 * their implementations from their service provider; core calls them.
 */
final class OrderExtensions
{
    /** @var list<class-string<TenderSplitter>> */
    private array $tenderSplitters = [];

    /** @var list<class-string<OrderTypeChangeGuard>> */
    private array $typeChangeGuards = [];

    /**
     * @param  class-string<TenderSplitter>  $splitter
     */
    public function addTenderSplitter(string $splitter): void
    {
        if (! in_array($splitter, $this->tenderSplitters, true)) {
            $this->tenderSplitters[] = $splitter;
        }
    }

    /**
     * @param  class-string<OrderTypeChangeGuard>  $guard
     */
    public function addTypeChangeGuard(string $guard): void
    {
        if (! in_array($guard, $this->typeChangeGuards, true)) {
            $this->typeChangeGuards[] = $guard;
        }
    }

    /**
     * @return list<TenderSplitter>
     */
    public function tenderSplitters(): array
    {
        return array_map(fn (string $class): TenderSplitter => app($class), $this->tenderSplitters);
    }

    /**
     * @return list<OrderTypeChangeGuard>
     */
    public function typeChangeGuards(): array
    {
        return array_map(fn (string $class): OrderTypeChangeGuard => app($class), $this->typeChangeGuards);
    }
}
