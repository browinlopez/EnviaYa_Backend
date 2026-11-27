<?php

use Database\Seeders\DemostracionQaSeeder;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/BaseDeQa.php';

beforeEach(fn () => baseDeQa());

it('siembra la plataforma entera y deja intacto lo que ya había', function () {
    $antes = loQueYaHabia();

    (new DemostracionQaSeeder)->run();

    $demo = DB::table('business')->where('NIT', 'like', 'DEMOQA-%');
    $correos = DB::table('user')->where('email', 'like', '%@demo.vecipaya.com');
    expect((clone $demo)->count())->toBe(7)
        ->and((clone $demo)->distinct()->count('type'))->toBe(4)
        ->and((clone $demo)->distinct()->count('municipality_id'))->toBe(3)
        ->and((clone $correos)->where('rol', 1)->count())->toBe(120)
        ->and((clone $correos)->where('rol', 3)->count())->toBe(8)
        ->and((clone $correos)->whereIn('rol', [4, 7])->count())->toBe(0)
        ->and(DB::table('owner_busines')->groupBy('owner_id')->selectRaw('count(*) as n')->pluck('n')->max())->toBe(3)
        ->and(DB::table('orderssales')->where('state', 4)->where('payment_state', 'paid')->count())->toBeGreaterThan(1500)
        ->and(DB::table('orderssales')->where('state', 5)->count())->toBeGreaterThan(30)
        ->and(DB::table('orderssales')->whereIn('state', [1, 2, 3])->where('is_scheduled', 0)->count())->toBeGreaterThanOrEqual(12)
        ->and(DB::table('orderssales')->where('is_scheduled', 1)->count())->toBe(3)
        ->and(DB::table('ventas_mostrador')->count())->toBeGreaterThan(500)
        ->and(DB::table('cash_deposits')->where('state', 'confirmada')->count())->toBeGreaterThan(50)
        ->and(DB::table('store_credit_movements')->where('type', 'consumo')->count())->toBeGreaterThan(10)
        ->and(DB::table('business_reviews')->count())->toBeGreaterThan(100)
        ->and(DB::table('complex_shifts')->whereNull('ended_at')->count())->toBe(1)
        ->and(DB::table('complex_entries')->whereNull('exited_at')->where('created_at', '>=', '2026-11-27')->count())->toBeGreaterThanOrEqual(3)
        ->and(DB::table('user')->where('rol', 6)->where('must_change_password', 1)->count())->toBe(0)
        ->and(loQueYaHabia($antes))->toBe($antes);
});

it('correrla dos veces deja lo mismo, sin duplicar', function () {
    $antes = loQueYaHabia();
    (new DemostracionQaSeeder)->run();
    $primera = filasPorTabla();

    (new DemostracionQaSeeder)->run();

    expect(filasPorTabla())->toBe($primera)
        ->and(loQueYaHabia($antes))->toBe($antes);
});

it('la purga retira todo lo que sembró y nada más', function () {
    $base = filasPorTabla();
    $antes = loQueYaHabia();
    (new DemostracionQaSeeder)->run();

    config(['semillas.demo_qa_purgar' => true, 'semillas.demo_qa_clave' => null]);
    (new DemostracionQaSeeder)->run();

    expect(filasPorTabla())->toBe($base)
        ->and(loQueYaHabia($antes))->toBe($antes);
});

it('sin DEMO_QA_PASSWORD no siembra nada', function () {
    config(['semillas.demo_qa_clave' => null]);
    $base = filasPorTabla();

    expect(fn () => (new DemostracionQaSeeder)->run())->toThrow(RuntimeException::class, 'DEMO_QA_PASSWORD')
        ->and(filasPorTabla())->toBe($base);
});

it('si falta un municipio del libreto no siembra nada', function () {
    DB::table('municipalities')->where('name', 'Galapa')->delete();
    $base = filasPorTabla();

    expect(fn () => (new DemostracionQaSeeder)->run())->toThrow(RuntimeException::class, 'Galapa')
        ->and(filasPorTabla())->toBe($base);
});
