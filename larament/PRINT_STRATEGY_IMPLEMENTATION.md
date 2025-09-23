# Print Strategy Pattern Implementation Documentation

## Overview

This document provides comprehensive documentation for the implementation of the Strategy design pattern in the PrintService to support multiple printing technologies. The implementation allows switching between different HTML-to-image conversion tools (Browsershot and wkhtmltoimage) and supports both queued and direct printing.

## Table of Contents

1. [Architecture Overview](#architecture-overview)
2. [File Structure](#file-structure)
3. [Interface and Strategy Implementations](#interface-and-strategy-implementations)
4. [PrintService Modifications](#printservice-modifications)
5. [Factory Pattern Implementation](#factory-pattern-implementation)
6. [Queue vs Direct Printing](#queue-vs-direct-printing)
7. [Usage Examples](#usage-examples)
8. [Configuration and Setup](#configuration-and-setup)
9. [Error Handling and Logging](#error-handling-and-logging)
10. [Benefits and Advantages](#benefits-and-advantages)

---

## Architecture Overview

The implementation follows the Strategy design pattern with these key components:

```
PrintService
    ├── PrintStrategyInterface (Contract)
    ├── BrowsershotPrintStrategy (Implementation)
    ├── WkhtmltoimagePrintStrategy (Implementation)
    └── PrintStrategyFactory (Factory)
```

### Design Principles Applied:
- **Strategy Pattern**: Encapsulates different printing algorithms
- **Factory Pattern**: Creates appropriate strategy instances
- **Dependency Injection**: PrintService uses strategies through interface
- **Open/Closed Principle**: Easy to add new strategies without modifying existing code

---

## File Structure

### New Files Created:

```
app/Services/PrintStrategies/
├── PrintStrategyInterface.php          # Strategy interface contract
├── BrowsershotPrintStrategy.php        # Browsershot implementation
├── WkhtmltoimagePrintStrategy.php      # wkhtmltoimage implementation
└── PrintStrategyFactory.php           # Factory for strategy creation
```

### Modified Files:

```
app/Services/PrintService.php           # Updated to use strategy pattern
```

---

## Interface and Strategy Implementations

### PrintStrategyInterface

**Location**: `app/Services/PrintStrategies/PrintStrategyInterface.php`

```php
interface PrintStrategyInterface
{
    /**
     * Convert HTML content to image
     */
    public function generateImageFromHtml(string $html, int $width = 572, int $height = 1200): string;

    /**
     * Check if the strategy's dependencies are available
     */
    public function isAvailable(): bool;

    /**
     * Get the strategy name
     */
    public function getName(): string;
}
```

**Purpose**: Defines the contract that all printing strategies must implement.

### BrowsershotPrintStrategy

**Location**: `app/Services/PrintStrategies/BrowsershotPrintStrategy.php`

**Key Features**:
- Uses Spatie\Browsershot package
- Requires Chrome/Chromium browser
- Supports remote Chrome instance (127.0.0.1:9222)
- Advanced availability checking

**Dependencies**:
- `/usr/bin/chromium-browser` executable
- Remote Chrome instance on port 9222
- Puppeteer environment setup

**Configuration Options**:
```php
Browsershot::html($html)
    ->windowSize($width, $height)
    ->setOption('executablePath', '/usr/bin/chromium-browser')
    ->setEnvironmentOptions([
        'XDG_CONFIG_HOME' => base_path('.puppeteer'),
        'HOME' => base_path('.puppeteer')
    ])
    ->setRemoteInstance('127.0.0.1', 9222)
    ->dismissDialogs()
    ->ignoreHttpsErrors()
    ->fullPage()
    ->save($tempImagePath);
```

### WkhtmltoimagePrintStrategy

**Location**: `app/Services/PrintStrategies/WkhtmltoimagePrintStrategy.php`

**Key Features**:
- Uses wkhtmltoimage binary
- Cross-platform support (Windows/Linux)
- No browser dependencies
- Lightweight and fast

**Platform-specific Paths**:
- **Windows**: `C:\Program Files\wkhtmltopdf\bin\wkhtmltoimage.exe`
- **Linux/macOS**: `/usr/local/bin/wkhtmltoimage`

**Command Line Options**:
```bash
wkhtmltoimage \
    --width 572 \
    --height 1200 \
    --format png \
    --quality 100 \
    --encoding UTF-8 \
    --disable-smart-width \
    --javascript-delay 1000 \
    --load-error-handling ignore \
    --load-media-error-handling ignore \
    input.html output.png
```

---

## PrintService Modifications

### New Constants and Properties

```php
class PrintService
{
    private const USE_QUEUE = false;  // Controls queue vs direct printing
    private PrintStrategyInterface $printStrategy;
}
```

### Strategy Management Methods

| Method | Purpose |
|--------|---------|
| `createPrintStrategy()` | Creates initial strategy (private) |
| `setPrintStrategy(PrintStrategyInterface $strategy)` | Sets strategy by instance |
| `setPrintStrategyByName(string $name)` | Sets strategy by name |
| `getPrintStrategy()` | Gets current strategy |
| `getAvailableStrategies()` | Lists available strategies |
| `isStrategyAvailable(string $name)` | Checks if strategy is available |

### Queue Control Methods

| Method | Purpose |
|--------|---------|
| `isUsingQueue()` | Returns current queue setting |
| `getStatusInfo()` | Returns comprehensive status |

### Updated Print Methods

#### `printOrderReceipt(Order $order, array $images)`
**Before**:
```php
public function printOrderReceipt(Order $order, array $images): void
{
    PrintOrderReceipt::dispatch($order);
}
```

**After**:
```php
public function printOrderReceipt(Order $order, array $images): void
{
    if (self::USE_QUEUE) {
        \Log::info("Dispatching order receipt to queue for order {$order->id}");
        PrintOrderReceipt::dispatch($order);
    } else {
        \Log::info("Printing order receipt directly for order {$order->id}");
        $this->printOrderProcess($order);
    }
}
```

#### `printKitchenReceipt($orderId, $items)`
**Before**:
```php
public function printKitchenReceipt($orderId, $items): void
{
    $this->printKitchenQueued($orderId, $items);
}
```

**After**:
```php
public function printKitchenReceipt($orderId, $items): void
{
    if (self::USE_QUEUE) {
        \Log::info("Dispatching kitchen receipt to queue for order {$orderId}");
        $this->printKitchenQueued($orderId, $items);
    } else {
        \Log::info("Printing kitchen receipt directly for order {$orderId}");
        $this->printKitchenDirect($orderId, $items);
    }
}
```

### New Direct Printing Method

#### `printKitchenDirect($orderId, $items)`
**Purpose**: Synchronous kitchen printing without queues
**Process**:
1. Load order with relationships
2. Prepare and validate items
3. Map items to printers
4. Print directly to each printer using current strategy

---

## Factory Pattern Implementation

### PrintStrategyFactory

**Location**: `app/Services/PrintStrategies/PrintStrategyFactory.php`

**Methods**:

| Method | Purpose | Usage |
|--------|---------|-------|
| `create(string $name)` | Create strategy by name | `PrintStrategyFactory::create('wkhtmltoimage')` |
| `createBestAvailable()` | Auto-select best strategy | `PrintStrategyFactory::createBestAvailable()` |
| `getAvailableStrategies()` | Get all available strategies | Returns array of working strategies |
| `getAllStrategyNames()` | Get all strategy names | Returns `['browsershot', 'wkhtmltoimage']` |

**Strategy Priority**:
1. **wkhtmltoimage** (preferred - lightweight, no browser dependency)
2. **Browsershot** (fallback - requires Chrome/Chromium)

---

## Queue vs Direct Printing

### Configuration

The `USE_QUEUE` constant in PrintService controls the printing mode:

```php
private const USE_QUEUE = false;  // Set to true for queue mode
```

### Behavior Comparison

| Aspect | Queue Mode (`USE_QUEUE = true`) | Direct Mode (`USE_QUEUE = false`) |
|--------|--------------------------------|----------------------------------|
| **Order Receipts** | Dispatched to `PrintOrderReceipt` job | Printed immediately via `printOrderProcess()` |
| **Kitchen Receipts** | Dispatched to `PrintKitchenOrder` jobs | Printed immediately via `printKitchenDirect()` |
| **Performance** | Non-blocking, asynchronous | Blocking, synchronous |
| **Error Handling** | Job retry mechanisms | Immediate error feedback |
| **Use Case** | High-volume restaurants | Low-volume or testing |

### Queue Job Integration

**PrintOrderReceipt Job**:
- Calls `printService->printOrderProcess($order)`
- Uses current strategy automatically

**PrintKitchenOrder Job**:
- Calls `printService->printKitchenProcess($order, $items, $printerId)`
- Uses current strategy automatically

---

## Usage Examples

### Basic Usage

```php
// Create service instance
$printService = new PrintService();

// Print order receipt (respects USE_QUEUE setting)
$printService->printOrderReceipt($order, []);

// Print kitchen receipt (respects USE_QUEUE setting)
$printService->printKitchenReceipt($orderId, $items);
```

### Strategy Management

```php
// Check current strategy
$currentStrategy = $printService->getPrintStrategy();
echo "Current: " . $currentStrategy->getName(); // "wkhtmltoimage"

// Switch to Browsershot
$printService->setPrintStrategyByName('browsershot');

// Switch using factory
$strategy = PrintStrategyFactory::create('wkhtmltoimage');
$printService->setPrintStrategy($strategy);

// Check availability
if ($printService->isStrategyAvailable('wkhtmltoimage')) {
    $printService->setPrintStrategyByName('wkhtmltoimage');
}
```

### Status Information

```php
$status = $printService->getStatusInfo();
/*
Returns:
[
    'using_queue' => false,
    'current_strategy' => 'wkhtmltoimage',
    'strategy_available' => true,
    'available_strategies' => ['wkhtmltoimage', 'browsershot']
]
*/
```

### Factory Usage

```php
// Get best available strategy
$bestStrategy = PrintStrategyFactory::createBestAvailable();

// Get all available strategies
$available = PrintStrategyFactory::getAvailableStrategies();

// Create specific strategy
try {
    $strategy = PrintStrategyFactory::create('wkhtmltoimage');
} catch (\InvalidArgumentException $e) {
    // Handle unknown strategy
}
```

---

## Configuration and Setup

### wkhtmltoimage Setup

#### Windows Installation:
1. Download wkhtmltopdf from: https://wkhtmltopdf.org/downloads.html
2. Install to default location: `C:\Program Files\wkhtmltopdf\`
3. Ensure `wkhtmltoimage.exe` is at: `C:\Program Files\wkhtmltopdf\bin\wkhtmltoimage.exe`

#### Linux Installation:
```bash
# Ubuntu/Debian
sudo apt-get install wkhtmltopdf

# CentOS/RHEL
sudo yum install wkhtmltopdf

# Manual installation
wget https://github.com/wkhtmltopdf/packaging/releases/download/0.12.6-1/wkhtmltox_0.12.6-1.focal_amd64.deb
sudo dpkg -i wkhtmltox_0.12.6-1.focal_amd64.deb
```

### Browsershot Setup

#### Requirements:
1. **Chrome/Chromium**: Install browser
2. **Remote Instance**: Start Chrome in headless mode
3. **Puppeteer**: Set up environment

#### Starting Remote Chrome:
```bash
# Linux
chromium-browser --headless --disable-gpu --remote-debugging-port=9222

# Or using Docker
docker run -d -p 9222:9222 --shm-size 1gb --name chrome \
  zenika/alpine-chrome --no-sandbox --remote-debugging-address=0.0.0.0 \
  --remote-debugging-port=9222
```

### Environment Configuration

Create `.puppeteer` directory in project root:
```bash
mkdir -p .puppeteer
chmod 755 .puppeteer
```

---

## Error Handling and Logging

### Strategy-Level Error Handling

Each strategy handles its own errors and logs appropriately:

```php
// BrowsershotPrintStrategy
try {
    Browsershot::html($html)->save($tempImagePath);
    return $tempImagePath;
} catch (\Exception $e) {
    Log::error("Error generating image with Browsershot: " . $e->getMessage());
    throw $e;
}

// WkhtmltoimagePrintStrategy
if (!$result->successful()) {
    $error = $result->errorOutput() ?: $result->output();
    Log::error("wkhtmltoimage command failed: " . $error);
    throw new \Exception("wkhtmltoimage failed: " . $error);
}
```

### PrintService Error Handling

```php
try {
    $tempImagePath = $this->printStrategy->generateImageFromHtml($html, 572, 100);
    // ... printing logic
} catch (\Exception $e) {
    \Log::error("Error printing kitchen order to printer {$printerId}: " . $e->getMessage());
    throw $e;
}
```

### Logging Levels

| Level | Usage |
|-------|-------|
| `Log::info()` | Strategy selection, successful operations |
| `Log::warning()` | Availability issues, missing configurations |
| `Log::error()` | Printing failures, strategy errors |

---

## Benefits and Advantages

### 1. **Flexibility**
- Easy switching between printing technologies
- Runtime strategy changes
- Environment-specific strategy selection

### 2. **Maintainability**
- Clean separation of concerns
- Each strategy handles its own logic
- Easy to add new printing methods

### 3. **Reliability**
- Automatic fallback to available strategies
- Proper error handling and logging
- Availability checking prevents runtime failures

### 4. **Performance Options**
- Queue mode for high-volume scenarios
- Direct mode for immediate feedback
- Strategy-specific optimizations

### 5. **Cross-Platform Support**
- Windows and Linux compatibility
- Platform-specific executable detection
- Graceful degradation

### 6. **Extensibility**
- Easy to add new strategies (e.g., Puppeteer, PhantomJS)
- Interface-based design allows different implementations
- Factory pattern simplifies strategy management

---

## Future Enhancements

### Potential New Strategies
1. **PuppeteerPrintStrategy**: Direct Puppeteer integration
2. **PhantomJSPrintStrategy**: PhantomJS-based rendering
3. **ImageMagickPrintStrategy**: ImageMagick convert
4. **PDFtoPNGStrategy**: PDF intermediate format

### Configuration Improvements
1. **Environment-based strategy selection**
2. **Runtime strategy switching via API**
3. **Strategy-specific configuration files**
4. **Performance monitoring and metrics**

### Queue Enhancements
1. **Priority-based job queuing**
2. **Retry strategies per print method**
3. **Batch printing capabilities**
4. **Real-time printing status updates**

---

## Conclusion

The Strategy pattern implementation for PrintService provides a robust, flexible, and maintainable solution for handling multiple printing technologies. The design allows for easy extension, proper error handling, and both synchronous and asynchronous printing modes, making it suitable for various restaurant management scenarios.

The implementation successfully decouples the printing logic from the specific technology used, enabling the application to adapt to different environments and requirements without code changes to the core PrintService logic.