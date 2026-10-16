# Indicaciones generales: PDF y catálogo de dietas

Las indicaciones generales de la consulta (control, dieta, otras) siguen siendo **texto libre**, con las reglas de siempre:
se cargan con la consulta abierta, la consulta cerrada es de solo lectura y se corrigen solo por las vías autorizadas
(`docs/historia-clinica.md`). Esta fase suma dos cosas: el **PDF en hoja aparte** y el **catálogo de dietas**. El reposo
tiene su propio certificado (`docs/reposo-medico.md`).

## PDF de indicaciones

- **dompdf, al vuelo**, patrón de `docs/pdf-clinico.md`: no se guarda en el servidor ni en el navegador (`no-store`,
  `no-cache`, `nosniff`). Sin numeración ni huella. Toma lo **ya guardado** (lo que el autoguardado todavía no envió no sale).
- Se genera durante la estadía, con la consulta **abierta o cerrada**. Una consulta anulada no existe (404).
- **Contenido** (`App\Support\PdfClinico\PdfIndicaciones`, vista `pdf-indicaciones/hoja`): clínica y logotipo
  (`config('clinexa.clinica')`), paciente (nombre y documento), profesional, fecha de la consulta (día en que se inició) y las
  indicaciones **vigentes** tal cual, en el orden de la pantalla, con su tipo como título si lo tienen (también las de tipo
  Reposo). Las retiradas no salen. **Nunca:** medicamentos, diagnósticos, receta, motivo, anamnesis ni signos; tampoco textos
  legales ni clínicos agregados. Todo escapado.
- **Sin indicaciones vigentes no hay PDF vacío:** un aviso ("Esta consulta no tiene indicaciones generales vigentes…"), sin
  registro de generación.
- **Quién:** los mismos que el PDF de la consulta (`ConsultaPolicy::generarPdf`): EXPORTAR sobre `PDF_CLINICO` y ser el
  Administrador, Supervisión médica o el profesional activo de la consulta. Sin permisos nuevos. No pueden Recepción, otro
  médico ni el personal de salud que hizo el triaje.
- Ruta `admin.pdf.indicaciones` (`/admin/consultas/{consulta}/pdf/indicaciones`). Enlace "PDF de indicaciones" junto a los
  otros PDF (barra del paciente y consulta cerrada), solo a quien puede; se abre en otra pestaña.
- **Auditoría:** cada generación, un **EXPORTAR** sobre `consultas` con el detalle "PDF de indicaciones generales de la
  consulta N", antes de entregar el PDF.

## Catálogo de dietas

- Tabla `dietas`: **nombre** (único, hasta 100) y **texto** (hasta 3000, el mismo tope que una indicación general).
  Estado ACTIVO o INACTIVO. **Nada se borra: se desactiva.**
- **Nace vacío.** Lo carga un médico o nutricionista; ninguna migración ni seeder trae contenido.
- **Módulo `DIETAS`** (Catálogos), en la matriz de permisos: Supervisión médica VER, CREAR, EDITAR y DESACTIVAR; el
  Administrador lo recibe solo. El **Médico tiene solo VER** (migración `medico_ve_dietas`): las lista y las usa desde la
  consulta, pero no las administra. Recepción no ve nada.
- Pantalla Catálogos → Dietas (`admin.dietas.*`). Los cambios quedan en Auditoría (no es contenido clínico).

## "Insertar dieta"

- En cada indicación vigente, con dietas activas en el catálogo: elegir una dieta y "Insertar dieta". El texto se **copia**
  al final de la indicación (en una línea aparte), que sigue siendo texto libre y se puede editar. Si no entra en los 3000
  caracteres de la indicación, **no se inserta, no se corta** y se avisa. Los saltos de línea se respetan en pantalla y en el PDF
  (`white-space: pre-line`) y cuentan como un carácter.
- **Sin enlace vivo:** la indicación no guarda de qué dieta salió. Editar o desactivar la dieta después no altera lo ya
  guardado. Una dieta desactivada deja de ofrecerse; lo copiado se conserva.
- Está donde se editan las indicaciones: la atención (consulta abierta) y la corrección autorizada. Nunca en una consulta
  cerrada.

## Migración

`create_dietas`: tabla nueva, módulo y reparto a Supervisión médica. Crea ACTIVO e INACTIVO si faltaran, no lee filas durante
`migrate --pretend`, no duplica nada y en una instalación nueva deja el módulo al seeder.

## Pendiente

El diseño del PDF es sobrio y provisorio: revisar el aspecto en papel antes de usarlo con pacientes reales.

## Tests

`tests/Feature/PdfClinico/PdfIndicacionesTest.php`, `tests/Feature/Dietas/DietasTest.php` y `MigracionDietasTest.php`.
