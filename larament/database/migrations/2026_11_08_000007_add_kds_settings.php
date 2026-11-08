<?php

use App\Enums\SettingKey;
use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ($this->keys() as $key) {
            Setting::firstOrCreate(
                ['key' => $key->value],
                ['value' => $key->defaultValue()],
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Setting::query()
            ->whereIn('key', array_map(fn (SettingKey $key): string => $key->value, $this->keys()))
            ->delete();
    }

    /**
     * @return list<SettingKey>
     */
    private function keys(): array
    {
        return [
            SettingKey::KDS_ITEM_TRACKING,
            SettingKey::KDS_REQUIRE_START,
            SettingKey::KDS_DEFAULT_PREP_MINUTES,
            SettingKey::KDS_ORDER_TYPE_TARGETS,
            SettingKey::KDS_WARNING_PERCENT,
            SettingKey::KDS_AUTO_FINISH_ON_COMPLETE,
            SettingKey::KDS_RECALL_MINUTES,
            SettingKey::KDS_NEW_ORDER_SOUND,
            SettingKey::KDS_CHANGE_SOUND,
            SettingKey::KDS_CANCEL_SOUND,
            SettingKey::KDS_SOUND_VOLUME,
            SettingKey::KDS_REMINDER_SECONDS,
        ];
    }
};
