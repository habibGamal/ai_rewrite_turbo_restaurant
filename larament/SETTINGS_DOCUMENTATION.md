# Settings Management System

This Laravel application includes a comprehensive settings management system that allows you to store and manage application configuration values in the database.

## Components

### 1. Setting Model (`App\Models\Setting`)
The Setting model represents a key-value pair stored in the database.

**Fields:**
- `key`: Unique string identifier for the setting
- `value`: The setting value (stored as text)

**Static Methods:**
- `getValue(string $key, $default = null)`: Get a setting value by key
- `setValue(string $key, $value)`: Set a setting value
- `hasKey(string $key)`: Check if a setting exists
- `getAllAsArray()`: Get all settings as an associative array

### 2. SettingsService (`App\Services\SettingsService`)
Service class that provides a higher-level interface for managing settings with caching and validation.

**Constants:**
- `WEBSITE_URL`: Key for website URL setting
- `CASHIER_PRINTER_IP`: Key for cashier printer IP setting

**Methods:**
- `get(string $key, $default = null)`: Get setting with caching
- `set(string $key, $value)`: Set setting and clear cache
- `setMultiple(array $settings)`: Set multiple settings at once
- `all()`: Get all settings with caching
- `getDefaults()`: Get default values for all settings
- `validate(string $key, $value)`: Validate a setting value
- `getLabel(string $key)`: Get Arabic label for a setting
- `getDescription(string $key)`: Get Arabic description for a setting

### 3. Filament Settings Page (`App\Filament\Pages\Settings`)
Admin interface for managing settings through Filament.

**Features:**
- Form-based interface for editing settings
- Arabic language support
- Input validation
- Save and reset to defaults functionality
- Success/error notifications

### 4. Artisan Command (`App\Console\Commands\SettingsCommand`)
CLI interface for managing settings.

**Usage:**
```bash
# Get a setting value
php artisan settings get website_url

# Set a setting value
php artisan settings set website_url "https://example.com"

# List all settings
php artisan settings list

# Reset all settings to defaults
php artisan settings reset
```

## Current Settings

### Website URL (`website_url`)
- **Default**: `http://127.0.0.1:38794`
- **Type**: URL
- **Description**: The main URL for the website
- **Validation**: Must be a valid URL

### Cashier Printer IP (`cashier_printer_ip`)
- **Default**: `192.168.1.100`
- **Type**: IP Address
- **Description**: IP address for the cashier printer to print receipts
- **Validation**: Must be a valid IP address

## Database Structure

The settings are stored in the `settings` table:

```sql
CREATE TABLE settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    key VARCHAR(255) UNIQUE NOT NULL,
    value TEXT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
);
```

## Caching

Settings are cached for 1 hour (3600 seconds) to improve performance. The cache is automatically cleared when settings are updated.

Cache keys follow the pattern: `settings.{key}` for individual settings and `settings.all` for all settings.

## Usage Examples

### In Controllers/Services
```php
use App\Services\SettingsService;

class SomeController extends Controller
{
    public function __construct(private SettingsService $settingsService)
    {
    }

    public function index()
    {
        $websiteUrl = $this->settingsService->getWebsiteLink();
        $printerIp = $this->settingsService->getCashierPrinterIp();
        
        // Or get any setting
        $customSetting = $this->settingsService->get('custom_key', 'default_value');
    }
}
```

### Direct Model Usage
```php
use App\Models\Setting;

// Get a setting
$value = Setting::getValue('website_url', 'default');

// Set a setting
Setting::setValue('website_url', 'https://example.com');

// Check if exists
if (Setting::hasKey('some_key')) {
    // Setting exists
}
```

## Testing

Run the settings tests:

```bash
php artisan test --filter SettingsTest
```

## Seeding

Default settings are seeded using the `SettingsSeeder`:

```bash
php artisan db:seed --class=SettingsSeeder
```

## Adding New Settings

To add a new setting:

1. Add a constant to `SettingsService`:
```php
public const NEW_SETTING = 'new_setting';
```

2. Add to the `getDefaults()` method:
```php
public function getDefaults(): array
{
    return [
        // ... existing settings
        self::NEW_SETTING => 'default_value',
    ];
}
```

3. Add labels and descriptions:
```php
public function getLabel(string $key): string
{
    return match ($key) {
        // ... existing cases
        self::NEW_SETTING => __('New Setting Label'),
        default => $key,
    };
}
```

4. Add validation if needed:
```php
public function validate(string $key, mixed $value): bool
{
    return match ($key) {
        // ... existing cases
        self::NEW_SETTING => /* validation logic */,
        default => true,
    };
}
```

5. Add to Filament form schema in `Settings.php`
6. Add translations to `lang/ar.json`
