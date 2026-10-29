<?php

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Seed all permissions and default roles
        (new RolesAndPermissionsSeeder)->run();

        // 2. Map existing users from role column to model_has_roles
        if (Schema::hasColumn('users', 'role')) {
            $roles = Role::pluck('id', 'name')->toArray();
            $existingUsers = DB::table('users')->select('id', 'role')->get();

            $userModelClass = config('auth.providers.users.model', 'App\\Models\\User');

            foreach ($existingUsers as $user) {
                $roleName = strtolower(trim((string) $user->role));
                if (isset($roles[$roleName])) {
                    $roleId = $roles[$roleName];

                    DB::table('model_has_roles')->updateOrInsert([
                        'role_id' => $roleId,
                        'model_type' => $userModelClass,
                        'model_id' => $user->id,
                    ]);
                }
            }

            // 3. Drop legacy role column
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('role');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('users', 'role')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('role')->nullable()->after('password');
            });

            // Map back primary role if possible
            $userModelClass = config('auth.providers.users.model', 'App\\Models\\User');
            $userRoles = DB::table('model_has_roles')
                ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                ->where('model_type', $userModelClass)
                ->select('model_id', 'roles.name')
                ->get();

            foreach ($userRoles as $ur) {
                DB::table('users')->where('id', $ur->model_id)->update(['role' => $ur->name]);
            }
        }
    }
};
