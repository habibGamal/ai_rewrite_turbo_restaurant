<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->randomLetter().fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (User $user) {
            if ($user->roles()->count() === 0) {
                $user->assignRole('admin');
            }
        });
    }

    public function admin(): static
    {
        return $this->afterCreating(fn (User $user) => $user->syncRoles(['admin']));
    }

    public function cashier(): static
    {
        return $this->afterCreating(fn (User $user) => $user->syncRoles(['cashier']));
    }

    public function viewer(): static
    {
        return $this->afterCreating(fn (User $user) => $user->syncRoles(['viewer']));
    }

    public function kitchen(): static
    {
        return $this->afterCreating(fn (User $user) => $user->syncRoles(['kitchen']));
    }

    public function watcher(): static
    {
        return $this->afterCreating(fn (User $user) => $user->syncRoles(['watcher']));
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
