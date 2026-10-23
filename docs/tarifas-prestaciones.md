# Tarifas y prestaciones (fase 1 económica)

Las **tarifas** versionadas por procedimiento y la **prestación** (el servicio prestado) con su monto fijado. Sin descuentos,
cambio manual de monto, cobros, convenios ni facturación: son fases futuras. El reparto de cada prestación está en
`docs/reparto-economico.md`. Nada se borra.

## Moneda

Tabla `monedas` (código ISO, nombre, símbolo, decimales, estado), sin pantalla. La migración carga **Guaraní (PYG, ₲, sin
decimales)** como dato de referencia. El modelo admite otras monedas; hoy solo se usa PYG. Los montos se guardan como
`decimal(15,2)` y en pantalla se escriben en guaraníes enteros (`150000` o `150.000`) y se muestran como `₲ 150.000`.

## Tarifas

- Campos: procedimiento, profesional (**vacío = tarifa general** del procedimiento), monto (> 0), moneda, vigente desde, vigente
  hasta (inclusive) y estado **VIGENTE** / **HISTORICO**. Guarda también la tarifa a la que reemplazó y quién la cargó.
- **Nace vacía:** ninguna migración ni seeder trae precios.
- **Alta:** la primera de un procedimiento y profesional (o la general), desde cualquier fecha. Si ya hay una, "Use Cambiar
  tarifa".
- **"Cambiar tarifa" no edita:** cierra la vigente (pasa a HISTORICO con "hasta" = el día anterior a la nueva) y abre otra
  VIGENTE desde **hoy o una fecha posterior** (y no antes de que empiece la que se cierra). Si la nueva empieza el mismo día
  que la cerrada, la cerrada queda "Sin vigencia (reemplazada el mismo día)". Mismo monto: se rechaza.
- **VIGENTE = la abierta** (sin "hasta"): una sola por procedimiento y profesional. Una tarifa se aplica a un día por su
  **rango**, no por el estado: una HISTORICO sigue valiendo para los días de su rango, y una VIGENTE con fecha futura todavía no.
- El modelo `Tarifa` rechaza cualquier otro cambio y el borrado (`RegistroEconomicoInmutable`).

### Restricciones en la base

- `tarifas_una_vigente`: índice único parcial `(procedimiento_id, COALESCE(profesional_id, 0)) WHERE vigente_hasta IS NULL`
  (SQLite y PostgreSQL).
- Solo PostgreSQL: `tarifas_sin_solapamiento` (EXCLUDE con `daterange(desde, hasta + 1, '[)')` y btree_gist: los rangos de un
  mismo procedimiento y profesional no se solapan), `tarifas_monto_positivo` y `tarifas_rango_valido`
  (`hasta >= desde - 1`). En `prestaciones`: monto positivo, y monto, moneda y tarifa van juntos.

### Búsqueda (`Tarifas\BuscarTarifa`)

Para un procedimiento, un profesional y un día: la tarifa **del profesional** que se aplica ese día; si no hay, la **general**
del procedimiento.

## Prestación

- **Nace al FINALIZAR la consulta** (`Atencion\Finalizar` llama a `Prestaciones\GenerarPrestacion`, en su transacción). Guarda
  paciente, profesional, procedimiento, fecha de servicio, consulta y turno, monto, moneda, tarifa usada y estado. **Sin
  contenido clínico.**
- **Procedimiento:** el del turno (`consultas.turno_id` → `turnos.procedimiento_id`). Sin turno, o turno sin procedimiento: el
  procedimiento **Consulta**, por su código (`config('clinexa.prestaciones.procedimiento_consulta')`, `CONS-001` por defecto).
  Si tampoco existe, queda sin procedimiento (pendiente y no se puede tarifar).
- **Fecha de servicio:** el día de la atención (`iniciada_en`, en la hora local).
- Con tarifa para ese día: **TARIFADO**, con el monto fijado. Sin tarifa: **PENDIENTE** ("Pendiente de tarifar"), sin monto. Con una
  visación aprobada se usa la tarifa del convenio y se guarda quién paga cada parte (`docs/convenios-seguros.md`).
- **Nunca impide finalizar:** cada prestación (la base y la de cada informe) corre en un punto de guardado propio; si falla, se
  deshace solo lo suyo, se reporta en el log, queda un evento **FALLO** en Auditoría (tabla `prestaciones`, solo consulta,
  informe si es de uno, profesional y fecha de servicio, sin contenido clínico) y la consulta se finaliza igual.
- **La base, una por consulta** (`prestaciones_base_unica`). La adenda y el desbloqueo no pasan por Finalizar: no crean otra.
- **Una por cada informe de estudio** (`prestaciones.informe_estudio_id`, `prestaciones_informe_unica`): al finalizar, cada informe
  **vigente** con procedimiento (de tipo ESTUDIO, `docs/informes-estudio.md`) genera la suya: profesional de la consulta,
  procedimiento del informe, misma fecha de servicio, tarifa y reparto como la base (el equipo entra solo si el procedimiento usa
  uno). Sin tarifa, pendiente de tarifar; sin acuerdo, pendiente de reparto. **Sin duplicar:** si el turno es de ese mismo
  procedimiento, la base cubre UN informe (el primero en la pantalla); un segundo informe igual genera la suya. Un informe
  agregado o repuesto por adenda o desbloqueo no genera nada; uno retirado después **no** anula su prestación (la anula Gerencia
  con motivo). Informes de consultas finalizadas antes del hito `PRESTACIONES_INFORMES`, y los anteriores sin procedimiento,
  nunca generan. Una consulta puede tener varias prestaciones; el detalle muestra las demás de la misma consulta.
