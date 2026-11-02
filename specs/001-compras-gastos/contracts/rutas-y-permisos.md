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
| POST | compras/{compra}/anular | compras.anular | COMPRAS,DESACTIVAR | motivo 5 a 300; solo CONFIRMADO (borrador: P5) |
| GET | compras/{compra}/comprobante | compras.comprobante | COMPRAS,VER | descarga de la imagen (disco local) |

`{compra}` con `whereNumber`.

## Matriz (`PerfilesPredefinidos::MATRIZ`)

| Perfil | COMPRAS | CONFIRMACION_COMPRAS |
|---|---|---|
| ADMINISTRADOR | todo | todo (sin confirmar lo propio) |
| GERENCIA | VER, DESACTIVAR | VER, EDITAR |
| COMPRAS_TESORERIA | VER, CREAR, EDITAR | — |
| resto | — | — |

`Permiso::ACCIONES_POR_MODULO`: COMPRAS `VER, CREAR, EDITAR, DESACTIVAR`; CONFIRMACION_COMPRAS `VER, EDITAR`.
Los perfiles existentes en la base real reciben estos permisos por la migración (como la de Liquidaciones). Los tests no cuentan módulos,
permisos ni rutas.
