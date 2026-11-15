<?php

namespace Database\Factories;

use App\Models\Membership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'phone' => '+2519'.fake()->unique()->numerify('########'),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /** An active paying member (period ends in 20 days). */
    public function member(): static
    {
        return $this->afterCreating(fn ($user) => Membership::query()->create([
            'user_id' => $user->id, 'status' => 'active',
            'current_period_start' => now()->subDays(10), 'current_period_end' => now()->addDays(20),
            'grace_ends_at' => now()->addDays(23), 'continuous_since' => now()->subDays(10), 'periods_paid' => 1,
        ]));
    }

    public function superAdmin(): static
    {
        return $this->role('super_admin');
    }

    public function role(string $role): static
    {
        return $this->state(fn () => ['role' => $role]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
