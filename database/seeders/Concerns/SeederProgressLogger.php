<?php

namespace Database\Seeders\Concerns;

trait SeederProgressLogger
{
    protected function startProgress(string $title, int $total): \Symfony\Component\Console\Helper\ProgressBar
    {
        $safeTotal = max($total, 1);

        $this->command->info("▶ {$title}");
        $bar = $this->command->getOutput()->createProgressBar($safeTotal);
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%%');
        $bar->start();

        return $bar;
    }

    protected function finishProgress(
        \Symfony\Component\Console\Helper\ProgressBar $bar,
        string $seederName,
        array $stats,
        bool $success,
        ?string $errorMessage = null
    ): void {
        $bar->finish();
        $this->command->newLine();

        $inserted = $stats['inserted'] ?? 0;
        $updated = $stats['updated'] ?? 0;
        $skipped = $stats['skipped'] ?? 0;
        $failed = $stats['failed'] ?? 0;
        $processed = $stats['processed'] ?? ($inserted + $updated + $skipped + $failed);

        if ($success) {
            $this->command->info("✅ {$seederName} completado correctamente");
        } else {
            $this->command->error("❌ {$seederName} falló");
            if ($errorMessage) {
                $this->command->error("   Motivo: {$errorMessage}");
            }
        }

        $this->command->line("   Procesados: {$processed}");
        $this->command->line("   Insertados: {$inserted}");
        $this->command->line("   Actualizados: {$updated}");
        $this->command->line("   Omitidos: {$skipped}");
        $this->command->line("   Fallidos: {$failed}");
    }
}
