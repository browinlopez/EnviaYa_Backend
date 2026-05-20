<?php

namespace App\Traits;

trait ValidateVerificationDigit
{
    /**
     * Valida el dígito de verificación DIAN para un NIT.
     * @param string $nit
     * @return int|false
     */
    protected function ValidateVerificationDigit($nit)
    {
        if (is_numeric(trim($nit))) {
            $secuencia = [3, 7, 13, 17, 19, 23, 29, 37, 41, 43, 47, 53, 59, 67, 71];
            $d = str_split(trim($nit));
            krsort($d);
            $cont = 0;
            $val = [];
            foreach ($d as $key => $value) {
                $val[$cont] = $value * $secuencia[$cont];
                $cont++;
            }
            $suma = array_sum($val);
            $div = intval($suma / 11);
            $num = $div * 11;
            $resta = $suma - $num;
            if ($resta == 1) {
                return $resta;
            } elseif ($resta != 0) {
                return 11 - $resta;
            } else {
                return $resta;
            }
        } else {
            return false;
        }
    }
}
