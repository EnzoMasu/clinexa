# Historia clínica: cierre de la consulta

## La regla

Una consulta **cerrada** (FINALIZADA o ANULADA) queda llaveada:

- **No se modifica.** Ni la consulta ni nada de lo que cuelga de ella: bloques de anamnesis, examen físico
  (signos vitales y hallazgos), diagnósticos, indicaciones, recetas en cualquier estado y sus renglones.
- **No se elimina.** Nada de la historia clínica se borra.
- **No se desactiva.** Una paciente con consultas cerradas no pasa a INACTIVO, y tampoco su Persona.

El cierre es total ("falla cerrado"): no hay forma de modificar una consulta cerrada, ni siquiera el
profesional que la atendió ni el Administrador, salvo por las correcciones autorizadas.

Únicas excepciones:

1. La transición **EN_CURSO → FINALIZADO** del servicio Finalizar: el estado, `finalizada_en` y el cierre
   del turno (ATENDIDO). Sale de una consulta abierta, así que la regla no la frena.
2. **Anular una receta EMITIDA** con su motivo (estado, fecha y motivo de anulación).
3. **Las correcciones autorizadas** (adenda y desbloqueo, más abajo): la adenda agrega una nota aparte y el desbloqueo
   abre una ventana de un solo uso con versión previa.

## Dónde está

| Capa | Qué hace |
|---|---|
| Modelos (`App\Models\Concerns\ProtegidoPorCierre`) | La regla de fondo. Rechaza crear, modificar o borrar en una consulta cerrada (`ConsultaCerrada`, 409) desde cualquier código: controladores, servicios, tinker. Mira el estado **guardado** de la consulta. |
| Builder (`App\Models\Builders\ConsultaClinica`) | Rechaza los cambios y borrados en masa (`where(...)->update()`, `->delete()`, `truncate()`), que no disparan los eventos del modelo. |
| Middleware `consulta.abierta` | En cada ruta de escritura sobre una consulta o sus recetas: 409 "La consulta está cerrada y no puede modificarse." a quien pase el permiso de la ruta (sin permiso, 403, sin revelar nada del registro). Anular una receta no lo lleva. |
| Pantallas | No ofrecen edición: la lectura muestra "Consulta cerrada: no puede modificarse."; no hay botón Editar ni "Guardar cambios". |

Lo que se escribe por fuera de Eloquent (`DB::table(...)`) no pasa por los modelos: solo lo usan las
migraciones. No hay triggers en PostgreSQL para esta regla (sí para el log de auditoría).

## Consecuencias

- **Se quitó la edición de consultas finalizadas** (rutas `consultas.edit` y `consultas.update`, el servicio
  `GuardarCambios`, el botón Editar y su popup).
- **Las recetas se crean, editan y emiten solo con la consulta EN_CURSO.** "Anular y corregir" ya no crea un
  reemplazo en una consulta cerrada: allí solo se puede anular.
- **Finalizar se rechaza con recetas en BORRADOR**: "Tiene recetas en borrador. Emítalas o anúlelas antes de
  finalizar." (después, con la consulta cerrada, ya no se podría).
- **Un segundo envío de Finalizar** encuentra la consulta cerrada y responde 409, sin escribir nada.
- **El autoguardado** solo vale en EN_PREPARACION y EN_CURSO.

## Nada se borra

No hay rutas DELETE. Los modelos de la historia clínica rechazan el borrado (`RegistroClinicoNoSeBorra`),
con una sola excepción: **"Quitar" un renglón de una receta en BORRADOR**. Una fila nueva que todavía no se
guardó se "Descarta" en la pantalla, sin tocar la base.

## Corregir una consulta cerrada: adenda y desbloqueo

La consulta FINALIZADA sigue de solo lectura. Hay dos vías de corrección, ambas con **motivo obligatorio**, y
nunca se pisa lo original sin dejar versión. Módulo nuevo **CORRECCIONES_HC** (VER, CREAR, EDITAR; sensible: se
registran sus lecturas). Lo reciben el perfil Supervisión médica (por la migración) y el Administrador; nadie más.

