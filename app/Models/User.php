<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Buyer\Buyer;
use App\Models\Owner\Owner;
use App\Models\Reviews\BusinessReview;
use App\Models\Reviews\UserReview;
use App\Models\User\UserAddress;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use OwenIt\Auditing\Contracts\Auditable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements Auditable, MustVerifyEmail
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable, \OwenIt\Auditing\Auditable;

    protected $table = 'user';

    protected $primaryKey = 'user_id';

    /*
     * Ahora sí lleva `created_at` / `updated_at`.
     *
     * Estaba en `false` porque la tabla no tenía las columnas, y sin ellas no
     * se podía responder desde cuándo existe cada registro — que es la mitad
     * de lo que pregunta cualquier reporte de crecimiento.
     *
     * La base también las rellena por su cuenta (`DEFAULT CURRENT_TIMESTAMP`),
     * porque el proyecto inserta tanto por Eloquent como por el constructor de
     * consultas y sólo uno de los dos caminos pasa por aquí.
     */
    public $timestamps = true;

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'address',
        'rol',
        'qualification',
        'state',
        'email_verification_token',
        'email_verified_at',
        'email_verified_by',
        'email_verification_expires_at',
        /*
         * La autorizacion de tratamiento de datos: cuando, que version de la
         * politica y desde que IP. Sin estas tres en `fillable`, Eloquent las
         * descarta EN SILENCIO al crear el usuario y el registro quedaria sin
         * la prueba del consentimiento — que es justo lo unico que hay que
         * poder demostrar.
         */
        'policy_accepted_at',
        'policy_version',
        'policy_ip',
    ];

    /*
     * El secreto del segundo factor NUNCA sale en una respuesta. Con él, quien
     * lo lea puede generar los mismos códigos que el teléfono: el segundo factor
     * dejaría de serlo. Solo se muestra una vez, al darlo de alta, y desde su
     * propio endpoint.
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        /*
         * `area` NO ES UN SECRETO: está oculto para que la respuesta no cambie
         * sola.
         *
         * Eloquent serializa las relaciones que estén cargadas, y `area` la
         * carga el middleware de permisos sobre `$request->user()`. Hoy
         * ninguna ruta hace las dos cosas —pasar por ese middleware y devolver
         * el usuario entero—, así que comprobado: nada cambia. Pero el día que
         * alguien ponga `modulo:` sobre una ruta que devuelva el usuario, le
         * aparecería una clave `area` en el JSON sin haberla pedido, y eso es
         * justo lo que este trabajo prometió no hacer.
         *
         * Quien la necesite la pide por su nombre: `AreasApiController::mios`
         * construye su respuesta campo por campo y `$hidden` no le afecta.
         */
        'area',
    ];

    protected $casts = [
        'state' => 'boolean',
        'email_verified_at' => 'datetime',
        'email_verification_expires_at' => 'datetime',
        /*
         * Fecha y no cadena: es el dato que responde «cuando autorizo esta
         * persona», y compararlo o formatearlo como texto invita justo a los
         * errores que importan en algo que hay que poder demostrar.
         */
        'policy_accepted_at' => 'datetime',
        /*
         * CIFRADOS en la base, no solo ocultos en la respuesta. Ocultarlos
         * protege de una fuga por la API; cifrarlos protege de una fuga de la
         * base, que es la que de verdad importa para un secreto que sirve para
         * suplantar a alguien.
         */
        'two_factor_secret' => 'encrypted',
        'two_factor_recovery_codes' => 'encrypted:array',
        'two_factor_confirmed_at' => 'datetime',
    ];

    /** ¿Tiene el segundo factor activo y comprobado? */
    public function tieneSegundoFactor(): bool
    {
        return $this->two_factor_secret !== null
            && $this->two_factor_confirmed_at !== null;
    }

    // Relaciones
    public function rolRelation()
    {
        return $this->belongsTo(Rol::class, 'rol', 'rol_id');
    }

    /**
     * El área del panel a la que pertenece, si es personal de la empresa.
     *
     * EXISTE PARA QUE SE CONSULTE UNA SOLA VEZ. Medido con la sonda de
     * consultas: `areas` salía DOS veces en `/admin/overview`, porque el
     * middleware `modulo` hacía su `Area::find($user->area_id)` y
     * `ResumenDelPanel::permisosDe()` hacía otro igual unos milisegundos
     * después, sobre la misma fila.
     *
     * Dos, no más: lo comprobé antes de escribirlo así. Los cinco sitios que
     * buscaban el área —tres middlewares de permisos, el resumen del panel y
     * «mis permisos»— nunca se apilan en una misma ruta, así que ninguna
     * petición pagaba tres. Los cinco usan ahora la relación de todos modos,
     * porque el día que `medios` o `categorias` se junten con `modulo` el
     * costo ya no aparece.
     *
     * Eloquent guarda una relación ya cargada en la instancia del modelo, y
     * `$request->user()` devuelve **la misma instancia** a todo el que
     * pregunte durante la petición: el primero que toque `$user->area` paga
     * la consulta y los demás leen lo que él dejó. No hay caché que invalidar
     * ni nada que vaciar entre peticiones, porque la instancia muere con la
     * petición.
     *
     * Con `area_id` nulo —cualquier comprador, tendero o domiciliario—
     * `BelongsTo` devuelve `null` **sin consultar**, así que esto no le cuesta
     * nada a quien no es del panel.
     */
    /**
     * El tipo de retorno esta puesto a proposito, y no por adorno: sin el,
     * Larastan no deduce la propiedad y los cinco sitios que usan `$user->area`
     * salen como «acceso a una propiedad que no existe». Las relaciones
     * vecinas de este modelo no lo llevan porque nadie las lee como
     * propiedad.
     *
     * @return BelongsTo<Area, $this>
     */
    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class, 'area_id');
    }

    public function domiciliary()
    {
        return $this->hasOne(Domiciliary::class, 'user_id', 'user_id');
    }

    public function buyer()
    {
        return $this->hasOne(Buyer::class, 'user_id', 'user_id');
    }

    public function owner()
    {
        return $this->hasOne(Owner::class, 'user_id', 'user_id');
    }

    public function reviewsWritten()
    {
        return $this->hasMany(UserReview::class, 'user_id', 'user_id');
    }

    public function businessReviews()
    {
        return $this->hasMany(BusinessReview::class, 'buyer_id', 'user_id');
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class, 'user_id', 'user_id');
    }

    public function addresses()
    {
        return $this->hasMany(UserAddress::class, 'user_id', 'user_id');
    }

    public function favoriteBusinesses()
    {
        return $this->belongsToMany(Business::class, 'business_user_favorites', 'user_id', 'busines_id');
    }

    public function affiliatedBusinesses()
    {
        return $this->belongsToMany(
            Business::class,
            'business_user_affiliations',
            'user_id',
            'busines_id'
        );
    }

    public function getTypeAttribute()
    {
        return $this->rolRelation?->name;
        // Devuelve: "Buyer", "Owner", "Domiciliary", etc.
    }
}
