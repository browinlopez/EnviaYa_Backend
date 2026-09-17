<?php

namespace App\Support;

/**
 * Las direcciones propias, siempre en el dominio vigente.
 *
 * El 2026-09-14 la plataforma volvió a vecipaya.com y el dominio anterior dejó
 * de apuntar al servidor. El código se actualizó, pero las variables del
 * entorno de producción (`APP_URL`, `PANEL_URL`, `SITIO_URL`) viven en Dokploy
 * y siguieron con el dominio viejo: el correo de verificación mandaba a la
 * gente a `api.` de un dominio muerto y el navegador ni siquiera abría.
 *
 * No es un problema que se vea al desplegar: se ve cuando alguien se registra.
 * Así que no se deja en manos de que alguien recuerde cambiar las variables:
 * una dirección del dominio anterior se traduce aquí a su equivalente, con la
 * misma ruta. Cualquier otro dominio —pruebas, desarrollo— pasa sin tocarse.
 */
final class DominioPropio
{
    public const ANTERIOR = 'enviaya.com.co';

    public const VIGENTE = 'vecipaya.com';

    /** Subdominios que cambiaron de nombre en la mudanza. */
    private const RENOMBRADOS = ['panel' => 'admin'];

    public static function url(?string $url, string $porDefecto): string
    {
        $url = trim((string) $url);

        if ($url === '') {
            return $porDefecto;
        }

        $anfitrion = strtolower((string) parse_url($url, PHP_URL_HOST));

        $esAnterior = $anfitrion === self::ANTERIOR
            || str_ends_with($anfitrion, '.' . self::ANTERIOR);

        if (! $esAnterior) {
            return $url;
        }

        $subdominio = rtrim(substr($anfitrion, 0, -strlen(self::ANTERIOR)), '.');
        $subdominio = self::RENOMBRADOS[$subdominio] ?? $subdominio;
        $nuevo = ($subdominio !== '' ? $subdominio . '.' : '') . self::VIGENTE;

        return (string) preg_replace(
            '#^(https?://)' . preg_quote($anfitrion, '#') . '#i',
            '${1}' . $nuevo,
            $url,
        );
    }
}
