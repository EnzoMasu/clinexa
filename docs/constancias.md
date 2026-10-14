# Constancias de retiro y de estado

Documentos que se imprimen desde el navegador (sin librerías), con el patrón de la receta: **inmutables**, con **número**,
**snapshot** (la hoja se dibuja siempre desde él) y **huella sha256**. Nacen emitidos (no hay borrador) y solo se pueden
**anular con motivo**. Nada se borra. Emitir y anular quedan en Auditoría (CREAR y ANULAR con el motivo).

## Texto provisorio

Todos los textos están en `config/constancias.php` (único lugar). Son **provisorios**: neutros y fácticos, sin revisión legal.
Mientras `constancias.borrador` sea `true`, cada constancia nueva sale marcada **BORRADOR SUJETO A REVISIÓN** (cartel, pie y
diagonal). Cuando el profesor o un asesor apruebe el texto: reemplazarlo en ese archivo y poner `borrador` en `false`. Las ya
emitidas conservan su texto y su marca (están en su snapshot y en su huella).

## Constancia de retiro (`CR-0000001`)

- La emite **Recepción** (`CONSTANCIAS_RETIRO`: VER, CREAR, EDITAR) desde Turnos, sobre un turno RETIRADO (retiro o rechazo).
- **Nada clínico**: solo paciente (nombre y documento), clínica, fecha y hora del hecho, fecha y hora del turno, y si fue retiro o
  rechazo. Ni urgencia, ni observación, ni nota, ni signos, ni cargo pendiente, ni tipo de turno. La lista de datos es cerrada
  (`DatosConstancia`) y un test la fija.

## Constancia de estado del turno (`CE-0000001`)

- La emite el **profesional ACTIVO del turno** o **Supervisión médica** (`CONSTANCIAS_ESTADO`: VER, CREAR, EDITAR; Supervisión se
  reconoce por tener VER sobre `CORRECCIONES_HC`). Otro médico no la emite, ni la anula, ni la lee.
- Solo **hechos del turno**, según su estado: ATENDIDO (se presentó y el turno figura como atendido), AUSENTE (no se presentó),
  RETIRADO (se presentó y se retiró o rechazó la atención). Incluye el profesional (nombre y matrícula). Sin diagnóstico ni datos
  clínicos. No aplica a turnos pendientes, confirmados, en consulta ni cancelados.
- Se emite desde Turnos (Médico) y desde la pantalla de la consulta (Supervisión, que no ve Turnos).

## Reglas comunes

- Una sola constancia EMITIDA por turno y tipo (índice único parcial). Emitir de nuevo abre la existente. Anulada, se puede emitir otra.
- La huella es el sha256 del snapshot en forma canónica (claves ordenadas). Si no coincide al abrir la hoja, avisa y no imprime.
- Permisos nuevos por la matriz existente: `CONSTANCIAS_RETIRO` a Recepción; `CONSTANCIAS_ESTADO` a Médico y Supervisión médica
  (migración `create_constancias`, una sola vez; no pisa lo cambiado desde la pantalla).

## Fuera de esta fase

Derivación, reposo médico y constancia de triaje. Revisión legal del texto (ver `docs/produccion.md`).
