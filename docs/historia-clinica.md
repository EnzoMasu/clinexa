# Historia clínica: cierre de la consulta

## La regla

Una consulta **cerrada** (FINALIZADA o ANULADA) queda llaveada:

- **No se modifica.** Ni la consulta ni nada de lo que cuelga de ella: bloques de anamnesis, examen físico
  (signos vitales y hallazgos), diagnósticos, indicaciones, recetas en cualquier estado y sus renglones.
- **No se elimina.** Nada de la historia clínica se borra.
- **No se desactiva.** Una paciente con consultas cerradas no pasa a INACTIVO, y tampoco su Persona.

El cierre es total ("falla cerrado"): no hay forma de modificar una consulta cerrada, ni siquiera el
profesional que la atendió ni el Administrador.

Únicas excepciones:

1. La transición **EN_CURSO → FINALIZADO** del servicio Finalizar: el estado, `finalizada_en` y el cierre
   del turno (ATENDIDO). Sale de una consulta abierta, así que la regla no la frena.
2. **Anular una receta EMITIDA** con su motivo (estado, fecha y motivo de anulación). Es lo único que se
   puede hacer con la consulta cerrada.

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

## Pendiente (sin implementar)

- **Adenda**: corregir una consulta cerrada agregando una nota fechada y firmada, sin modificar lo original.
- **Desbloqueo por un supervisor**: reabrir una consulta cerrada de forma excepcional, con motivo y registro.

Hasta que existan, una consulta cerrada no se corrige de ninguna manera.

## Tests

`tests/Feature/HistoriaClinica/ConsultaCerradaTest.php`: la matriz (cada sección y cada ruta de escritura,
con el profesional dueño, otro profesional, el Administrador y la enfermería), los cambios directos desde el
modelo, las excepciones permitidas, los borrados y la auditoría.
