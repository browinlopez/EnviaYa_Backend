<?php

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use RuntimeException;

/**
 * Acuerdo de vinculación del domiciliario.
 *
 * Toma los datos que ya están en la plataforma (nombre y documento de la
 * persona), la firma trazada en el panel y produce el PDF del acuerdo, que
 * queda archivado en R2 como un documento más de esa persona.
 *
 * Se genera PDF y no un .docx editable a propósito: un acuerdo firmado que
 * cualquiera puede reescribir en Word no sirve como respaldo.
 */
class ContratoService
{
    /** Solo PNG: es lo que produce el canvas del panel y no pierde el trazo. */
    private const FIRMA_PREFIJO = 'data:image/png;base64,';

    /** Una firma razonable pesa unos pocos KB; 2 MB es ya un archivo pegado. */
    private const FIRMA_MAX_BYTES = 2 * 1024 * 1024;

    private const MESES = [
        1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
        'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre',
    ];

    public function __construct(private MediaService $medios)
    {
    }

    /**
     * Genera el acuerdo y lo archiva.
     *
     * @param  object  $domiciliario  Fila con user_id, document y el nombre del usuario.
     * @param  string  $firmaDataUrl  PNG en data URL tal cual sale del canvas.
     * @return array{media_id:int,url:string,name:string,signed_at:string}
     */
    public function generar(
        object $domiciliario,
        string $firmaDataUrl,
        string $ciudad,
        ?int $adminId = null,
        ?string $adminNombre = null,
        ?string $origen = null,
    ): array {
        // Se valida antes de renderizar: da igual el trabajo de armar el PDF
        // si la firma no es una firma.
        $this->decodificarFirma($firmaDataUrl);
        $ahora = Carbon::now();

        $pdf = $this->renderizar($domiciliario, $firmaDataUrl, $ciudad, $ahora, $adminNombre, $origen);

        $nombreArchivo = 'acuerdo-vinculacion-' . $ahora->format('Ymd-His') . '.pdf';

        $archivo = $this->medios->guardarContenido(
            'usuarios',
            $domiciliario->user_id,
            $domiciliario->name,
            $pdf,
            $nombreArchivo,
            'application/pdf',
            'documentos',
            $adminId,
        );

        DB::table('domiciliary')
            ->where('domiciliary_id', $domiciliario->domiciliary_id)
            ->update([
                'contract_media_id'  => $archivo['id'],
                'contract_signed_at' => $ahora,
                'contract_city'      => $ciudad,
            ]);

        return [
            'media_id'  => $archivo['id'],
            'url'       => $archivo['url'],
            'name'      => $archivo['name'],
            'signed_at' => $ahora->toDateTimeString(),
            'size'      => strlen($pdf),
        ];
    }

    /**
     * EL ACUERDO EN TEXTO, PARA LEERLO ANTES DE FIRMAR.
     *
     * Sale de la MISMA plantilla que produce el PDF, no de una copia. Es la
     * diferencia entre firmar lo que se leyó y firmar otra cosa parecida: una
     * segunda redacción del acuerdo se desincroniza en la primera corrección
     * que alguien haga de un lado solo, y entonces hay una persona que aceptó
     * un texto y un archivo que dice otro.
     *
     * Se devuelve en bloques —título, párrafo, punto— y no en HTML porque
     * quien lo pinta es una pantalla de teléfono sin navegador dentro.
     *
     * @return list<array{tipo:string,texto:string}>
     */
    public function comoTexto(object $domiciliario): array
    {
        /*
         * Con una firma de mentira: el texto que se lee es el mismo, y pedir
         * una firma de verdad para poder LEER el acuerdo seria al reves de
         * como funciona firmar algo.
         */
        $html = $this->renderizar(
            $domiciliario,
            self::FIRMA_PREFIJO,
            '__________',
            Carbon::now(),
            null,
            'en la aplicación por el propio trabajador',
            soloHtml: true,
        );

        // Solo el cuerpo: el encabezado de la empresa y el pie de firmas los
        // pinta la pantalla a su manera.
        $cuerpo = preg_replace('#<div class="firmas">.*#s', '', $html);

        $bloques = [];

        preg_match_all('#<(h1|h2|p|li)\b[^>]*>(.*?)</\1>#s', $cuerpo, $coincidencias, PREG_SET_ORDER);

        foreach ($coincidencias as [, $etiqueta, $interior]) {
            $texto = trim(html_entity_decode(
                preg_replace('/\s+/', ' ', strip_tags($interior)),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8',
            ));

            if ($texto === '') {
                continue;
            }

            $bloques[] = [
                'tipo'  => match ($etiqueta) {
                    'h1', 'h2' => 'titulo',
                    'li'       => 'punto',
                    default    => 'parrafo',
                },
                'texto' => $texto,
            ];
        }

        return $bloques;
    }

