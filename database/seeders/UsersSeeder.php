<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UsersSeeder extends Seeder
{
    public function run(): void
    {
        User::insert([
            [
                'name' => 'browin',
                'email' => 'admin@gmail.com',
                'email_verified_at' => null,
                'email_verification_token' => null,
                'email_verification_expires_at' => null,
                'password' => Hash::make('password123'),
                'phone' => null,
                'address' => null,
                'rol' => 4,
                'qualification' => 0.00,
                'state' => null,
            ],
            [
                'name' => 'Browin smith Lopez Santiago',
                'email' => 'browin@gmail.com',
                'email_verified_at' => '2026-02-12 13:08:18',
                'email_verification_token' => '11h0cv9HF7jZMDQ1Cb7pBx2xtHHVmwgmm4YYYPQe37xGFwRtw7e6POcu3aWh',
                'email_verification_expires_at' => '2026-02-12 15:37:09',
                'password' => Hash::make('password123'),
                'phone' => null,
                'address' => null,
                'rol' => 1,
                'qualification' => 0.00,
                'state' => 1,
            ],
            [
                'name' => 'domicilio',
                'email' => 'domicilio@gmail.com',
                'email_verified_at' => '2026-02-12 13:08:18',
                'email_verification_token' => null,
                'email_verification_expires_at' => null,
                'password' => Hash::make('password123'),
                'phone' => '3002464977',
                'address' => 'calle 20',
                'rol' => 3,
                'qualification' => 5.00,
                'state' => 1,
            ],
            [
                'name' => 'Geovanny Boom',
                'email' => 'tendero@gmail.com',
                'email_verified_at' => '2026-02-12 13:08:18',
                'email_verification_token' => null,
                'email_verification_expires_at' => null,
                'password' => Hash::make('password123'),
                'phone' => null,
                'address' => null,
                'rol' => 2,
                'qualification' => 0.00,
                'state' => 1,
            ],
        ]);
    }
}