<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UsersSeeder extends Seeder
{
    public function run(): void
    {
        User::insert([
            [
                'name' => 'browin',
                'email' => 'admin@gmail.com',
                'email_verified_at' => now(),
                'email_verification_token' => Str::random(60),
                'email_verification_expires_at' => now()->addMinutes(60),
                'password' => Hash::make('password123'),
                'phone' => null,
                'address' => null,
                'rol_id' => 4,
                'qualification' => 0.00,
                'state' => null,
            ],
            [
                'name' => 'Browin smith Lopez Santiago',
                'email' => 'browin@gmail.com',
                'email_verified_at' => now(),
                'email_verification_token' => Str::random(60),
                'email_verification_expires_at' => now()->addMinutes(60),
                'password' => Hash::make('password123'),
                'phone' => null,
                'address' => null,
                'rol_id' => 1,
                'qualification' => 0.00,
                'state' => 1,
            ],
            [
                'name' => 'domicilio',
                'email' => 'domicilio@gmail.com',
                'email_verified_at' => now(),
                'email_verification_token' => Str::random(60),
                'email_verification_expires_at' => now()->addMinutes(60),
                'password' => Hash::make('password123'),
                'phone' => '3002464977',
                'address' => 'calle 20',
                'rol_id' => 3,
                'qualification' => 5.00,
                'state' => 1,
            ],
            [
                'name' => 'Geovanny Boom',
                'email' => 'tendero@gmail.com',
                'email_verified_at' => now(),
                'email_verification_token' => Str::random(60),
                'email_verification_expires_at' => now()->addMinutes(60),
                'password' => Hash::make('password123'),
                'phone' => null,
                'address' => null,
                'rol_id' => 2,
                'qualification' => 0.00,
                'state' => 1,
            ],
        ]);
    }
}