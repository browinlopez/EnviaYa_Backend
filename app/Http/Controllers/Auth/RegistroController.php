<?php

namespace App\Http\Controllers\Auth;

use App\Services\Totp;
use App\Http\Controllers\Controller;
use App\Models\Buyer\Buyer;
use App\Models\Buyer\BuyerComplex;
use App\Models\Buyer\ResidentialComplex;
use App\Models\User\UserAddress;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use App\Mail\VerifyEmailCustomMail;

class RegistroController extends Controller
{
    use AyudasDeAcceso;

    // Registro
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name'               => 'required|string|max:255',
            'email'              => 'required|string|email|unique:user,email',
            'password'           => 'required|string|min:6',
            'phone'              => 'nullable|string|max:20',
            'belongs_to_complex' => 'boolean',
            'complex_id'         => 'nullable|integer|exists:residential_complexes,complex_id',
            /*
             * Dónde vive dentro del conjunto.
             *
             * Se piden en el registro porque es cuando la persona ya declaró
             * su conjunto; pedirlos después obliga a volver a preguntarle por
             * el contexto entero.
             *
             * Texto y no número: hay conjuntos con "Torre A" y apartamentos
             * como "502B".
             */
            'tower'              => 'nullable|string|max:40',
            'apartment'          => 'nullable|string|max:40',
            /*
             * LA AUTORIZACION DE TRATAMIENTO DE DATOS.
             *
             * La Ley 1581 la pide PREVIA, EXPRESA E INFORMADA, y pide poder
             * demostrarla. Aca no se pedia nada: se recogia nombre, telefono,
             * correo, direccion y ubicacion, y se ataba al historial de
             * compras, sin una casilla ni un enlace a la politica.
             *
             * `accepted` y no `boolean`: rechaza el false explicito igual que
             * el campo ausente, que es lo que corresponde a un consentimiento.
             * Mismo criterio que el formulario de la web, que ya lo exigia.
             */
            'consentimiento'     => 'accepted',
            'politica_version'   => 'nullable|string|max:20',
        ], [
            'consentimiento.accepted' =>
                'Falta la autorizacion de tratamiento de datos.',
        ]);

        /*
         * Quien autorizo, cuando y desde donde. Se toma del servidor y no del
         * cliente: una hora que manda el telefono no demuestra nada, porque el
         * telefono la elige. La IP y la version de la politica completan lo que
         * hace falta para responder «que acepto exactamente esta persona».
         */
        $huellaDelConsentimiento = [
            'policy_accepted_at' => now(),
            'policy_version'     => $validated['politica_version'] ?? null,
            'policy_ip'          => $request->ip(),
        ];

        try {
            $usuario = DB::transaction(function () use ($validated, $huellaDelConsentimiento) {

                $user = User::create([
                    'name'     => $validated['name'],
                    'email'    => $validated['email'],
                    'password' => Hash::make($validated['password']),
                    'phone'    => $validated['phone'] ?? null,
                    'rol'      => 1,
                    'state'    => true,
                    'email_verification_token' => Str::random(60),
                    'email_verification_expires_at' => Carbon::now()->addMinutes(60),
                    ...$huellaDelConsentimiento,
                ]);

                $buyer = Buyer::create([
                    'user_id' => $user->user_id,
                    'qualification' => 0.00,
                    'state' => true,
                    /*
                     * ESTA BANDERA NUNCA SE PONÍA.
                     *
                     * Se validaba, se usaba para decidir si crear la fila en
                     * `buyer_complex`… y no se guardaba. Todo comprador que
                     * declaraba vivir en un conjunto quedaba con la bandera en
                     * 0 mientras el pivote decía que sí. Dos fuentes que se
                     * contradicen desde siempre, y `AffiliationController` le
                     * mostraba al tendero la equivocada.
                     */
                    /*
                     * Con `??` y no directo: la regla es `boolean`, no
                     * `required`, asi que si el cliente no manda el campo la
                     * clave NO EXISTE en `$validated` y esto reventaba con
                     * «Undefined array key» — un 500 en el registro, que es la
                     * primera pantalla que toca cualquiera. Se comprobo contra
                     * el servidor de verdad.
                     */
                    'belongs_to_complex' => !empty($validated['belongs_to_complex']) ? 1 : 0,
                ]);

                if (!empty($validated['belongs_to_complex']) && !empty($validated['complex_id'])) {
                    BuyerComplex::create([
                        'buyer_id' => $buyer->buyer_id,
                        'complex_id' => $validated['complex_id'],
                    ]);

                    /*
                     * Y su primera dirección, si dijo torre y apartamento.
                     *
                     * Sin esto, quien se registra declarando su conjunto
                     * tendría que volver a escribirlo todo la primera vez que
                     * pide: el conjunto quedaba anotado en el pivote y su
                     * dirección no existía.
                     *
                     * Hereda las coordenadas del conjunto. Son las buenas
                     * hasta que la edite en el mapa, y bastante mejores que
                     * dejarla sin punto: sin coordenadas el domiciliario no
                     * puede abrir la ruta.
                     */
                    if (!empty($validated['tower']) && !empty($validated['apartment'])) {
                        $conjunto = ResidentialComplex::find($validated['complex_id']);

                        UserAddress::create([
                            'user_id'         => $user->user_id,
                            'address'         => $conjunto?->address ?: ($conjunto?->name ?: 'Conjunto'),
                            'complex_id'      => $validated['complex_id'],
                            'tower'           => $validated['tower'],
                            'apartment'       => $validated['apartment'],
                            'latitude'        => $conjunto?->latitude,
                            'longitude'       => $conjunto?->longitude,
                            'municipality_id' => $conjunto?->municipality_id,
                            'state'           => true,
                        ]);
                    }
                }

                return $user;
            });

            /*
             * EL CORREO SE MANDA FUERA DE LA TRANSACCION.
             *
             * Estaba dentro, y eso ataba la cuenta al SMTP: un tropiezo de
             * Gmail —o los cuatro segundos que tarda en una conexion mala—
             * hacia rodar atras el registro entero y devolvia un 500. La
             * persona se quedaba sin cuenta por algo que no tiene nada que ver
             * con crearla.
             *
             * Y si el envio falla, la cuenta YA EXISTE: se avisa de que el
             * correo no salio, en vez de negar el registro. Reenviarlo es un
             * boton; volver a registrarse con el mismo correo es un 409.
             */
            try {
                $this->sendVerificationEmail($usuario);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('No se pudo enviar la verificacion', [
                    'email' => $usuario->email,
                    'error' => $e->getMessage(),
                ]);

                return response()->json([
                    'message' => 'Tu cuenta quedo creada, pero no pudimos enviarte el correo de verificacion. Pide que te lo reenviemos.',
                    'email_enviado' => false,
                ], 201);
            }

            return response()->json([
                'message' => 'Registro exitoso. Revisa tu correo para verificar tu cuenta.',
                'email_enviado' => true,
            ], 201);
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->errorInfo[1] == 1062) {
                return response()->json([
                    'message' => 'El correo ya está registrado'
                ], 409);
            }

            return response()->json([
                'message' => 'Error al registrar el usuario',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
