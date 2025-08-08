# Old System Data Migration Guide

This guide explains how to migrate data from the old Turbo Restaurant system to the new Laravel-based system.

## Overview

The migration process consists of two main steps:
1. **Import the old database** into a separate database connection
2. **Migrate the data** from the old schema to the new Laravel schema

## Prerequisites

1. Ensure you have the old system SQL dump file (`old_system.sql`)
2. Configure your database connections in `.env`
3. Run the new Laravel migrations first

## Step 1: Configure Database Connections

Add the old database connection settings to your `.env` file:

```env
# Old System Database Connection
OLD_DB_HOST=127.0.0.1
OLD_DB_PORT=3306
OLD_DB_DATABASE=sraya
OLD_DB_USERNAME=root
OLD_DB_PASSWORD=your_password
OLD_DB_CHARSET=utf8mb4
OLD_DB_COLLATION=utf8mb4_unicode_ci
```

## Step 2: Import Old Database

Import the old system SQL file into the old database:

```bash
# Import the SQL file (creates database if it doesn't exist)
php artisan db:import-old

# Or specify a custom file path
php artisan db:import-old /path/to/your/old_system.sql

# Drop existing database before importing (careful!)
php artisan db:import-old --drop-first
```

## Step 3: Run Laravel Migrations

Ensure your new Laravel database schema is up to date:

```bash
# Run the migrations
php artisan migrate

# Or reset and run migrations
php artisan migrate:fresh
```

## Step 4: Migrate Data

Now migrate the data from the old system to the new system:

```bash
# Dry run (no actual data insertion)
php artisan migrate:old-data --dry-run

# Full migration
php artisan migrate:old-data

# Migrate specific table only
php artisan migrate:old-data --table=categories

# Custom chunk size for large datasets
php artisan migrate:old-data --chunk=500
```

## Migration Order

The migration follows this order to respect foreign key constraints:

1. **categories** - Product categories
2. **regions** - Delivery regions
3. **customers** - Customer information
4. **suppliers** - Supplier information
5. **printers** - Printer configurations
6. **products** - Product catalog
7. **product_components** - Product-inventory relationships
8. **printer_products** - Printer-product relationships
9. **drivers** - Delivery drivers
10. **dine_tables** - Restaurant tables
11. **expense_types** - Expense categories
12. **settings** - System settings
13. **users** - System users
14. **shifts** - Work shifts
15. **orders** - Customer orders
16. **order_items** - Order line items
17. **payments** - Payment records
18. **inventory_items** - Inventory management
19. **expenses** - Expense records
20. **purchase_invoices** - Purchase invoices
21. **purchase_invoice_items** - Purchase invoice items
22. **return_purchase_invoices** - Return invoices
23. **return_purchase_invoice_items** - Return invoice items
24. **stocktakings** - Stock taking records
25. **stocktaking_items** - Stock taking items
26. **wastes** - Waste records
27. **wasted_items** - Wasted items
28. **daily_snapshots** - Daily summary reports

## Data Mapping Notes

### Users Table
- If email is missing, generates: `user{id}@example.com`
- If password is missing, sets default: `password` (hashed)

### Orders Table
- Default shift_id = 1 if missing
- Default status = 'pending' if missing
- Default type = 'dine_in' if missing
- Default payment_status = 'unpaid' if missing

### Products Table
- Default type = 'normal' if missing
- Default unit = 'piece' if missing
- Default legacy = false if missing

### Regional Data
- Currency: EGP (Egyptian Pound)
- UI Language: Arabic
- All text fields support Arabic characters

## Troubleshooting

### Common Issues

1. **Foreign Key Constraints**
   - Make sure to migrate tables in the correct order
   - Some old data might reference non-existent records

2. **Missing Data**
   - The command provides default values for required fields
   - Check the migration summary for any skipped records

3. **Database Connection Issues**
   - Verify your `.env` settings
   - Ensure the old database is accessible
   - Check MySQL/MariaDB permissions

4. **Memory Issues with Large Datasets**
   - Use the `--chunk` option to process data in smaller batches
   - Increase PHP memory limit if needed

### Command Options

**migrate:old-data options:**
- `--connection=old_system` - Database connection name for old system
- `--dry-run` - Run without inserting data (for testing)
- `--table=tablename` - Migrate specific table only
- `--chunk=1000` - Number of records to process at once

**db:import-old options:**
- `file` - Path to SQL file (default: `old_system.sql` in project root)
- `--database=old_system` - Database connection to import to
- `--drop-first` - Drop database before importing

## Example Workflow

```bash
# 1. Import old database
php artisan db:import-old

# 2. Test the migration (dry run)
php artisan migrate:old-data --dry-run

# 3. Run actual migration
php artisan migrate:old-data

# 4. Check specific table if needed
php artisan migrate:old-data --table=customers --dry-run
```

## Data Validation

After migration, verify the data:

```bash
# Check record counts
php artisan tinker
>>> App\Models\Category::count()
>>> App\Models\Customer::count()
>>> App\Models\Order::count()
```

## Backup Recommendations

1. **Always backup your new database** before running the migration
2. Keep a copy of the old system SQL dump
3. Test the migration on a staging environment first

## Support

If you encounter issues:
1. Check the Laravel logs: `storage/logs/laravel.log`
2. Run commands with `-v` flag for verbose output
3. Use `--dry-run` to test without making changes
