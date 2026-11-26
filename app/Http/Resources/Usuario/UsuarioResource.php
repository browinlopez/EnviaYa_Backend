<?php

declare(strict_types=1);

namespace App\Http\Resources\Usuario;

use App\Models\Conjunto\ComplexStaff;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

/**
 * EL USUARIO, TAL COMO LO DEVUELVEN EL LOGIN Y EL PERFIL.
 *
 * Seis sitios devolvian `$user` crudo —el login, el acceso biometrico, el
 * perfil, dos del alta de domiciliarios y la correccion del propio perfil— y
 * eso significa que la respuesta era «las columnas que tenga la tabla `user`».
 * Una migracion que anadiera un campo lo convertia en campo publico de la API
 * sin que nadie lo decidiera.
 *
 * Es la entidad mas sensible del sistema, asi que la lista va escrita.
 *
 * QUE PROTEGE `$hidden` Y QUE NO. El modelo oculta `password`,
 * `remember_token`, los dos de dos factores y `area` —esa ultima se añadio el
 * 11 de noviembre para que la relacion del panel no se publicara sola—. Lo
 * que NO oculta y por tanto viaja hoy:
 *
 *   email_verification_token        un token
 *   email_verification_expires_at
 *   policy_ip                       la IP desde donde acepto la politica
 *   area_id, access_level           campos internos del panel
 *
 * En cinco de los seis sitios el usuario es EL PROPIO, asi que su token y su
 * IP no son una fuga: son sus datos. En el sexto —crear un domiciliario— el
 * usuario es el recien creado y lo recibe quien lo crea, que puede verificarle
 * el correo en su nombre. Eso puede estar bien (lo acaba de dar de alta) o no,
 * y por eso se deja escrito en vez de decidido.
 *
 * SE CONSERVAN LAS VEINTE CLAVES, incluida la del token: quitarla cambia lo
 * que reciben dos apps publicadas en las tiendas. Lo que ya no puede pasar es
 * que la lista crezca sola.
 *
 * LAS RELACIONES SE PASAN TAL CUAL, y esto no es un detalle: el login carga
 * `buyer`, `owner.businesses` o `domiciliary.businesses` segun el rol —la app
 * las necesita para saber a que pantalla llevar a la persona— y una lista
 * blanca que solo dejara columnas las habria TIRADO, rompiendo el arranque de
 * la app en las tres. Por eso los tres nombres estan en la lista.
 *
 * Y LAS TRES RELACIONES YA NO VIAJAN ENTERAS. Cada una pasa por su propio
 * Resource —`CompradorResource`, `PropietarioResource`,
 * `DomiciliarioResource`— y los negocios que cuelgan de las dos ultimas por
 * `NegocioVinculadoResource`. Eso cierra lo que esta clase dejo abierto en su
 * primera version: una columna nueva en cualquiera de esas tablas se publicaba
 * sola en el login.
 *
 * En `NegocioVinculadoResource` esta el hallazgo que salio de mirarlas: el
 * tope de credito de la tienda viaja al tendero Y al domiciliario, teniendo
 * escrito en el propio repositorio que «el panel del tendero no lo recibe».
 */
class UsuarioResource extends JsonResource
{
    /**
     * Las veinte columnas y las tres relaciones que esta respuesta publica.
     *
     * Sale de la respuesta REAL de `GET /v1/profile`, no del esquema: lo que
     * hay que conservar es lo que las apps reciben hoy.
     */
    private const CAMPOS = [
        'user_id',
        'name',
        'email',
        'email_verified_at',
        'email_verified_by',
        'email_verification_token',
        'email_verification_expires_at',
        'two_factor_confirmed_at',
        'phone',
        'address',
        'rol',
        'area_id',
        'access_level',
        'qualification',
        'state',
        'created_at',
        'updated_at',
        'policy_accepted_at',
        'policy_version',
        'policy_ip',
        // Las que carga el login segun el rol. Sin estas tres, la app no sabe
        // a que pantalla llevar a la persona.
        'buyer',
        'owner',
        'domiciliary',
    ];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /*
         * `Arr::only` sobre la serializacion del modelo y no los campos a
         * mano: el `toArray()` del modelo aplica sus `casts` y serializa las
         * fechas como las serializa Eloquent, y las apps leen ESE formato.
         * Escribir `'created_at' => $u->created_at` cambiaria el formato.
         *
         * Y conserva solo las relaciones que de verdad esten cargadas, porque
         * `toArray()` no mete las que no lo estan: el usuario del panel —rol
         * 4— sale sin ninguna de las tres, igual que antes.
         */
        $u = $this->resource;
        $datos = Arr::only($u->toArray(), self::CAMPOS);

        /*
         * Añadida a propósito el 26 de noviembre, y a mano porque el modelo
         * la oculta (para que no salga en cada usuario serializado): el panel
         * de aliados la lee para llevar al celador a «Crea tu clave».
         *
         * Solo para el personal de un conjunto (roles 5 y 6): a los demás
         * no les aplica y su respuesta no cambia —las apps publicadas leen
         * exactamente las claves de siempre—.
         */
        if (in_array((int) $u->rol, ComplexStaff::ROLES_DE_USUARIO, true)) {
            $datos['must_change_password'] = (bool) $u->must_change_password;
        }

        /*
         * LAS TRES RELACIONES PASAN POR SU PROPIO RESOURCE, que es lo que
         * cierra el agujero que esta clase dejaba abierto: hasta ahora se
         * serializaban enteras, asi que una columna nueva en `buyer`, `owner`
         * o `domiciliary` —o en los `businesses` que cuelgan de las dos
         * ultimas— se publicaba sola en el login.
         *
         * Solo las que de verdad esten cargadas. El login carga una segun el
         * rol y el usuario del panel ninguna; tocar una relacion no cargada
         * disparia una consulta por cada usuario de la respuesta.
         */
        foreach ([
            'buyer' => CompradorResource::class,
            'owner' => PropietarioResource::class,
            'domiciliary' => DomiciliarioResource::class,
        ] as $relacion => $recurso) {
            if (! $u->relationLoaded($relacion) || $u->{$relacion} === null) {
                continue;
            }

            $datos[$relacion] = (new $recurso($u->{$relacion}))->resolve();
        }

        return $datos;
    }
}
