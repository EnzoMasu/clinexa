<?php

namespace App\Support;

/**
 * Enlaces cargados por los usuarios (sitio web y redes de los proveedores).
 *
 * - Solo se arma un <a href> cuando el valor es una URL http:// o https:// (esUrlWeb); cualquier
 *   otra cosa (un @usuario, un número de WhatsApp) se muestra como texto, siempre escapado.
 * - Nunca se acepta un esquema que no sea http o https (javascript:, data:, vbscript:, ...),
 *   aunque venga disfrazado con mayúsculas, espacios o caracteres de control, que los navegadores
 *   ignoran al interpretar el esquema.
 */
final class Enlace
{
    /** Es una URL web que se puede mostrar como enlace. */
    public static function esUrlWeb(?string $valor): bool
    {
        return $valor !== null
            && preg_match('#^https?://[^\s<>"\']+$#i', $valor) === 1
            && filter_var($valor, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Tiene un esquema ("algo:") distinto de http o https. Se mira sin espacios ni caracteres de
     * control, como lo haría el navegador ("java\tscript:" es "javascript:").
     */
    public static function tieneEsquemaNoPermitido(string $valor): bool
    {
        $limpio = strtolower(preg_replace('/[\x00-\x20\x7F]+/', '', $valor) ?? '');

        return preg_match('/^[a-z][a-z0-9+.\-]*:/', $limpio) === 1
            && preg_match('#^https?://#', $limpio) !== 1;
    }

    /** Sitio web: si no trae esquema, se le antepone https:// ("clinica.com.py" -> "https://clinica.com.py"). */
    public static function normalizarSitio(?string $valor): ?string
    {
        $valor = trim((string) $valor);
        if ($valor === '') {
            return null;
        }

        return preg_match('/^[a-z][a-z0-9+.\-]*:/i', $valor) ? $valor : 'https://'.$valor;
    }

    /** El host tiene forma de dominio: etiquetas separadas por puntos y un final de letras (".com", ".py"). */
    public static function tieneDominio(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host)
            && preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:[a-z]{2,63}|xn--[a-z0-9-]{1,59})$/i', $host) === 1;
    }
}
