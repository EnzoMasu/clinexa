# Compras y gastos

Registro de las compras y los gastos operativos de la clínica con su comprobante, categoría de gasto, condición e IVA. Spec, plan y
tareas en `specs/001-compras-gastos/`. **Estado**: usable de punta a punta con lo mínimo (esquema, permisos, catálogo de tipos, categorías, alta, edición, detalle,
listado, confirmar y anular). Imagen del comprobante y lo de «Trabajo futuro» quedan pendientes.

## Alcance

- Una compra pertenece a un proveedor activo y a una categoría de gasto activa, y lleva un comprobante (uno por compra, en la misma fila).
- Todo en guaraníes enteros (`moneda_id` PYG fija).
- Fuera de alcance: pagos a proveedores, saldos, reversiones, Cuentas por Pagar (solo se dejan los datos listos), stock, notas de
  crédito y débito (a definir después).
- Contado y anticipo **no tocan la caja** (pendiente para una fase posterior).

## Módulos y permisos

| Módulo | Acciones | Quién |
|---|---|---|
| `COMPRAS` | VER, CREAR, EDITAR, DESACTIVAR | Compras y tesorería: VER, CREAR, EDITAR. Gerencia: VER, CREAR, EDITAR, DESACTIVAR (anular y descartar). |
| `CONFIRMACION_COMPRAS` | VER, EDITAR | Gerencia y Administrador. Nadie confirma lo que cargó, tampoco el Administrador: lo cargado por Gerencia lo confirma el Administrador. |
| `TIPOS_COMPROBANTE_COMPRA` | VER, CREAR, EDITAR, DESACTIVAR | Administrador todo; Gerencia y Compras y tesorería, VER. Los demás, nada. |

### Categorías de gasto (`CATEGORIAS_GASTO`)

Las crean, editan y desactivan Compras y tesorería (`COMPRAS_TESORERIA`), Gerencia (`GERENCIA`) y el Administrador (VER, CREAR, EDITAR y
DESACTIVAR). **El sistema no trae categorías base**: cada clínica carga las suyas (ni seeders ni migraciones las crean). Sin ninguna
categoría activa, el formulario de la compra lo avisa y, con permiso, enlaza al alta. Los perfiles existentes reciben los permisos por la
migración `2026_11_07_100001_categorias_gasto_permisos` (solo suma, repetible).

Gerencia suma CREAR y EDITAR sobre `COMPRAS` en los perfiles existentes por la migración `2026_11_08_100001_gerencia_carga_compras` (solo suma, repetible).

Los perfiles existentes los reciben por la migración `2026_11_02_100003_create_compras` (`PerfilesPredefinidos::PERMISOS_COMPRAS`).

## Estados

`PENDIENTE` ("Borrador", se edita) → `CONFIRMADO` (por otra persona) → `ANULADO` (con motivo de 5 a 300 caracteres). El borrador
también se descarta: quien lo cargó o Gerencia, pasa a `ANULADO` con motivo. Confirmada o anulada es de solo lectura; nada se borra
(el modelo lanza `RegistroEconomicoInmutable`).

## Listado, confirmar y anular

- **Listado** (menú Económico › Compras, `COMPRAS` VER): grilla con orden y filtros por columna (N.º, fecha, proveedor, categoría de gasto,
  comprobante, condición, total y estado); busca por número de comprobante. El filtro de categoría lista también las inactivas.
- **Confirmar** (`CONFIRMACION_COMPRAS` EDITAR): solo un borrador y nunca el propio. Queda quién y cuándo; en Auditoría es un EDITAR con el
  detalle «Confirmación de la compra». La confirmada es de solo lectura; para corregirla se anula y se carga una nueva.
- **Anular** (`COMPRAS` DESACTIVAR: Gerencia y Administrador): una confirmada o un borrador, con motivo obligatorio de 5 a 300 caracteres. Sin plazo
  ni segunda aprobación. Quien cargó un borrador y puede editarlo (Compras y tesorería) lo descarta con motivo, pero no anula una
  confirmada ni lo ajeno. Queda quién, cuándo y el motivo; en Auditoría es un ANULAR con el valor anterior y el nuevo del estado. Nunca se borra, y
  la anulada libera el número del comprobante. Lo hace `Compras\FlujoCompra`, con la fila bloqueada.
- Confirmar y anular **no tocan la caja** en esta fase.

## Tipos de comprobante

Catálogo editable (`tipos_comprobante_compra`, activo o inactivo) con casillas: requiere número, número con formato legal
(`000-000-0000000`), requiere timbrado, requiere fecha del comprobante y discrimina IVA. Las reglas miran **solo las casillas**, nunca el
nombre ni el id. Siembra inicial: factura legal (todo sí), ticket y recibo (número y fecha), sin comprobante (nada).

## Reglas

- Duplicado: un comprobante (proveedor + tipo + número) una sola vez entre las compras **no anuladas** (`compras_comprobante_unico`).
  Sin número, fuera del control.
- IVA: solo con un tipo que discrimina IVA, por tasa (10 %, 5 % y exento, IVA incluido), calculado como Facturación
  (`Compras\IvaCompra` sobre `Facturacion\Iva`); total = suma. Los demás tipos cargan solo el total, sin IVA.
- Fechas: ni la de compra ni la del comprobante pueden ser futuras; se cargan compras de días anteriores; no hay otra restricción entre
  ambas. El vencimiento no puede ser anterior a la fecha de compra.
- Importes: total mayor que cero. Contado: sin vencimiento y anticipo 0. Crédito: vencimiento obligatorio y `0 ≤ anticipo < total`.
- Imagen del comprobante (opcional): JPG, PNG o PDF, máximo 5 MB, en el disco local (pendiente de implementar).

## Trabajo futuro

Anotado, no hecho en esta fase:
- Contado y anticipo con la caja (egreso al confirmar; reversión al anular).
- Imagen del comprobante (carga, almacenamiento y vista).
- Vencimiento para cuentas por pagar (alertas y listado de vencidos).
- Pagos a proveedores y saldos.
- Reversiones de pagos.

## Pendientes

- Notas de crédito y débito (a definir).
- Anulada ya tomada por Cuentas por Pagar (se define con esa fase).
