<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            UserSeeder::class,
            SettingsSeeder::class,
            TableTypeSeeder::class,
            // CategorySeeder::class,
            // PrinterSeeder::class,
            // SupplierSeeder::class,
            // RegionSeeder::class,
            // ExpenceTypeSeeder::class,
            // ProductSeeder::class,
            // ClothesBusinessSeeder::class,
            // ExpenseSeeder::class,
            // PurchaseInvoiceSeeder::class,
        ]);
    }
}
