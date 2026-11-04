<?php

// Compartido por GrillasTodasTest y GrillasDesplegablesTest (se carga desde tests/Pest.php).

/** Rutas GET de /admin que dibujan una grilla. */
function grillasTodasRutas(): array
{
    return [
        'acuerdos-reparto.index', 'auditoria.index', 'cajas.index', 'cargos-salud.index', 'categorias-gasto.index',
        'categorias-proveedor.index', 'cie10.index', 'ciudades.index', 'cobros.index', 'consultorios.index',
        'convenios.index', 'convenios.tarifas', 'departamentos.index', 'dietas.index', 'disponibilidades.index',
        'equipos.index', 'especialidades.index', 'estudios.index', 'facturas.index', 'facturas.timbrados',
        'facturas.tasas-iva', 'historias-clinicas.index', 'liquidaciones.index', 'medios-pago.index',
        'facturas.motivos', 'origenes-turno.index', 'pacientes.index', 'paises.index', 'perfiles-acceso.index',
        'personal-salud.index', 'personas.index', 'prestaciones.index', 'procedimientos.index', 'profesionales.index',
        'proveedores.index', 'responsables-pago.index', 'sucursales.index', 'tarifas.index', 'tipos-bloque-anamnesis.index',
        'tipos-documento.index', 'tipos-equipo.index', 'tipos-indicacion.index', 'tipos-red-social.index', 'turnos.index',
        'usuarios.index',
    ];
}
