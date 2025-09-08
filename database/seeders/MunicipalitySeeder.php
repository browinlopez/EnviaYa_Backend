<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class MunicipalitySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $municipalities = [
            // Amazonas
            ['name' => 'Leticia', 'department_id' => 1],
            // Antioquia
            ['name' => 'Medellín', 'department_id' => 2],
            ['name' => 'Envigado', 'department_id' => 2],
            ['name' => 'Itagüí', 'department_id' => 2],
            ['name' => 'Bello', 'department_id' => 2],
            ['name' => 'Rionegro', 'department_id' => 2],
            ['name' => 'Caucasia', 'department_id' => 2],
            ['name' => 'Apartadó', 'department_id' => 2],
            ['name' => 'Turbo', 'department_id' => 2],
            ['name' => 'La Ceja', 'department_id' => 2],
            ['name' => 'Sabaneta', 'department_id' => 2],
            // Arauca
            ['name' => 'Arauca', 'department_id' => 3],
            ['name' => 'Arauquita', 'department_id' => 3],
            ['name' => 'Saravena', 'department_id' => 3],
            ['name' => 'Tame', 'department_id' => 3],
            // Atlántico
            ['name' => 'Barranquilla', 'department_id' => 4],
            ['name' => 'Soledad', 'department_id' => 4],
            ['name' => 'Malambo', 'department_id' => 4],
            ['name' => 'Puerto Colombia', 'department_id' => 4],
            ['name' => 'Sabanalarga', 'department_id' => 4],
            // Bolívar
            ['name' => 'Cartagena', 'department_id' => 5],
            ['name' => 'Magangué', 'department_id' => 5],
            ['name' => 'Turbana', 'department_id' => 5],
            ['name' => 'Mompox', 'department_id' => 5],
            ['name' => 'Arjona', 'department_id' => 5],
            // Boyacá
            ['name' => 'Tunja', 'department_id' => 6],
            ['name' => 'Sogamoso', 'department_id' => 6],
            ['name' => 'Duitama', 'department_id' => 6],
            ['name' => 'Chiquinquirá', 'department_id' => 6],
            ['name' => 'Paipa', 'department_id' => 6],
            // Caldas
            ['name' => 'Manizales', 'department_id' => 7],
            ['name' => 'Chinchiná', 'department_id' => 7],
            ['name' => 'Villamaría', 'department_id' => 7],
            ['name' => 'La Dorada', 'department_id' => 7],
            ['name' => 'Riosucio', 'department_id' => 7],
            // Caquetá
            ['name' => 'Florencia', 'department_id' => 8],
            ['name' => 'San Vicente del Caguán', 'department_id' => 8],
            ['name' => 'La Montañita', 'department_id' => 8],
            ['name' => 'El Doncello', 'department_id' => 8],
            // Casanare
            ['name' => 'Yopal', 'department_id' => 9],
            ['name' => 'Aguazul', 'department_id' => 9],
            ['name' => 'Támara', 'department_id' => 9],
            ['name' => 'Villanueva', 'department_id' => 9],
            // Cauca
            ['name' => 'Popayán', 'department_id' => 10],
            ['name' => 'Santander de Quilichao', 'department_id' => 10],
            ['name' => 'Cauca', 'department_id' => 10],
            ['name' => 'Piamonte', 'department_id' => 10],
            // Cesar
            ['name' => 'Valledupar', 'department_id' => 11],
            ['name' => 'La Jagua de Ibirico', 'department_id' => 11],
            ['name' => 'Agustin Codazzi', 'department_id' => 11],
            ['name' => 'Riohacha', 'department_id' => 11],
            // Chocó
            ['name' => 'Quibdó', 'department_id' => 12],
            ['name' => 'Istmina', 'department_id' => 12],
            ['name' => 'Riosucio', 'department_id' => 12],
            ['name' => 'Acandí', 'department_id' => 12],
            // Córdoba
            ['name' => 'Montería', 'department_id' => 13],
            ['name' => 'Lorica', 'department_id' => 13],
            ['name' => 'Cereté', 'department_id' => 13],
            ['name' => 'Sincelejo', 'department_id' => 13],
            ['name' => 'San Antero', 'department_id' => 13],
            // Cundinamarca
            ['name' => 'Bogotá', 'department_id' => 14],
            ['name' => 'Zipaquirá', 'department_id' => 14],
            ['name' => 'Soacha', 'department_id' => 14],
            ['name' => 'Fusagasugá', 'department_id' => 14],
            // Guaviare
            ['name' => 'San José del Guaviare', 'department_id' => 15],
            // Guainía
            ['name' => 'Inírida', 'department_id' => 16],
            // Huila
            ['name' => 'Neiva', 'department_id' => 17],
            ['name' => 'La Plata', 'department_id' => 17],
            ['name' => 'Garzón', 'department_id' => 17],
            // La Guajira
            ['name' => 'Riohacha', 'department_id' => 18],
            ['name' => 'Maicao', 'department_id' => 18],
            ['name' => 'Fonseca', 'department_id' => 18],
            // Magdalena
            ['name' => 'Santa Marta', 'department_id' => 19],
            ['name' => 'Ciénaga', 'department_id' => 19],
            ['name' => 'Fundación', 'department_id' => 19],
            // Meta
            ['name' => 'Villavicencio', 'department_id' => 20],
            ['name' => 'Acacías', 'department_id' => 20],
            ['name' => 'Granada', 'department_id' => 20],
            ['name' => 'Restrepo', 'department_id' => 20],
            // Nariño
            ['name' => 'Pasto', 'department_id' => 21],
            ['name' => 'Tumaco', 'department_id' => 21],
            ['name' => 'Ipiales', 'department_id' => 21],
            ['name' => 'Cumbal', 'department_id' => 21],
            // Norte de Santander
            ['name' => 'Cúcuta', 'department_id' => 22],
            ['name' => 'Villa del Rosario', 'department_id' => 22],
            ['name' => 'Pamplona', 'department_id' => 22],
            // Putumayo
            ['name' => 'Mocoa', 'department_id' => 23],
            ['name' => 'Colón', 'department_id' => 23],
            ['name' => 'Villagarzón', 'department_id' => 23],
            // Quindío
            ['name' => 'Armenia', 'department_id' => 24],
            ['name' => 'Calarcá', 'department_id' => 24],
            ['name' => 'Montenegro', 'department_id' => 24],
            // Risaralda
            ['name' => 'Pereira', 'department_id' => 25],
            ['name' => 'Dosquebradas', 'department_id' => 25],
            ['name' => 'Santa Rosa de Cabal', 'department_id' => 25],
            // San Andrés y Providencia
            ['name' => 'San Andrés', 'department_id' => 26],
            // Santander
            ['name' => 'Bucaramanga', 'department_id' => 27],
            ['name' => 'Floridablanca', 'department_id' => 27],
            ['name' => 'Girón', 'department_id' => 27],
            // Sucre
            ['name' => 'Sincelejo', 'department_id' => 28],
            ['name' => 'Morroa', 'department_id' => 28],
            ['name' => 'Corozal', 'department_id' => 28],
            // Tolima
            ['name' => 'Ibagué', 'department_id' => 29],
            ['name' => 'Honda', 'department_id' => 29],
            ['name' => 'Melgar', 'department_id' => 29],
            // Valle del Cauca
            ['name' => 'Cali', 'department_id' => 30],
            ['name' => 'Palmira', 'department_id' => 30],
            ['name' => 'Tuluá', 'department_id' => 30],
            // Vaupés
            ['name' => 'Mitú', 'department_id' => 31],
            // Vichada
            ['name' => 'Puerto Carreño', 'department_id' => 32],
        ];

        foreach ($municipalities as $municipality) {
            DB::table('municipalities')->insert([
                'name' => $municipality['name'],
                'department_id' => $municipality['department_id'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
