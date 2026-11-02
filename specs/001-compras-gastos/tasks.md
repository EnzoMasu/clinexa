---
description: "Tareas de Compras y Gastos"
---

# Tasks: Compras y Gastos

**Input**: `/specs/001-compras-gastos/` (plan.md, spec.md, data-model.md, research.md, contracts/, quickstart.md)
**Rama**: `prueba-speckit` (nunca `main`). Commit WIP chico al terminar cada fase; sin tocar la base real (eso es `/aplicar`, con confirmación de Enzo).
**Pruebas**: se incluyen (el plan las prevé por fase). Datos inventados; SQLite en memoria (`tests/Feature`, `tests/Unit`) y `clinexa_test` (`tests/Postgres`). Durante el desarrollo, solo `composer test:rapido`. Helpers globales de test con el prefijo del archivo. Sin números fijos de módulos, permisos ni rutas.
**Reglas transversales**: textos en "usted"; fechas dd/mm/aaaa con `App\Support\Fecha`; nada se borra; todo cambio se audita.

## Formato: `[ID] [P?] [Story] Descripción con ruta`

- **[P]**: se puede en paralelo (archivos distintos, sin dependencia pendiente).
- **[P7]**: regla decidida por Enzo. **[POSTERGADA]**: fuera de alcance, no se implementa.

---

## Phase 1: Setup

- [x] T001 [P] Crear el enum `CondicionCompra` (CONTADO, CREDITO) en `app/Enums/CondicionCompra.php` (permitido por la constitución 1.0.1, principio IV)
- [x] T002 [P] Crear `docs/compras-gastos.md` con el esqueleto del tema (alcance, módulos, estados, pendientes) y mencionarlo en el mapa de `CLAUDE.md`

---

## Phase 2: Foundational (bloquea todas las historias)

**Esquema**

- [x] T003 Migración `database/migrations/2026_11_xx_create_tipos_comprobante_compra.php`: tabla con `nombre` único, `requiere_numero`, `numero_formato_legal`, `requiere_timbrado`, `requiere_fecha_comprobante`, `discrimina_iva`, `estado_id` (ACTIVO/INACTIVO, como los demás catálogos), timestamps; siembra factura legal (número, formato legal, timbrado, fecha y discrimina IVA: sí), ticket (número y fecha: sí), recibo (número y fecha: sí) y sin comprobante (todo no)
- [x] T004 Migración `database/migrations/2026_11_xx_create_compras.php` con todas las columnas de `data-model.md` (`tipo_comprobante_id` FK, `condicion` string, montos `bigint` null (los tres juntos; null si el tipo no discrimina IVA) ≥ 0, `iva_10/iva_5` null, `total` `bigint` > 0, `moneda_id` PYG fija, `registrada_por`, `confirmada_por/en`, `anulada_por/en`, `motivo_anulacion`) e índices `(fecha)`, `(proveedor_id, fecha)`, `(categoria_gasto_id, fecha)`, `(estado_id)`
- [x] T005 En la misma migración (T004): índice único parcial `compras_comprobante_unico (proveedor_id, tipo_comprobante_id, numero_comprobante) WHERE numero_comprobante IS NOT NULL AND anulada_en IS NULL` (SQLite y PostgreSQL; las ANULADAS no cuentan)
- [x] T006 En la misma migración (T004), solo PostgreSQL: CHECK `total > 0`; desglose todo o nada y, si hay, `total = monto_10 + monto_5 + monto_exento`; condición/vencimiento/anticipo (`vencimiento` obligatorio si CREDITO, `≥ fecha`, y null si CONTADO; `anticipo` 0 si CONTADO y `0 ≤ anticipo < total` si CREDITO); pares `confirmada_por/en` y `anulada_por/en/motivo_anulacion` juntos; `confirmada_por <> registrada_por` (la anulación sí puede ser de quien cargó, P5/P6); motivo de 5 a 300 caracteres
- [x] T007 Migración de módulos y permisos (puede ir en la de T004): módulos `COMPRAS`, `CONFIRMACION_COMPRAS` y `TIPOS_COMPROBANTE_COMPRA` (ACTIVO_INACTIVO) en `database/seeders/ModuloSistemaSeeder.php`; `estado_modulo` de `COMPRAS` con PENDIENTE (inicial), CONFIRMADO, ANULADO; permisos a los perfiles existentes de la base real (como la migración de Liquidaciones)
- [x] T008 [P] `app/Models/Permiso.php`: `ACCIONES_POR_MODULO` de COMPRAS (`VER, CREAR, EDITAR, DESACTIVAR`), CONFIRMACION_COMPRAS (`VER, EDITAR`) y TIPOS_COMPROBANTE_COMPRA (`VER, CREAR, EDITAR, DESACTIVAR`)
- [x] T009 [P] `app/Support/PerfilesPredefinidos.php`: matriz de `contracts/rutas-y-permisos.md` (ADMINISTRADOR todo; GERENCIA COMPRAS `VER, DESACTIVAR` y CONFIRMACION_COMPRAS `VER, EDITAR`; COMPRAS_TESORERIA COMPRAS `VER, CREAR, EDITAR`; TIPOS_COMPROBANTE_COMPRA: Administrador todo, GERENCIA y COMPRAS_TESORERIA `VER`, el resto nada)
- [x] T010 Test de esquema en `tests/Postgres/ComprasPostgresTest.php` (`clinexa_test`): índice único parcial (el duplicado se rechaza, el mismo número con la compra original anulada se acepta, número null no choca) y cada CHECK de T006

