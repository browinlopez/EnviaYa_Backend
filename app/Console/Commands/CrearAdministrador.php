<?php

namespace App\Console\Commands;

use App\Models\Area;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Validator;

/**
 * Crea —o repone— una cuenta de administración del panel.
 *
 * POR QUÉ HACE FALTA UN COMANDO Y NO BASTA EL SEEDER.
 *
 * `UsersSeeder` no corre en producción a propósito: crea gente falsa
 * (`admin@gmail.com`, `tendero@gmail.com`, "Tienda de prueba") que no tiene
 * nada que hacer en el servidor de verdad. Y la clave de semilla ya no está en
 * el repositorio, así que tampoco había credenciales que repartir. Resultado:
 * el panel se publicaba y no había forma de entrar.
 *
 * TRES COSAS QUE HAY QUE PONER JUNTAS, Y ES FÁCIL OLVIDAR UNA.
 *
 *  · `rol = 4`, que es lo que exige el middleware `EnsureAdmin`.
 *  · `area_id`, que es de donde salen los permisos por módulo.
 *  · `email_verified_at`, o el acceso queda a medias.
 *
 * La segunda es la traicionera: sin área, `AreasApiController::mios` devuelve
 * `permissions: []`, así que la cuenta entra al panel y lo ve VACÍO. Parece un
 * fallo del panel y es una cuenta a medio crear.
 *
 * Por defecto entra en el área `sistema` (Tecnología), la única con `['*']` en
 * ver y gestionar.
 */
class CrearAdministrador extends Command
{
    protected $signature = 'admin:crear
        {--email= : Correo de la cuenta}
        {--nombre= : Nombre visible}
        {--area=sistema : Código del área (sistema, gerencia, contabilidad, marketing, comercial, sst…)}
        {--nivel=gestor : gestor (puede cambiar cosas) o consulta (solo mirar)}
        {--clave= : Contraseña. Si no se pasa, se genera y se imprime una vez}';

    protected $description = 'Crea o repone una cuenta de administración del panel';

    public function handle(): int
    {
        $email  = $this->option('email')  ?: $this->ask('Correo');
        $nombre = $this->option('nombre') ?: $this->ask('Nombre visible');

        /*
         * La clave se genera si no la dan, y se imprime UNA vez. Pedirla por
         * argumento es cómodo pero queda en el historial del shell y en los
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

        $area = Area::where('code', $this->option('area'))->first();

        if (!$area) {
            $this->error("No existe un área con el código «{$this->option('area')}».");
            $this->line('Disponibles: ' . Area::pluck('code')->implode(', '));

            return self::FAILURE;
        }

        $nivel = $this->option('nivel');

        if (!in_array($nivel, Area::NIVELES, true)) {
            $this->error('El nivel debe ser: ' . implode(' o ', Area::NIVELES));

            return self::FAILURE;
        }

        /*
         * `rol` es una clave foránea. Sin la fila 4, el insert revienta con un
         * "FOREIGN KEY constraint failed" que no le dice nada a quien está
         * intentando entrar por primera vez a un servidor recién montado.
         */
        if (!Rol::find(4)) {
            $this->error('No existe el rol 4 (administración) en la tabla `rol`.');
            $this->line('Siémbralo antes: php artisan db:seed --class=RolesAndPermissionsSeeder');

            return self::FAILURE;
        }

        $existia = User::where('email', $email)->exists();

        /*
         * `updateOrCreate` y no `create`: el uso más frecuente de esto no es
         * dar de alta, es recuperar el acceso de alguien que perdió su clave.
         * Fallar con "ya existe" obligaría a entrar a la base a mano, que es
         * justo lo que este comando viene a evitar.
         */
        $usuario = User::updateOrCreate(
            ['email' => $email],
            [
                'name'              => $nombre,
                'password'          => Hash::make($clave),
                'rol'               => 4,
                'state'             => 1,
                'email_verified_at' => now(),
            ],
        );

        // Fuera del fillable: se asignan aparte a propósito, porque son los
        // que deciden qué ve la cuenta y no deben poder llegar por un formulario.
        $usuario->area_id      = $area->id;
        $usuario->access_level = $nivel;
        $usuario->save();

        $this->newLine();
        $this->info($existia ? 'Cuenta repuesta.' : 'Cuenta creada.');
        $this->table(['Campo', 'Valor'], [
            ['Correo', $usuario->email],
            ['Nombre', $usuario->name],
            ['Rol', '4 (administración)'],
            ['Área', "{$area->name} ({$area->code})"],
            ['Nivel', $nivel],
            ['Módulos', $area->code === 'sistema' ? 'todos' : count($area->permisos($nivel)) . ' con permiso'],
        ]);

        if ($generada) {
            $this->newLine();
            $this->warn('Contraseña generada (no se vuelve a mostrar):');
            $this->line('    ' . $clave);
            $this->newLine();
            $this->line('Cámbiala al entrar. Mientras esté en el historial de');
            $this->line('esta terminal, la sabe cualquiera que lo lea.');
        }

        return self::SUCCESS;
    }
}
