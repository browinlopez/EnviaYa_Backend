<?php

declare(strict_types=1);

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Concerns\ComprobarPertenencia;
use App\Http\Controllers\Controller;
use App\Http\Requests\Business\ActualizarNegocioRequest;
use App\Http\Requests\Business\CrearNegocioRequest;
use App\Models\Business;
use Illuminate\Support\Facades\DB;

class BusinessController extends Controller
{
    use ComprobarPertenencia;

    // Crear negocio con transacción
    public function store(CrearNegocioRequest $request)
    {

        DB::beginTransaction();

        try {
            $business = Business::create([
                'name' => $request->name,
                'phone' => $request->phone,
                'address' => $request->address,
                'latitude' => $request->latitude,
                'longitude' => $request->longitude,
                'municipality_id' => $request->municipality_id,
                'NIT' => $request->NIT,
                'razonSocial_DCD' => $request->razonSocial_DCD,
                'logo' => $request->logo,
                'type' => $request->type,
                'state' => true,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Negocio creado correctamente',
                'business' => $business->load('municipality'),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Error al crear el negocio',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // Actualizar negocio
    public function update(ActualizarNegocioRequest $request)
    {

        /*
         * Cambiar el nombre, el NIT o la razon social de OTRA tienda.
         * `busines_id` venia en el cuerpo y no se miraba de quien era.
         */
        if ($no = $this->negarNegocioAjeno($request, $request->busines_id)) {
            return $no;
        }

        DB::beginTransaction();

        try {
            $business = Business::with('owners')->findOrFail($request->busines_id);

            $business->update($request->only([
                'name',
                'phone',
                'address',
                'municipality_id',
                'NIT',
                'razonSocial_DCD',
                'logo',
                'type',
                'state',
            ]));

            if ($request->filled('owner_ids')) {
                $business->owners()->sync($request->owner_ids);
            }

            DB::commit();

            return response()->json([
                'message' => 'Negocio actualizado correctamente',
                'business' => $business->load('owners', 'products', 'reviews', 'municipality'),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Error al actualizar el negocio',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
