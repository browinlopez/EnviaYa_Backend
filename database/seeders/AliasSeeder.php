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
        DB::table('alias')->insert([
            [
                'alias_id' => 1,
                'name' => 'Casa'
            ],
            [
                'alias_id' => 2,
                'name' => 'Trabajo'
            ],
            [
                'alias_id' => 3,
                'name' => 'Otros'
            ],
        ]);
    }
}
