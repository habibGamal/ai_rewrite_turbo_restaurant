<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use Laravel\Mcp\Server\Testing\TestResponse;

// Shared by the MCP test files (loaded with require_once).

if (! function_exists('mcpJson')) {
    /** The JSON a tool returned, decoded. */
    function mcpJson(TestResponse $response): array
    {
        $raw = (fn () => $this->response->toArray())->call($response);

        return json_decode($raw['result']['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
    }

    /** A completed order with no extras; $extra overrides any column. */
    function mcpOrder(string $type, float $total, string $at, array $extra = []): Order
    {
        return Order::factory()->create(array_merge([
            'type' => $type,
            'status' => OrderStatus::COMPLETED,
            'sub_total' => $total,
            'service' => 0,
            'tax' => 0,
            'discount' => 0,
            'fees_total' => 0,
            'equipment_total' => 0,
            'total' => $total,
            'profit' => $total / 2,
            'created_at' => $at,
        ], $extra));
    }
}
