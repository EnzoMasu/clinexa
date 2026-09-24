{{-- Refleja Password::defaults() de AppServiceProvider: si cambia la regla, cambiar este texto. --}}
<div {{ $attributes->merge(['class' => 'text-xs text-gray-600 dark:text-gray-400']) }}>
    <p>La contraseña debe tener:</p>
    <ul class="mt-1 list-disc ps-5 space-y-0.5">
        <li>Al menos 8 caracteres</li>
        <li>Al menos una mayúscula y una minúscula</li>
        <li>Al menos un número</li>
        <li>Al menos un carácter especial (por ejemplo ! @ # $ % &amp; *)</li>
        <li>No más de 3 caracteres iguales seguidos ("aaa" sí, "aaaa" no)</li>
    </ul>
</div>
