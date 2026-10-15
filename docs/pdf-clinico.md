# PDF clínico (triaje y consulta)

PDF de **triaje** y de la **consulta**, generados en el servidor con **dompdf** (`barryvdh/laravel-dompdf`). Se pueden
sacar en cualquier momento de la estadía del paciente, con la consulta **abierta** (en triaje o en curso) o **cerrada**.

## Regla de librerías (cambio)

Hasta ahora no se usaban librerías para imprimir (receta y constancias salen del navegador). **Los PDF nuevos usan dompdf.**
La receta y las constancias ya hechas **no se tocan**: siguen imprimiéndose desde el navegador.

## Qué es y qué no es

- **Al vuelo:** el PDF se arma en memoria y va directo a la respuesta. **No se guarda en el servidor** (ni en `storage/` ni en
  `public/`) **ni en el navegador**: cabeceras `Cache-Control: no-store, private`, `Pragma: no-cache`, `nosniff`. (dompdf solo
  mantiene en `storage/fonts` el caché de métricas de la tipografía, que no tiene datos de pacientes.)
- **Solo lo registrado, tal cual.** Sin interpretar, sin valores de referencia nuevos ni textos clínicos agregados. Todo
  lo escrito por el usuario sale escapado.
- **Encabezado:** clínica, paciente (nombre, documento, ficha), fecha y hora de la consulta con su estado (En triaje, En curso,
  Finalizada), profesional, turno, y **quién lo generó y cuándo**.
- **Fuera de esta fase:** receta, indicaciones y reposo. Tampoco recetas ni adendas.

## Contenido

| Sección | PDF de triaje | PDF de la consulta |
|---|---|---|
| Motivo de consulta | no | sí |
| Urgente y observación del triaje (quién y cuándo) | si existen | si existen |
| Anamnesis (con "Retirado" y autoría "Cargado por…") | sí | sí |
| Signos vitales (valor y etiqueta de alerta guardada, y autoría) | sí | sí |
| Hallazgos (con autoría) | no | sí |
| Diagnósticos (principal y "Retirado") | no | sí |

Las etiquetas de alerta de los signos ("Fuera de lo normal", "Muy alterado") son las que el sistema **guardó** en
`examenes_fisicos.signos_alertas` (no se recalculan). Las secciones vacías se omiten.

## Quién puede

La regla está en un solo lugar: `ConsultaPolicy::generarPdf`. Hace falta **EXPORTAR sobre `PDF_CLINICO`** y, además, ser:

- el **Administrador**;
- **Supervisión médica** (se reconoce por tener VER sobre `CORRECCIONES_HC`, como en las constancias);
- el **profesional activo** de la consulta;
- solo para el **PDF de triaje**: el **personal de salud activo que cargó o modificó algo del triaje** de esa consulta
  (anamnesis, signos o urgencia). No puede sacar el PDF de la consulta, que tiene el motivo y los diagnósticos.

**Recepción no puede**, ni otro médico, ni otro personal de salud. Una consulta **anulada** no existe (404).

- **Permiso nuevo, en la matriz existente:** módulo `PDF_CLINICO` (Clínica), única acción **EXPORTAR**. Lo traen Médico,
  Enfermería y Supervisión médica (el Administrador lo recibe solo). Migración `pdf_clinico`: idempotente, no pisa lo
  cambiado desde la matriz y corre también con `migrate --pretend` y contra un catálogo sin sus datos.
- Rutas: `admin.pdf.triaje` y `admin.pdf.consulta` (`/admin/consultas/{consulta}/pdf/triaje|consulta`).
- Los enlaces "PDF de triaje" y "PDF de la consulta" aparecen en la barra del paciente (triaje, atención y corrección) y en
  la consulta cerrada, solo a quien puede generarlos; se abren en otra pestaña.

## Auditoría

Cada generación deja un registro **EXPORTAR** ("Generar PDF" en pantalla) sobre `consultas` con el id de la consulta, el
usuario y el detalle "PDF de triaje de la consulta N" o "PDF de consulta de la consulta N". No se agrupa ni se omite al
repetir: cada PDF generado es un registro. Se registra antes de entregar el PDF: si falla, no sale. Un pedido negado no
deja registro de generación.

## Pendiente

El diseño del PDF es sobrio y provisorio: revisar el aspecto en papel antes de usarlo con pacientes reales.
