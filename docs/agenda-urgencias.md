# Entre pacientes y urgencias

Además de los turnos con horario, la agenda tiene dos tipos de turno sin horario: **entre pacientes** y **urgencia**.

## Tipos de turno

`turnos.tipo` (enum `App\Enums\TipoTurno`, CHECK `turnos_tipo_valido` en PostgreSQL):

| Tipo | Horas y consultorio | Dónde se da | Origen |
|---|---|---|---|
| `NORMAL` (con horario) | obligatorios | Nuevo turno, "Con horario" | el que se elija |
| `ENTRE_PACIENTES` | nulos | Nuevo turno, "Entre pacientes" | el que se elija |
| `URGENCIA` | nulos | Turnos, "Registrar urgencia" | siempre `PRESENCIAL` (por código) |

- El CHECK `turnos_horas_segun_tipo` exige horas y consultorio en `NORMAL`, y horas nulas en los otros dos.
- Los turnos que ya existían quedaron `NORMAL`; la migración lo verifica.
- **No hay estados nuevos** y las transiciones (`Turno::TRANSICIONES`) son las de siempre.

### Las horas nulas y las restricciones EXCLUDE

`rango` es una columna generada `tsrange` que usan las tres restricciones EXCLUDE (por profesional, por consultorio
y por paciente).

En PostgreSQL, `tsrange(NULL, NULL)` **no es NULL**: es el rango ilimitado `(,)`, que se superpone con todo. Por eso
`rango` es NULL cuando falta alguna hora:

```
CASE WHEN hora_inicio IS NULL OR hora_fin IS NULL THEN NULL ELSE tsrange(...) END
```

Con `rango` NULL, `&&` da NULL y las EXCLUDE no se aplican. La migración rehace `rango` y vuelve a crear las tres
EXCLUDE con su misma definición. Los tests de `tests/Postgres/TurnosTiposTest.php` lo comprueban.

### Reglas de los turnos sin horario

- **No ocupan horarios.** El cálculo de horarios libres (`Agenda`) y la superposición de la paciente
  (`Turno::superpuestoDeLaPaciente`) los ignoran.
- **Fecha.** No se agendan en una fecha pasada.
- **"Hora".** Su "hora" llega al **comienzo de su día** (hora de Paraguay): `Turno::llegoSuHora` usa
  `Fecha::yaLlego(fecha, '00:00')`.
  - "No se presentó" y "Pasar a ausente" valen el mismo día.
  - Los de días anteriores se cierran con Cerrar jornada.
- **Segundo turno del día.** La confirmación "segundo turno del día con el mismo profesional" aplica a cualquier
  combinación de tipos.
- **En pantalla.** Se muestran con una pastilla, "Entre pacientes" (azul) o "Urgencia" (rojo), en lugar de la hora
  (componente `x-admin.turno-hora`).
- **Orden de las listas del día** (`Turno::scopeOrdenDelDia`):
  1. urgencias;
  2. los de horario, por hora;
  3. los entre pacientes, por orden de carga.

## Cupo entre pacientes

El médico le dice a recepción cuántos pacientes "entre pacientes" atenderá en la jornada.

### Dónde se fija

- **Tabla:** `cupos_entre_pacientes`, con un registro por profesional y fecha. `cantidad` va de 0 a 30 y está
  auditada.
- **Pantalla:** Turnos → "Cupo entre pacientes" (`CupoEntrePacientesController`). Muestra la fecha (hoy por
  defecto) y los profesionales que atienden ese día, con:
  - el cupo;
  - los agendados: turnos ENTRE_PACIENTES no cancelados;
  - un campo para fijarlo.
- **Permisos:** VER sobre DISPONIBILIDAD para mirar; CREAR o EDITAR para fijar. No hay módulo nuevo.

### Reglas

- El profesional debe tener una disponibilidad activa ese día. Si no: "El profesional no atiende ese día.".
- La fecha va de hoy a 60 días adelante.
- El cupo no baja de los ya agendados.
- Sin cupo fijado, el cupo es 0.
- **Concurrencia.** Fijar el cupo y agendar un entre pacientes bloquean la misma fila del cupo (`lockForUpdate`).
  Dos envíos casi simultáneos no pasan el cupo. `tests/Postgres/EntrePacientesConcurrenciaTest.php` lo prueba con
  dos conexiones.

## Urgencias

### Registrar una urgencia

Recepción usa Turnos → "Registrar urgencia" (`UrgenciaController`, CREAR sobre TURNOS):

