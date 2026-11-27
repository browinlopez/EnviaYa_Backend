<?php

declare(strict_types=1);

namespace App\Models\Owner;

use App\Models\Business;
use App\Models\DocumentType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class Owner extends Authenticatable implements AuditableContract
{
    use Auditable, HasApiTokens, HasFactory;

    protected $table = 'owner';

    protected $primaryKey = 'owner_id';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'state',
        'profile_photo',
        'document_type',
        'document_number',
        'birthdate',
        'contact_secondary',
        'notes',
    ];

    protected $hidden = [
        'password',
    ];

    // Relación con negocios
    /** @return BelongsToMany<Business, $this> */
    public function businesses(): BelongsToMany
    {
        return $this->belongsToMany(
            Business::class,
            'owner_busines',
            'owner_id',
            'busines_id'
        )->withPivot('state')
            /*
             * Por nombre, como `NegocioDelUsuario::negociosDe()`. Sin orden,
             * la app tomaba `businesses[0]` en el orden de la tabla mientras
             * las rutas `/negocio/*` sin cabecera elegían el primero por
             * nombre: con dos locales, una pantalla mostraba uno y otra
             * actuaba sobre el otro. Las versiones de la app que aún no
             * mandan `X-Negocio` quedan así de acuerdo con el servidor.
             */
            ->orderBy('business.name');
    }

    // Relación con tabla user
    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    /** @return BelongsTo<DocumentType, $this> */
    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    /*
     * AQUÍ HABÍA UN `getProfilePhotoUrlAttribute()` Y NADIE LO LLAMABA.
     *
     * El gemelo del que había en `Product`: miraba el disco `public` del
     * contenedor para una foto que vive en R2, así que su condición era
     * siempre falsa, y la rama que sí hacía algo usaba `Storage::url()` sobre
     * el disco por defecto —`local`—, que no es el que estaba comprobando.
     *
     * No está en `$appends` ni se usa en ningún sitio del repositorio.
     */
}
