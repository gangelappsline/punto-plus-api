<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Acepta una lista de teléfonos o una cadena separada por comas. */
class PhoneNumbersInput implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null) {
            return;
        }

        if (! is_array($value) && ! is_string($value)) {
            $fail('Los teléfonos deben enviarse como una lista o separados por comas.');

            return;
        }

        $items = is_array($value) ? $value : [$value];
        $numbers = [];

        foreach ($items as $item) {
            if (! is_string($item)) {
                $fail('Cada teléfono debe ser una cadena.');

                return;
            }

            foreach (explode(',', $item) as $number) {
                $number = trim($number);
                if ($number !== '') {
                    $numbers[] = $number;
                }
            }
        }

        if (count($numbers) > 10) {
            $fail('No se pueden guardar más de 10 teléfonos.');

            return;
        }

        foreach ($numbers as $number) {
            if (strlen($number) > 30) {
                $fail('Cada teléfono debe tener como máximo 30 caracteres.');

                return;
            }
        }
    }
}
