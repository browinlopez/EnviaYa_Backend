<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\Owner\Owner;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Da de alta a quien administra una tienda.
 *
 * Había comandos para el administrador del panel y para el dueño de un
 * conjunto, y ninguno para el tendero, que es el que más se repite: cada
 * negocio nuevo necesita uno. Se hacía a mano en la base, y hacerlo a mano
 * tiene un fallo silencioso que se repite siempre —el que explica el bloque de
 * abajo.
 *
 * TRES COSAS QUE VAN JUNTAS, y las tres hacen falta:
 *
 *  · la cuenta con `rol = 2`, que es lo único que mira `EnsureBusinessOwner`
 *    antes de dejar pasar al panel del tendero;
 *  · la ficha en `owner`, y su vínculo en `owner_busines`, que es lo que dice
 *    CUÁL negocio es el suyo;
 *  · el correo verificado.
 *
 * LA SEGUNDA ES LA QUE SE OLVIDA, Y NO FALLA RUIDOSAMENTE. Sin la cadena
 * `user → owner → owner_busines → business` la cuenta entra y no ve nada, pero
 * lo peor pasa del otro lado: `OrderController` busca por ahí a quién avisarle
 * de un pedido nuevo, así que un negocio sin esa cadena NO RECIBE NINGÚN
 * AVISO. El cliente paga, el pedido queda bien guardado, y en la tienda nadie
 * se entera de que hay algo que preparar.
 *
 * Uso:
 *   php artisan negocio:tendero --negocio=8 --email=... --nombre="..."
 *   php artisan negocio:tendero --negocio=8 --email=... --clave=...
 *
 * Sin `--negocio` lista los que hay. Sin `--clave` genera una y la imprime una
 * sola vez.
 */
class CrearTendero extends Command
{
    /** Tabla `rol`: el 2 es el tendero. Igual que en `EnsureBusinessOwner`. */
    private const ROL_TENDERO = 2;

    protected $signature = 'negocio:tendero
        {--negocio= : Identificador del negocio. Si falta, se listan}
        {--email= : Correo de la cuenta}
        {--nombre= : Nombre visible}
        {--telefono= : Teléfono de contacto}
        {--clave= : Contraseña. Si no se pasa, se genera y se imprime una vez}';

    protected $description = 'Crea o repone la cuenta de quien administra una tienda';

    public function handle(): int
    {
        if (!Rol::find(self::ROL_TENDERO)) {
            $this->error('No existe el rol 2 (tendero) en la tabla `rol`.');
            $this->line('Siémbralo antes: php artisan db:seed --class=RolesAndPermissionsSeeder');

            return self::FAILURE;
        }

        $negocioId = (int) $this->option('negocio');

        if (!$negocioId) {
            $negocios = Business::where('state', 1)->orderBy('name')
                ->get(['busines_id', 'name', 'address']);

            if ($negocios->isEmpty()) {
                $this->error('No hay negocios registrados todavía.');

                return self::FAILURE;
            }

            $this->newLine();
            $this->line('  <options=bold>NEGOCIOS</>');
            foreach ($negocios as $n) {
                $this->line(sprintf('    %3d  %-36s %s',
                    $n->busines_id, $n->name, mb_strimwidth($n->address ?? '', 0, 40, '…')));
            }
            $this->newLine();
            $this->line('  Vuelve con <options=bold>--negocio=N</>');
            $this->newLine();

            return self::FAILURE;
        }

        $negocio = Business::find($negocioId);

        if (!$negocio) {
            $this->error("No existe el negocio {$negocioId}.");

            return self::FAILURE;
        }

        $email  = $this->option('email') ?: $this->ask('Correo');
        $nombre = $this->option('nombre') ?: ('Tendero ' . $negocio->name);

        /*
         * La clave se genera si no la dan, y se imprime UNA vez. Pasarla por
         * argumento es cómodo y queda en el historial del shell y en los
         * registros de quien orquesta el contenedor.
         */
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
         * Si el correo ya existe y es de OTRO rol, no se toca. Convertir a un
         * comprador en tendero por escribir mal un correo le daría acceso al
         * panel de una tienda que no es suya, y el que se queda sin su cuenta
         * ni se entera.
         */
        $existente = User::where('email', $email)->first();

        if ($existente && (int) $existente->rol !== self::ROL_TENDERO) {
            $this->error("El correo {$email} ya es de una cuenta con rol {$existente->rol}.");
            $this->line('Si de verdad quieres convertirla, cámbiale el rol desde el panel primero.');

            return self::FAILURE;
        }

        $existia = (bool) $existente;

        DB::transaction(function () use ($email, $nombre, $clave, $negocio, &$usuario, &$owner) {
            $usuario = User::updateOrCreate(
                ['email' => $email],
                [
                    'name'     => $nombre,
                    'password' => Hash::make($clave),
                    'rol'      => self::ROL_TENDERO,
                    'phone'    => $this->option('telefono') ?: $negocio->phone,
                    'address'  => $negocio->address,
                    'state'    => 1,
                    /*
                     * Verificado de entrada: esta cuenta la crea la plataforma,
                     * no se registra sola, así que no hay ningún correo que
                     * confirmar. Dejarlo en null manda al tendero a una
                     * pantalla de verificación que espera un mensaje que nadie
                     * va a enviar.
                     */
                    'email_verified_at' => now(),
                ],
            );

            $owner = Owner::firstOrCreate(
                ['user_id' => $usuario->user_id],
                ['state' => 1],
            );

            // El vínculo con el negocio: lo que convierte la cuenta en la
            // dueña de ESTA tienda y lo que usa `OrderController` para avisar.
            DB::table('owner_busines')->updateOrInsert(
                ['owner_id' => $owner->owner_id, 'busines_id' => $negocio->busines_id],
                ['state' => 1],
            );
        });

        $this->newLine();
        $this->line($existia
            ? '  <options=bold>Cuenta repuesta.</>'
            : '  <options=bold>Cuenta creada.</>');
        $this->newLine();
        $this->line(sprintf('    negocio    %d · %s', $negocio->busines_id, $negocio->name));
        $this->line(sprintf('    usuario    %d · %s', $usuario->user_id, $usuario->email));
        $this->line(sprintf('    owner      %d', $owner->owner_id));
        $this->line('    rol        2 (tendero)');

        if ($generada) {
            $this->newLine();
            $this->line('    <options=bold>Contraseña:</> ' . $clave);
            $this->line('    <fg=yellow>No se vuelve a mostrar.</>');
        }

        $this->newLine();

        return self::SUCCESS;
    }
}
