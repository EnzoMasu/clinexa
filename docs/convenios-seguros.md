# Convenios y visaciones (fase 4a económica)

Los **convenios** con las aseguradoras, la **cobertura** de cada paciente y las **visaciones** (la autorización que la aseguradora da
caso por caso), y cómo cambian la prestación y el cobro. El sistema **no** define qué cubre cada plan ni cuánto: lo decide la
aseguradora en cada visación, que Recepción carga. Sin liquidación mensual (fase 4b), facturas ni timbrado, notas de crédito,
contador de usos ni tope anual, planes de pago, saldos o crédito, ni reglas de cobertura por plan. Nada se borra. Ver también
`docs/tarifas-prestaciones.md`, `docs/reparto-economico.md` y `docs/cobros-caja.md`.

## Estructura

- **Responsable de pago** (el rol de Personas que ya existía: aseguradoras, empresas, otros profesionales, otras clínicas) →
  **convenio** (`convenios`: nombre, vigente desde / hasta, **VIGENTE** / **HISTORICO**) → **planes** (`planes_convenio`: solo un
  nombre, **ACTIVO** / **INACTIVO**). El convenio solo cambia su nombre o pasa a histórico con su último día; el plan se desactiva.
- **Tarifa del convenio** (`tarifas_convenio`): convenio, procedimiento, monto en guaraníes, vigencia y estado, versionada como las
  tarifas generales: una abierta por convenio y procedimiento (`tarifas_convenio_una_vigente`), y en PostgreSQL sin solapamiento
  (`tarifas_convenio_sin_solapamiento`). **Un solo formulario** para el alta y el cambio: si ya hay una, se cierra (HISTORICO, "hasta"
  = el día anterior) y se abre otra desde hoy o después. "Dar de baja" la cierra con su último día. Si el procedimiento no figura en el
  convenio, se usa la tarifa general (con la del profesional, como siempre).
- **Cobertura de la paciente** (`coberturas_paciente`): plan, número de asegurado, titular o beneficiario, vigente desde / hasta. Es
  solo un dato de afiliación para la visación y el ticket: no calcula nada. Una sola abierta por paciente (`coberturas_una_vigente`) y
  en PostgreSQL los rangos de una paciente no se solapan (`coberturas_paciente_sin_solapamiento`). Cargar otra cierra la vigente (queda
  en el historial). "Dar de baja" la cierra sin reemplazo.
- **Visación** (`visaciones`, `lineas_visacion`): ligada a la cobertura abierta de la paciente y a **un turno o una consulta**, con
  número (obligatorio, único por responsable de pago entre las no anuladas: `visaciones_numero_unico`), fecha, estado **VIGENTE** o
  **ANULADO** (con motivo) y líneas. Cada línea: procedimiento, resultado **APROBADO** o **NO_CUBIERTO** y, si es aprobado, lo que paga
  la paciente: **NINGUNO** (la aseguradora cubre todo), **PORCENTAJE** (0 a 100, dos decimales) o **MONTO_FIJO** (guaraníes).

## La prestación (`Convenios\CalcularCobertura`)

Al fijar el monto (al finalizar la consulta, también las de informe de estudio; al tarifar una pendiente) se busca una visación
**VIGENTE** de la consulta o del turno de la consulta con una línea para el procedimiento:

- **APROBADO**: la tarifa del convenio para ese día (o la general como respaldo). La paciente paga según la línea, nunca más que la
  tarifa; el resto, la aseguradora. Porcentaje redondeado al guaraní (la mitad, hacia arriba); el resto, a la aseguradora: la suma es
  exacta. Pagador **ASEGURADORA**; lo de la aseguradora, si es mayor que cero, queda **pendiente de liquidar** (`liquidacion` =
  PENDIENTE; la usa la fase 4b).
