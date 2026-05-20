<?php

namespace App\Traits;

use Carbon\Carbon;
use DateTimeInterface;
use Throwable;

trait FormatsDates
{
    /**
     * Formatea recursivamente cualquier fecha encontrada
     * dentro de arrays/objetos serializados.
     */
    public function formatDatesRecursively(mixed $data): mixed
    {
        // Objetos Carbon / DateTime
        if ($data instanceof DateTimeInterface) {
            return $this->formatSingleDate($data);
        }

        // Arrays recursivos
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->formatDatesRecursively($value);
            }

            return $data;
        }

        // Objetos stdClass / serializables
        if (is_object($data)) {
            foreach (get_object_vars($data) as $key => $value) {
                $data->$key = $this->formatDatesRecursively($value);
            }

            return $data;
        }

        // Strings que parecen fecha
        if (is_string($data) && $this->looksLikeDate($data)) {
            try {
                return $this->formatSingleDate(Carbon::parse($data));
            } catch (Throwable $e) {
                return $data;
            }
        }

        return $data;
    }

    /**
     * Timezone configurable.
     */
    protected function apiTimezone(): string
    {
        return env('APP_TIMEZONE', 'America/Bogota');
    }

    /**
     * Detecta si un string parece fecha SQL/ISO.
     */
    protected function looksLikeDate(string $value): bool
    {
        $value = trim($value);

        if (
            $value === '' ||
            $value === '0000-00-00' ||
            $value === '0000-00-00 00:00:00'
        ) {
            return false;
        }

        return preg_match(
            '/^\d{4}-\d{2}-\d{2}(?:[T\s]\d{2}:\d{2}(?::\d{2})?(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?)?$/',
            $value
        ) === 1;
    }

    /**
     * Formatea una fecha individual a timezone Colombia.
     */
    protected function formatSingleDate(DateTimeInterface $date): string
    {
        $carbon = ($date instanceof Carbon ? $date : Carbon::parse($date))
            ->copy()
            ->timezone($this->apiTimezone());

        $hasTime = $carbon->format('H:i:s') !== '00:00:00';

        return $hasTime
            ? $carbon->format('d/m/Y H:i')
            : $carbon->format('d/m/Y');
    }
}