# Compras y gastos

Registro de las compras y los gastos operativos de la clínica con su comprobante, categoría de gasto, condición e IVA. Spec, plan y
tareas en `specs/001-compras-gastos/`. **Estado**: en construcción (esquema, permisos y catálogo de tipos listos; alta, edición y detalle de la compra listos; flujo,
listado e imagen pendientes).

## Alcance

- Una compra pertenece a un proveedor activo y a una categoría de gasto activa, y lleva un comprobante (uno por compra, en la misma fila).
- Todo en guaraníes enteros (`moneda_id` PYG fija).
- Fuera de alcance: pagos a proveedores, saldos, reversiones, Cuentas por Pagar (solo se dejan los datos listos), stock, notas de
  crédito y débito (a definir después).
- Contado y anticipo **no tocan la caja** (pendiente para una fase posterior).

## Módulos y permisos

| Módulo | Acciones | Quién |
|---|---|---|
| `COMPRAS` | VER, CREAR, EDITAR, DESACTIVAR | Compras y tesorería: VER, CREAR, EDITAR. Gerencia: VER, DESACTIVAR (anular y descartar). |
| `CONFIRMACION_COMPRAS` | VER, EDITAR | Gerencia. Nadie confirma lo que cargó, tampoco el Administrador. |
| `TIPOS_COMPROBANTE_COMPRA` | VER, CREAR, EDITAR, DESACTIVAR | Administrador todo; Gerencia y Compras y tesorería, VER. Los demás, nada. |

Los perfiles existentes los reciben por la migración `2026_11_02_100003_create_compras` (`PerfilesPredefinidos::PERMISOS_COMPRAS`).

## Estados

`PENDIENTE` ("Borrador", se edita) → `CONFIRMADO` (por otra persona) → `ANULADO` (con motivo de 5 a 300 caracteres). El borrador
también se descarta: quien lo cargó o Gerencia, pasa a `ANULADO` con motivo. Confirmada o anulada es de solo lectura; nada se borra
(el modelo lanza `RegistroEconomicoInmutable`).

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

## Pendientes

- Egreso de caja de contado y anticipo (fase posterior).
- Notas de crédito y débito (a definir).
- Anulada ya tomada por Cuentas por Pagar (se define con esa fase).
