<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Database\Seeders\Concerns\SeederProgressLogger;

class MunicipalitySeeder extends Seeder
{
    use SeederProgressLogger;

    public function run()
    {
        $jsonDirectory = public_path('catalogs/json');
        $tables = ['countries', 'departments', 'municipalities'];

        foreach ($tables as $table) {
            $jsonPath = $jsonDirectory . '/' . $table . '.json';
            if (!file_exists($jsonPath)) {
                $this->command->warn("No se encontró el archivo JSON: {$jsonPath}");
                continue;
            }

            $data = json_decode(file_get_contents($jsonPath), true);
            $now = now()->toDateTimeString();

            $rows = [];
            foreach ($data as $row) {
                // Limpiar espacios en todos los campos string
                foreach ($row as $k => $v) {
                    if ($k === 'code') {
                        // Forzar a string y limpiar espacios
                        $row[$k] = trim((string) $v);
                    } elseif (is_string($v)) {
                        $row[$k] = trim($v);
                    }
                }
                $row['created_at'] = $now;
                $row['updated_at'] = $now;
                $rows[] = $row;
            }

            // Filtrar filas válidas y eliminar duplicados por 'name'
            $filtered = [];
            foreach ($rows as $row) {
                if (isset($row['name'])) {
                    unset($row['id']); // Eliminar id para que sea autoincremental
                    $filtered[$row['name']] = $row; 
                }
            }
            $rows = array_values($filtered);

            if (empty($rows)) {
                $this->command->warn("No hay datos válidos para la tabla {$table}");
                continue;
            }

            $stats = ['processed' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];
            $bar = $this->startProgress("Seeding {$table}", count($rows));

            $existingNames = DB::table($table)
                ->whereIn('name', array_column($rows, 'name'))
                ->pluck('name')
                ->all();
            $existingLookup = array_flip($existingNames);

            try {
                foreach ($rows as $row) {
                    try {
                        $name = $row['name'];
                        DB::table($table)->updateOrInsert(['name' => $name], $row);

                        if (isset($existingLookup[$name])) {
                            $stats['updated']++;
                        } else {
                            $stats['inserted']++;
                            $existingLookup[$name] = true;
                        }
                    } catch (\Throwable $exception) {
                        $stats['failed']++;
                        $this->command->warn("{$table} fila fallida (name={$row['name']}): {$exception->getMessage()}");
                    }

                    $stats['processed']++;
                    $bar->advance();
                }

                $this->finishProgress($bar, "MunicipalitySeeder:{$table}", $stats, $stats['failed'] === 0, $stats['failed'] > 0 ? 'Algunas filas fallaron.' : null);
            } catch (\Throwable $exception) {
                $this->finishProgress($bar, "MunicipalitySeeder:{$table}", $stats, false, $exception->getMessage());
                throw $exception;
            }
        }
    }
}
