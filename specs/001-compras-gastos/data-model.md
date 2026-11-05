# Data Model: Compras y Gastos

## Tabla nueva `tipos_comprobante_compra` (modelo `TipoComprobanteCompra`, `Auditable`, módulo `TIPOS_COMPROBANTE_COMPRA`, estados ACTIVO_INACTIVO, sin borrado)

| Campo | Tipo | Reglas |
|---|---|---|
| id | bigint | |
| nombre | string | único |
| requiere_numero | boolean | si es falso, la compra no lleva número y queda fuera del control de duplicados |
| numero_formato_legal | boolean | exige `^\d{3}-\d{3}-\d{7}$` (solo con `requiere_numero`) |
| requiere_timbrado | boolean | |
| requiere_fecha_comprobante | boolean | |
| discrimina_iva | boolean | solo la factura legal; si es falso, la compra carga solo el total, sin desglose, y no genera IVA |
| estado_id | FK estados | ACTIVO/INACTIVO (módulo `TIPOS_COMPROBANTE_COMPRA`, como `categorias_gasto`, principio IV); solo los activos se eligen en compras nuevas; las compras existentes conservan el tipo |
| created_at, updated_at | timestamps | |

Siembra inicial (por migración): factura legal (número, formato legal, timbrado, fecha y discrimina IVA: sí), ticket (número y fecha: sí), recibo (número y fecha: sí),
sin comprobante (todo no). Ninguna regla de código mira el nombre ni el id del tipo, solo estas casillas.

## Tabla nueva `compras` (modelo `Compra`, `Auditable`, `TieneEstado`, módulo de estado `COMPRAS`)

Comprobante y compra van en la misma fila: la spec fija un comprobante por compra.

| Campo | Tipo | Reglas |
|---|---|---|
| id | bigint | |
| proveedor_id | FK proveedores | obligatorio; activo al crear o editar (FR-002) |
| categoria_gasto_id | FK categorias_gasto | obligatorio; activa al crear o editar |
| fecha | date | obligatoria; no futura (P2, FR-014) |
| tipo_comprobante_id | FK tipos_comprobante_compra | obligatorio; tipo activo al crear o editar (R3) |
| numero_comprobante | string null | obligatorio si el tipo `requiere_numero` (con formato legal `000-000-0000000` si `numero_formato_legal`); null si no lo requiere |
| timbrado | string null | obligatorio si el tipo `requiere_timbrado` |
| fecha_comprobante | date null | obligatoria si el tipo `requiere_fecha_comprobante` (FR-003); no futura; sin otra restricción respecto de `fecha` |
| imagen_comprobante | string null | ruta en disco `local` (opcional) |
| moneda_id | FK monedas | PYG fija |
| monto_10, monto_5, monto_exento | bigint null | ≥ 0, IVA incluido; solo con tipo que discrimina IVA; null los tres juntos si no discrimina (P4) |
| iva_10, iva_5 | bigint null | calculados (R4); null si el tipo no discrimina IVA |
| total | bigint | > 0 (P3); si hay desglose, = monto_10 + monto_5 + monto_exento (CHECK); sin desglose se carga directo |
| condicion | string (enum `CondicionCompra`) | CONTADO, CREDITO |
| vencimiento | date null | obligatorio si CREDITO, null si CONTADO (CHECK); no anterior a `fecha` (P2) |
| anticipo | bigint | 0 si CONTADO; 0 ≤ anticipo < total si CREDITO (P3) |
| observaciones | text null | |
| estado_id | FK estados | PENDIENTE (inicial) → CONFIRMADO → ANULADO |
| registrada_por | FK users | quien cargó |
| confirmada_por, confirmada_en | FK users, timestamp null | juntos; `confirmada_por <> registrada_por` |
| anulada_por, anulada_en, motivo_anulacion | FK users, timestamp, string null | juntos; motivo 5 a 300 caracteres (como Liquidaciones) |
| created_at, updated_at | timestamps | |

**Derivado (sin columna)**: `adeudado = total - anticipo` si CREDITO; 0 si CONTADO.

### Índices y restricciones

- Único parcial `compras_comprobante_unico (proveedor_id, tipo_comprobante_id, numero_comprobante) WHERE numero_comprobante IS NOT NULL AND anulada_en IS NULL`
  (SQLite y PostgreSQL). Las ANULADAS no cuentan (R5); `anulada_en` se fija junto con el estado ANULADO (CHECK de pares), sin depender del id del estado.
- Índices: `(fecha)`, `(proveedor_id, fecha)`, `(categoria_gasto_id, fecha)`, `(estado_id)`.
- CHECK en PostgreSQL (probados en `tests/Postgres`): total > 0; desglose todo o nada y, si hay, total = suma; condición/vencimiento (≥ fecha)/anticipo, pares confirmada/anulada, quien confirma ≠ quien carga.
- Sin borrado: `deleting` lanza excepción; `updating` fuera de PENDIENTE lanza excepción, salvo la transición a CONFIRMADO o ANULADO
  hecha por `FlujoCompra` (confirmar, anular, descartar).

## Transiciones

| De | A | Quién | Condiciones |
|---|---|---|---|
| — | PENDIENTE | CREAR en COMPRAS | proveedor y categoría activos, comprobante válido y no duplicado |
| PENDIENTE | PENDIENTE (editar) | EDITAR en COMPRAS | ídem |
| PENDIENTE | CONFIRMADO | EDITAR en CONFIRMACION_COMPRAS | no lo cargó él mismo |
| CONFIRMADO | ANULADO | DESACTIVAR en COMPRAS | motivo; también lo propio (P6) |
| PENDIENTE | ANULADO (descartar) | quien lo cargó, o DESACTIVAR en COMPRAS (P5) | motivo |

## Enum

`App\Enums\CondicionCompra` (CONTADO, CREDITO): lista cerrada atada a la lógica; requiere la enmienda 1.0.1 del principio IV.

## Existentes que se usan (sin modificar)

`Proveedor`, `CategoriaGasto` (primer uso como FK), `Moneda`, `Estado`, `ModuloSistema`, `Permiso`, `PerfilAcceso`, `LogAuditoria`, `Facturacion\Iva`.

## Para Cuentas por Pagar (US4, FR-012)

Scope `Compra::aCreditoVigentes()`: CONFIRMADO + CREDITO, con proveedor, total, anticipo, adeudado y vencimiento. Sin pantalla en esta fase.
