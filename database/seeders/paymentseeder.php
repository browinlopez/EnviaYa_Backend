<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class paymentseeder extends Seeder
{
    public function run()
    {
        // Métodos de pago comunes en delivery
        $methods = [
            ['name' => 'Efectivo', 'state' => true],
            ['name' => 'Tarjeta de crédito', 'state' => true],
            ['name' => 'Tarjeta débito', 'state' => true],
            ['name' => 'Transferencia bancaria', 'state' => true],
            ['name' => 'Pago por QR', 'state' => true],
            ['name' => 'Pago móvil (Nequi, Daviplata)', 'state' => true],
        ];

        // Formas de pago típicas en delivery
        $forms = [
            ['name' => 'Pago contra entrega', 'state' => true],
            ['name' => 'Pago anticipado', 'state' => true],
            ['name' => 'Pago único', 'state' => true],
        ];

        // Insertar métodos
        foreach ($methods as $method) {
            DB::table('payment_methods')->insert($method);
        }

        // Insertar formas
        foreach ($forms as $form) {
            DB::table('payment_forms')->insert($form);
        }

        // Obtener IDs
        $methodIds = DB::table('payment_methods')->pluck('methods_id', 'name')->toArray();
        $formIds = DB::table('payment_forms')->pluck('forms_id', 'name')->toArray();

        // Relacionar métodos con formas específicas
        $relations = [
            'Efectivo' => ['Pago contra entrega'],
            'Tarjeta de crédito' => ['Pago anticipado', 'Pago único'],
            'Tarjeta débito' => ['Pago anticipado', 'Pago único'],
            'Transferencia bancaria' => ['Pago anticipado'],
            'Pago por QR' => ['Pago anticipado', 'Pago único'],
            'Pago móvil (Nequi, Daviplata)' => ['Pago anticipado', 'Pago único'],
        ];

        foreach ($relations as $methodName => $formNames) {
            $methodId = $methodIds[$methodName];
            foreach ($formNames as $formName) {
                $formId = $formIds[$formName];
                DB::table('payment_method_forms')->insert([
                    'methods_id' => $methodId,
                    'forms_id' => $formId,
                ]);
            }
        }
    }
}
