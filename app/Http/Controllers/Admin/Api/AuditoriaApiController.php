<?php

namespace App\Http\Controllers\Admin\Api;

use App\Services\Ajustes;
use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\Conjunto\ComplexStaff;
use App\Models\Operacion\DomiciliaryDocument;
use App\Models\Reviews\BusinessReview;
use App\Models\Reviews\DomiciliaryReview;
use App\Models\User;
use App\Services\BusinessMediaService;
use App\Services\ContratoService;
use App\Services\MediaService;
use App\Services\VinculosDelRol;
use App\Support\ListadoPaginado;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AuditoriaApiController extends Controller
{

    public function audits(Request $request)
    {
        $q = DB::table('audits as a')
            ->leftJoin('user as u', 'u.user_id', '=', 'a.user_id')
            ->select([
                'a.id', 'a.event', 'a.auditable_type', 'a.auditable_id',
                'a.old_values', 'a.new_values', 'a.url', 'a.ip_address', 'a.created_at',
                'u.name as user_name', 'u.email as user_email',
            ]);

        if ($evento = trim((string) $request->query('event', ''))) {
            $q->where('a.event', $evento);
        }

        if ($dias = (int) $request->query('days')) {
            $q->where('a.created_at', '>=', Carbon::now()->subDays($dias));
        }

        return response()->json(ListadoPaginado::responder(
            $request,
            $q,
            buscables: ['u.name', 'u.email', 'a.auditable_type', 'a.url'],
            ordenables: [
                'id'         => 'a.id',
                'event'      => 'a.event',
                'created_at' => 'a.created_at',
                'user_name'  => 'u.name',
            ],
            ordenPorDefecto: 'id',
            resumen: fn ($f) => $this->resumenDeAuditoria($f),
        ));
    }

    private function resumenDeAuditoria($q): array
    {
        $r = ListadoPaginado::soloAgregados($q, "
            COUNT(*) as total,
            SUM(CASE WHEN a.event = 'created' THEN 1 ELSE 0 END) as creados,
            SUM(CASE WHEN a.event = 'updated' THEN 1 ELSE 0 END) as actualizados,
            SUM(CASE WHEN a.event = 'deleted' THEN 1 ELSE 0 END) as borrados,
            COUNT(DISTINCT a.user_id) as personas
        ");

        return [
            'total'        => (int) ($r->total ?? 0),
            'creados'      => (int) ($r->creados ?? 0),
            'actualizados' => (int) ($r->actualizados ?? 0),
            'borrados'     => (int) ($r->borrados ?? 0),
            'personas'     => (int) ($r->personas ?? 0),
        ];
    }

    /* ==================================================================
       BLOQUES DEL PANEL DE INICIO, POR ÁREA

       El panel dejó de ser el mismo para todos. Dos reglas, y en este orden:

       1. QUÉ se ve lo decide el permiso, no el área. Cada bloque va detrás del
          módulo que lo alimenta, así que nadie recibe en el inicio un dato que
          no podría abrir en su sección. Que sea el mismo permiso evita que
          aparezca una tercera regla capaz de discrepar de las otras dos.

       2. QUÉ VA PRIMERO lo decide el área. SST puede ver los pedidos, pero lo
          que necesita al entrar es quién está rodando sin SOAT. Un panel que
          empieza con los ingresos obliga a bajar todos los días hasta lo suyo.
       ================================================================== */
}
