<?php

namespace App\Http\Controllers\Negocio;

use App\Http\Controllers\Controller;
use App\Models\Domiciliary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * LA TIENDA DA DE ALTA A SUS DOMICILIARIOS.
 *
 * Antes lo hacía solo el equipo. Ahora la tienda puede:
 *
 *  · VINCULAR a alguien que ya reparte en VeciPa'Ya (lo busca por teléfono,
 *    correo o cédula). No se le toca nada más: sus papeles y su acuerdo son
 *    los que ya tiene.
 *  · CREAR uno nuevo: cuenta de domiciliario, vinculada SOLO a esta tienda y
 *    «fuera de turno». Queda marcado con `registrado_por_negocio`, y por eso
 *    no recibe pedidos hasta firmar el acuerdo y tener cédula, licencia y
 *    SOAT (ver PapelesDelDomiciliario y ReglasDeDespacho).
 *
 * Lo que NO hace: convertir en domiciliario la cuenta de un comprador. Esa
 * persona usa la app para comprar; cambiarle el rol desde una tienda le
 * cambiaría la app que ve. Si el correo ya es de alguien que no reparte, se
 * dice y se pide otro.
 */
class AltaDeDomiciliarioController extends Controller
{
    /**
     * Busca a un domiciliario que ya exista, por teléfono, correo o cédula.
     * Solo coincidencias exactas: esto no es un directorio para curiosear.
     */
    public function buscar(Request $request)
    {
        $negocioId = (int) $request->attributes->get('busines_id');
        $q = trim((string) $request->query('q', ''));

        if (mb_strlen($q) < 6) {
            return response()->json(['data' => null]);
        }

        $d = $this->encontrar($q);

        if (!$d) {
            return response()->json(['data' => null]);
        }

        $yaEsTuyo = DB::table('business_domiciliary')
            ->where('busines_id', $negocioId)
            ->where('domiciliary_id', $d->domiciliary_id)
            ->where('state', 1)
            ->exists();

        return response()->json(['data' => [
            'domiciliary_id' => (int) $d->domiciliary_id,
            'name'           => $d->user?->name,
            'phone'          => $d->user?->phone,
            'ya_es_tuyo'     => $yaEsTuyo,
        ]]);
    }

    /**
     * Vincula uno existente (`domiciliary_id`) o crea uno nuevo con sus datos.
     */
    public function guardar(Request $request)
    {
        $negocioId = (int) $request->attributes->get('busines_id');

        if ($request->filled('domiciliary_id')) {
            $request->validate([
                'domiciliary_id' => 'integer|exists:domiciliary,domiciliary_id',
                'q'              => 'required|string|min:6|max:255',
            ], [
                'q.required' => 'Búscalo primero por su cédula, teléfono o correo.',
            ]);
            $id = (int) $request->input('domiciliary_id');

            /*
             * Sólo se vincula a quien la tienda ENCONTRÓ con un dato exacto
             * suyo. Con el id a secas bastaba recorrer 1, 2, 3… para quedar
             * vinculado a toda la flota de la plataforma, y el vínculo es lo
             * que abre la ficha, los papeles y el efectivo del domiciliario.
             */
            $hallado = $this->encontrar(trim((string) $request->input('q')));

            if (!$hallado || (int) $hallado->domiciliary_id !== $id) {
                return response()->json([
                    'message' => 'Ese dato no corresponde a ese domiciliario. Búscalo de nuevo por su cédula, teléfono o correo.',
                ], 422);
            }

            $this->vincular($negocioId, $id);

            return response()->json(['message' => 'Quedó vinculado a tu tienda.', 'domiciliary_id' => $id], 201);
        }

        $datos = $request->validate([
            'name'     => 'required|string|max:255',
            'document' => 'required|string|max:20',
            'phone'    => 'required|string|max:20',
            'email'    => 'required|email|max:255|unique:user,email',
            'password' => 'required|string|min:8|max:100',
        ], [
            'email.unique'    => 'Ese correo ya tiene una cuenta. Si es un domiciliario, búscalo para vincularlo.',
            'password.min'    => 'La contraseña inicial debe tener al menos 8 caracteres.',
            'document.required' => 'Falta el número de cédula.',
        ]);

        $documento = preg_replace('/\D/', '', $datos['document']);
        $telefono = preg_replace('/\D/', '', $datos['phone']);

        if (Domiciliary::where('document', $documento)->exists()) {
            return response()->json([
                'message' => 'Ya hay un domiciliario con esa cédula. Búscalo para vincularlo a tu tienda.',
                'errors'  => ['document' => ['Ya hay un domiciliario con esa cédula.']],
            ], 422);
        }

        $id = DB::transaction(function () use ($datos, $documento, $telefono, $negocioId) {
            $userId = DB::table('user')->insertGetId([
                'name'              => trim($datos['name']),
                'email'             => mb_strtolower(trim($datos['email'])),
                'password'          => Hash::make($datos['password']),
                'phone'             => $telefono,
                'rol'               => 3,
                'state'             => 1,
                'qualification'     => 0,
                // Verificado: lo da de alta una tienda que lo conoce, y sin esto
                // el login lo rechaza y no hay bandeja donde confirmar nada.
                'email_verified_at' => now(),
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);

            $domiId = DB::table('domiciliary')->insertGetId([
                'user_id'                => $userId,
                'document'               => $documento,
                'available'              => 0,
                'qualification'          => 0,
                'state'                  => 1,
                'registrado_por_negocio' => $negocioId,
            ], 'domiciliary_id');

            $this->vincular($negocioId, $domiId);

            return $domiId;
        });

        return response()->json([
            'message'        => 'Domiciliario creado. Ahora falta el acuerdo y sus papeles.',
            'domiciliary_id' => $id,
        ], 201);
    }

    /** Coincidencia EXACTA por correo, teléfono o cédula; nunca parcial. */
    private function encontrar(string $q): ?Domiciliary
    {
        if (mb_strlen($q) < 6) {
            return null;
        }

        $soloDigitos = preg_replace('/\D/', '', $q);

        return Domiciliary::with('user')
            ->where(function ($w) use ($q, $soloDigitos) {
                $w->whereHas('user', fn ($u) => $u->where('email', $q))
                    ->when($soloDigitos !== '', fn ($x) => $x
                        ->orWhereHas('user', fn ($u) => $u->where('phone', $soloDigitos))
                        ->orWhere('document', $soloDigitos));
            })
            ->first();
    }

    private function vincular(int $negocioId, int $domiciliarioId): void
    {
        $existe = DB::table('business_domiciliary')
            ->where('busines_id', $negocioId)
            ->where('domiciliary_id', $domiciliarioId);

        if ($existe->exists()) {
            $existe->update(['state' => 1]);
            return;
        }

        DB::table('business_domiciliary')->insert([
            'busines_id'     => $negocioId,
            'domiciliary_id' => $domiciliarioId,
            'state'          => 1,
        ]);
    }
}
