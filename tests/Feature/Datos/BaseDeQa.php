<?php

use App\Models\Rol;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Los catálogos de QA y lo que ya había: un tendero con su tienda, un comprador y un conjunto.
function baseDeQa(): void
{
    Carbon::setTestNow('2026-11-27 16:30:00');
    config(['semillas.demo_qa_clave' => 'ClaveDeDemo2026', 'semillas.demo_qa_purgar' => false]);

    foreach ([1 => 'comprador', 2 => 'tendero', 3 => 'domiciliario', 5 => 'dueno_conjunto', 6 => 'celador'] as $id => $nombre) {
        Rol::firstOrCreate(['rol_id' => $id], ['name' => $nombre, 'guard_name' => 'web']);
    }

    foreach ([1, 2, 3, 4, 5, 6, 7] as $id) {
        DB::table('payment_methods')->insertOrIgnore(['methods_id' => $id, 'name' => "Medio {$id}", 'state' => 1]);
    }

    DB::table('payment_forms')->insertOrIgnore([['forms_id' => 1, 'name' => 'Contra entrega', 'state' => 1], ['forms_id' => 2, 'name' => 'Anticipado', 'state' => 1]]);
    DB::table('document_types')->insertOrIgnore(['id' => 1, 'code' => 'CC', 'name_en' => 'ID', 'name_es' => 'Cédula']);
    tiposDeNegocioReales();

    $pais = DB::table('countries')->insertGetId(['name' => 'Colombia', 'iso_code' => 'CO']);
    $dep = DB::table('departments')->insertGetId(['name' => 'Atlántico', 'country_id' => $pais]);
    $ciudades = [];
    foreach (['Barranquilla', 'Soledad', 'Malambo', 'Puerto Colombia', 'Galapa'] as $nombre) {
        $ciudades[$nombre] = DB::table('municipalities')->insertGetId(['name' => $nombre, 'department_id' => $dep]);
    }

    $tendero = User::factory()->create(['email' => 'tendero@gmail.com', 'rol' => 2]);
    $negocio = DB::table('business')->insertGetId(['name' => 'Tienda el progreso', 'type' => 1, 'state' => 1, 'municipality_id' => $ciudades['Barranquilla']], 'busines_id');
    $owner = DB::table('owner')->insertGetId(['user_id' => $tendero->user_id, 'state' => 1], 'owner_id');
    DB::table('owner_busines')->insert(['owner_id' => $owner, 'busines_id' => $negocio, 'state' => 1]);

    for ($i = 1; $i <= 45; $i++) {
        enLaTienda($negocio, productoDeCatalogo("PRODUCTO DE TIENDA {$i}")->products_id, 1000 + $i * 300, 10);
    }

    $comprador = User::factory()->create(['email' => 'compradora@gmail.com', 'rol' => 1]);
    DB::table('buyer')->insert(['user_id' => $comprador->user_id, 'qualification' => 0, 'state' => 1]);
    DB::table('residential_complexes')->insert(['name' => 'Los Mangos', 'state' => 1, 'municipality_id' => $ciudades['Barranquilla']]);
}

// Cada fila completa de lo que ya había, por su llave: si la semilla tocara una columna, cambiaría.
function loQueYaHabia(?array $antes = null): array
{
    $llaves = [
        'user' => 'user_id', 'business' => 'busines_id', 'residential_complexes' => 'complex_id', 'products' => 'products_id',
        'products_business' => 'busines_products_id', 'buyer' => 'buyer_id', 'owner' => 'owner_id',
    ];

    return collect($llaves)->map(fn ($llave, $tabla) => DB::table($tabla)
        ->when($antes !== null, fn ($q) => $q->whereIn($llave, array_keys($antes[$tabla])))
        ->orderBy($llave)->get()->keyBy($llave)->map(fn ($f) => (array) $f)->all())->all();
}

// Cuántas filas tiene cada tabla de la base de pruebas, en SQLite o en MySQL.
function filasPorTabla(): array
{
    $esquemas = ['main', DB::connection()->getDatabaseName()];

    return collect(Schema::getTables())->filter(fn ($t) => in_array($t['schema'] ?? 'main', $esquemas, true))
        ->mapWithKeys(fn ($t) => [$t['name'] => DB::table($t['name'])->count()])->sortKeys()->all();
}
