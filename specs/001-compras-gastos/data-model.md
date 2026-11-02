# Data Model: Compras y Gastos

## Tabla nueva `compras` (modelo `Compra`, `Auditable`, `TieneEstado`, módulo de estado `COMPRAS`)

Comprobante y compra van en la misma fila: la spec fija un comprobante por compra.

| Campo | Tipo | Reglas |
|---|---|---|
| id | bigint | |
| proveedor_id | FK proveedores | obligatorio; activo al crear o editar (FR-002) |
| categoria_gasto_id | FK categorias_gasto | obligatorio; activa al crear o editar |
| fecha | date | obligatoria (fecha futura: P2) |
| tipo_comprobante | string | FACTURA, TICKET, RECIBO, SIN_COMPROBANTE (R3/P8) |
| numero_comprobante | string null | FACTURA: `000-000-0000000`; TICKET/RECIBO: obligatorio; SIN_COMPROBANTE: null |
| timbrado | string null | obligatorio solo en FACTURA |
| fecha_comprobante | date null | obligatoria salvo SIN_COMPROBANTE (FR-003) |
| imagen_comprobante | string null | ruta en disco `local` (opcional) |
| moneda_id | FK monedas | PYG fija |
| monto_10, monto_5, monto_exento | bigint | ≥ 0, IVA incluido |
| iva_10, iva_5 | bigint | calculados (R4) |
| total | bigint | = monto_10 + monto_5 + monto_exento (CHECK); cero: P3 |
| condicion | string | CONTADO, CREDITO |
| vencimiento | date null | obligatorio si CREDITO, null si CONTADO (CHECK); orden con fecha: P2 |
| anticipo | bigint | 0 si CONTADO; 0 ≤ anticipo ≤ total si CREDITO (igual al total: P3) |
| observaciones | text null | |
| estado_id | FK estados | PENDIENTE (inicial) → CONFIRMADO → ANULADO |
| registrada_por | FK users | quien cargó |
| confirmada_por, confirmada_en | FK users, timestamp null | juntos; `confirmada_por <> registrada_por` |
| anulada_por, anulada_en, motivo_anulacion | FK users, timestamp, string null | juntos; motivo 5 a 300 caracteres (como Liquidaciones) |
| created_at, updated_at | timestamps | |

**Derivado (sin columna)**: `adeudado = total - anticipo` si CREDITO; 0 si CONTADO.

### Índices y restricciones

- Único parcial `compras_comprobante_unico (proveedor_id, tipo_comprobante, numero_comprobante) WHERE numero_comprobante IS NOT NULL`
  (SQLite y PostgreSQL). Inclusión de anuladas: ver research R5.
- Índices: `(fecha)`, `(proveedor_id, fecha)`, `(categoria_gasto_id, fecha)`, `(estado_id)`.
- CHECK en PostgreSQL (probados en `tests/Postgres`): total, condición/vencimiento/anticipo, pares confirmada/anulada, quien confirma ≠ quien carga.
- Sin borrado: `deleting` lanza excepción; `updating` fuera de PENDIENTE lanza excepción, salvo la transición a CONFIRMADO o ANULADO
  hecha por `FlujoCompra`.

## Transiciones

| De | A | Quién | Condiciones |
|---|---|---|---|
| — | PENDIENTE | CREAR en COMPRAS | proveedor y categoría activos, comprobante válido y no duplicado |
| PENDIENTE | PENDIENTE (editar) | EDITAR en COMPRAS | ídem |
| PENDIENTE | CONFIRMADO | EDITAR en CONFIRMACION_COMPRAS | no lo cargó él mismo |
| CONFIRMADO | ANULADO | DESACTIVAR en COMPRAS | motivo; no lo cargó él mismo (P6) |
| PENDIENTE | ANULADO | P5 | sin definir |

## Existentes que se usan (sin modificar)

`Proveedor`, `CategoriaGasto` (primer uso como FK), `Moneda`, `Estado`, `ModuloSistema`, `Permiso`, `PerfilAcceso`, `LogAuditoria`, `Facturacion\Iva`.

## Para Cuentas por Pagar (US4, FR-012)

Scope `Compra::aCreditoVigentes()`: CONFIRMADO + CREDITO, con proveedor, total, anticipo, adeudado y vencimiento. Sin pantalla en esta fase.
