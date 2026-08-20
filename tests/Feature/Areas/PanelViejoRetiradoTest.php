<?php

use App\Models\Rol;
use App\Models\User;

/**
 * EL PANEL ANTERIOR EN BLADE, RETIRADO
 *
 * Había un segundo administrador completo colgado de `routes/web.php` cuyo único
 * guardia era `auth`: ni rol, ni módulo, ni área. Y el login web autentica con
 * `Auth::attempt` contra la tabla `user` sin filtrar por rol, donde también
 * están los compradores y los domiciliarios.
 *
 * Es decir: cualquier comprador, con su propio correo y su clave, entraba a
 * `/admin/negocios`, editaba, borraba y se descargaba el reporte financiero de
 * la plataforma. Toda la autorización por área protege `/v1/admin/*` y no
 * llegaba hasta allá.
 *
 * Estas pruebas no comprueban una funcionalidad: comprueban una AUSENCIA. Están
 * para que nadie reponga esas rutas sin darse cuenta de lo que abren, porque
 * volver a colgarlas de `auth` no daría ningún error visible.
 */

function comoComprador(): User
{
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'buyer', 'guard_name' => 'web']);

    return User::factory()->create(['rol' => 1]);
}

it('un comprador con sesión web no alcanza el administrador', function (string $ruta) {
    $this->actingAs(comoComprador());

    // 404 y no 403: la ruta ya no existe. Un 403 significaría que sigue ahí y
    // que lo único que la tapa es un middleware.
    $this->get($ruta)->assertNotFound();
})->with([
    '/admin/negocios',
    '/admin/domiciliarios',
    '/admin/compradores',
    '/admin/conjuntos',
    '/admin/productos',
    '/admin/owners',
    '/admin/category-business',
    '/admin/reportes/financieros',
    // El que más importaba: el libro con los ingresos de toda la plataforma.
    '/admin/reportes/financieros/export',
]);

it('escribir tampoco: no hay dónde', function () {
    $this->actingAs(comoComprador());

    $this->delete('/admin/negocios/1')->assertNotFound();
    $this->put('/admin/domiciliarios/1')->assertNotFound();
    $this->post('/admin/productos/store')->assertNotFound();
});

it('los libros de Excel siguen existiendo, pero pidiendo permiso', function () {
    /*
     * Retirar el panel viejo no podía costar los reportes: son varias hojas de
     * detalle que ya estaban escritas. Se movieron al API del panel nuevo, y ahí
     * el mismo comprador que antes se los bajaba ahora recibe un 403.
     */
    $this->actingAs(comoComprador());

    $this->getJson('/v1/admin/reports/financial/excel')->assertForbidden();
});

it('quien llegue al dashboard viejo acaba en el panel', function () {
    /*
     * El nombre `dashboard` sigue existiendo porque lo usan como destino todos
     * los controladores de sesión de Breeze; borrarlo habría hecho estallar el
     * login con un "route not found". Ahora solo redirige.
     */
    config(['app.panel_url' => 'https://panel.ejemplo.test']);

    $this->actingAs(comoComprador());

    $this->get('/dashboard')->assertRedirect('https://panel.ejemplo.test');
});