    /* ================================================================== */

    private function renderizar(
        object $d,
        string $firma,
        string $ciudad,
        Carbon $ahora,
        ?string $adminNombre,
        ?string $origen = null,
        bool $soloHtml = false,
    ): string {
        $html = View::make('contratos.domiciliario', [
            'empresa' => [
                'nombre'           => config('services.contrato.empresa'),
                'nit'              => config('services.contrato.nit'),
                'plataforma'       => config('services.contrato.plataforma'),
                'representante'    => config('services.contrato.representante'),
                'representante_cc' => config('services.contrato.representante_cc'),
                // A donde se le notifica a la plataforma: la clausula de
                // notificaciones lo imprime, y sin esto saldria en blanco.
                'correo'           => config('services.sitio.correo'),
            ],
            'trabajador' => [
                'nombre'    => $d->name ?? 'Sin nombre',
                'documento' => $d->document ?? null,
            ],
            'firma' => [
                'imagen'        => $firma,
                'ciudad'        => $ciudad,
                'dia'           => $ahora->day,
                'mes'           => self::MESES[$ahora->month],
                'anio'          => $ahora->year,
                'generado'      => $ahora->format('d/m/Y \a \l\a\s H:i'),
                /*
                 * DE DONDE SALIO LA FIRMA, dicho tal cual.
                 *
                 * El pie del acuerdo existe para que un PDF firmado en
                 * pantalla se distinguiera de uno que alguien armo por su
                 * cuenta. Desde que el domiciliario puede firmar desde su
                 * telefono, decir siempre «capturada en el panel de
                 * administracion» convertiria ese pie en una afirmacion falsa
                 * sobre el unico documento que tendria que poder sostenerse.
                 */
                'capturado_por' => $origen ?: ($adminNombre
                    ? 'el panel de administración por ' . $adminNombre
                    : 'la administración'),
                // Huella del contenido firmado: si alguien altera el PDF, deja
                // de coincidir con lo que registró la plataforma.
                'huella'        => strtoupper(substr(hash('sha256', $firma . $d->user_id . $ahora), 0, 16)),
            ],
        ])->render();

        if ($soloHtml) {
            return $html;
        }

        $opciones = new Options();
        $opciones->set('defaultFont', 'DejaVu Sans');
        // La firma llega como data URL: no hay ninguna descarga que habilitar.
        $opciones->set('isRemoteEnabled', false);
        $opciones->set('isHtml5ParserEnabled', true);

        $dompdf = new Dompdf($opciones);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    /**
     * Valida la firma antes de meterla en el documento.
     *
     * Sin esto, un data URL apuntando a otro tipo de contenido acabaría
     * incrustado en un PDF que después se archiva como prueba de una firma.
     */
    private function decodificarFirma(string $dataUrl): string
    {
        if (!str_starts_with($dataUrl, self::FIRMA_PREFIJO)) {
            throw new RuntimeException('La firma debe ser una imagen PNG capturada en el panel.');
        }

        $binario = base64_decode(substr($dataUrl, strlen(self::FIRMA_PREFIJO)), true);

        if ($binario === false || $binario === '') {
            throw new RuntimeException('La firma no se pudo leer: vuelve a trazarla.');
        }

        if (strlen($binario) > self::FIRMA_MAX_BYTES) {
            throw new RuntimeException('La firma supera el tamaño permitido.');
        }

        // Cabecera PNG. Un data URL puede decir "image/png" y traer otra cosa.
        if (substr($binario, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            throw new RuntimeException('La firma no es un PNG válido.');
        }

        return $binario;
    }
}
