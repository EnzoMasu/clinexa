<?php

namespace App\Rules;

use App\Support\Ruc;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * RUC de Paraguay: formato (6 a 8 dígitos, guion y dígito verificador) y, salvo que se apague con
 * clinexa.validar_digito_ruc, que el dígito verificador sea el correcto (App\Support\Ruc).
 */
class RucValido implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $ruc = (string) $value;
        if (! Ruc::tieneFormato($ruc)) {
            $fail('El RUC debe tener de 6 a 8 dígitos, un guion y el dígito verificador (por ejemplo 80012345-1).');

            return;
        }
        if (Ruc::validaDigito() && ! Ruc::esValido($ruc)) {
            $fail('El dígito verificador del RUC no es correcto. Revise el número.');
        }
    }
}
