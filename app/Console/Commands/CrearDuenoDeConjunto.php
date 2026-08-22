<?php

namespace App\Console\Commands;

use App\Models\Buyer\ResidentialComplex;
use App\Models\Conjunto\ComplexStaff;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Da de alta al administrador de un conjunto.
 *
 * Los celadores los crea el dueño desde su propio panel; al dueño lo tiene que
 * crear alguien de la plataforma, y ese alguien es este comando mientras el
 * panel interno no tenga la pantalla.
 *
 * TRES COSAS QUE VAN JUNTAS, igual que con el administrador del panel:
 *
 *  · la cuenta con `rol = 5`, que es lo que mira `EnsureComplexStaff`;
 *  · la ficha en `complex_staff`, que es la que dice CUÁL conjunto es el suyo;
 *  · el correo verificado, o el acceso queda a medias.
 *
 * La segunda es la que importa: sin ella la cuenta entra y no ve nada, porque
 * todo el panel de aliados se acota por el conjunto de la ficha.
 */
class CrearDuenoDeConjunto extends Command
{
    protected $signature = 'conjunto:dueno
        {--email= : Correo de la cuenta}
        {--nombre= : Nombre visible}
        {--conjunto= : Identificador del conjunto. Si falta, se listan}
        {--clave= : Contraseña. Si no se pasa, se genera y se imprime una vez}';

    protected $description = 'Crea o repone la cuenta del administrador de un conjunto';

    public function handle(): int
    {
        if (!Rol::find(ComplexStaff::ROL_DUENO)) {
            $this->error('Falta el rol 5 (dueño de conjunto) en la tabla `rol`.');
            $this->line('Lo crea la migración `create_complex_staff`: corre php artisan migrate.');

            return self::FAILURE;
        }

        $complexId = (int) $this->option('conjunto');

        if (!$complexId) {
            $conjuntos = ResidentialComplex::where('state', 1)
                ->orderBy('name')
                ->get(['complex_id', 'name', 'address']);

            if ($conjuntos->isEmpty()) {
                $this->error('No hay conjuntos registrados todavía.');
                $this->line('Créalos desde el panel, en Comunidad → Conjuntos.');

                return self::FAILURE;
            }

            $this->info('Conjuntos disponibles:');
            $this->table(
                ['id', 'Nombre', 'Dirección'],
                $conjuntos->map(fn ($c) => [$c->complex_id, $c->name, $c->address])->all(),
            );

            $complexId = (int) $this->ask('¿A cuál lo vinculas? (id)');
        }

        $conjunto = ResidentialComplex::find($complexId);

        if (!$conjunto) {
            $this->error("No existe un conjunto con el identificador {$complexId}.");

            return self::FAILURE;
        }

        $email  = $this->option('email')  ?: $this->ask('Correo');
        $nombre = $this->option('nombre') ?: $this->ask('Nombre visible');

        $generada = false;
        $clave    = $this->option('clave');

        if (!$clave) {
            $clave    = Str::password(16);
            $generada = true;
        }

        $validacion = Validator::make(
            ['email' => $email, 'name' => $nombre, 'password' => $clave],
            [
                'email'    => ['required', 'email'],
                'name'     => ['required', 'string', 'min:2'],
                'password' => ['required', Password::min(8)],
            ],
        );

        if ($validacion->fails()) {
            foreach ($validacion->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        /*
         * Una persona pertenece a UN conjunto: `complex_staff` tiene índice
         * único por usuario. Si ya estaba en otro, se reasigna en vez de
         * fallar — mudarse de edificio es más frecuente que un error.
         */
        $existia = User::where('email', $email)->exists();

        $usuario = DB::transaction(function () use ($email, $nombre, $clave, $conjunto) {
            $u = User::updateOrCreate(
                ['email' => $email],
                [
                    'name'              => $nombre,
                    'password'          => Hash::make($clave),
                    'rol'               => ComplexStaff::ROL_DUENO,
                    'state'             => 1,
                    'email_verified_at' => now(),
                ],
            );

            ComplexStaff::updateOrCreate(
                ['user_id' => $u->user_id],
                [
                    'complex_id' => $conjunto->complex_id,
                    'role'       => ComplexStaff::DUENO,
                    'state'      => true,
                ],
            );

            return $u;
        });

        $this->newLine();
        $this->info($existia ? 'Cuenta repuesta.' : 'Cuenta creada.');
        $this->table(['Campo', 'Valor'], [
            ['Correo', $usuario->email],
            ['Nombre', $usuario->name],
            ['Rol', '5 (dueño de conjunto)'],
            ['Conjunto', "{$conjunto->name} (#{$conjunto->complex_id})"],
            ['Torres', $conjunto->towers_count ?? 'sin definir'],
        ]);

        if ($generada) {
            $this->newLine();
            $this->warn('Contraseña generada (no se vuelve a mostrar):');
            $this->line('    ' . $clave);
        }

        $this->newLine();
        $this->line('Entra en el panel de aliados. Desde ahí puede crear a sus celadores.');

        return self::SUCCESS;
    }
}
