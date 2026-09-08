<?php

namespace App\Http\Controllers\Admin\Api;

use App\Services\AltasYCambiosDeUsuario;
use App\Services\Ajustes;
use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\Conjunto\ComplexStaff;
use App\Models\Operacion\DomiciliaryDocument;
use App\Models\Reviews\BusinessReview;
use App\Models\Reviews\DomiciliaryReview;
use App\Models\User;
use App\Services\BusinessMediaService;
use App\Services\ContratoService;
use App\Services\MediaService;
use App\Services\VinculosDelRol;
use App\Support\ListadoPaginado;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UsuariosApiController extends Controller
{
    public function __construct(private readonly AltasYCambiosDeUsuario $personas)
    {
    }


    public function users(Request $request)
    {
        $q = DB::table('user as u')
            /*
             * QUIEN VERIFICO EL CORREO, no solo si esta verificado.
             *
             * Con el boton de verificar a mano, la insignia verde pasa a cubrir
             * dos hechos distintos: uno comprobado —la persona abrio el enlace
             * que le llego— y otro afirmado por un administrador. Sin esta
             * columna serian indistinguibles, y la diferencia importa el dia
             * que haya que recuperar una contrasena: si el correo estaba mal
             * escrito, la cuenta queda verificada y a la vez inalcanzable.
             */
            ->leftJoin('user as vb', 'vb.user_id', '=', 'u.email_verified_by')
            ->select([
                'u.user_id', 'u.name', 'u.email', 'u.phone', 'u.address', 'u.rol',
                'u.qualification', 'u.state', 'u.email_verified_at',
                'u.email_verified_by', 'vb.name as email_verified_by_name',
            ]);

        if ($rol = (int) $request->query('rol')) {
            $q->where('u.rol', $rol);
        }

        return response()->json(ListadoPaginado::responder(
            $request,
            $q,
            buscables: ['u.name', 'u.email', 'u.phone'],
            ordenables: [
                'user_id'           => 'u.user_id',
                'name'              => 'u.name',
                'rol'               => 'u.rol',
                'state'             => 'u.state',
                'qualification'     => 'u.qualification',
                'email_verified_at' => 'u.email_verified_at',
            ],
            ordenPorDefecto: 'user_id',
            direccionPorDefecto: 'asc',
            resumen: fn ($f) => $this->personas->resumenDeUsuarios($f),
        ));
    }

    /**
     * Da por bueno un correo sin que su duenio abra el enlace.
     *
     * PARA QUE EXISTE: durante las pruebas se dan de alta cuentas desde la app
     * —un domiciliario de prueba, un comprador de prueba— con correos de usar y
     * tirar a los que nunca va a llegar nada. Sin esto quedan bloqueadas al
     * iniciar sesion y hay que entrar a la base a mano.
     *
     * QUE CUESTA: `email_verified_at` deja de significar "esta direccion existe
     * y es suya" para significar "alguien lo dio por bueno". Por eso se guarda
     * QUIEN lo hizo: es lo unico que permite seguir distinguiendo una cosa de
     * la otra, y lo que explica, el dia que esa cuenta no pueda recuperar su
     * contrasena, por que no le llega el correo.
     *
     * Va por Eloquent y no por el constructor de consultas a proposito: `User`
     * es auditable y asi el cambio queda ademas en el registro de auditoria,
     * con fecha y con autor.
     */
    public function verifyUserEmail(Request $request, $id)
    {
        $user = User::find($id);
        abort_if(!$user, 404, 'El usuario no existe.');

        if ($user->email_verified_at) {
            /*
             * No se vuelve a escribir. Si ya lo habia verificado la persona por
             * su correo, sobrescribirlo con un autor manual convertiria una
             * verificacion buena en una dudosa, y eso no se puede deshacer.
             */
            return response()->json([
                'message' => 'Este correo ya estaba verificado.',
            ], 422);
        }

        $user->update([
            'email_verified_at' => now(),
            'email_verified_by' => $request->user()?->user_id,
            // El enlace pendiente se apaga: ya no hay nada que confirmar.
            'email_verification_expires_at' => null,
        ]);

        return response()->json([
            'message' => 'Correo verificado. Ya puede iniciar sesion.',
            'user'    => $this->personas->showUser($id)->getData(),
        ]);
    }

    /* ==================================================================
       NEGOCIOS
       ================================================================== */

    /*
     * El alta, la edicion y la ficha viven en `AltasYCambiosDeUsuario`.
     *
     * Van juntas porque `updateUser` termina devolviendo la ficha, y porque
     * las reglas de que rol puede convivir con que area y que conjunto se
     * tocan entre si. Aca solo queda la puerta HTTP.
     */
    public function storeUser(Request $request)
    {
        return $this->personas->storeUser($request);
    }

    public function updateUser(Request $request, $id)
    {
        return $this->personas->updateUser($request, $id);
    }

    public function showUser($id)
    {
        return $this->personas->showUser($id);
    }

}
