<?php

declare(strict_types=1);

namespace Database\Seeders\DemostracionQa;

use Carbon\CarbonImmutable;

// Lo que cada etapa deja creado para la siguiente: ids, fechas y quién compra dónde.
final class Escenario
{
    public const DOMINIO = 'demo.vecipaya.com';

    // Marca de los negocios y del conjunto (columna NIT): por ella los encuentra la purga.
    public const MARCA = 'DEMOQA-';

    public readonly Lote $lote;

    public readonly CarbonImmutable $ahora;

    public readonly CarbonImmutable $hoy;

    /** @var array<string, array<string, mixed>> negocio del guion => datos y id */
    public array $negocios = [];

    /** @var array<string, array{user_id:int, owner_id:int, correo:string}> */
    public array $duenos = [];

    /** @var list<array<string, mixed>> */
    public array $domiciliarios = [];

    /** @var list<array<string, mixed>> */
    public array $compradores = [];

    /** @var array<string, mixed> */
    public array $conjunto = [];

    /** @var array<int, list<string>> rol => correos */
    public array $cuentas = [];

    /** @var list<array<string, mixed>> pedidos entregados en el conjunto, para la portería */
    public array $alConjunto = [];

    /** @var array<int, array{0:float, 1:int}> negocio => [suma de estrellas, reseñas] */
    public array $estrellasNegocio = [];

    /** @var array<int, array{0:float, 1:int}> domiciliario => [suma de estrellas, reseñas] */
    public array $estrellasDomiciliario = [];

    /** @var array<int, int> promoción => usos */
    public array $usosDePromocion = [];

    /** @var array<string, true> */
    private array $correos = [];

    /** @var array<string, int> municipio => id */
    public array $municipios = [];

    /** @var array<string, int> oficio => tipo de negocio */
    public array $tipos = [];

    public function __construct(
        public readonly string $clave,
        public readonly float $tarifa,
        public readonly float $reparto,
        public readonly float $comision,
    ) {
        $this->lote = new Lote;
        $this->ahora = CarbonImmutable::now()->startOfMinute();
        $this->hoy = $this->ahora->startOfDay();
    }

    // Un correo de demostración único a partir del nombre: «Luz Dary» => luz.dary@demo...
    public function correo(string $nombre, string $prefijo = ''): string
    {
        $base = $prefijo.self::slug($nombre);
        $correo = $base.'@'.self::DOMINIO;

        for ($i = 2; isset($this->correos[$correo]); $i++) {
            $correo = $base.$i.'@'.self::DOMINIO;
        }

        $this->correos[$correo] = true;

        return $correo;
    }

    public static function slug(string $texto): string
    {
        $sin = strtr(mb_strtolower($texto), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u']);

        return trim((string) preg_replace('/[^a-z0-9]+/', '.', $sin), '.');
    }

    public function haceDias(int $dias, int $hora = 9, int $minuto = 0): CarbonImmutable
    {
        return $this->hoy->subDays($dias)->setTime($hora, $minuto);
    }
}
