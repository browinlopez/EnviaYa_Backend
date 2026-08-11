<?php

namespace Database\Factories;

use App\Models\Rol;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
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
     * La tabla `user` de este proyecto no es la de Laravel por defecto:
     * PK user_id, sin remember_token, y `rol` es FK obligatoria a rol.rol_id.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'phone' => fake()->numerify('3#########'),
            'rol' => fn () => Rol::firstOrCreate(
                ['rol_id' => 1],
                ['name' => 'comprador', 'guard_name' => 'web'],
            )->rol_id,
            'qualification' => 0,
            'state' => 1,
        ];
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
