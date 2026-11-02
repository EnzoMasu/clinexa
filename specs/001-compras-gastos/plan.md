# Implementation Plan: Compras y Gastos

**Branch**: `prueba-speckit` (la rama de implementación la indica Enzo) | **Date**: 2026-11-02 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/001-compras-gastos/spec.md`

## Summary

Registro de compras y gastos con su comprobante, categoría de gasto, condición (contado o crédito con anticipo), IVA por tasa y
estados PENDIENTE (borrador) → CONFIRMADO → ANULADO. Compras y tesorería carga; Gerencia o el Administrador confirman y anulan, nunca lo
que cargaron ellos mismos (mismo esquema que `FlujoLiquidacion`). Reutiliza `Proveedor`, `CategoriaGasto`, `Moneda` (PYG fija),
`Estado`/`estado_modulo`, la matriz de permisos, `Auditable` y el cálculo de IVA de `Facturacion\Iva`. **No toca la caja** (pendiente).

## Technical Context

**Language/Version**: PHP / Laravel (versión del proyecto), Blade + Tailwind + Vite, JS plano

**Primary Dependencies**: las del proyecto; ninguna nueva

**Storage**: PostgreSQL (`clinexa_dev`); imagen del comprobante en el disco `local` (como el logotipo de `Clinica`)

**Testing**: Pest/PHPUnit: SQLite en memoria (`tests/Feature`, `tests/Unit`) y `clinexa_test` (`tests/Postgres`) para índices y CHECK

**Target Platform**: servidor Linux, navegador de escritorio

**Project Type**: aplicación web monolítica (panel administrativo)

**Performance Goals**: SC-001 (< 2 min la carga) y SC-004 (total mensual por categoría < 30 s): con índices por fecha, proveedor, categoría y estado alcanza

**Constraints**: nada se borra; todo se audita; fechas con `App\Support\Fecha`; textos en "usted"; montos enteros en guaraníes

**Scale/Scope**: una clínica; cientos de registros por mes

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principio | Cumple | Cómo |
|---|---|---|
| I. Laravel + PostgreSQL | Sí | Sin pila nueva. Restricciones críticas también como índice único parcial y CHECK en PostgreSQL. |
| II. Nunca borrado físico | Sí | Sin DELETE: el registro se anula con motivo; el modelo lanza excepción en `deleting` (como `RegistroEconomicoInmutable`). |
| III. Auditoría de todo cambio | Sí | `Auditable` en `Compra`; confirmar y anular se auditan con su detalle. Módulo **no** sensible (no hay datos del paciente). |
| IV. Estados y catálogos desde tablas | Sí | Estados de la tabla `Estado` vía `estado_modulo` (PENDIENTE, CONFIRMADO, ANULADO, ya existen). Tipo de comprobante: catálogo en tabla editable (R3). Condición: enum `CondicionCompra`, permitido por la enmienda 1.0.1 del principio IV. |
| V. Historia clínica inmutable | N/A | No toca la historia clínica. |
| VI. Enzo decide las reglas | Sí | Las cuatro dudas se preguntaron (spec, Clarifications); las que siguen abiertas están en **Pendientes**, sin asumir. |

**Resultado**: pasa, sujeto a que se guarde la enmienda 1.0.1 de la constitución (suma `CondicionCompra` a los enums permitidos).

## Project Structure

### Documentation (this feature)

```text
specs/001-compras-gastos/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/rutas-y-permisos.md
└── tasks.md             # lo genera /speckit-tasks
```

### Source Code (repository root)

```text
app/Models/Compra.php                          # nuevo
app/Models/TipoComprobanteCompra.php           # nuevo: catálogo editable (casillas de reglas)
app/Enums/CondicionCompra.php                  # nuevo: CONTADO, CREDITO
app/Support/Compras/                           # nuevo: RegistrarCompra, FlujoCompra (confirmar/anular), IvaCompra (usa Facturacion\Iva)
app/Http/Controllers/Admin/CompraController.php
app/Http/Requests/CompraRequest.php
app/Policies/CompraPolicy.php                  # solo PENDIENTE se edita; nadie confirma lo propio
database/migrations/2026_11_xx_create_compras.php      # tabla, índices, CHECK (PG), módulos, estado_modulo, permisos de perfiles
database/seeders/ModuloSistemaSeeder.php       # COMPRAS, CONFIRMACION_COMPRAS y TIPOS_COMPROBANTE_COMPRA
app/Support/PerfilesPredefinidos.php           # COMPRAS_TESORERIA, GERENCIA
app/Models/Permiso.php                         # ACCIONES_POR_MODULO de los tres módulos
app/Http/Controllers/Admin/TipoComprobanteCompraController.php  # catálogo con baja, vía $seccion
resources/views/admin/compras/                 # index (filtros + total), create/edit, show
routes/web.php
docs/compras-gastos.md                         # doc del tema (y mención en CLAUDE.md)
tests/Feature/Compras/, tests/Unit/Compras/, tests/Postgres/ComprasPostgresTest.php
```

**Structure Decision**: mismo esquema que Liquidaciones (`app/Support/Liquidaciones`, `LiquidacionController`, módulo de aprobación aparte).

## Permisos (detalle en [contracts/rutas-y-permisos.md](contracts/rutas-y-permisos.md))

- Módulo **COMPRAS** (`VER`, `CREAR`, `EDITAR`, `DESACTIVAR`): COMPRAS_TESORERIA `VER, CREAR, EDITAR`; GERENCIA `VER, DESACTIVAR` (anular).
- Módulo **CONFIRMACION_COMPRAS** (`VER`, `EDITAR`): GERENCIA `VER, EDITAR`. El Administrador tiene todo, como siempre.
- **Quien carga no confirma** (ni anula lo propio, según "como Liquidaciones"): en `FlujoCompra` (`registrada_por !== usuario`, mensaje
  "No puede confirmar una compra que cargó usted.") y en la policy para ocultar el botón; test de que tampoco el Administrador confirma lo suyo.
- Módulo **TIPOS_COMPROBANTE_COMPRA** (`VER, CREAR, EDITAR, DESACTIVAR`, estados ACTIVO_INACTIVO): Administrador todo; COMPRAS_TESORERIA y el resto de perfiles solo `VER`.
- `CATEGORIAS_GASTO` para COMPRAS_TESORERIA sigue en `VER` (no se pidió cambiarlo).

## Auditoría

- Alta, edición del borrador, confirmación y anulación: `LogAuditoria` vía `Auditable` (antes/después) y detalle de la acción
  (motivo en la anulación). El cambio de imagen del comprobante registra la ruta (la anterior no se borra del disco, como el logotipo).
- Sin auditoría de lecturas (módulo no sensible). Los montos no se ocultan en Auditoría: quien no tiene VER en COMPRAS no entra al módulo;
  ver Pendiente P6.

## Orden de implementación (fases chicas, un commit WIP cada una)

1. **Esquema**: migración de `tipos_comprobante_compra` (con siembra) y de `compras` + índices (único parcial sin ANULADAS) + CHECK en PostgreSQL, módulos, `estado_modulo`, permisos de perfiles. Tests de esquema (Postgres).
2. **Modelo y reglas**: `Compra` (inmutable fuera de PENDIENTE, sin borrado), `IvaCompra`, validaciones del comprobante y duplicado. Tests Unit.
3. **Alta y edición del borrador** (US1): request, controlador, vistas create/edit, proveedores y categorías activos. Tests Feature.
4. **Confirmar y anular** (US2): `FlujoCompra`, policy, botones, motivo. Tests de "quien carga no confirma".
5. **Listado y filtros con total** (US3): filtros por proveedor, categoría, período, estado, condición; total del filtro. Tests.
6. **Imagen del comprobante**: subida opcional, ver/descargar con permiso. Tests.
7. **Datos para Cuentas por Pagar** (US4): scope `Compra::aCreditoVigentes()` con importe adeudado; sin pantalla. Test.
8. **Docs y demo**: `docs/compras-gastos.md`, CLAUDE.md, recorrido manual; datos demo solo si Enzo lo pide.

Luego `/aplicar` (respaldo `--probar`, migración) solo con confirmación de Enzo.

## Pendientes (preguntar a Enzo antes de implementar la fase indicada)

- **P1 (fase 1, diferido por decisión)**: contado y anticipo **no tocan la caja**. Una fase posterior definirá el egreso (medio de pago, qué caja, cuándo).
- **P2 (fase 2)**: ¿se admite fecha de compra futura? ¿Vencimiento anterior a la fecha de compra? (la spec lo lista como caso borde).
- **P3 (fase 2)**: ¿anticipo igual al total? (hoy solo es seguro 0 ≤ anticipo ≤ total). ¿Importe total cero?
- **P4 (fase 2)**: ¿ticket, recibo y "sin comprobante" también discriminan IVA, o van exentos?
- **P5 (fase 4)**: un borrador que no va más, ¿quién lo descarta? La spec dice que solo Gerencia anula; ¿Compras puede anular su propio borrador?
- **P6 (fase 4)**: ¿Gerencia puede anular una compra que cargó ella (p. ej. el Administrador)? Hoy se aplica "como Liquidaciones": no.
- **P7 (fase 6)**: formatos y tamaño de la imagen (propuesta técnica: PNG, JPG o PDF, hasta 5 MB).
- ~~P8~~ **Resuelto**: tipo de comprobante en tabla, condición como enum `CondicionCompra` (research R3). El duplicado excluye ANULADAS (R5).
- ~~P9~~ **Resuelto**: módulo `TIPOS_COMPROBANTE_COMPRA` (`VER, CREAR, EDITAR, DESACTIVAR`), estados ACTIVO_INACTIVO, mismo patrón que `CATEGORIAS_GASTO`.
- **Pendiente de Enzo**: notas de crédito/débito y su efecto en los totales (hoy fuera de la spec).
- Fuera de esta fase: "período ya informado" (no existe cierre de período) y "anulada ya tomada por Cuentas por Pagar" (se define con CxP).

## Complexity Tracking

Sin violaciones que justificar.