### Adenda (nota agregada)

- Se agrega a una consulta FINALIZADA. **Nunca se edita ni se borra.** Campos: motivo (10 a 300 caracteres), texto
  (hasta 3000), autor y fecha. No toca la consulta.
- La pueden agregar: el profesional ACTIVO de la consulta con CREAR sobre HISTORIA_CLINICA, o quien tenga CREAR sobre
  CORRECCIONES_HC (`ConsultaPolicy::agregarAdenda`).
- Se ve en la lectura (página y popup), debajo de lo original, con autor y fecha, para quien ve la consulta.

### Desbloqueo (corrección autorizada)

- Solo con **EDITAR sobre CORRECCIONES_HC** y **nunca para quien atendió esa consulta** (su persona es el profesional
  de la consulta): "Esta consulta la atendió usted: otro supervisor tiene que desbloquearla." (`ConsultaPolicy::desbloquear`).
  Como el Administrador tampoco puede desbloquear lo que atendió, **hacen falta al menos dos supervisores** (o un
  supervisor y el Administrador) para poder corregir las consultas de cada uno.
- Pide motivo (20 a 500). Abre una **ventana de un solo uso, vigente 24 horas, una por consulta a la vez** (índice único
  parcial en la base), cancelable. Estados del catálogo compartido: VIGENTE, REALIZADO (se usó), VENCIDO y CANCELADO. El
  vencimiento se aplica al consultar (pantalla, corrección o nuevo desbloqueo).
- Mientras está vigente se corrigen **solo**: motivo, bloques de anamnesis (editar, agregar, retirar), hallazgos,
  indicaciones y diagnósticos, con las validaciones de siempre (`FormularioConsulta`, grupos CLINICO y ANAMNESIS, reglas
  finales; `GuardarSecciones`). **Jamás**: signos vitales (ni su autoría ni sus alertas), triaje, recetas, paciente,
  profesional, fechas, estado ni autoría de la consulta. Un pedido con cualquiera de esos campos se rechaza entero.
- **Un solo guardado**: al guardar, la ventana pasa a REALIZADO y la consulta queda cerrada otra vez. Si no cambió nada
  no se consume.

### Cómo se levanta el cierre

El permiso de escritura se abre **solo** dentro de `VentanaDeCorreccion::dentro()`, ligado a un desbloqueo de esa
consulta, y se cierra al terminar (también si hay error): nada queda abierto entre pedidos. Adentro, `ProtegidoPorCierre`
deja pasar únicamente las tablas y campos de `VentanaDeCorreccion::PERMITIDOS` de esa consulta; todo lo demás sigue dando
409. `CorregirConsulta` corre en una transacción, con la consulta y el desbloqueo bloqueados, y revalida vigencia, supervisor
y estado.

### Versiones y auditoría

- Antes de aplicar cada corrección se guarda una **versión** inmutable (`consulta_versiones`: número, `snapshot` con todas
  las secciones corregibles como estaban, desbloqueo, usuario y fecha). La lectura muestra "Corregida N veces" y, con VER
  sobre CORRECCIONES_HC, la sección "Historial de correcciones" con el antes de cada una y el motivo.
- Auditoría: CREAR de adenda, de desbloqueo y de versión; EDITAR de la consulta (antes y después) con el detalle
  "Corrección autorizada (desbloqueo #N)"; cancelación y vencimiento. Las tablas `adendas_consulta`, `desbloqueos_consulta`
  y `consulta_versiones` son contenido clínico: se ocultan a quien no tiene VER sobre HISTORIA_CLINICA.

## Tests

`tests/Feature/HistoriaClinica/ConsultaCerradaTest.php`: la matriz (cada sección y cada ruta de escritura,
con el profesional dueño, otro profesional, el Administrador y la enfermería), los cambios directos desde el
modelo, las excepciones permitidas, los borrados y la auditoría.
`tests/Feature/HistoriaClinica/CorreccionesTest.php`: adenda y desbloqueo (quién puede), lista blanca, un solo uso y
vencimiento, alcance del permiso, versión, auditoría y escapado.
