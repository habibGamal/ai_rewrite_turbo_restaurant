<?php

namespace Tests\Unit;

use App\Providers\OrderServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Register the OrderServiceProvider for unit tests
        $this->app->register(OrderServiceProvider::class);

        // Run migrations
        $this->artisan('migrate');
    }
}
