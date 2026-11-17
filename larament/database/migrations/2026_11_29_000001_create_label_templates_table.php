<?php

use App\Models\LabelTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Barcode label designs («تصميمات الملصقات»): each shop sets its own label
 * size, labels per row and what goes where. One starter design matching the
 * old 38×25 mm variant labels is created so printing works out of the box.
 */
return new class extends Migration
{
    private array $permissions = [
        'view_label_templates', 'create_label_templates', 'update_label_templates', 'delete_label_templates',
        'print_labels',
    ];

    public function up(): void
    {
        Schema::create('label_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('width_mm', 6, 2);
            $table->decimal('height_mm', 6, 2);
            $table->unsignedTinyInteger('columns')->default(1);
            $table->decimal('column_gap_mm', 5, 2)->default(0);
            $table->unsignedSmallInteger('dpi')->default(203);
            $table->json('elements');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        DB::table('label_templates')->insert([
            'name' => 'ملصق 38×25',
            'width_mm' => 38,
            'height_mm' => 25,
            'columns' => 1,
            'column_gap_mm' => 0,
            'dpi' => 203,
            'elements' => json_encode(LabelTemplate::starterElements(), JSON_UNESCAPED_UNICODE),
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($this->permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('label_templates');
        Permission::query()->whereIn('name', $this->permissions)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
