<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ($this->defaults() as $key => $value) {
            Setting::firstOrCreate(['key' => $key], ['value' => $value]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Setting::query()->whereIn('key', array_keys($this->defaults()))->delete();
    }

    /**
     * Literal keys and defaults as they were when this migration was written, so
     * later changes to the SettingKey enum cannot break fresh installs.
     *
     * @return array<string, string>
     */
    private function defaults(): array
    {
        return [
            'kds_item_tracking' => 'true',
            'kds_require_start' => 'false',
            'kds_default_prep_minutes' => '10',
            'kds_order_type_targets' => '{}',
            'kds_warning_percent' => '75',
            'kds_auto_finish_on_complete' => 'true',
            'kds_recall_minutes' => '15',
            'kds_new_order_sound' => 'chime',
            'kds_change_sound' => 'bell',
            'kds_cancel_sound' => 'alert',
            'kds_sound_volume' => '80',
            'kds_reminder_seconds' => '30',
        ];
    }
};
