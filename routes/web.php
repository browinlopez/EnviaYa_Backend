<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\VerificacionDeCorreoController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| RUTAS WEB
|--------------------------------------------------------------------------
|
| Acá queda MUY poco a propósito. La administración vive entera en el panel de
| React contra `/v1/admin/*`, y la aplicación móvil contra el resto del API.
| Lo que sobrevive en el servidor web son tres cosas que necesitan una página
| de verdad: la portada pública, la verificación de correo y la recuperación de
| contraseña (el enlace del correo tiene que abrir en algún sitio).
|
| POR QUÉ SE RETIRÓ EL PANEL ANTERIOR EN BLADE
|
| Había un segundo administrador completo colgado de acá —negocios,
| domiciliarios, categorías, conjuntos, productos, propietarios y reportes— y
| su único guardia era `auth`. Ni rol ni módulo ni área: toda la autorización
| que se construyó (`admin` + `modulo:<clave>` + nivel de acceso) protege
| `/v1/admin/*` y no llegaba hasta acá.
|
| El login web autentica con `Auth::attempt` contra la tabla `user` sin filtrar
| por rol, y en esa tabla están también los compradores y los domiciliarios. El
| resultado era que cualquier comprador, con su propio correo y su clave, podía
| entrar a `/admin/negocios`, editar y borrar registros, y descargarse el
| reporte financiero de la plataforma.
|
| No se le puso el middleware que le faltaba: se retiró. Todo lo que hacía está
| en el panel nuevo, así que mantener dos administradores en paralelo era
| duplicar el trabajo de cada cambio y dejar abierta una puerta que nadie mira.
| Los libros de Excel, que era lo único que solo existía acá, se movieron a
| `/v1/admin/reports/{kind}/excel`, detrás de `modulo:reportes`.
|
| Los controladores (`App\Http\Controllers\Admin\*`, sin contar `Admin\Api`) y
| las vistas (`resources/views/admin`) se borraron en el mismo cambio.
*/

/*
 * LA RAÍZ REDIRIGE AL SITIO PÚBLICO.
 *
 * Acá vivía la landing vieja en Blade, la de la plantilla comprada. Ya no:
 * el sitio público es `EnviaYa_Landing`, un proyecto aparte que se despliega
 * solo. Mantener dos landings significaba que cambiar un texto obligaba a
 * acordarse de las dos, y la de acá se quedó atrás hace meses.
 *
 * Se redirige en vez de devolver 404 porque quien llega a la raíz del API por
 * un enlace viejo o escribiendo el dominio a mano debería acabar donde está el
 * contenido, no en una página de error.
 */
Route::get('/', function () {
    return redirect()->away(config('services.sitio.url'));
});

// --- Verificación de correo de la app móvil (token propio, no el firmado de
//     Laravel: la app abre este enlace desde el correo). ---
Route::get('/verify-email', [VerificacionDeCorreoController::class, 'verify'])
    ->name('verify.email');

/*
 * `dashboard` sobrevive solo como señal, sin vista ni controlador.
 *
 * Aquí estaba la portada del panel viejo, y la usan como destino todos los
 * controladores de sesión de Breeze (`redirect()->intended(route('dashboard'))`)
 * y su barra de navegación. Borrar el nombre habría hecho estallar el login con
 * un "route not found" en vez de arreglar nada.
 *
 * Así que se queda apuntando a donde de verdad está la administración: quien
 * llegue buscándola acaba en el panel.
 */
Route::get('/dashboard', fn () => redirect(config('app.panel_url') ?: '/'))
    ->middleware('auth')
    ->name('dashboard');

Route::middleware('auth')->group(function () {
    /*
     * La cuenta PROPIA y nada más.
     *
     * Es lo único que queda detrás de una sesión web. No hay administración
     * acá: un usuario puede ver y cambiar sus datos, que son los suyos.
     */
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    /*
     * LA VERIFICACIÓN NATIVA DE LARAVEL VIVE EN `auth.php`, NO AQUÍ.
     *
     * Estas tres rutas estaban declaradas en los dos archivos, y `web.php`
     * carga `auth.php` al final: el nombre `verification.send` resolvía a la
     * versión de allá —`EmailVerificationNotificationController`— mientras que
     * el POST lo atendía la de aquí, `AuthController::resendVerificationEmail`.
     * Generar la URL por el nombre y llamarla llevaban a controladores
     * distintos.
     *
     * Se quedan las de `auth.php`, que es el archivo de Breeze y donde alguien
     * las va a buscar. Nada del proyecto depende de esta copia: la
     * verificación que se usa de verdad es la de abajo, por token propio, y el
     * reenvío desde la app va por `/v1/resend-verification-email`.
     */
});

require __DIR__ . '/auth.php';
