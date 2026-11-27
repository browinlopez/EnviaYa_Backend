<?php

namespace Database\Seeders;

use App\Http\Middleware\EnsureInvestor;
use App\Models\Order\OrdersSales;
use App\Models\Rol;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * LA CUENTA DE DEMOSTRACIÓN DEL INVERSIONISTA.
 *
 * `inversionista@demo.example.com`, con la clave de `SEED_PASSWORD`. Entra al
 * panel de aliados y ve sus módulos con las cifras que haya en la base: no
 * siembra pedidos ni negocios, porque el panel es justamente para leer los
 * que ya hay. Lo único que completa es la fecha de alta de los negocios de
 * muestra que no la tienen (ver `fechasDeAltaDeMuestra`).
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

        $this->fechasDeAltaDeMuestra();
    }

    /**
     * LAS FECHAS DE ALTA DE LOS NEGOCIOS DE MUESTRA.
     *
     * Los negocios que siembran `MuestraDeTresNegociosSeeder` y compañía no
     * traen `created_at` (la columna se llena sola solo desde agosto de 2026),
     * y sin ella el módulo Negocios no tiene «días hasta la primera venta» ni
     * «negocios nuevos por mes» que enseñar en una demostración.
     *
     * Solo toca los que NO tienen fecha y SÍ tienen ventas: les pone de alta
     * unos días antes de su primer pedido entregado (entre 2 y 24, fijo por
     * negocio para que correrlo dos veces dé lo mismo). Nunca pisa una fecha
     * que ya exista, y nunca en producción (ver el `return` de arriba).
     */
    private function fechasDeAltaDeMuestra(): void
    {
        $primeras = DB::table('orderssales')
            ->where('state', OrdersSales::ENTREGADO)
            ->groupBy('busines_id')
            ->selectRaw('busines_id, MIN(sale_date) as primera');

        $negocios = DB::table('business as b')
            ->joinSub($primeras, 'p', 'p.busines_id', '=', 'b.busines_id')
            ->whereNull('b.created_at')
            ->get(['b.busines_id', 'p.primera']);

        foreach ($negocios as $n) {
            $dias = 2 + ((int) $n->busines_id * 7) % 23;

            DB::table('business')->where('busines_id', $n->busines_id)->whereNull('created_at')->update([
                'created_at' => Carbon::parse($n->primera)->subDays($dias)->setTime(9, 0),
            ]);
        }

        if ($negocios->isNotEmpty()) {
            $this->command?->info("Fecha de alta de muestra para {$negocios->count()} negocios.");
        }
    }
}
