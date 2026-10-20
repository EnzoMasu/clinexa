# Orden de estudio

Documento **aparte de la consulta**, con el patrón del certificado de reposo (`docs/reposo-medico.md`): **inmutable**, con
número correlativo propio (**`OE-0000001`**), **snapshot** (el PDF se dibuja siempre desde él) y **huella sha256**. Nace
emitida (no hay borrador) y solo se puede **anular con motivo**. Nada se borra. Se imprime en **PDF con dompdf, al vuelo**
(patrón de `docs/pdf-clinico.md`). Una orden puede llevar **varios estudios**.

## Quién puede

La regla está en `OrdenEstudioPolicy`. Módulo nuevo **`ORDENES_ESTUDIO`** (Clínica): VER, CREAR, EDITAR.

| | Emitir (CREAR) | Ver y PDF (VER) | Anular (EDITAR) |
|---|---|---|---|
| Profesional activo de la consulta | sí | sí | las que emitió |
| Administrador | **no** | sí | sí |
| Supervisión médica (VER sobre `CORRECCIONES_HC`) | **no** (aunque tenga CREAR) | sí | no |
| Otro médico, Enfermería, Recepción | no | no | no |

- Matriz: Médico VER, CREAR y EDITAR; Supervisión médica solo VER (como en el reposo); el Administrador lo recibe solo.
  Recepción no ve nada.
- Se emite con la consulta **en curso** o **finalizada**; no en triaje ni anulada. Emitirla no modifica la consulta.
- Entrada: enlace **"Órdenes de estudio"** en la barra del paciente (atención) y en la consulta cerrada; abre en otra pestaña
  la lista de órdenes de la consulta, con el formulario para el profesional.
- Rutas: `admin.ordenes-estudio.consulta`, `.emitir`, `.show`, `.pdf`, `.anular`. No hay DELETE.

## Catálogo de estudios

- Tabla `estudios`: **nombre** (único, hasta 150). Estado ACTIVO o INACTIVO. **Nada se borra: se desactiva.**
- **Nace vacío.** Ninguna migración ni seeder trae contenido.
- **Módulo `ESTUDIOS`** (Catálogos): Supervisión médica VER, CREAR, EDITAR y DESACTIVAR; el Administrador lo recibe solo. El
  **Médico tiene solo VER**: lo usa desde la orden, no lo administra. Recepción no ve nada.
- Pantalla Catálogos → Estudios (`admin.estudios.*`). Los cambios quedan en Auditoría (no es contenido clínico).
- En la orden se ofrecen solo los **activos**. El nombre se **copia** al snapshot: editar o desactivar un estudio después no
  altera las órdenes ya emitidas.
- **Estudio que no está en el catálogo:** el médico lo escribe a mano (uno por renglón). Va solo en esa orden; **no se agrega
  al catálogo**. En el snapshot queda sin `estudio_id`.

## Contenido

Lista **cerrada**, armada campo por campo (`App\Support\OrdenEstudio\DatosOrden`):

- Clínica (nombre, dirección y teléfono de la sucursal) y el logotipo que ya usa la receta (`config('clinexa.clinica.logo')`).
- Número y fecha de emisión.
- Paciente: nombre, documento, fecha de nacimiento, edad (cumplida el día de la emisión) y ficha.
- Profesional: nombre, especialidades activas, matrícula y **contacto = teléfono de la clínica**.
- Estudios solicitados (al menos uno, hasta 30): primero los del catálogo (por nombre), después los escritos a mano.
- Indicación clínica: texto libre, opcional (hasta 2000).
- Diagnóstico presuntivo con su CIE-10: **opcional por orden**, **omitido por defecto**. El profesional marca cuáles de los
  diagnósticos vigentes de la consulta se imprimen.
- Líneas impresas de firma y de aclaración/sello.

**Nunca:** motivo de consulta, anamnesis o síntomas, signos, hallazgos, recetas ni indicaciones generales. Tampoco campos
clínicos que no se pidieron (semanas de gestación, lateralidad, etc.).

**Fuera de esta fase:** precio, cotización, quién paga, vigencia de la orden, resultados de otras instituciones. El informe de un estudio realizado en la
clínica está en `docs/informes-estudio.md` (sin vínculo con la orden).

## Texto provisorio

Los textos fijos (título, títulos de las secciones, firma y aclaración) están en **`config/ordenes_estudio.php`** (único
lugar), sin revisión. Mientras `ordenes_estudio.borrador` sea `true`, cada orden nueva sale marcada **BORRADOR SUJETO A
REVISIÓN** en el cartel superior y en el pie, **sin marca diagonal** (taparía campos). Aprobado el texto: reemplazarlo ahí y
poner `borrador` en `false`. Las ya emitidas conservan su texto y su marca (snapshot y huella).

## Inmutable, huella y PDF

- El modelo `OrdenEstudio` rechaza cualquier cambio salvo EMITIDO → ANULADO (estado, fecha y motivo), y el borrado
  (`OrdenEstudioInmutable`). Anular exige motivo (5 a 300 caracteres); el documento y su huella no cambian.
- La huella es el sha256 del snapshot en forma canónica (`Constancia::calcularHuella`). Si no coincide, el detalle **avisa** y
  el PDF **no se genera** (ni se registra).
- PDF al vuelo (`PdfOrden`): no se guarda en el servidor ni en el navegador (`no-store`, `no-cache`, `nosniff`). Una anulada
  sale marcada ANULADA.

## Auditoría

CREAR al emitir (sin el snapshot; queda la huella), **EXPORTAR** por cada PDF generado ("PDF de la orden de estudio OE-…",
antes de entregarlo) y ANULAR con el motivo. La tabla `ordenes_estudio` es contenido clínico: sus valores se ocultan a quien
no tiene VER sobre HISTORIA_CLINICA (`Auditoria::permisoDeContenido`).

## Migración

`create_ordenes_estudio`: dos tablas nuevas, los dos módulos y su reparto a Médico y Supervisión médica. Crea los estados si
faltaran, no lee filas durante `migrate --pretend`, no duplica nada, no carga estudios y en una instalación nueva deja los
módulos al seeder.

## Pendiente

- El diseño del PDF es sobrio y provisorio: revisar el aspecto en papel antes de usarlo con pacientes reales.
- Revisión de los textos de `config/ordenes_estudio.php` (ver `docs/produccion.md`).

## Tests

`tests/Feature/OrdenEstudio/OrdenEstudioTest.php` y `MigracionOrdenEstudioTest.php`.
