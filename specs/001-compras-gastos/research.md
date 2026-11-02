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

- **Decision (Enzo)**: el **tipo de comprobante** es un catálogo en tabla `tipos_comprobante_compra`, editable desde pantalla (activar/desactivar), con
  casillas propias (`requiere_numero`, `numero_formato_legal`, `requiere_timbrado`, `requiere_fecha_comprobante`). Las reglas miran las casillas, nunca el nombre.
  Siembra: factura legal, ticket, recibo, sin comprobante. La **condición** es el enum `CondicionCompra` (CONTADO, CREDITO), porque cambia validaciones
  (vencimiento, anticipo, Cuentas por Pagar); requiere enmendar el principio IV (constitución 1.0.1).
- **Rationale**: el tipo lo edita el usuario (principio IV); la condición es una lista cerrada atada a la lógica, como `TipoFactura`.
- **Pendiente P9**: qué módulo y permisos gobiernan la pantalla del catálogo de tipos (no se asume).

## R4. IVA

- **Decision**: se cargan `monto_10`, `monto_5` y `monto_exento` (IVA incluido); `iva_10` e `iva_5` se calculan con `Facturacion\Iva`
  (÷11, ÷21, redondeo al guaraní, la mitad hacia arriba) y se guardan; `total = monto_10 + monto_5 + monto_exento` (CHECK).
- **Rationale**: respuesta de Enzo; mismo cálculo que la factura emitida.

## R5. Comprobante y duplicados

- **Decision**: la validación del número sale de las casillas del tipo: `requiere_numero` (obligatorio), `numero_formato_legal` (regex `^\d{3}-\d{3}-\d{7}$`,
  formato exacto a confirmar con `docs/ruc.md`/facturación), `requiere_timbrado`. Sin `requiere_numero`: sin número. Índice único parcial
  `(proveedor_id, tipo_comprobante_id, numero) WHERE numero IS NOT NULL AND anulada_en IS NULL` en SQLite y PostgreSQL. Las anuladas **no cuentan**
  para el duplicado (decisión de Enzo): al anular, el comprobante puede cargarse de nuevo.
- **Rationale**: FR-005 y respuesta de Enzo. El aviso indica el registro existente (escenario US1-2); ayuda al vuelo con `Unicidad`.

## R6. Moneda

- **Decision**: `moneda_id` apuntando a PYG, fija (no se elige en el formulario). Montos `bigint` enteros.
- **Rationale**: FR-013; deja el modelo listo como `Tarifa` sin habilitar otras monedas.

## R7. Caja

- **Decision**: contado y anticipo no generan `MovimientoCaja`. Pendiente P1.

## R8. Imagen del comprobante

- **Decision**: disco `local` (privado), carpeta `compras/`, descarga por ruta con `permiso:COMPRAS,VER`. Nunca se borra el archivo
  anterior. Formatos y tamaño: P7. No son datos clínicos, la regla del navegador no aplica, pero no se guarda nada en el navegador.
