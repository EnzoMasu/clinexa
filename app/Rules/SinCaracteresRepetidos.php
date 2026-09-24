<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rechaza 4 o más caracteres iguales seguidos ("0000", "aaaa"). Hasta 3 se permiten.
 */
class SinCaracteresRepetidos implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (preg_match('/(.)\1{3,}/u', (string) $value)) {
            $fail('La contraseña no puede tener 4 o más caracteres iguales seguidos (por ejemplo "0000" o "aaaa").');
        }
    }
}
