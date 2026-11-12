<?php

use App\Models\Chat\Chat;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

/**
 * LAS 32 RUTAS RETIRADAS, Y LO QUE LAS SUSTITUYE.
 *
 * El 11 de noviembre de 2026 se retiraron 31 rutas de `/v1`: la superficie
 * pasó de 325 a 294. No fue una limpieza a ojo —`scripts/rutas_sin_llamante.py`
 * buscó cada una en el código de los cuatro clientes, en cada versión
 * publicada de la app (1.0.0 a 1.0.12) y en los 202 commits de su historia
 * con `git log -S`: 31 de 31 sin un solo llamante, y 31 de 31 que nunca
 * aparecieron—. El detector se comprueba a sí mismo: antes de contestar busca
 * tres rutas que SÍ se llaman y sale con error si no las encuentra.
 *
 * ESTA PRUEBA NO ES LA DEL CONGELADO. `SuperficieDeLaApiTest` ya nota
 * cualquier cambio de superficie, incluido que vuelva una de estas. Lo que se
 * fija acá es lo otro, lo que un diff de 294 líneas no dice:
 *
 *  1. QUÉ se retiró, con nombre, para que volver a añadir una sea un acto
 *     deliberado y no un descuido de copiar y pegar;
 *  2. y que NO SE PERDIÓ NADA, que es la única parte con riesgo real.
 *
 * El punto 2 importa porque una de las 31 sí tenía algo detrás.
 *
 * LA TRIGÉSIMA SEGUNDA, del 12 de noviembre de 2026: `GET /v1/users`. No
 * salió en la tanda anterior porque el detector la daba por viva, y la daba
 * por viva por un falso positivo —buscaba `users` como subcadena y eso sale
 * en `/admin/users` y en la clave de caché `useQuery(["users"])`—. Devolvía
 * el personal con sus correos a cualquiera con sesión. Su motivo propio está
 * en `tests/Feature/Seguridad/ElPersonalNoSeListaConSesionTest.php`.
 */
test('ninguna de las 32 retiradas volvio al router', function () {
    $lista = json_decode(
        (string) file_get_contents(base_path('scripts/rutas-candidatas.json')),
        true,
    );

    $retiradas = [];
    foreach ($lista['grupos'] as $filas) {
        foreach ($filas as [$verbo, $uri]) {
            $retiradas[] = $verbo.' '.$uri;
        }
    }

    /* La lista es el registro de lo que se fue: si alguien la recorta, esta
       prueba deja de comprobar lo que dice comprobar.

       Y se lee SOLO `grupos`, no `pendientes`. La division costo esta prueba
       en rojo: el 12 de noviembre se añadieron a `grupos` ocho candidatas
       recién medidas, y siete de ellas siguen en el router. Decir «retirada»
       de una ruta viva es exactamente lo que este archivo existe para
       impedir, así que el estado va en el fichero: `grupos` es lo retirado y
       `pendientes` lo que falta decidir. Los dos buscadores recorren los dos;
       esta prueba, solo el primero. */
    expect($retiradas)->toHaveCount(32);

    $vivas = [];
    foreach (Route::getRoutes() as $ruta) {
        foreach (array_diff($ruta->methods(), ['HEAD']) as $verbo) {
            $vivas[] = $verbo.' '.$ruta->uri();
        }
    }

    expect(array_values(array_intersect($retiradas, $vivas)))->toBe([]);
});

test('el primer mensaje abre la conversacion, que es lo que hacia chats/create', function () {
    /*
     * LA ÚNICA DE LAS 31 QUE DABA MIEDO.
     *
     * `POST v1/chats/create` era el único endpoint que creaba una
     * conversación. Retirarlo parece dejar el chat sin puerta de entrada, y
     * eso sería romper una función viva por un informe.
     *
     * No lo es, y el motivo explica por qué ningún cliente la llamó nunca:
     * `send-message` busca el chat privado entre las dos personas y LO CREA
     * si no existe —con su bloqueo de filas para que dos mensajes
     * simultáneos no abran dos chats—. `create` sobraba desde el principio.
     *
     * Esto no estaba fijado por ninguna prueba: las de `AvisoDeMensajeTest`
     * mandan mensajes pero miran el aviso, no si la conversación nació. O
     * sea que el día que alguien «simplificara» `send-message` exigiendo un
     * `chat_id`, el chat se quedaría sin forma de empezar y ninguna prueba
     * se pondría roja.
     */
    foreach ([1 => 'comprador', 2 => 'tendero'] as $id => $nombre) {
        Rol::firstOrCreate(['rol_id' => $id], ['name' => $nombre, 'guard_name' => 'web']);
    }

    $comprador = User::factory()->create(['rol' => 1]);
    $tendero = User::factory()->create(['rol' => 2]);

    expect(Chat::count())->toBe(0);

    Sanctum::actingAs($comprador);

    $r = $this->postJson('/v1/chats/send-message', [
        'user_id' => $comprador->user_id,
        'recipient_id' => $tendero->user_id,
        'content' => '¿Tienes leche deslactosada?',
    ])->assertCreated();

    // La conversación existe y la respuesta dice cuál es, que es lo que la
    // app necesita para seguir leyéndola.
    expect(Chat::count())->toBe(1);
    expect($r->json('chat_id'))->toBe(Chat::first()->chat_id);

    /*
     * Y EL SEGUNDO MENSAJE NO ABRE OTRA. Es la mitad que de verdad hace falta
     * probar: una creación «si no existe» que no encuentre el chat anterior
     * deja una conversación nueva por mensaje, y el síntoma no es un error,
     * es un historial que se parte.
     */
    $this->postJson('/v1/chats/send-message', [
        'user_id' => $comprador->user_id,
        'recipient_id' => $tendero->user_id,
        'content' => 'Sí, de la que sea',
    ])->assertCreated();

    expect(Chat::count())->toBe(1);
});

test('de las categorias se puede seguir leyendo la lista, que es lo que pide la app', function () {
    /*
     * `GET v1/categories/show` traía UNA categoría y se retiró; las cuatro
     * escrituras de `categories-business` también. Lo que la app usa de
     * verdad es la lista por tipo de negocio —se la trae entera para pintar
     * el formulario de un producto—, y eso sigue en pie.
     *
     * Va acá porque el grupo `admin` de `categories-business` quedó vacío al
     * retirar sus cuatro rutas y se borró: un grupo de middleware sin rutas
     * dentro no protege nada, pero borrarlo es tocar el archivo de rutas y
     * conviene que algo compruebe que la lectura sobrevivió.
     */
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);
    Sanctum::actingAs(User::factory()->create(['rol' => 1]));

    $this->getJson('/v1/categories-business/index')->assertOk();
});
