<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DocumentTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Idempotente: correr de nuevo actualiza en vez de duplicar
        $types = [
            [
                'id' => 1,
                'code' => 'CC',
                'name_en' => 'Citizenship ID',
                'name_es' => 'Cédula de ciudadanía'
            ],
            [
                'id' => 2,
                'code' => 'TI',
                'name_en' => 'Identity Card',
                'name_es' => 'Tarjeta de identidad'
            ],
            [
                'id' => 3,
                'code' => 'CE',
                'name_en' => 'Foreigner ID',
                'name_es' => 'Cédula de extranjería'
            ],
            [
                'id' => 4,
                'code' => 'PA',
                'name_en' => 'Passport',
                'name_es' => 'Pasaporte'
            ],
            [
                'id' => 5,
                'code' => 'NIT',
                'name_en' => 'Tax Identification Number',
                'name_es' => 'NIT'
            ],
            [
                'id' => 6,
                'code' => 'RC',
                'name_en' => 'Civil Registry',
                'name_es' => 'Registro civil'
            ]
        ];

        foreach ($types as $type) {
            DB::table('document_types')->updateOrInsert(
                ['id' => $type['id']],
                $type,
            );
        }
    }
}
