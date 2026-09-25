<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

abstract class Controller
{
    /**
     * Respuesta de un listado de /admin: la página completa ({vista}.index), o solo la tabla
     * ({vista}._tabla) cuando la pide la búsqueda/paginación en vivo por AJAX.
     * "Vary" evita que el navegador confunda en caché el fragmento con la página completa.
     */
    protected function listado(Request $request, string $vista, array $datos): Response
    {
        return response()
            ->view($request->ajax() ? "{$vista}._tabla" : "{$vista}.index", $datos)
            ->header('Vary', 'X-Requested-With');
    }

    /**
     * Filtra por texto contenido en cualquiera de las columnas (sin distinguir mayúsculas).
     */
    protected function buscarEn(Builder $query, string $busqueda, array $columnas): Builder
    {
        return $query->when($busqueda !== '', fn ($query) => $query->where(function ($query) use ($busqueda, $columnas) {
            foreach ($columnas as $columna) {
                $query->orWhereLike($columna, "%{$busqueda}%");
            }
        }));
    }
}