**Modelos y catálogo de tipos**

- [x] T011 [P] Modelo `app/Models/TipoComprobanteCompra.php` (`Auditable`, activo/inactivo, `deleting` lanza excepción) con las casillas como atributos booleanos
- [x] T012 Controlador `app/Http/Controllers/Admin/TipoComprobanteCompraController.php`, `app/Http/Requests/TipoComprobanteCompraRequest.php` y vistas `resources/views/admin/tipos-comprobante-compra/` (index, create, edit; activar/desactivar). `numero_formato_legal` solo con `requiere_numero`
- [x] T013 Ruta en `routes/web.php`: `$seccion('tipos-comprobante-compra', TipoComprobanteCompraController::class, 'TIPOS_COMPROBANTE_COMPRA', 'tipoComprobanteCompra', conBaja: true)`, más entrada en el menú del panel
- [x] T014 [P] Modelo `app/Models/Compra.php` (`Auditable`, `TieneEstado`, módulo de estado `COMPRAS`, cast de `condicion` a `CondicionCompra`; `deleting` lanza excepción; `updating` fuera de PENDIENTE lanza excepción, salvo la transición a CONFIRMADO o ANULADO hecha por `FlujoCompra`)
- [x] T015 [P] `app/Support/Compras/IvaCompra.php` con `Facturacion\Iva` (solo si el tipo tiene `discrimina_iva`; si no, sin desglose ni IVA, P4): `iva_10 = monto_10 ÷ 11`, `iva_5 = monto_5 ÷ 21`, al guaraní entero (la mitad hacia arriba); `total = monto_10 + monto_5 + monto_exento`
- [x] T016 Tests Unit en `tests/Unit/Compras/`: `IvaCompraTest` (110.000 al 10 % → 10.000; 21.000 al 5 % → 1.000; total 131.000), `CompraInmutableTest` (en `tests/Feature/Compras/`: necesita la base; sin borrado; no se edita fuera de PENDIENTE) y test Feature del catálogo de tipos (permisos: solo el Administrador crea/edita/desactiva; Gerencia y Compras ven; el resto no entra)

**Checkpoint**: esquema, permisos y catálogo de tipos listos.

---

## Phase 3: User Story 1 - Registrar una compra o gasto con su comprobante (P1) 🎯 MVP

**Objetivo**: cargar una compra de un proveedor activo con comprobante y categoría, y verla en el listado.
**Prueba independiente**: quickstart pasos 1 y 2 (alta con IVA y duplicado).

