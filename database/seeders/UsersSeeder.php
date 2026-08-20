<?php

namespace Database\Seeders;

use App\Models\Domiciliary;
use App\Models\User;
use App\Support\ClaveDeSemilla;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * USUARIOS DE PRUEBA DEL ENTORNO DE DESARROLLO
 *
 * Un administrador, un comprador, un domiciliario y un tendero, con sus fichas y
 * una tienda vinculada, para que el flujo completo funcione en una base recién
 * creada.
 *
 * DOS COSAS QUE CAMBIARON, las dos por el mismo motivo:
 *
 * 1. La clave ya no está en el código. Eran `password123` para los cuatro, uno de
 *    ellos `admin@gmail.com` con rol 4 — es decir, la clave del administrador
 *    publicada en el repositorio. Ahora sale de `SEED_PASSWORD` o se genera al
 *    azar y se imprime una vez. Ver `ClaveDeSemilla`.
 *
 * 2. No corre en producción. Son usuarios FALSOS: `admin@gmail.com`,
 *    `tendero@gmail.com`, "Tienda de prueba". Nada de eso tiene sentido en el
 *    servidor de verdad, y un `db:seed --force` dentro de un despliegue los
 *    habría creado sin que nadie se enterara. Quien de verdad los necesite ahí,
 *    pone `SEED_ALLOW_PRODUCTION=true` y sabe lo que está haciendo.
 */
class UsersSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction() && !config('semillas.permitir_en_produccion')) {
            $this->command?->warn(
                'UsersSeeder no corre en producción: crea usuarios de prueba. '
                . 'Si de verdad hacen falta, SEED_ALLOW_PRODUCTION=true.',
            );

            return;
        }

        $clave = Hash::make(ClaveDeSemilla::resolver($this->command));

        // updateOrCreate por email: el seeder se puede correr las veces
        // que haga falta sin duplicar usuarios ni reventar por el unique.
        $users = [
            [
                'name' => 'browin',
                'email' => 'admin@gmail.com',
                /*
                 * Verificado. El login exige el correo confirmado, y este usuario
                 * no tiene bandeja de entrada donde recibir nada: sembrarlo sin
                 * verificar dejaba un administrador que no podía entrar y sin
                 * forma de arreglarlo desde la aplicación.
                 */
                'email_verified_at' => now(),
                'email_verification_token' => null,
                'email_verification_expires_at' => null,
                'password' => $clave,
                'phone' => null,
                'address' => null,
                'rol' => 4,
                'qualification' => 0.00,
                /*
                 * Activo, como los demás. Estaba en `null` y el login rechaza eso
                 * —`if (!$user->state)` responde "tu cuenta está deshabilitada"—,
                 * así que una base recién sembrada creaba un administrador que no
                 * podía entrar. No se notaba porque en las bases ya existentes
                 * alguien lo había activado a mano, y `updateOrCreate` solo lo
                 * volvía a apagar al reejecutar el seeder.
                 */
                'state' => 1,
            ],
            [
                'name' => 'Browin smith Lopez Santiago',
                'email' => 'browin@gmail.com',
                'email_verified_at' => '2026-02-12 13:08:18',
                'email_verification_token' => '11h0cv9HF7jZMDQ1Cb7pBx2xtHHVmwgmm4YYYPQe37xGFwRtw7e6POcu3aWh',
                'email_verification_expires_at' => '2026-02-12 15:37:09',
                'password' => $clave,
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
                'password' => $clave,
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
                'password' => $clave,
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
