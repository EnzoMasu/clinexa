<?php

namespace App\Http\Middleware;

use App\Support\ContextoAuditoria;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marca que se está atendiendo un pedido web: solo entonces la auditoría registra cambios.
 * Seeders, migraciones y comandos de consola nunca pasan por acá, así que no generan log.
 */
class ContextoAuditoriaWeb
{
    public function __construct(private ContextoAuditoria $contexto) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->contexto->pedidoWeb = true;

        try {
            return $next($request);
        } finally {
            // Por si la misma instancia de la aplicación sigue viva (tests, servidores persistentes).
            $this->contexto->pedidoWeb = false;
            $this->contexto->motivoCierre = null;
            $this->contexto->sensibles = null;
        }
    }
}
