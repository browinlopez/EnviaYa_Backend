<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PaymentSeeder extends Seeder
{
    public function run()
    {
        // Métodos de pago con ids EXPLÍCITOS: la app y el backend usan
        // estos números (1 Efectivo, 2 Tarjeta crédito, 5 QR, ...).
        $methods = [
            1 => 'Efectivo',
            2 => 'Tarjeta de crédito',
            3 => 'Tarjeta débito',
            4 => 'Transferencia bancaria',
            5 => 'Pago por QR',
            6 => 'Pago móvil (Nequi, Daviplata)',
        ];

        foreach ($methods as $id => $name) {
            DB::table('payment_methods')->updateOrInsert(
                ['methods_id' => $id],
                ['name' => $name, 'state' => true],
            );
        }

        $forms = [
            1 => 'Pago contra entrega',
            2 => 'Pago anticipado',
            3 => 'Pago único',
        ];

        foreach ($forms as $id => $name) {
            DB::table('payment_forms')->updateOrInsert(
                ['forms_id' => $id],
                ['name' => $name, 'state' => true],
            );
        }

        // Relación método ↔ formas (por id, idempotente)
        $relations = [
            1 => [1],       // Efectivo → contra entrega
            2 => [2, 3],    // Tarjeta crédito → anticipado, único
            3 => [2, 3],    // Tarjeta débito → anticipado, único
            4 => [2],       // Transferencia → anticipado
            5 => [2, 3],    // QR → anticipado, único
            6 => [2, 3],    // Pago móvil → anticipado, único
        ];

        foreach ($relations as $methodId => $formIds) {
            foreach ($formIds as $formId) {
                DB::table('payment_method_forms')->updateOrInsert([
                    'methods_id' => $methodId,
                    'forms_id' => $formId,
                ], []);
            }
        }
    }
}
