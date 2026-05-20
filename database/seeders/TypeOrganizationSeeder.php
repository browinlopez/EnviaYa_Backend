<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\TypeOrganization;
use Database\Seeders\Concerns\SeederProgressLogger;

class TypeOrganizationSeeder extends Seeder
{
    use SeederProgressLogger;

    public function run(): void
    {
        $json = file_get_contents(public_path('catalogs/json/type_organizations.json'));
        $data = json_decode($json, true);

        $stats = ['processed' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];
        $bar = $this->startProgress('Seeding type organizations', is_array($data) ? count($data) : 0);

        try {
            foreach ($data as $row) {
                try {
                    $model = TypeOrganization::updateOrCreate(
                        ['code' => trim((string) $row['code'])],
                        [
                            'name' => trim((string) $row['name']),
                            'bold_name' => isset($row['bold_name']) ? trim((string) $row['bold_name']) : null
                        ]
                    );

                    $model->wasRecentlyCreated ? $stats['inserted']++ : $stats['updated']++;
                } catch (\Throwable $exception) {
                    $stats['failed']++;
                    $this->command->warn('TypeOrganizationSeeder fila fallida: ' . $exception->getMessage());
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