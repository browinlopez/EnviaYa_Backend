<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Conjunto\SolicitudDeEntrada;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// Sin mayúsculas ni espacios: el celador escribe «a» y el residente guardó «A».
class AutorizacionDelResidente
{
    /** @return Collection<int, int> ids de usuario */
    public function residentesDe(int $complexId, string $torre, string $apto): Collection
    {
        return DB::table('user_address as ua')
            ->join('user as u', 'u.user_id', '=', 'ua.user_id')
            ->where('ua.complex_id', $complexId)
            ->where('ua.state', 1)
            ->where('u.rol', 1)
            ->where('u.state', 1)
            ->whereRaw('LOWER(TRIM(ua.tower)) = ?', [mb_strtolower(trim($torre))])
            ->whereRaw('LOWER(TRIM(ua.apartment)) = ?', [mb_strtolower(trim($apto))])
            ->distinct()
            ->pluck('ua.user_id')
            ->map(fn ($id) => (int) $id);
    }

    /** ¿Vive esta persona en el apartamento de la solicitud? */
    public function esResidente(int $userId, SolicitudDeEntrada $s): bool
    {
        return $this->residentesDe($s->complex_id, $s->tower, $s->apartment)->contains($userId);
    }
}
