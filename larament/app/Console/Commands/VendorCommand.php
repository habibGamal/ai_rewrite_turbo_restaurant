<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Creates the hidden vendor ("god mode") account of this install, or resets
 * its password. The vendor is the only account that can open the vendor
 * console (business type, modules and features). Customers see it as
 * "الدعم الفني" wherever its name shows up.
 */
class VendorCommand extends Command
{
    protected $signature = 'pos:vendor
        {--email= : Login email for the vendor account (default: support@pos.local, or the current one)}
        {--password= : Password to set; a random one is generated and printed when omitted}';

    protected $description = 'Create the hidden vendor account or reset its password';

    public function handle(): int
    {
        $vendor = User::query()->onlyVendor()->withTrashed()->first();
        $email = $this->option('email') ?: ($vendor?->email ?? 'support@pos.local');
        $password = $this->option('password') ?: Str::password(16, symbols: false);

        if (strlen($password) < 8) {
            $this->error('Password must be at least 8 characters.');

            return self::FAILURE;
        }

        $emailTaken = User::query()->withVendor()->withTrashed()
            ->where('email', $email)
            ->when($vendor, fn ($query) => $query->whereKeyNot($vendor->getKey()))
            ->exists();
        if ($emailTaken) {
            $this->error("The email {$email} already belongs to another user.");

            return self::FAILURE;
        }

        $created = $vendor === null;
        $vendor ??= new User;

        $vendor->forceFill([
            'name' => User::VENDOR_DISPLAY_NAME,
            'email' => $email,
            'password' => $password,
            'is_vendor' => true,
            'email_verified_at' => $vendor->email_verified_at ?? now(),
        ]);
        $vendor->deleted_at = null;
        $vendor->save();

        $this->info($created ? 'Vendor account created.' : 'Vendor password reset.');
        $this->table(['Email', 'Password'], [[$email, $this->option('password') ? '(as given)' : $password]]);
        $this->warn('Store this password now; it is not shown again.');

        return self::SUCCESS;
    }
}
