<?php

use App\Models\Rol;
use App\Models\User;
use App\Support\ClaveDeSemilla;
use Database\Seeders\DemoSeeder;
use Database\Seeders\UsersSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * LAS CLAVES DE LOS SEEDERS
 *
 * Había `Hash::make('password123')` para cuatro usuarios en `UsersSeeder`, uno de
 * ellos `admin@gmail.com` con rol 4. Es decir: la clave del administrador,
 * publicada en el repositorio y lista para viajar a producción en el primer
 * `db:seed --force` de un despliegue.
 *
 * Lo que se comprueba acá es lo que impide que vuelva: que la clave venga del
 * entorno y que estos seeders no corran en producción.
 */

/**
 * Corre el seeder DIRECTAMENTE, sin pasar por Artisan.
 *
 * `$this->seed()` invoca `db:seed`, y ese comando tiene su propia protección de
 * producción: pregunta por consola antes de correr. En una prueba no hay nadie
 * que responda, así que se quedaba colgado en la confirmación en vez de
 * comprobar la guarda del seeder, que es lo que interesa acá.
 */
function sembrar(string $clase): void
{
    app($clase)->run();
}

beforeEach(function () {
    ClaveDeSemilla::olvidar();

    /*
     * Los roles y el tipo de documento van antes: `user.rol` es una clave ajena y
     * la tienda de prueba necesita un tipo de documento. En una base de verdad
     * los siembra `DatabaseSeeder` en ese orden.
     */
    foreach ([1 => 'buyer', 2 => 'owner', 3 => 'domiciliary', 4 => 'admin'] as $id => $nombre) {
        Rol::firstOrCreate(['rol_id' => $id], ['name' => $nombre, 'guard_name' => 'web']);
    }

    DB::table('document_types')->insertOrIgnore([
        'id'      => 1,
        'code'    => 'CC',
        'name_en' => 'Citizenship ID',
        'name_es' => 'Cédula de ciudadanía',
    ]);

    // `business.type` apunta a category_business: la tienda de prueba es tipo 1.
    DB::table('category_business')->insertOrIgnore(['id' => 1, 'name' => 'Tienda']);
});

it('la clave sale del entorno y no del código', function () {
    config(['semillas.clave' => 'una-clave-de-prueba']);

    sembrar(UsersSeeder::class);

    $admin = User::where('email', 'admin@gmail.com')->firstOrFail();

    expect(Hash::check('una-clave-de-prueba', $admin->password))->toBeTrue();

    // Y la que estaba escrita en el repositorio ya no abre nada.
    expect(Hash::check('password123', $admin->password))->toBeFalse();

});

it('sin clave configurada, genera una distinta en cada corrida', function () {
    // Sin esto la prueba dependería del `.env` de la máquina, y en una con
    // SEED_PASSWORD puesta pasaría sin comprobar nada.
    config(['semillas.clave' => null]);

    sembrar(UsersSeeder::class);
    $claveGenerada = ClaveDeSemilla::resolver();
    $primera = User::where('email', 'admin@gmail.com')->firstOrFail()->password;

    ClaveDeSemilla::olvidar();
    User::where('email', 'admin@gmail.com')->delete();

    sembrar(UsersSeeder::class);
    $segunda = User::where('email', 'admin@gmail.com')->firstOrFail()->password;

    /*
     * Comparar los dos hashes no probaría nada: bcrypt sala cada uno, así que
     * salen distintos incluso con la misma clave. Lo que se comprueba es que la
     * clave de la primera corrida NO abra la cuenta de la segunda.
     */
    expect(Hash::check($claveGenerada, $primera))->toBeTrue();
    expect(Hash::check($claveGenerada, $segunda))->toBeFalse();
});

it('los cuatro usuarios de una corrida comparten la clave', function () {
    config(['semillas.clave' => 'misma-para-todos']);

    sembrar(UsersSeeder::class);

    // Con una clave distinta por usuario, la salida del comando sería la única
    // forma de saber cuál es cuál.
    foreach (['admin@gmail.com', 'browin@gmail.com', 'domicilio@gmail.com', 'tendero@gmail.com'] as $correo) {
        $u = User::where('email', $correo)->firstOrFail();
        expect(Hash::check('misma-para-todos', $u->password))->toBeTrue();
    }

});

it('el administrador de prueba queda ACTIVO y puede entrar', function () {
    config(['semillas.clave' => 'una-clave']);

    sembrar(UsersSeeder::class);

    /*
     * Estaba con `state => null`, y el login responde "tu cuenta está
     * deshabilitada" ante cualquier valor falso. Una base recién sembrada creaba
     * un administrador que no podía entrar, y no se notaba porque en las bases
     * ya existentes alguien lo había activado a mano — hasta que reejecutar el
     * seeder volvía a apagarlo.
     */
    $admin = User::where('email', 'admin@gmail.com')->firstOrFail();

    expect((int) $admin->state)->toBe(1);
    expect((int) $admin->rol)->toBe(4);

    /*
     * Y con el correo verificado, que era el SEGUNDO motivo por el que el login
     * lo rechazaba. Este usuario no tiene bandeja de entrada donde recibir el
     * enlace, así que sin esto quedaba bloqueado sin forma de arreglarlo desde
     * la aplicación.
     */
    expect($admin->email_verified_at)->not->toBeNull();
});

it('el login acepta al administrador recién sembrado', function () {
    /*
     * La comprobación de verdad: los dos campos anteriores existen para esto, y
     * comprobarlos por separado dejaría fuera cualquier tercer motivo de rechazo
     * que aparezca mañana.
     */
    config(['semillas.clave' => 'clave-de-prueba']);

    sembrar(UsersSeeder::class);

    $this->postJson('/v1/login', [
        'email'    => 'admin@gmail.com',
        'password' => 'clave-de-prueba',
    ])->assertOk()->assertJsonStructure(['token', 'user']);
});

it('en producción no siembra usuarios de prueba', function () {
    // `isProduction()` lee el enlace 'env' del contenedor, que se fija al
    // arrancar: cambiar `config('app.env')` no lo mueve.
    $this->app['env'] = 'production';

    sembrar(UsersSeeder::class);

    // `admin@gmail.com` y "Tienda de prueba" no tienen nada que hacer en el
    // servidor de verdad, y un `--force` en un despliegue los habría creado.
    expect(User::where('email', 'admin@gmail.com')->exists())->toBeFalse();
});

it('en producción tampoco siembra la demostración', function () {
    // `isProduction()` lee el enlace 'env' del contenedor, que se fija al
    // arrancar: cambiar `config('app.env')` no lo mueve.
    $this->app['env'] = 'production';

    sembrar(DemoSeeder::class);

    expect(User::where('email', 'like', '%@' . DemoSeeder::DOMINIO)->exists())->toBeFalse();
});

it('con permiso explícito sí corre en producción', function () {
    // Negarse siempre sería peor: hay instalaciones donde de verdad hace falta
    // sembrar algo. Lo que no puede pasar es que ocurra por descuido.
    // `isProduction()` lee el enlace 'env' del contenedor, que se fija al
    // arrancar: cambiar `config('app.env')` no lo mueve.
    $this->app['env'] = 'production';
    config(['semillas.permitir_en_produccion' => true]);
    config(['semillas.clave' => 'explicita']);

    sembrar(UsersSeeder::class);

    expect(User::where('email', 'admin@gmail.com')->exists())->toBeTrue();

});
