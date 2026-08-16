<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Domiciliary;
use Illuminate\Support\Facades\Hash;

class UsersSeeder extends Seeder
{
    public function run(): void
    {
        // updateOrCreate por email: el seeder se puede correr las veces
        // que haga falta sin duplicar usuarios ni reventar por el unique.
        $users = [
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
        ];

        foreach ($users as $data) {
            User::updateOrCreate(['email' => $data['email']], $data);
        }

        $this->ensureBuyerProfile();
        $this->ensureTenderoStore();
        $this->linkTestDomiciliaryToStore();
    }

    /**
     * El comprador de prueba necesita su perfil buyer para poder ordenar.
     */
    private function ensureBuyerProfile(): void
    {
        $comprador = User::where('email', 'browin@gmail.com')->first();
        if (!$comprador) {
            return;
        }

        DB::table('buyer')->updateOrInsert(
            ['user_id' => $comprador->user_id],
            ['qualification' => 0.00, 'belongs_to_complex' => 0, 'state' => 1],
        );
    }

    /**
     * El tendero de prueba necesita su registro de owner y una tienda
     * (tipo 1 · Tienda) para que el panel y las órdenes funcionen en una
     * base recién sembrada. Si ya tiene tienda, no se toca nada.
     */
    private function ensureTenderoStore(): void
    {
        $tendero = User::where('email', 'tendero@gmail.com')->first();
        if (!$tendero) {
            return;
        }

        // Owner (document_type_id 1 = Cédula, sembrada por DocumentTypeSeeder)
        DB::table('owner')->updateOrInsert(
            ['user_id' => $tendero->user_id],
            [
                'document_type_id' => 1,
                'document_number' => '1000000000',
                'state' => 1,
            ],
        );

        $ownerId = DB::table('owner')
            ->where('user_id', $tendero->user_id)
            ->value('owner_id');

        // ¿Ya tiene alguna tienda vinculada?
        $hasBusiness = DB::table('owner_busines')
            ->where('owner_id', $ownerId)
            ->exists();

        if ($hasBusiness) {
            return;
        }

        DB::table('business')->updateOrInsert(
            ['name' => 'Tienda de prueba'],
            [
                'phone' => '3000000001',
                'address' => 'Calle 1 # 2 - 3',
                'description' => 'Tienda de barrio para el entorno de pruebas',
                'qualification' => 0.00,
                'type' => 1, // Tienda
                'state' => 1,
            ],
        );

        $businessId = DB::table('business')
            ->where('name', 'Tienda de prueba')
            ->value('busines_id');

        DB::table('owner_busines')->updateOrInsert(
            ['owner_id' => $ownerId, 'busines_id' => $businessId],
            ['state' => 1],
        );
    }

    /**
     * El domiciliario de prueba (domicilio@gmail.com) queda con su ficha de
     * domiciliario creada y vinculado a la tienda del tendero de prueba
     * (tendero@gmail.com), para que el flujo completo — despachar orden →
     * domiciliario la recibe — funcione de una en un entorno recién sembrado.
     */
    private function linkTestDomiciliaryToStore(): void
    {
        $domiciliaryUser = User::where('email', 'domicilio@gmail.com')->first();
        $tendero = User::where('email', 'tendero@gmail.com')->first();

        if (!$domiciliaryUser || !$tendero) {
            return;
        }

        // 1. Ficha de domiciliario (disponible y activo)
        $domiciliary = Domiciliary::firstOrCreate(
            ['user_id' => $domiciliaryUser->user_id],
            ['available' => 1, 'qualification' => 5.00, 'state' => 1],
        );

        // 2. Tienda del tendero: user → owner → primera tienda
        $business = $tendero->owner?->businesses()->first();

        if (!$business) {
            $this->command?->warn(
                'UsersSeeder: el tendero de prueba no tiene tienda; el domiciliario quedó sin vincular.',
            );
            return;
        }

        // 3. Vínculo tienda ↔ domiciliario (idempotente)
        DB::table('business_domiciliary')->updateOrInsert(
            [
                'busines_id' => $business->busines_id,
                'domiciliary_id' => $domiciliary->domiciliary_id,
            ],
            ['state' => 1],
        );
    }
}
