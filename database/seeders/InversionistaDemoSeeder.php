<?php

namespace Database\Seeders;

use App\Http\Middleware\EnsureInvestor;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * LA CUENTA DE DEMOSTRACIÓN DEL INVERSIONISTA.
 *
 * `inversionista@demo.example.com`, con la clave de `SEED_PASSWORD`. Entra al
 * panel de aliados y ve el tablero de seguimiento con las cifras que haya en
 * la base: no siembra pedidos ni negocios, porque el tablero es justamente
 * para leer los que ya hay.
 *
 * Idempotente: correrlo dos veces deja la misma cuenta, con la clave puesta
 * otra vez y habilitada.
 *
 *     php artisan db:seed --class=InversionistaDemoSeeder
 */
class InversionistaDemoSeeder extends Seeder
{
    public const CORREO = 'inversionista@demo.example.com';

    public function run(): void
    {
        if (app()->environment('production') && ! config('semillas.permitir_en_produccion')) {
            $this->command?->error('No se siembran cuentas de demostración en producción.');

            return;
        }

        $clave = config('semillas.clave');

        if (! $clave) {
            $this->command?->error('Falta SEED_PASSWORD en el .env.');

            return;
        }

        Rol::firstOrCreate(
            ['rol_id' => EnsureInvestor::ROL_INVERSIONISTA],
            ['name' => 'inversionista', 'guard_name' => 'web'],
        );

        $user = User::where('email', self::CORREO)->first() ?? new User(['email' => self::CORREO]);

        $user->forceFill([
            'name' => 'Inversionista de demostración',
            'password' => Hash::make($clave),
            'rol' => EnsureInvestor::ROL_INVERSIONISTA,
            'state' => true,
            'email_verified_at' => $user->email_verified_at ?? now(),
            'must_change_password' => false,
        ])->save();

        $this->command?->info('Inversionista: '.self::CORREO.' (clave: SEED_PASSWORD).');
    }
}
