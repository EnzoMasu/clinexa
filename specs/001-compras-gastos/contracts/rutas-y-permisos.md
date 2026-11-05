# Contrato: rutas y permisos de Compras y Gastos

Todas bajo el grupo del panel (`routes/web.php`), con el middleware `permiso:MODULO,ACCION`, como Liquidaciones.

| Método | Ruta | Nombre | Permiso | Notas |
|---|---|---|---|---|
| GET | compras | compras.index | COMPRAS,VER | filtros: proveedor, categoría, desde/hasta (dd/mm/aaaa), estado, condición; total del filtro |
| GET | compras/create | compras.create | COMPRAS,CREAR | solo proveedores y categorías activos |
| POST | compras | compras.store | COMPRAS,CREAR | crea en PENDIENTE; duplicado → error que enlaza al registro existente |
| GET | compras/{compra} | compras.show | COMPRAS,VER | |
| GET | compras/{compra}/edit | compras.edit | COMPRAS,EDITAR | solo PENDIENTE (policy) |
| PUT | compras/{compra} | compras.update | COMPRAS,EDITAR | solo PENDIENTE |
| POST | compras/{compra}/confirmar | compras.confirmar | CONFIRMACION_COMPRAS,EDITAR | nunca lo propio: "No puede confirmar una compra que cargó usted." |
| POST | compras/{compra}/anular | compras.anular | COMPRAS,DESACTIVAR | motivo 5 a 300; solo CONFIRMADO; también lo propio (P6) |
| POST | compras/{compra}/descartar | compras.descartar | COMPRAS,VER + policy | solo PENDIENTE; quien lo cargó o quien tenga COMPRAS,DESACTIVAR; motivo 5 a 300 (P5) |
| GET | compras/{compra}/comprobante | compras.comprobante | COMPRAS,VER | descarga de la imagen (disco local) |

`{compra}` con `whereNumber`.

## Catálogo de tipos de comprobante

Mismo patrón que `categorias-gasto` (`$seccion('tipos-comprobante-compra', TipoComprobanteCompraController::class, 'TIPOS_COMPROBANTE_COMPRA', 'tipoComprobanteCompra', conBaja: true)`):
`index` VER; `create`/`store` CREAR; `edit`/`update` EDITAR (casillas de reglas); baja lógica DESACTIVAR (activar/desactivar). Nunca `destroy`.

## Matriz (`PerfilesPredefinidos::MATRIZ`)

| Perfil | COMPRAS | CONFIRMACION_COMPRAS | TIPOS_COMPROBANTE_COMPRA |
|---|---|---|---|
| ADMINISTRADOR | todo | todo (sin confirmar lo propio) | todo |
| GERENCIA | VER, DESACTIVAR | VER, EDITAR | VER |
| COMPRAS_TESORERIA | VER, CREAR, EDITAR | — | VER |
| resto | — | — | — |

`Permiso::ACCIONES_POR_MODULO`: COMPRAS `VER, CREAR, EDITAR, DESACTIVAR`; CONFIRMACION_COMPRAS `VER, EDITAR`; TIPOS_COMPROBANTE_COMPRA `VER, CREAR, EDITAR, DESACTIVAR`.
Los perfiles existentes en la base real reciben estos permisos por la migración (como la de Liquidaciones). Los tests no cuentan módulos,
permisos ni rutas.
