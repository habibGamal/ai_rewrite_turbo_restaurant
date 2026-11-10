<?php

namespace App\Costing;

use App\Costing\Strategies\ImmediateCostingStrategy;
use App\Costing\Strategies\OffCostingStrategy;
use App\Costing\Strategies\WeightedAverageCostingStrategy;
use App\Services\SettingsService;

class CostingStrategyResolver
{
    public function __construct(private SettingsService $settings) {}

    public function resolve(): CostingStrategy
    {
        return app(match ($this->settings->getCostingMethod()) {
            CostingMethod::Off => OffCostingStrategy::class,
            CostingMethod::Immediate => ImmediateCostingStrategy::class,
            CostingMethod::WeightedAverage => WeightedAverageCostingStrategy::class,
        });
    }
}
