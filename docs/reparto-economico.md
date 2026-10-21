# Reparto económico (fase 2 económica)

Los **acuerdos de reparto** (clínica, profesional, dueño del equipo), un **equipo** mínimo y el **reparto** de cada prestación,
fijado al crearse. Sin cobros, pagos al profesional o al dueño, liquidaciones, descuentos, montos fijos, uso de equipo por
paciente ni reserva en la agenda: son fases futuras. Nada se borra. Ver también `docs/tarifas-prestaciones.md`.

## Reglas del cálculo

- **Solo porcentajes**, de 0 a 100 con hasta dos decimales, sobre el **monto de la prestación tal cual** (el IVA se resuelve en
  Facturación). Montos en guaraníes enteros. Se calcula en centésimas enteras (`Repartos\Porcentaje`).
- **Profesional**: el porcentaje de su acuerdo para el procedimiento de la prestación (la excepción) o, si no tiene, su acuerdo
  **general**.
- **Equipo**: si el procedimiento usa un equipo **ACTIVO**, el porcentaje del acuerdo de ese equipo, para su **dueño** (un proveedor).
- **Clínica** = 100 − profesional − equipo.
- **Redondeo**: profesional y equipo al guaraní más cercano (la mitad, hacia arriba); la clínica recibe el resto, así la suma es
  exacta. Con los porcentajes en 100 justo, si los dos redondeos se pasaran por un guaraní, se ajusta el del equipo.
- Si el profesional es también el dueño del equipo, son **dos líneas** separadas.
- Los acuerdos se eligen por la **fecha de servicio** de la prestación y por su rango (no por el estado), como las tarifas. El equipo
  es el que el procedimiento usa al calcular (la asignación de equipos no está versionada en esta fase).

## Acuerdos (`acuerdos_reparto`, modelo `AcuerdoReparto`)

- **PROFESIONAL**: profesional, procedimiento (vacío = general), porcentaje. **EQUIPO**: equipo y el porcentaje de su dueño.
- Vigente desde / hasta (inclusive), estado **VIGENTE** / **HISTORICO**, a quién reemplaza y quién lo cargó. **Nace vacía.**
- **Alta**: la primera de una clave, desde cualquier fecha. Si ya hay una: "Use Cambiar porcentaje".
- **"Cambiar porcentaje" no edita**: cierra el vigente (HISTORICO, "hasta" = el día anterior) y abre otro desde **hoy o después**.
  Mismo porcentaje: se rechaza. El modelo rechaza cualquier otro cambio y el borrado.
- Base: `acuerdos_reparto_una_vigente` (índice único parcial por tipo, profesional, procedimiento y equipo, SQLite y
  PostgreSQL). Solo PostgreSQL: `acuerdos_reparto_sin_solapamiento` (EXCLUDE con daterange y btree_gist),
  `acuerdos_reparto_porcentaje_valido`, `acuerdos_reparto_rango_valido` y `acuerdos_reparto_clave_valida` (cada tipo con sus campos).

## Equipos (`equipos`, `equipo_procedimiento`, `tipos_equipo`)

- Nombre (único), tipo (catálogo `TipoEquipo`, editable, **nace vacío**), dueño (un **Proveedor**; en el formulario se ofrecen
  primero los de "Equipos médicos"), estado ACTIVO / INACTIVO y los procedimientos que lo usan.
- **Un procedimiento usa como máximo UN equipo activo**: `EquipoController` lo valida al guardar (alta, edición y reactivación),
  con los procedimientos bloqueados. Un equipo inactivo no reparte.
- No usa `turnos.equipo_id` ni toca la Agenda: la reserva de equipos queda para después.

## Reparto de la prestación (`repartos_prestacion`, `lineas_reparto`)

- **Uno por prestación** (`prestacion_id` único). Estados: **PENDIENTE** ("pendiente de reparto", con el motivo), **REPARTIDO** y
  **ANULADO**. Guarda el monto base, la moneda, el equipo, cuándo y quién repartió.
- **Una línea por beneficiario** (CLINICA, PROFESIONAL, EQUIPO; únicas por reparto): profesional o proveedor dueño, equipo,
  porcentaje, monto y los acuerdos usados. Inmutables (`LineaReparto` rechaza cambios y borrado). Solo PostgreSQL:
  `lineas_reparto_valores_validos` y `lineas_reparto_beneficiario_valido`.