- **Origen** (`Prestacion::ORIGENES`): "Consulta/Turno" (la base) o "Informe de estudio", en el listado, el filtro y el detalle.
  Nunca se muestra el contenido del informe.
- **Consultas ya cerradas no generan prestación retroactiva** (la migración no crea ninguna). La migración guarda en
  `hitos_sistema` (código PRESTACIONES) el momento en que corre: lo finalizado antes nunca aparece ni se genera.
- **Consultas sin prestación** (en Prestaciones): las FINALIZADAS desde ese momento que no tienen la base
  (`GenerarPrestacion::sinPrestacion`) y los informes que debían generar la suya y no la tienen
  (`GenerarPrestacion::informesSinPrestacion`), con su origen. Todos los que ven la pantalla ven un aviso con las cantidades; la
  lista (las 50 más antiguas) y el botón **"Generar"** (`admin.prestaciones.generar` y `admin.prestaciones.generar-informe`) son
  solo de quien administra tarifas (EDITAR sobre TARIFAS: Administrador y Gerencia). "Generar" usa el mismo servicio que al
  finalizar (tarifa vigente el día del servicio, o pendiente); dos veces no duplica.
- **Tarifar** una pendiente: con la tarifa que se aplica a su **fecha de servicio** (no la de hoy). El monto queda fijado y no
  cambia si la tarifa cambia después. Sin monto manual.
- **Anular:** con motivo (5 a 300 caracteres), pendiente o tarifada. Se conservan fecha, motivo y quién. Nada se borra.
- El modelo `Prestacion` solo admite PENDIENTE → TARIFADO (una vez) y → ANULADO; rechaza el resto y el borrado.

## Pantallas (grupo "Económico" del menú)

- **Tarifas** (`admin.tarifas.*`): listado (solo vigentes, o también históricas; búsqueda por procedimiento o profesional),
  "Nueva tarifa", "Cambiar tarifa" e "Historial" (todas las versiones de ese procedimiento y profesional).
- **Prestaciones** (`admin.prestaciones.*`): listado con filtros de fecha de servicio (desde, hasta), profesional, estado, origen y
  paciente; botón "Tarifar" en las pendientes; detalle con "Anular la prestación"; aviso y bloque "Consultas sin prestación".

## Permisos

Módulos nuevos (en la matriz, grupo Económico):

| | TARIFAS | PRESTACIONES |
|---|---|---|
| Acciones | VER, CREAR (alta), EDITAR (cambiar) | VER, EDITAR (tarifar), DESACTIVAR (anular) |
| Administrador | todo (solo) | todo (solo) |
| Gerencia | VER, CREAR, EDITAR | VER, EDITAR, DESACTIVAR |
| Recepción, Caja, Facturación y convenios | VER | VER |
| Médico, Enfermería, Supervisión médica, Auditor, Compras | nada | nada |

El Médico no ve montos: no tiene los módulos y la pantalla de atención no muestra nada económico (en el informe de estudio ve solo
el nombre del procedimiento).

## Auditoría

CREAR de cada tarifa (alta) y de cada prestación (detalle "Al finalizar la consulta N"); en el cambio, EDITAR de la cerrada y
CREAR de la nueva con el detalle "Cambio de tarifa"; EDITAR "Tarifado" al tarifar; ANULAR con el motivo. Las tablas `tarifas` y
`prestaciones` tienen montos: sus valores se ocultan ("Contenido económico") a quien no tiene VER sobre TARIFAS o PRESTACIONES
(`Auditoria::TABLAS_ECONOMICAS`). Por eso el Auditor y Supervisión médica ven los eventos sin los montos.

## Migración

`create_tarifas_y_prestaciones`: tres tablas, el estado **TARIFADO** (los demás ya existían: VIGENTE, HISTORICO, PENDIENTE,
ANULADO), la moneda PYG y los dos módulos con su reparto. Crea los estados que falten, no lee filas durante
`migrate --pretend`, no duplica nada, no carga tarifas y en una instalación nueva deja los módulos al seeder.

`prestacion_de_informes_de_estudio`: `informes_estudio.procedimiento_id` y `prestaciones.informe_estudio_id` (vacíos), cambia el
único por consulta por los índices únicos parciales `prestaciones_base_unica` (consulta_id WHERE informe_estudio_id IS NULL) y
`prestaciones_informe_unica` (informe_estudio_id WHERE IS NOT NULL), en SQLite y PostgreSQL, y guarda el hito
`PRESTACIONES_INFORMES`. Las prestaciones existentes son todas base y cumplen las dos. No lee filas (`--pretend`) ni depende del
catálogo. El `down` solo vale sin prestaciones de informes.

## Tests

`tests/Feature/Economico/TarifasPrestacionesTest.php`, `MigracionTarifasTest.php`, `PrestacionInformesTest.php`,
`tests/Postgres/TarifasTest.php` (solapamiento y CHECK) y `tests/Postgres/PrestacionInformesTest.php` (índices parciales).
