<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AliasSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Idempotente: correr el seeder de nuevo no duplica ni revienta
        foreach ([1 => 'Casa', 2 => 'Trabajo', 3 => 'Otros'] as $id => $name) {
            DB::table('alias')->updateOrInsert(
                ['alias_id' => $id],
                ['name' => $name],
            );
        }
    }
}