- [ ] T017 [US1] `app/Support/Compras/ValidarComprobante.php`: valida número, timbrado y fecha **solo mirando las casillas** del tipo (`requiere_numero`, `numero_formato_legal` con `^\d{3}-\d{3}-\d{7}$`, `requiere_timbrado`, `requiere_fecha_comprobante`); sin `requiere_numero`, el número queda null. La fecha del comprobante no puede ser futura; no hay otra restricción respecto de la fecha de compra. Nunca compara por nombre ni por id del tipo
- [ ] T018 [US1] `app/Support/Compras/RegistrarCompra.php`: crea en PENDIENTE con `registrada_por`, `moneda_id` PYG fija; con tipo que `discrimina_iva` guarda el desglose y el IVA por `IvaCompra`, y sin esa casilla guarda solo el total (montos e IVA null, P4); proveedor, categoría y tipo de comprobante activos (FR-002); detecta el duplicado (proveedor + tipo + número, sin contar ANULADAS) y devuelve el registro existente (apoyarse en `Unicidad`)
- [ ] T019 [P] [US1] `app/Http/Requests/CompraRequest.php`: reglas de FR-001/FR-003/FR-004/FR-013/FR-014: fecha de compra no futura (`Fecha::hoy()`), vencimiento no anterior a la fecha de compra, total > 0, CONTADO: sin vencimiento y anticipo 0; CREDITO: vencimiento obligatorio y `0 ≤ anticipo < total`; desglose por tasa solo si el tipo `discrimina_iva` (si no, solo total); mensajes en "usted", fechas dd/mm/aaaa vía `Fecha`
- [ ] T020 [US1] `app/Http/Controllers/Admin/CompraController.php` (`create`, `store`, `show`, `edit`, `update`), `app/Policies/CompraPolicy.php` (solo PENDIENTE se edita; descarte: P5) y rutas `compras.*` de `contracts/rutas-y-permisos.md` con `whereNumber` en `routes/web.php`
- [ ] T021 [P] [US1] Vistas `resources/views/admin/compras/create.blade.php`, `edit.blade.php`, `show.blade.php`: solo proveedores, categorías y tipos activos; los campos de timbrado, número, fecha del comprobante y el desglose por tasa (solo con `discrimina_iva`; si no, un único campo de total) se muestran u ocultan según las casillas del tipo elegido (script `resources/js/compra-formulario.js`, sin datos en el navegador); el error de duplicado enlaza a la compra existente
- [ ] T022 [P] [US1] Tests Feature en `tests/Feature/Compras/AltaCompraTest.php`: alta correcta y auditada; duplicado rechazado con enlace; duplicado permitido si la original está ANULADA; proveedor, categoría o tipo inactivos no se eligen y las compras antiguas se siguen viendo; factura sin timbrado rechazada; sin comprobante sin número; el control no depende del nombre del tipo (tipo nuevo con las mismas casillas se comporta igual); crédito sin vencimiento rechazado; perfil sin permiso no entra; fecha de compra futura, vencimiento anterior, total cero y anticipo igual al total rechazados; fecha del comprobante futura rechazada; fecha de compra pasada aceptada; fecha del comprobante anterior, igual o posterior a la de compra (sin ser futura) aceptada; ticket, recibo y sin comprobante guardan solo el total sin IVA, y un tipo nuevo con `discrimina_iva` desglosa (nunca por nombre)

**Checkpoint**: US1 funciona sola (MVP). Commit WIP.

---

## Phase 4: User Story 2 - Seguir el estado de cada compra o gasto (P2)

**Objetivo**: Gerencia confirma y anula con motivo; nadie confirma lo propio.
**Prueba independiente**: quickstart pasos de confirmación y anulación.

- [ ] T023 [US2] `app/Support/Compras/FlujoCompra.php`: `confirmar` (PENDIENTE → CONFIRMADO; rechaza con "No puede confirmar una compra que cargó usted." si `registrada_por` es quien confirma, también para el Administrador); `anular` (CONFIRMADO → ANULADO, solo Gerencia o Administrador, también lo propio, P6) y `descartar` (PENDIENTE → ANULADO, solo quien lo cargó o quien tenga COMPRAS,DESACTIVAR, P5); ambos con motivo de 5 a 300 caracteres y responsable y fecha con `Fecha`
- [ ] T024 [US2] Rutas `compras.confirmar` (CONFIRMACION_COMPRAS,EDITAR), `compras.anular` (COMPRAS,DESACTIVAR) y `compras.descartar` (COMPRAS,VER + policy) en `routes/web.php`; métodos en `CompraController` y reglas en `CompraPolicy` (oculta confirmar lo propio; descartar solo el borrador propio salvo COMPRAS,DESACTIVAR; Compras no anula confirmadas)
- [ ] T025 [P] [US2] Botones (confirmar, anular, descartar) y modal de motivo en `resources/views/admin/compras/show.blade.php`; auditoría de confirmar, anular y descartar con el detalle (motivo) vía `Auditable`
- [ ] T026 [P] [US2] Tests Feature en `tests/Feature/Compras/FlujoCompraTest.php`: Gerencia confirma lo que cargó otra persona; quien carga no confirma (ni el Administrador lo propio); Gerencia anula una confirmada aunque la haya cargado ella (motivo, fecha y responsable); Compras descarta su propio borrador pero no el ajeno ni anula confirmadas; Gerencia descarta cualquier borrador; confirmada y anulada son de solo lectura; todo auditado; nada se borra

**Checkpoint**: US1 + US2. Commit WIP.

---

## Phase 5: User Story 3 - Consultar y filtrar compras y gastos (P2)

**Objetivo**: filtros y total coherentes.
**Prueba independiente**: con registros de varios meses, el filtro devuelve exactamente los que coinciden y el total es la suma.

