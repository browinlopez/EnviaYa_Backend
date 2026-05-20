<?php

namespace App\Services;

use App\Models\Business;
use Illuminate\Support\Facades\DB;
use App\Traits\ValidateVerificationDigit;
use Illuminate\Validation\ValidationException;

class BusinessService
{
    use ValidateVerificationDigit;

    /**
     * Store a new business.
     */
    public function store(array $data, $logoFile = null): Business
    {
        // 1. Validación de Dígito de Verificación (Persona Jurídica)
        $typeOrg = $data['type_organization_id'] ?? null;
        $nit = $data['identification_number'] ?? null;
        $dv = $data['verification_digit'] ?? null;

        if ($typeOrg == 1) {
            if (is_null($dv) || $dv === '') {
                throw ValidationException::withMessages([
                    'verification_digit' => ['El dígito de verificación es obligatorio para personas jurídicas.']
                ]);
            }

            $correctDv = $this->ValidateVerificationDigit($nit);
            if ($correctDv !== false && $correctDv != $dv) {
                throw ValidationException::withMessages([
                    'verification_digit' => ["El dígito de verificación es incorrecto. El correcto debe ser: {$correctDv}."]
                ]);
            }
        }

        // 2. Subir logo si viene como archivo (flujo Web Admin)
        if ($logoFile) {
            $filename = time() . '_' . $logoFile->getClientOriginalName();
            $logoFile->move(public_path('Negocios'), $filename);
            $data['logo'] = url('Negocios/' . $filename);
        }

        // 3. Crear el negocio en una transacción
        return DB::transaction(function () use ($data) {
            $business = Business::create([
                'name' => $data['name'],
                'phone' => $data['phone'] ?? null,
                'address' => $data['address'] ?? null,
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'municipality_id' => $data['municipality_id'] ?? null,
                'identification_number' => $data['identification_number'] ?? null,
                'verification_digit' => $data['verification_digit'] ?? null,
                'legal_name' => $data['legal_name'] ?? null,
                'type_organization_id' => $data['type_organization_id'] ?? null,
                'logo' => $data['logo'] ?? null,
                'category_business_id' => $data['category_business_id'],
                'state' => isset($data['state']) ? (bool) $data['state'] : true
            ]);

            // Sincronizar dueños y domiciliarios si están presentes
            if (isset($data['products'])) {
                $business->products()->sync($data['products']);
            }
            if (isset($data['domiciliaries'])) {
                $business->domiciliaries()->sync($data['domiciliaries']);
            }

            return $business;
        });
    }

    /**
     * Update an existing business.
     */
    public function update(Business $business, array $data, $logoFile = null): Business
    {
        // 1. Validación de Dígito de Verificación (Persona Jurídica)
        $typeOrg = $data['type_organization_id'] ?? $business->type_organization_id;
        $nit = $data['identification_number'] ?? $business->identification_number;
        $dv = $data['verification_digit'] ?? $business->verification_digit;

        if ($typeOrg == 1) {
            if (is_null($dv) || $dv === '') {
                throw ValidationException::withMessages([
                    'verification_digit' => ['El dígito de verificación es obligatorio para personas jurídicas.']
                ]);
            }

            $correctDv = $this->ValidateVerificationDigit($nit);
            if ($correctDv !== false && $correctDv != $dv) {
                throw ValidationException::withMessages([
                    'verification_digit' => ["El dígito de verificación es incorrecto. El correcto debe ser: {$correctDv}."]
                ]);
            }
        }

        // 2. Subir nuevo logo si viene como archivo (flujo Web Admin)
        if ($logoFile) {
            if ($business->logo) {
                $oldPath = public_path(str_replace(url('/') . '/', '', $business->logo));
                if (file_exists($oldPath)) {
                    unlink($oldPath);
                }
            }

            $filename = time() . '_' . $logoFile->getClientOriginalName();
            $logoFile->move(public_path('Negocios'), $filename);
            $data['logo'] = url('Negocios/' . $filename);
        }

        // 3. Actualizar en transacción
        return DB::transaction(function () use ($business, $data) {
            $business->update($data);

            if (isset($data['products'])) {
                $business->products()->sync($data['products']);
            }
            if (isset($data['domiciliaries'])) {
                $business->domiciliaries()->sync($data['domiciliaries']);
            }
            if (isset($data['owner_ids'])) {
                $business->owners()->sync($data['owner_ids']);
            }

            return $business;
        });
    }

    /**
     * Delete a business.
     */
    public function destroy(Business $business): void
    {
        DB::transaction(function () use ($business) {
            if ($business->logo) {
                $path = public_path(str_replace(url('/') . '/', '', $business->logo));
                if (file_exists($path)) {
                    unlink($path);
                }
            }
            $business->delete();
        });
    }
}
