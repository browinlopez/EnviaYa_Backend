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
        DB::table('aliases')->insert([
            [
                'name' => 'Casa'
            ],
            [
                'name' => 'Trabajo'
            ],
            [
                'name' => 'Otros'
            ],
        ]);
    }
}