- [ ] T027 [US3] Listado `compras.index` en `CompraController` con filtros proveedor, categoría, `desde`/`hasta` (dd/mm/aaaa), estado y condición, y total del filtro (suma de `total`); índices de T004
- [ ] T028 [P] [US3] Vista `resources/views/admin/compras/index.blade.php` con filtros, estado "Borrador" para PENDIENTE y total; listado en vivo si corresponde (`resources/js/listado-en-vivo.js`)
- [ ] T029 [P] [US3] Tests Feature en `tests/Feature/Compras/ListadoComprasTest.php`: cada filtro devuelve solo lo que coincide, el total coincide con la suma, la persona sin permiso no ve el módulo ni los montos

**Checkpoint**: US1–US3. Commit WIP.

---

## Phase 6: User Story 4 - Datos listos para Cuentas por Pagar (P3)

**Objetivo**: las compras a crédito vigentes exponen proveedor, importe adeudado, vencimiento y estado.
**Prueba independiente**: quickstart paso del crédito (adeudado 81.000).

- [ ] T030 [US4] Scope `Compra::aCreditoVigentes()` (CONFIRMADO + CREDITO) y atributo derivado `adeudado = total - anticipo` si CREDITO, 0 si CONTADO, en `app/Models/Compra.php`; sin pantalla
- [ ] T031 [P] [US4] Test Unit `tests/Unit/Compras/CuentasPorPagarTest.php`: crédito confirmado aparece con proveedor, adeudado (131.000 − 50.000 = 81.000), vencimiento y estado; contado, borrador y anulada no aparecen

---

## Phase 7: Imagen del comprobante y tarea postergada

Las decisiones P2 a P6 de Enzo (2026-11-02) están fusionadas en T003–T006, T015, T017–T019 y T021–T026. Solo quedan:

- [ ] T032 [P7] (con US1/US3) Imagen del comprobante: JPG, PNG o PDF, máximo 5 MB (`mimes:jpg,jpeg,png,pdf|max:5120`); subida y ruta `compras.comprobante` (COMPRAS,VER) en `CompraController.php`, `CompraRequest.php`, vistas y `tests/Feature/Compras/ImagenComprobanteTest.php`; disco `local`, carpeta `compras/`, la anterior no se borra, la ruta se audita
- [ ] T033 [POSTERGADA: fuera de alcance de esta prueba, a definir después] Tratar notas de crédito/débito y su efecto en los totales (importe cero o negativo, devoluciones): modelo, validación, filtros y totales (`Compra.php`, `CompraRequest.php`, listado de T027). No implementar; no borrar la tarea

---

## Phase 8: Polish y cruce

- [ ] T034 [P] Completar `docs/compras-gastos.md` (estados, permisos, tipos de comprobante y sus casillas, duplicados sin anuladas, pendientes) y actualizar el mapa de `CLAUDE.md` (modelos, `app/Support/Compras/`, doc nuevo)
- [ ] T035 [P] Revisar textos en "usted" y fechas dd/mm/aaaa con `Fecha` en todas las vistas y mensajes nuevos de `resources/views/admin/compras/`, `tipos-comprobante-compra/` y de `app/Http/Requests/`
- [ ] T036 Recorrer `quickstart.md` en el navegador (lo hace Enzo antes de `/fusionar`) y agregar el recorrido a `docs/recorrido-manual.md`
- [ ] T037 Verificación manual de SC-001 y SC-002 (Enzo, con datos inventados): una compra completa con comprobante se registra en menos de 2 minutos (cronometrar); todo registro tiene tipo de comprobante y los tipos que lo requieren llevan número; ninguno queda sin auditar
- [ ] T038 `composer test:completo` antes de cualquier commit que no sea WIP; `/aplicar prueba-speckit` solo con confirmación de Enzo

---

## Dependencias

- Phase 1 → Phase 2 → historias. T004 depende de T003; T005 y T006 van dentro de T004; T010 depende de T004–T006; T012–T013 de T011; T014 de T004.
- US1 (Phase 3) depende de T011–T015. US2 depende de US1 (necesita compras cargadas). US3 depende de US1 (puede ir en paralelo con US2). US4 depende de US2 (necesita CONFIRMADO).
- Phase 7: T032 va con US1/US3 (después de T020). T033 postergada.
- Phase 8 al final.

## Paralelo

- Phase 1: T001 y T002. Phase 2: T008 y T009; T011, T014 y T015. US1: T019, T021 y T022 una vez hecho T018. US2: T025 y T026. US3: T028 y T029.
- Con una sola persona, el orden sugerido es el de los IDs.

## Estrategia

- **MVP**: Phase 1, Phase 2 y US1 (T001–T022). Probar en el navegador (Enzo) y seguir con US2, US3 y US4.
- Un commit WIP por fase; push al terminar cada etapa. T033 queda postergada hasta que Enzo la defina.
- Fuera de esta fase (decisión ya tomada): contado y anticipo no tocan la caja (P1, diferido).
