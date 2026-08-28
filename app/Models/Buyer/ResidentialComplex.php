<?php

namespace App\Models\Buyer;

use App\Models\Audit\Audit;

class ResidentialComplex extends Audit
{
    protected $table = 'residential_complexes';
    protected $primaryKey = 'complex_id';
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
        'address',
        'state',
        'people_count',
        /*
         * Faltaban las tres primeras y el CRUD del panel no lo notaba porque
         * usa Query Builder. Cualquier `create()` las habría descartado en
         * silencio, que es de los fallos más difíciles de ver.
         */
        'latitude',
        'longitude',
        'municipality_id',
        'towers_count',
        'apartments_per_tower',
    ];

    public function buyers()
    {
        return $this->hasMany(BuyerComplex::class, 'complex_id', 'complex_id');
    }
}