- **Paciente:** el buscador de siempre, solo con pacientes activos y con el texto de ayuda para un paciente no
  registrado o inactivo. El paciente tiene que estar registrado.
- **Profesional:** todos los activos; primero los que atienden hoy.
- **Sin texto libre:** lo clínico lo escribe el triaje.

Se crea un turno con estos datos:

- `URGENCIA` de hoy, PENDIENTE;
- origen `PRESENCIAL`, buscado por código; si falta, el error lo dice;
- `llegada_en` = ahora;
- en la auditoría, CREAR con el detalle "Urgencia registrada".

### Una sola urgencia abierta

Una paciente no puede tener dos urgencias abiertas (PENDIENTE, CONFIRMADO, SALTADO o EN_CONSULTA) con el mismo
profesional el mismo día. Si lo intenta: "La paciente ya tiene una urgencia abierta con ese profesional.".

- Se valida con la fila de la paciente bloqueada.
- Lo respalda el índice único parcial `turnos_una_urgencia_abierta`, en PostgreSQL y SQLite.

### "Urgencias de hoy"

El panel de recepción (`admin.turnos.urgencias`) muestra:

- paciente, profesional, hora de registro y estado;
- **nada clínico:** ni la observación del triaje, ni signos alterados, ni las urgencias que marcó el triaje.

Con EDITAR sobre TURNOS se puede cancelar una urgencia **solo mientras está PENDIENTE**, por si se registró por error.
No hay reasignación.

### Qué cuenta como urgencia para el profesional

- los turnos `URGENCIA`;
- los turnos de cualquier tipo cuya consulta de triaje (no anulada) está marcada urgente (`triaje_urgente`).

## Pantalla del profesional (Consulta)

### Los botones

- **"Atender sin turno".** Antes se llamaba "Sin turno (urgencia)"; las consultas sin turno ahora dicen "Sin turno".
- **"Entre pacientes (N)":** sus turnos ENTRE_PACIENTES de hoy en PENDIENTE, CONFIRMADO o SALTADO.
- **"Urgencias (N)":** sus turnos de hoy por atender que son urgencia.
  - Cada turno cuenta una sola vez.
  - No cuentan EN_CONSULTA, ATENDIDO, CANCELADO ni AUSENTE.

La lógica está en `App\Support\Atencion\PendientesDelProfesional`.

### Los contadores

- Se actualizan cada 15 s con `admin.atencion.contadores`, que devuelve solo:
  - los dos conteos;
  - la última urgencia: id de turno y nombre del paciente.
- **Datos:** sin contenido clínico y en JSON con `< > & ' "` codificados.
- **Auditoría:** no registran VER.
- **Sesión vencida o 403:** se detienen, como el refresco de la lista.

### Los popups

Los dos popups (`resources/js/pendientes-profesional.js`) funcionan igual:

- **Carga:** por fetch, solo al hacer clic.
- **Contenido:** plantillas Blade escapadas, servidas con SinAlmacenar e insertadas con `fragmento-inerte.js`.
- **Accesibilidad:** `role="dialog"`, Esc y foco atrapado.
- **Al cerrarse:** se vacían.

**Entre pacientes** (`_entre-pacientes`):

- **Cada fila:** paciente (apellido y nombre, edad, ficha), hora de carga, estado del triaje y "Signos alterados".
- **Botones:** "Atender" y "No se presentó"; en un turno que ya no se presentó, "Pasar a ausente".
- **Orden:** por orden de carga, como guía; el profesional elige.
- **Sin la observación** y no registra VER.

**Urgencias** (`_urgencias`):

- **Cada fila:**
  - paciente;
  - origen: "Recepción" o "Triaje";
  - hora de registro o de la marca del triaje;
  - estado del triaje y "Signos alterados";
  - la observación del triaje;
  - el botón "Atender".
- **Orden:** la más antigua primero.
- **Auditoría:** como trae contenido clínico, registra un VER sobre cada consulta cuya observación muestra, con el
  detalle "Urgencias: observación del triaje" y la regla de 5 minutos (`Auditoria::registrarLecturas`, dos
  consultas fijas).

### Aviso de urgencia nueva

Cuando aparece una urgencia que no estaba, se muestra un cartel fijo arriba:

- dice "Nueva urgencia: APELLIDO, NOMBRE", solo con el nombre, sin la observación, puesto con `x-text`;
- tiene el botón "Ver urgencias";
- se cierra al abrir el popup o con la X, y no reaparece por la misma urgencia;
- **sin sonido.**

Los ids ya vistos quedan **solo en memoria**: nada se guarda en el navegador.

