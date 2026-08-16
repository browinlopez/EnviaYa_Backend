<?php

namespace App\Models\Marketing;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pieza publicitaria. La imagen vive en `media_files` con entity_type
 * 'banners'; acá solo está lo que decide cuándo y a quién se muestra.
 */
class Banner extends Model
{
    protected $table = 'banners';

    /** Raíz de medios para esta entidad, la misma que valida MediaService. */
    public const ENTIDAD_MEDIOS = 'banners';

    protected $fillable = [
        'campaign_id',
        'title',
        'subtitle',
        'placement',
        'platform',
        'link_type',
        'link_value',
        'priority',
        'starts_at',
        'ends_at',
        'target_municipalities',
        'target_complexes',
        'target_business_categories',
        'target_roles',
        'state',
    ];

    protected $casts = [
        'campaign_id'                => 'integer',
        'priority'                   => 'integer',
        'starts_at'                  => 'date',
        'ends_at'                    => 'date',
        'target_municipalities'      => 'array',
        'target_complexes'           => 'array',
        'target_business_categories' => 'array',
        'target_roles'               => 'array',
        'impressions_count'          => 'integer',
        'clicks_count'               => 'integer',
        'state'                      => 'integer',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(AdCampaign::class, 'campaign_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(BannerEvent::class, 'banner_id');
    }

    /**
     * Banners encendidos cuya campaña está vigente.
     *
     * La vigencia propia del banner es opcional: cuando va nula manda la de la
     * campaña, que ya filtró el `whereHas`. Por eso las condiciones de fecha
     * aceptan NULL en vez de exigir un rango.
     */
    public function scopeEntregable(Builder $q): Builder
    {
        $hoy = now()->toDateString();

        // Calificadas por el mismo motivo que en los demás scopes del módulo:
        // en cuanto esta consulta se combine con un join, `state` deja de ser
        // resoluble por sí solo.
        return $q->where($q->qualifyColumn('state'), 1)
            ->whereHas('campaign', fn (Builder $c) => $c->vigente())
            ->where(fn (Builder $s) => $s
                ->whereNull($s->qualifyColumn('starts_at'))
                ->orWhereDate($s->qualifyColumn('starts_at'), '<=', $hoy))
            ->where(fn (Builder $s) => $s
                ->whereNull($s->qualifyColumn('ends_at'))
                ->orWhereDate($s->qualifyColumn('ends_at'), '>=', $hoy));
    }

    /**
     * ¿Este banner aplica al contexto de quien lo pide?
     *
     * Un `target_*` vacío o nulo significa "sin restricción". Se compara en PHP
     * y no en SQL a propósito: ver la nota de la migración de `banners`.
     */
    public function aplicaA(array $contexto): bool
    {
        $coincide = function (?array $permitidos, $valor): bool {
            if (empty($permitidos)) {
                return true; // sin restricción declarada
            }

            // Con restricción declarada pero sin dato del cliente no se puede
            // afirmar que aplique: se descarta, que es lo prudente cuando el
            // anunciante pagó por un público concreto.
            if ($valor === null || $valor === '') {
                return false;
            }

            return in_array((int) $valor, array_map('intval', $permitidos), true);
        };

        return $coincide($this->target_municipalities, $contexto['municipality_id'] ?? null)
            && $coincide($this->target_complexes, $contexto['complex_id'] ?? null)
            && $coincide($this->target_business_categories, $contexto['business_category_id'] ?? null)
            && $coincide($this->target_roles, $contexto['rol'] ?? null);
    }
}