- **Cuándo se calcula** (`Repartos\RepartirPrestacion::alFijarMonto`): al crearse la prestación con monto
  (`GenerarPrestacion`) o al tarifarla (`TarifarPrestacion`). Una prestación sin monto no se reparte hasta tarifarla.
- **Pendiente**: falta el acuerdo del profesional, o el del equipo cuando el procedimiento usa uno, o los porcentajes superan 100.
  Queda PENDIENTE con el motivo ("Falta el acuerdo del profesional para el 06/10/2026.").
- **Nunca frena** la finalización ni el tarifado: corre en un punto de guardado propio; si falla, se deshace solo lo suyo, se
  reporta en el log y queda un evento **FALLO** en Auditoría (tabla `repartos_prestacion`, solo prestación, profesional y fecha de
  servicio, sin contenido clínico). La prestación queda sin reparto y aparece entre las pendientes.
- **Fijo**: un reparto hecho no cambia aunque cambien los acuerdos.
- **Anular** la prestación anula su reparto ("Prestación anulada: motivo"); las líneas se conservan.
- La adenda y el desbloqueo no pasan por Finalizar: no crean otra prestación ni otro reparto.
- **Prestaciones anteriores** a esta migración no se reparten: la migración guarda el hito `REPARTOS` en `hitos_sistema`; solo
  entran las prestaciones creadas desde ese momento.

## Pantallas

- **Acuerdos de reparto** (`admin.acuerdos-reparto.*`, grupo Económico): listado (vigentes o también históricos; filtro por tipo;
  búsqueda por profesional, procedimiento o equipo), "Nuevo acuerdo" (del profesional) y "Nuevo acuerdo de equipo", "Cambiar
  porcentaje" e "Historial".
- **Equipos** (`admin.equipos.*`, Económico): listado, alta y edición con los procedimientos que lo usan, desactivar.
  **Tipos de equipo** (`admin.tipos-equipo.*`, Catálogos).
- **Prestaciones**: con VER sobre REPARTOS, aviso con la cantidad de pendientes de reparto, filtro "Pendientes de reparto" y el
  detalle del reparto en la prestación (líneas, porcentajes, montos y acuerdos usados). **"Repartir"** (en el listado y el detalle)
  para quien tiene EDITAR sobre ACUERDOS_REPARTO: recalcula con los acuerdos vigentes en la fecha del servicio; dos veces no
  duplica ("Esta prestación ya está repartida.").

## Permisos

| | ACUERDOS_REPARTO | REPARTOS | EQUIPOS | TIPOS_EQUIPO |
|---|---|---|---|---|
| Acciones | VER, CREAR (alta), EDITAR (cambiar porcentaje, Repartir) | VER (detalle y pendientes) | VER, CREAR, EDITAR, DESACTIVAR | VER, CREAR, EDITAR, DESACTIVAR |
| Administrador | todo (solo) | todo (solo) | todo (solo) | todo (solo) |
| Gerencia | VER, CREAR, EDITAR | VER | todo | todo |
| Los demás perfiles | nada | nada | nada | nada |

Médico, Enfermería, Recepción y Caja no ven porcentajes ni repartos: Recepción y Caja ven la prestación y su monto, sin la
sección de reparto, el aviso ni el filtro.

## Auditoría

CREAR de cada acuerdo, equipo y tipo; en el cambio, EDITAR del cerrado y CREAR del nuevo con el detalle "Cambio de porcentaje";
CREAR del reparto y de cada línea con el detalle "Reparto de la prestación N" (o "Repartir (prestación N)"); EDITAR del reparto
pendiente que se reparte; ANULAR con el motivo; los procedimientos de un equipo como lista de antes y después. Las tablas
`acuerdos_reparto`, `repartos_prestacion` y `lineas_reparto` tienen porcentajes y montos: se ocultan ("Contenido económico") a
quien no tiene VER sobre ACUERDOS_REPARTO o REPARTOS (`Auditoria::TABLAS_ECONOMICAS`).

## Migración

`create_reparto_economico`: seis tablas, el estado **REPARTIDO** (los demás ya existían), los cuatro módulos con sus estados y los
permisos de Gerencia. Crea los estados que falten, no lee filas durante `migrate --pretend`, no duplica nada, no carga acuerdos,
equipos ni tipos, no reparte prestaciones existentes y en una instalación nueva deja los módulos al seeder.

## Tests

`tests/Feature/Economico/RepartoEconomicoTest.php`, `MigracionRepartoTest.php` y `tests/Postgres/AcuerdosRepartoTest.php`
(solapamiento y CHECK).
