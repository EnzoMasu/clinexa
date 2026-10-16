# Retiros y rechazos

Salidas de un paciente sin ser atendido. Dos tipos: **RETIRO** (se retiró sin ser atendido) y **RECHAZO** (rechazó la
atención). El turno pasa al estado **RETIRADO** (final) en los dos casos; el tipo queda en `salidas_sin_atencion`.

## Quién registra qué

- **Recepción** registra el hecho desde Turnos y la Agenda general (botones "Retiro" y "Rechazo", EDITAR sobre TURNOS).
  Solo de turnos PENDIENTE, CONFIRMADO o SALTADO, de hoy o de días anteriores. Servicio: `RegistrarSalida`.
- **El cierre de jornada** del profesional registra un RETIRO (origen `CIERRE_JORNADA`) de quienes tenían el triaje
  iniciado o terminado; los demás pasan a AUSENTE como siempre.
- **El profesional del turno** puede completar una **nota clínica**, una sola vez (pantalla Consulta, "Retiros y
  rechazos"). `GuardarNotaSalida` + `ConsultaPolicy::notaDeSalida`. La nota es inmutable.

## Triaje terminado: solo retiro

Si el triaje está terminado (consulta EN_PREPARACION con `preparada_en`), el turno **no puede** pasar a CANCELADO ni a
AUSENTE (`Turno::puedePasarA` y `Turno::pasarA`): solo se registra su retiro o rechazo. Eso deja un **cargo pendiente**
(`cargo_pendiente`): solo una marca, sin monto, tarifa ni pagador, porque no existe Facturación. Con el triaje solo
iniciado todavía se puede cancelar; su retiro no genera cargo.

## Qué se conserva

Nada se borra. Al pasar a RETIRADO la consulta EN_PREPARACION pasa a ANULADO con todo lo cargado. La salida y la nota
quedan en Auditoría: la nota (`notas_salida`) es contenido clínico y se oculta a quien no tiene VER sobre
HISTORIA_CLINICA (`Auditoria::permisoDeContenido`).

## Recepción

No ve nada clínico: ni si el triaje era urgente, ni la observación, ni la nota. Ve el estado RETIRADO. La pantalla
"Retiros y rechazos" exige VER sobre HISTORIA_CLINICA y ser profesional activo.

## Fuera de esta fase

Derivación y constancia de triaje (fase siguiente). El reposo médico está en `docs/reposo-medico.md`. Las constancias de retiro y de estado están en `docs/constancias.md`.