- **NO_CUBIERTO**: la tarifa **general** completa, a cargo de la paciente (pagador PACIENTE); queda la referencia a la visación.
- **Sin visación** para ese procedimiento, o con la cobertura o el convenio fuera de vigencia ese día: **particular** (tarifa general).
  Si la paciente tenía cobertura, la prestación guarda un **aviso** ("La visación N.º X no incluye este procedimiento", "La paciente
  tiene cobertura … y no hay visación", "La cobertura de la paciente venció el …"). Sin cobertura, sin aviso.
- **Nunca frena** la atención ni la finalización (corre dentro del punto de guardado de cada prestación, como antes).

El **snapshot** queda en la prestación y no cambia: pagador, responsable de pago, convenio, plan, visación y su línea, número de
visación, tarifa usada (`tarifa_id` o `tarifa_convenio_id`), monto total, `monto_paciente` y `monto_aseguradora`. En PostgreSQL:
`prestaciones_partes_validas` (suman exacto), `prestaciones_pagador_valido` (lo de la aseguradora, con su responsable, convenio, plan
y visación), `prestaciones_liquidacion_valida`, `prestaciones_firma_completa` y `prestaciones_monto_con_tarifa` (la general o la del
convenio, no las dos).

El **reparto** (profesional, equipo, clínica) no cambia: se calcula sobre el monto total, pague quien pague.

**Prestaciones anteriores** a la migración: no se recalculan (hito `CONVENIOS` en `hitos_sistema`). Quedan sin snapshot (pagador
vacío) y son particulares: la paciente paga el monto.

## Aplicar visación (`Convenios\AplicarVisacion`)

Para una visación que llegó después de finalizar (o una particular que se visa tarde): "Aplicar visación", en la página de la
paciente, con la visación (de esa consulta o de su turno, vigente, con línea para el procedimiento) o "Particular (sin visación)" y
un **motivo**. Solo si la prestación **no tiene cobro vigente** (si ya se cobró como particular, primero se revierte el cobro, fase
3b) y no está liquidada. Es el **único** caso en que se recalcula el snapshot; el estado anterior queda en Auditoría (EDITAR con el
antes y el después, detalle "Aplicar visación N.º X. Motivo: …"). La firma recibida vuelve a pedirse. Si el total cambia, el reparto
anterior queda **ANULADO** (con sus líneas, "Reemplazado al aplicar la visación…") y se calcula otro sobre el total nuevo
(`Repartos\RepartirPrestacion::reemplazar`; el único por prestación es entre los no anulados: `repartos_prestacion_uno_vigente`).

Una **visación anulada** deja a la prestación ya aplicada como está, con un aviso: la corrección es manual (revertir y reaplicar).

## Cobro en caja (cambia la fase 3)

- Se cobra **solo la parte de la paciente**. Se ofrecen las prestaciones con parte de la paciente mayor que cero; el total del cobro
  es la suma de esas partes, completo. Una con su parte en cero no se ofrece: se muestra **"A cargo de aseguradora"**. Las
  particulares no cambian.
- La reversión devuelve lo que la paciente pagó (las líneas del cobro, que ya eran por la parte cobrada).
- Estado de cobro (`Prestacion::estadoCobroTexto`): **Cobrada**, **Pendiente** o **A cargo de aseguradora** (parte de la paciente
  en cero).

## Firma recibida y ticket

- **Firma recibida** (`Convenios\FirmaRecibida`): marca por prestación de aseguradora, con fecha y usuario. Se desmarca con motivo
  mientras no esté liquidada.
- **Ticket** (`Convenios\TicketVisacion`): la planilla que firma la paciente, PDF con dompdf, al vuelo (sin guardarse, `no-store`),
  cada generación en Auditoría (EXPORTAR sobre `visaciones`). Con todas las prestaciones de aseguradora de la consulta: clínica y
  logotipo, profesional, fecha y hora, nombre y cédula de la paciente, aseguradora, plan, número de asegurado, número de visación,
  procedimientos o estudios realizados y la línea de firma. **Sin montos ni nada clínico.**

## Pantallas

- **Convenios** (`admin.convenios.*`, grupo Económico), con dos pestañas: **Convenios y planes** (listado, "Nuevo convenio" con sus
  planes en un solo paso, detalle con los planes, agregar, activar o desactivar, sus tarifas y "Pasar a histórico") y **Tarifas del
  convenio** (listado con filtro por convenio, "Cargar o cambiar tarifa", historial y "Dar de baja").
- **Cobertura y visaciones de la paciente** (`admin.coberturas.show`), sin entrada en el menú. El flujo de Recepción es desde el turno:
  en la Agenda general, en Turnos y en Urgencias de hoy, cada turno no cancelado lleva el botón **"Visación"**, y, si la paciente tiene
  cobertura vigente, "Seguro: aseguradora" y "Visación cargada" o "Falta visación" (solo con VER sobre VISACIONES, sin montos, sin frenar
  ninguna acción del turno; `EstadoVisacionTurnos`). El botón abre la página con el turno elegido y el procedimiento del turno como
  primera línea; Recepción completa número, resultado y qué paga la paciente (hasta cinco líneas). Sin cobertura vigente, los datos de la
  cobertura van arriba del mismo formulario y se guardan con la visación en **una sola transacción** (si falla una, no queda la otra);
  con cobertura, se muestra resumida con "Cambiar la cobertura". Al guardar se vuelve a la pantalla de origen (`volver`, solo rutas del
  propio sitio) con el aviso. Acceso secundario: la ficha y el listado de Pacientes ("Cobertura y visaciones"), y la prestación. Además:
  historial de coberturas, anular visaciones con motivo y las prestaciones con cobertura o aviso, con "Aplicar visación", la firma y el
  ticket. "Cupo entre pacientes" no lleva el botón: es la pantalla del cupo por profesional, no lista turnos (los turnos entre pacientes
  sí lo llevan en la Agenda general).
- **Prestaciones**: el pagador, las partes, la firma y el aviso en el detalle (con el enlace a la página de la paciente y el ticket);
  para quien ve las visaciones, el aviso "Hay N prestaciones con aviso de cobertura" y el filtro.
- **Cobrar**: la columna "A cobrar" (la parte de la paciente) y la lista "A cargo de aseguradora".

## Permisos

| | CONVENIOS | COBERTURAS | VISACIONES |
|---|---|---|---|
| Acciones | VER, CREAR (alta y cambio de tarifa), EDITAR (agregar plan), DESACTIVAR (plan, histórico, baja de tarifa) | VER, CREAR (cargar o cambiar), EDITAR (dar de baja) | VER (y los avisos), CREAR (cargar), EDITAR (aplicar, firma), DESACTIVAR (anular), EXPORTAR (ticket) |
| Administrador | todo (solo) | todo (solo) | todo (solo) |
| Gerencia | todo | VER | VER, EXPORTAR |
| Facturación y convenios | todo | VER, CREAR, EDITAR | todo |
| Recepción | nada | VER, CREAR, EDITAR | todo |
| Caja, Médico, Enfermería y los demás | nada | nada | nada |

Caja cobra la parte de la paciente con sus permisos de siempre. Médico y Enfermería no ven montos ni visaciones. En Auditoría, los
valores de `convenios`, `planes_convenio` y `tarifas_convenio` se ocultan a quien no tiene VER sobre CONVENIOS; `coberturas_paciente`,
sobre COBERTURAS; `visaciones` y `lineas_visacion`, sobre VISACIONES (`Auditoria::TABLAS_ECONOMICAS`). El snapshot de la prestación,
como siempre, a quien no tiene VER sobre PRESTACIONES.

## Auditoría

CREAR de cada convenio y plan ("Alta del convenio"), tarifa ("Alta de la tarifa del convenio"; en el cambio, EDITAR de la cerrada y
CREAR de la nueva, "Cambio de tarifa del convenio"; "Baja de la tarifa del convenio"), cobertura ("Alta de la cobertura" o "Cambio de
cobertura", con el EDITAR de la cerrada; "Baja de la cobertura") y visación con sus líneas ("Visación N.º X"); ANULAR de la visación
con el motivo; DESACTIVAR o EDITAR del plan; EDITAR del convenio pasado a histórico; EDITAR de la prestación al aplicar una visación
y con la firma ("Firma recibida", "Firma desmarcada. Motivo: …"); EXPORTAR del ticket. Al finalizar la consulta no se agrega ningún
evento: la prestación (su CREAR) trae el snapshot.

## Migración

`create_convenios_y_visaciones`: seis tablas, el snapshot en `prestaciones` (vacío en las existentes), el único parcial de los
repartos, los CHECK y EXCLUDE de PostgreSQL, el hito `CONVENIOS`, los tres módulos y los permisos
(`PerfilesPredefinidos::PERMISOS_CONVENIOS`, lo mismo que la matriz). Crea los estados que falten, no lee filas durante `--pretend`,
no carga convenios ni tarifas, no recalcula prestaciones ni cobros y en una instalación nueva deja los módulos al seeder. En SQLite
vuelve a crear los únicos parciales de `prestaciones` (SQLite rehace la tabla al agregar claves foráneas y pierde su WHERE). El
`down` solo vale sin prestaciones de aseguradora ni repartos reemplazados.

## Tests

`tests/Feature/Economico/ConveniosVisacionesTest.php`, `MigracionConveniosTest.php` y `tests/Postgres/ConveniosVisacionesTest.php`
(los EXCLUDE de cobertura y tarifa del convenio, el número único, los CHECK y la migración con prestaciones existentes).
