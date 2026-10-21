# Informes de estudio

El informe de un estudio **realizado en la clínica** (por ejemplo, una ecografía), escrito por el profesional **dentro de la
consulta**, en la pantalla de atención (sección **"Estudios"**, que reemplaza al botón "Estudios (Próximamente)"). La paciente
que viene solo por el estudio entra como cualquier consulta (con turno o sin turno), con su ficha.

## Qué es

- **Texto libre**, hasta 5000 caracteres. Sin campos clínicos específicos (semanas de gestación, medidas, etc.): se definen
  más adelante.
- Cada informe tiene: **estudio realizado**, un **procedimiento de tipo ESTUDIO** activo (`procedimiento_id`, obligatorio en los
  nuevos; ya no hay "Otro" escrito a mano), **solicitante** (texto libre opcional, hasta 150, por si lo pidió un médico externo) y
  el **texto**. El profesional ve solo el nombre del procedimiento, nunca montos.
- Los informes **anteriores** a los procedimientos conservan su estudio (del catálogo o escrito a mano, `estudio_id`) y quedan sin
  procedimiento: se pueden seguir corrigiendo así. El catálogo de estudios sigue solo para las órdenes de estudio.
- Cada informe vigente genera **su prestación** al finalizar la consulta (`docs/tarifas-prestaciones.md`).
- **Varios por consulta** (hasta 10 vigentes), en el orden de la pantalla.
- El nombre del procedimiento se **copia** (`informes_estudio.estudio_nombre`): renombrarlo o desactivarlo no altera lo ya
  escrito. Un procedimiento que hoy está inactivo se conserva en la fila que ya lo tenía.
- Autoría: quién lo escribió (`usuario_id`) y el último que lo cambió (`modificado_por_id`), como la anamnesis.

## Parte de la consulta

Es una lista más del **grupo CLÍNICO** (`FormularioConsulta`, `GuardarSecciones`), con las mismas reglas que las indicaciones:

- Se escribe con la consulta **en curso**, con **autoguardado**. Nada clínico en el navegador. Un informe sin estudio o sin
  texto es una "fila incompleta": no se guarda en el borrador; al finalizar es un error.
- **Escribe solo el profesional activo de la consulta** (`ConsultaPolicy::escribirClinico`). No escriben Enfermería (triaje),
  otro médico, el Administrador ni Recepción.
- **Consulta cerrada = solo lectura** (`ProtegidoPorCierre`). Correcciones solo por **adenda** o por **desbloqueo** del
  supervisor, con las reglas de siempre (`docs/historia-clinica.md`): el informe está en `VentanaDeCorreccion::PERMITIDOS`, en
  la versión previa (`Instantanea`, clave `informes`; las versiones anteriores no la tienen) y en el formulario de corrección.
- **Nada se borra:** un informe se **retira** (`activo = false`) y se puede reponer, como la anamnesis y los diagnósticos. Uno
  nuevo sin guardar se "Descarta" en la pantalla.
- Un informe vigente cuenta como contenido clínico para **Deshacer atención**.

## Quién ve

- Lo ve quien ve la consulta (VER sobre HISTORIA_CLINICA): página de la consulta y popup del historial, con "Retirado". El
  Administrador ve todo. **Recepción no ve nada.**
- **Auditoría:** sus cambios quedan en el EDITAR de la consulta (sección "informes de estudio"). La tabla `informes_estudio` es
  contenido clínico: se oculta a quien no tiene VER sobre HISTORIA_CLINICA (`Auditoria::permisoDeContenido`).

## PDF por informe

- **dompdf, al vuelo**, patrón de `docs/pdf-clinico.md`: no se guarda en el servidor ni en el navegador (`no-store`,
  `no-cache`, `nosniff`). Sin numeración ni huella, sin marca diagonal. Toma lo **ya guardado**.
- Con la consulta **abierta o cerrada**. Un informe retirado, de otra consulta o de una consulta anulada no existe (404).
- **Contenido** (`App\Support\PdfClinico\PdfInformeEstudio`, vista `pdf-informe-estudio/hoja`): clínica (nombre, dirección y
  teléfono de la sucursal) y logotipo, paciente (nombre, documento, nacimiento y edad, ficha), profesional (nombre,
  especialidades, matrícula), fecha de la consulta, estudio, solicitante si hay, el texto tal cual y líneas de firma y de
  aclaración y sello. **Nunca** diagnósticos, medicamentos ni nada más de la consulta. Todo escapado.
- **Quién:** los mismos que el PDF de la consulta (`ConsultaPolicy::generarPdf`): EXPORTAR sobre `PDF_CLINICO` y ser el
  Administrador, Supervisión médica o el profesional activo de la consulta. Sin permisos nuevos.
- Ruta `admin.pdf.informe-estudio` (`/admin/consultas/{consulta}/pdf/informes/{informe}`). Enlace "PDF del informe" en cada
  informe vigente guardado (atención, corrección y lectura), solo a quien puede; se abre en otra pestaña.
- **Auditoría:** cada generación, un **EXPORTAR** sobre `consultas` con el detalle "PDF del informe de estudio N de la consulta
  M", antes de entregar el PDF.

## Fuera de esta fase

Cobro y descuentos por combinar consulta y estudio, equipo en la agenda (no se usa `turnos.equipo_id`), vínculo con una orden
de estudio de la clínica, vínculo entre el catálogo de estudios y los procedimientos, resultados de estudios hechos en otra
institución y rangos de referencia.

## Migración

`create_informes_estudio`: solo la tabla nueva. No lee ni cambia filas y no depende de ningún dato de catálogo (sin módulos ni
permisos nuevos). `prestacion_de_informes_de_estudio` agrega `procedimiento_id` (ver `docs/tarifas-prestaciones.md`).

## Pendiente

El diseño del PDF es sobrio y provisorio: revisar el aspecto en papel antes de usarlo con pacientes reales.

## Tests

`tests/Feature/InformeEstudio/InformeEstudioTest.php` y `tests/Feature/Economico/PrestacionInformesTest.php`.