Mientras haya urgencias, el título de la pestaña muestra "(N) Urgencias".

### Atender

- **Los tres tipos:** `Atender`, con las reglas de siempre. Atiende el profesional del turno, con CREAR sobre
  HISTORIA_CLINICA, un turno de hoy y en un estado válido. No se valida la hora.
- **Urgencia con otra consulta en curso.** Atender una urgencia (URGENCIA o triaje urgente) cuando el profesional
  tiene otra consulta EN_CURSO pide confirmación: "Tiene una consulta en curso con NOMBRE. ¿Atender la urgencia
  igualmente?".
  - El servidor la exige (`confirmar_urgencia=1`).
  - La consulta en curso queda abierta y se retoma con "Continuar".
  - El detalle del evento lo deja dicho: "Atender. Urgencia con la consulta #ID en curso, confirmado".

### Las listas

- **"Agenda de hoy" y "Por llamar de nuevo"** muestran solo turnos con horario. Los otros dos tipos viven en sus
  botones.
- **"En consulta" y "Atendidos hoy"** los muestran con su pastilla, igual que la barra de la pantalla de atención.

## Triaje

La lista de Triaje incluye los entre pacientes y las urgencias de hoy de todos los profesionales, con la pastilla en
lugar de la hora. El triaje de estos turnos funciona como el de cualquier otro.

Orden de la lista:

1. urgencias;
2. urgentes marcados por el triaje;
3. el resto por hora;
4. los entre pacientes, por orden de carga.

## Cerrar jornada

Cerrar jornada incluye los entre pacientes y las urgencias no atendidos: pasan a AUSENTE como los demás.

### Advertencia de urgencias

Si entre los que se cierran hay urgencias (URGENCIA o triaje urgente):

- la vista previa muestra la sección roja "Atención: urgencias sin atender";
- tiene la casilla "Confirmo que revisé las urgencias", que el servidor también exige
  (`CerrarJornada::FALTA_CONFIRMAR_URGENCIAS`);
- el detalle del cierre dice cuántas se revisaron.

### Qué pasa con el triaje

Cuando un turno pasa a AUSENTE, `Turno::pasarA` pasa su consulta EN_PREPARACION a **ANULADO** en la misma
transacción.

**No se borra nada.** Se conservan:

- la consulta, con urgente, observación y quién y cuándo la marcó;
- el examen físico y los signos alterados;
- los bloques de anamnesis.

Pero una consulta ANULADA no aparece en los listados, el historial ni los conteos. Lo prueba
`CerrarJornadaTiposTest`.

## Seguridad y auditoría

- **Módulos:** no hay módulos nuevos.
- **Lo propio:** el médico solo ve y atiende lo suyo; con lo ajeno recibe 403.
- **Recepción** no ve contenido clínico.
- **Respuestas:** todo va escapado, y los fragmentos y popups van con SinAlmacenar.
- **Auditoría:**
  - cupo: CREAR y EDITAR con antes y después;
  - alta de cada tipo: "Entre pacientes", "Urgencia registrada" y "... Segundo turno del día confirmado";
  - cancelación de una urgencia: "Urgencia cancelada";
  - atención y la confirmación con una consulta en curso;
  - cierre con urgencias revisadas;
  - el VER del popup de urgencias.

## Pendiente

### Constancia visible del retiro (etapa de estados)

Un paciente con triaje urgente que se va sin atenderse queda con su consulta ANULADA, con los datos conservados
pero invisible. Para que quede constancia visible:

1. **Un motivo de cierre del turno.** Sumar una columna `turnos.motivo_cierre` (por ejemplo "Se retiró sin
   atenderse", "No se presentó", "Cierre de jornada") y completarla en `pasarA(AUSENTE)`. No cambia los estados.
2. **Un estado de consulta propio.** En lugar de ANULADO, un estado `RETIRADO` para la consulta con triaje. Seguiría
   siendo de solo lectura (cerrada) y no contaría como atención.
3. **En la historia clínica:** una fila "Se retiró sin atenderse", con la fecha, el triaje (urgente, observación y
   signos) y quién lo cargó. Las consultas `RETIRADO` serían visibles; las ANULADAS seguirían ocultas.
4. **En Cerrar jornada:** si se cierra una urgencia con triaje, pedir el motivo (retiro o no presentación) en lugar
   de la casilla general.

### Grilla de recepción

Una vista del día por profesional con los turnos con horario, los entre pacientes (cupo usado de cupo total) y las
urgencias registradas, para que recepción vea de un vistazo la carga de cada consultorio.
