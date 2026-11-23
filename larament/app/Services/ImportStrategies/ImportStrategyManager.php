<?php

namespace App\Services\ImportStrategies;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

class ImportStrategyManager
{
    /**
     * Registered strategies keyed by identifier.
     *
     * @var array<string, ImportStrategyInterface>
     */
    protected array $strategies = [];

    public function __construct()
    {
        $this->registerDefaultStrategies();
    }

    /**
     * Register core default strategies.
     */
    protected function registerDefaultStrategies(): void
    {
        $this->register(new ConsumableWarehouseImportStrategy);
        $this->register(new ComprehensiveKitchenImportStrategy);
    }

    /**
     * Register a new import strategy.
     */
    public function register(ImportStrategyInterface $strategy): static
    {
        $this->strategies[$strategy->getKey()] = $strategy;

        return $this;
    }

    /**
     * Get strategy by key.
     */
    public function getStrategy(string $key): ImportStrategyInterface
    {
        if (! isset($this->strategies[$key])) {
            throw new InvalidArgumentException("Unknown import strategy: {$key}");
        }

        return $this->strategies[$key];
    }

    /**
     * Automatically detect the appropriate strategy based on file contents.
     */
    public function detectStrategy(UploadedFile|string $file): ImportStrategyInterface
    {
        // Try each registered strategy to see if it supports the file
        foreach ($this->strategies as $strategy) {
            if ($strategy->supports($file)) {
                return $strategy;
            }
        }

        // Fallback to kitchen structure if none matches
        return $this->strategies['kitchen_structure'] ?? reset($this->strategies);
    }

    /**
     * Resolve strategy: if explicit key provided (and not 'auto'), use it; otherwise auto-detect.
     */
    public function resolveStrategy(?string $key, UploadedFile|string $file): ImportStrategyInterface
    {
        if (! empty($key) && $key !== 'auto') {
            return $this->getStrategy($key);
        }

        return $this->detectStrategy($file);
    }

    /**
     * Get all registered strategies.
     *
     * @return array<string, ImportStrategyInterface>
     */
    public function getAvailableStrategies(): array
    {
        return $this->strategies;
    }

    /**
     * Get dropdown options array for UI forms.
     *
     * @return array<string, string>
     */
    public function getStrategyOptions(): array
    {
        $options = [
            'auto' => 'كشف تلقائي حسب هيكل الملف (Auto Detect)',
        ];

        foreach ($this->strategies as $key => $strategy) {
            $options[$key] = $strategy->getLabel();
        }

        return $options;
    }
}
