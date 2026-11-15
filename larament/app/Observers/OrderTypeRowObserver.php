<?php

namespace App\Observers;

use App\Models\OrderTypeRow;
use App\Orders\TabPermissions;

class OrderTypeRowObserver
{
    public function __construct(private readonly TabPermissions $tabPermissions) {}

    public function created(OrderTypeRow $row): void
    {
        $this->tabPermissions->ensureForType($row);
    }

    public function updated(OrderTypeRow $row): void
    {
        if ($row->wasChanged('code')) {
            $this->tabPermissions->renameForType((string) $row->getOriginal('code'), $row);
        }
    }

    public function deleted(OrderTypeRow $row): void
    {
        $this->tabPermissions->removeForType($row);
    }
}
