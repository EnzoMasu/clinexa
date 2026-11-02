# Research: Compras y Gastos

## R1. Estados

- **Decision**: reutilizar `PENDIENTE` (se muestra "Borrador", como el borrador de la receta), `CONFIRMADO` y `ANULADO`, ya existentes en
  `estados`, asignados al módulo `COMPRAS` en `estado_modulo` (PENDIENTE inicial).
- **Rationale**: principio IV (estados desde la tabla); no hace falta sembrar estados nuevos.
- **Alternatives**: estado `BORRADOR` nuevo (descartado: PENDIENTE ya cumple ese papel en recetas).

## R2. Confirmación separada de la carga

- **Decision**: módulo de permiso aparte `CONFIRMACION_COMPRAS` (VER, EDITAR) y regla "nunca lo propio" en `Support/Compras/FlujoCompra`,
  igual que `APROBACION_LIQUIDACIONES` + `FlujoLiquidacion::PROPIA`. CHECK en PostgreSQL: `confirmada_por <> registrada_por`, y
  `(confirmada_en IS NULL) = (confirmada_por IS NULL)`; idem anulación con motivo.
- **Rationale**: patrón ya probado; respuesta de Enzo "como Liquidaciones".
- **Alternatives**: acción especial en la matriz (no existe; `Permiso::ACCIONES` es fijo).

## R3. Tipo de comprobante y condición

- **Decision provisional**: columna `string` con valores fijos validados en la aplicación (`FACTURA`, `TICKET`, `RECIBO`, `SIN_COMPROBANTE`;
  `CONTADO`, `CREDITO`), porque cada valor cambia validaciones (timbrado, número, vencimiento, anticipo, Cuentas por Pagar).
- **Rationale**: son listas cerradas atadas a la lógica, como `TipoFactura`.
- **Pendiente P8**: la constitución lista los enums permitidos y no incluye estos; agregar un enum requiere enmienda aprobada por Enzo.
  Alternativa sin enmienda: tablas `tipos_comprobante_compra` y `condiciones_compra` con `codigo` fijo, sembradas por migración.

## R4. IVA

- **Decision**: se cargan `monto_10`, `monto_5` y `monto_exento` (IVA incluido); `iva_10` e `iva_5` se calculan con `Facturacion\Iva`
  (÷11, ÷21, redondeo al guaraní, la mitad hacia arriba) y se guardan; `total = monto_10 + monto_5 + monto_exento` (CHECK).
- **Rationale**: respuesta de Enzo; mismo cálculo que la factura emitida.

## R5. Comprobante y duplicados

- **Decision**: factura: `numero` con regex `^\d{3}-\d{3}-\d{7}$` y `timbrado` (obligatorio; formato exacto a confirmar con `docs/ruc.md`/facturación);
  ticket y recibo: `numero` libre obligatorio; sin comprobante: sin número. Índice único parcial
  `(proveedor_id, tipo_comprobante, numero) WHERE numero IS NOT NULL` en SQLite y PostgreSQL. Las anuladas **cuentan** para el duplicado
  (la spec dice "impedir cargar dos veces"); si al anular y recargar debe poder reusarse el número, hay que excluir ANULADO: **preguntar**
  (se suma a P5/P6 al implementar la fase 2).
- **Rationale**: FR-005 y respuesta de Enzo. El aviso indica el registro existente (escenario US1-2); ayuda al vuelo con `Unicidad`.

## R6. Moneda

- **Decision**: `moneda_id` apuntando a PYG, fija (no se elige en el formulario). Montos `bigint` enteros.
- **Rationale**: FR-013; deja el modelo listo como `Tarifa` sin habilitar otras monedas.

## R7. Caja

- **Decision**: contado y anticipo no generan `MovimientoCaja`. Pendiente P1.

## R8. Imagen del comprobante

- **Decision**: disco `local` (privado), carpeta `compras/`, descarga por ruta con `permiso:COMPRAS,VER`. Nunca se borra el archivo
  anterior. Formatos y tamaño: P7. No son datos clínicos, la regla del navegador no aplica, pero no se guarda nada en el navegador.
