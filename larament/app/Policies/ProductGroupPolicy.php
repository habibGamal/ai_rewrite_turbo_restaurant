<?php

namespace App\Policies;

/**
 * Retail product groups (الموديلات) share the product permissions: whoever
 * may manage products may manage the groups their SKUs belong to.
 */
class ProductGroupPolicy extends BasePolicy
{
    protected string $resource = 'products';
}
