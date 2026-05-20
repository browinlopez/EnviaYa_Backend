<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Database\Seeders\Concerns\SeederProgressLogger;

class TypeDocumentIdentificationSeeder extends Seeder
{
    use SeederProgressLogger;

    public function run(): void
    {
        $jsonPath = public_path('catalogs/json/type_document_identifications.json');
        if (!file_exists($jsonPath)) {
            $this->command->error("No se encontró el archivo JSON: {$jsonPath}");
            return;
        }

        $data = json_decode(file_get_contents($jsonPath), true);
        $now = now()->toDateTimeString();

        $rows = [];
        foreach ($data as $row) {
            foreach ($row as $k => $v) {
                if (is_string($v)) {
                    $row[$k] = trim($v);
                }
            }
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
            $rows[] = $row;
        }

        // Elimina posibles filas de encabezado y duplicados por id
        $filtered = [];
        foreach ($rows as $row) {
            if (isset($row['id']) && is_numeric($row['id'])) {
                $filtered[$row['id']] = $row;
            }
        }
        $rows = array_values($filtered);

        if (empty($rows)) {
            $this->command->warn('No hay datos válidos para la tabla type_document_identifications');
            return;
        }

        $stats = ['processed' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];
        $bar = $this->startProgress('Seeding type_document_identifications', count($rows));

        $existingIds = DB::table('type_document_identifications')
            ->whereIn('id', array_column($rows, 'id'))
            ->pluck('id')
            ->map(fn($id) => (int) $id)
            ->all();
        $existingLookup = array_flip($existingIds);

        try {
            foreach ($rows as $row) {
                try {
                    $id = (int) $row['id'];
                    DB::table('type_document_identifications')->updateOrInsert(['id' => $id], $row);

                    if (isset($existingLookup[$id])) {
                        $stats['updated']++;
                    } else {
                        $stats['inserted']++;
                        $existingLookup[$id] = true;
                    }
                } catch (\Throwable $exception) {
                    $stats['failed']++;
                    $this->command->warn("type_document_identifications fila fallida (id={$row['id']}): {$exception->getMessage()}");
                }

                $stats['processed']++;
                $bar->advance();
            }

            $this->finishProgress($bar, class_basename(self::class), $stats, $stats['failed'] === 0, $stats['failed'] > 0 ? 'Algunas filas fallaron.' : null);
        } catch (\Throwable $exception) {
            $this->finishProgress($bar, class_basename(self::class), $stats, false, $exception->getMessage());
            throw $exception;
        }
    }
}
