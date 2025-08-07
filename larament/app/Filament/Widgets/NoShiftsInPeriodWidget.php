<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;

class NoShiftsInPeriodWidget extends Widget
{
    protected static string $view = 'filament.widgets.no-shifts-in-period';

    protected int|string|array $columnSpan = 'full';
}
