<?php

namespace App\Models;

use App\Models\Buyer\Buyer;
use App\Models\Owner\Owner;
use App\Models\Reviews\BusinessReview;
use App\Models\Reviews\UserReview;
use App\Models\User\UserAddress;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Notifications\Notifiable;
use OwenIt\Auditing\Contracts\Auditable;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Contracts\Auth\MustVerifyEmail;

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
