<?php

use App\Enums\SettingKey;
use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $this->setVisible(true);
    }

    public function down(): void
    {
        $this->setVisible(false);
    }

    private function setVisible(bool $visible): void
    {
        $setting = Setting::query()->where('key', SettingKey::CASHIER_TABS->value)->first();

        if ($setting === null) {
            return;
        }

        $entries = json_decode((string) $setting->value, true);

        if (! is_array($entries)) {
            return;
        }

        foreach ($entries as $index => $entry) {
            if (! is_array($entry)) {
                continue;
            }

            if (in_array($entry['key'] ?? null, ['type:companies', 'system:receivables'], true)) {
                $entries[$index]['visible'] = $visible;
            }
        }

        $setting->value = json_encode($entries, JSON_UNESCAPED_UNICODE);
        $setting->save();
    }
};
