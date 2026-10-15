# Certificado de reposo médico

Documento **aparte de la consulta**, con el patrón de las constancias (`docs/constancias.md`): **inmutable**, con número
correlativo propio (**`RM-0000001`**), **snapshot** (el PDF se dibuja siempre desde él) y **huella sha256**. Nace emitido (no
hay borrador) y solo se puede **anular con motivo**. Nada se borra. Se imprime en **PDF con dompdf, al vuelo** (patrón de
`docs/pdf-clinico.md`).

## Quién puede

La regla está en `ReposoMedicoPolicy`. Módulo nuevo **`REPOSO_MEDICO`** (Clínica): VER, CREAR, EDITAR.

| | Emitir (CREAR) | Ver y PDF (VER) | Anular (EDITAR) |
|---|---|---|---|
| Profesional activo de la consulta | sí | sí | los que emitió |
| Administrador | **no** | sí | sí |
| Supervisión médica (VER sobre `CORRECCIONES_HC`) | **no** (aunque tenga CREAR) | sí | no |
| Otro médico, Enfermería, Recepción | no | no | no |

- Matriz: Médico VER, CREAR y EDITAR; Supervisión médica solo VER; el Administrador lo recibe solo. Recepción no ve nada.
- Se emite con la consulta **en curso** (el paciente puede pedirlo durante la atención) o **finalizada**; no en triaje ni
  anulada. Al ser un documento aparte, emitirlo no modifica la consulta (la regla de cierre no se toca).
- Entrada: enlace **"Reposo médico"** en la barra del paciente (atención) y en la consulta cerrada; abre en otra pestaña la
  lista de certificados de la consulta, con el formulario para el profesional.
- Rutas: `admin.reposos.consulta` (lista y formulario), `admin.reposos.emitir`, `admin.reposos.show`, `admin.reposos.pdf`,
  `admin.reposos.anular`. No hay DELETE.

## Contenido

Lista **cerrada**, armada campo por campo (`App\Support\Reposo\DatosReposo`):

- Clínica (nombre, dirección y teléfono de la sucursal) y el logotipo que ya usa la receta (`config('clinexa.clinica.logo')`).
- Número y fecha de emisión.
- Paciente: nombre, documento, fecha de nacimiento, edad (cumplida el día de la emisión), teléfono y ficha.
- Empleador o institución: texto libre, opcional, por certificado.
- Profesional: nombre, especialidades activas, matrícula y **contacto = teléfono de la clínica** (no datos personales).
- Fecha de atención (día en que se inició la consulta, hora local).
- Días (entero ≥ 1, sin tope clínico; el máximo técnico es 36.500 para que las fechas sean válidas), **desde** (hoy o
  posterior, nunca anterior) y **hasta** = desde + días − 1 (ambas inclusive), calculado.
- Modalidad, una: reposo absoluto, relativo / laboral, domiciliario u otra indicación (con su texto).
- Observaciones y recomendaciones: texto libre, opcional.
- Prórroga (opcional): el número de un certificado anterior **emitido**, **del mismo paciente**, y el "desde" nuevo tiene que
  ser **posterior al "hasta"** del anterior (puede haber días entre medio).
- Diagnóstico con su CIE-10: **opcional por certificado y por diagnóstico**, **omitido por defecto**. El profesional marca
  cuáles de los diagnósticos vigentes de la consulta se imprimen (código y descripción del catálogo).
- Líneas impresas de firma y de aclaración/sello.

**Nunca:** motivo de consulta, anamnesis o síntomas, signos, hallazgos, medicamentos o indicaciones de receta, dietas ni
indicaciones generales. Tampoco "origen del reposo" (accidente de trabajo, enfermedad profesional, maternidad): queda fuera
hasta su revisión.

## Texto provisorio

Los textos fijos (título, modalidades, "ambas fechas inclusive", la leyenda "se extiende a solicitud del/de la paciente…",
firma y aclaración) están en **`config/reposo.php`** (único lugar), sin revisión legal. Mientras `reposo.borrador` sea `true`,
cada certificado nuevo sale marcado **BORRADOR SUJETO A REVISIÓN** (cartel, pie y diagonal). Aprobado el texto: reemplazarlo
ahí y poner `borrador` en `false`. Los ya emitidos conservan su texto y su marca (snapshot y huella).

## Inmutable, huella y PDF

- El modelo `ReposoMedico` rechaza cualquier cambio salvo EMITIDO → ANULADO (estado, fecha y motivo), y el borrado
  (`ReposoInmutable`). Anular exige motivo (5 a 300 caracteres); el documento y su huella no cambian.
- La huella es el sha256 del snapshot en forma canónica (`Constancia::calcularHuella`). Si no coincide, el detalle **avisa** y
  el PDF **no se genera** (ni se registra).
- PDF al vuelo (`PdfReposo`): no se guarda en el servidor ni en el navegador (`no-store`, `no-cache`, `nosniff`). Un anulado
  sale marcado ANULADO.

## Auditoría

CREAR al emitir (sin el snapshot; queda la huella), **EXPORTAR** por cada PDF generado ("PDF del certificado de reposo
RM-…", antes de entregarlo) y ANULAR con el motivo. La tabla `reposos_medicos` es contenido clínico: sus valores se ocultan a
quien no tiene VER sobre HISTORIA_CLINICA (`Auditoria::permisoDeContenido`).

## Migración

`create_reposos_medicos`: tabla nueva, módulo y reparto a Médico y Supervisión médica. Crea EMITIDO y ANULADO si faltaran,
no lee filas durante `migrate --pretend`, no duplica nada y en una instalación nueva deja el módulo al seeder.

## Tests

`tests/Feature/Reposo/ReposoMedicoTest.php` y `MigracionReposoTest.php`.
