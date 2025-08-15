# Queued Printing System Documentation

## Overview
The printing system has been converted to use Laravel queue jobs for better performance and reliability. This prevents the application from blocking during printing operations.

## Changes Made

### 1. Created Queue Jobs
- `App\Jobs\PrintOrderReceipt` - Handles cashier receipt printing
- `App\Jobs\PrintKitchenOrder` - Handles kitchen order printing

### 2. Updated PrintService Methods
- `printOrderReceipt()` - Now dispatches `PrintOrderReceipt` job
- `printKitchen()` - Now dispatches multiple `PrintKitchenOrder` jobs (one per printer)

## Queue Configuration

### Queue Name
Both jobs are dispatched to the `printing` queue for better organization.

### Job Properties
- **Timeout**: 120 seconds (2 minutes)
- **Retries**: 3 attempts on failure
- **Queue**: `printing`

## Running the Queue

### Start Queue Worker
To process printing jobs, run the queue worker:
```bash
cd e:\AI_rewirte\larament; php artisan queue:work --queue=printing
```

### Monitor Queue (Optional)
```bash
cd e:\AI_rewirte\larament; php artisan queue:monitor printing
```

### Process All Queued Jobs
```bash
cd e:\AI_rewirte\larament; php artisan queue:work --stop-when-empty
```

## Benefits

1. **Non-blocking**: API responses are immediate, printing happens in background
2. **Reliability**: Failed print jobs are retried automatically
3. **Scalability**: Multiple queue workers can process print jobs simultaneously
4. **Error Handling**: Failed jobs are logged and can be retried
5. **Performance**: UI remains responsive during printing operations

## Error Handling

- Failed jobs are logged in the Laravel log files
- After 3 failed attempts, jobs are moved to the `failed_jobs` table
- Failed jobs can be retried using: `php artisan queue:retry all`

## Production Deployment

For production environments, consider using:
- **Supervisor** to keep queue workers running
- **Redis** instead of database queue for better performance
- **Multiple workers** for high-volume printing

### Example Supervisor Configuration
```ini
[program:larament-queue-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/larament/artisan queue:work --queue=printing --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/path/to/larament/storage/logs/worker.log
stopwaitsecs=3600
```

## Arabic UI Support
The queue jobs maintain full Arabic language support through the existing Blade templates and Browsershot rendering system.
