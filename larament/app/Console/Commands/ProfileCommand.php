<?php

namespace App\Console\Commands;

use App\Exceptions\BusinessTypeLockedException;
use App\Modules\BusinessType;
use App\Services\FeatureService;
use App\Services\Setup\BusinessProfileService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Fallback for the setup wizard: sets the business type of the install,
 * switches its modules to that type's defaults, applies the type's starting
 * data (active order types, a table type for restaurants) and marks setup
 * as done. Refuses once an order exists. Customers never see this.
 */
class ProfileCommand extends Command
{
    protected $signature = 'pos:profile
        {type? : One of restaurant, cafe, bakery, fashion, grocery, general_retail}
        {--keep-modules : Change the type but leave the enabled modules as they are}';

    protected $description = 'Show or set the business type (locked after the first order)';

    public function handle(FeatureService $features, BusinessProfileService $profile): int
    {
        $value = $this->argument('type');

        if ($value === null) {
            $this->line('Business type: '.$features->businessType()->value.($features->businessTypeLocked() ? ' (locked)' : ''));
            $this->line('Modules: '.implode(', ', array_map(fn ($module) => $module->value, $features->enabledModules())));

            return self::SUCCESS;
        }

        $type = BusinessType::tryFrom((string) $value);
        if ($type === null) {
            $this->error('Unknown business type. Use one of: '.implode(', ', array_column(BusinessType::cases(), 'value')));

            return self::FAILURE;
        }

        try {
            $profile->setUp($type, $this->option('keep-modules') ? $features->enabledModules() : null);
        } catch (BusinessTypeLockedException $e) {
            $this->error('The business type is locked: this install already has orders.');

            return self::FAILURE;
        } catch (ValidationException $e) {
            $this->error(collect($e->errors())->flatten()->implode(' '));

            return self::FAILURE;
        }

        $this->info("Business type set to {$type->value}.");
        $this->line('Modules: '.implode(', ', array_map(fn ($module) => $module->value, $features->enabledModules())));

        return self::SUCCESS;
    }
}
