# Constitución de Clinexa

Las reglas operativas (git, base de datos, pruebas, texto y fechas, producción) están
en `CLAUDE.md` y `docs/`. Esta constitución solo fija los principios transversales.
Ante un conflicto, manda `CLAUDE.md`.

## Principios

### I. Laravel + PostgreSQL
El sistema es Laravel sobre PostgreSQL. No se incorpora otra pila ni otro motor de
datos sin decisión expresa de Enzo. Las pruebas siguen lo definido en `CLAUDE.md`
(sección Pruebas) y `docs/pruebas.md`.

### II. Nunca borrado físico
Ningún registro de las tablas de negocio se elimina de la base: se desactiva. Quedan
fuera las tablas técnicas de Laravel (sesiones, caché, colas, tokens). Los borrados
masivos y destructivos siguen prohibidos según `CLAUDE.md` (sección Base de datos).

### III. Auditoría de todo cambio
Toda tabla audita crear, editar, desactivar, anular y revertir, más los eventos de
autenticación, en `LogAuditoria`. Las lecturas (VER) se auditan en los módulos
marcados como sensibles (datos privados del paciente: historia clínica, recetas,
indicaciones, etc.); esa lista vive en el campo `es_sensible` de `ModuloSistema`.
La visibilidad del contenido clínico y de recetas en Auditoría sigue lo definido en
`CLAUDE.md` (sección Historia clínica).

### IV. Estados y catálogos desde tablas
Los estados salen de la tabla `Estado`. Los catálogos que edita el usuario viven en
tablas. Los enums de PHP se usan solo para listas cerradas y estables: `TipoPersona`,
`Sexo`, `EstadoCivil`, `DiaSemana`, `TipoDiagnostico`, `TipoProcedimiento`,
`TipoFactura`, `TipoMovimientoCaja`, `AccionPermiso`, `AccionAuditoria`, `TipoTurno`
(lista cerrada atada a la lógica de agenda). Los enums existentes no se migran.

### V. Historia clínica cerrada, inmutable
Una consulta cerrada (FINALIZADA o ANULADA) y todo lo que cuelga de ella es de solo
lectura. Detalle en `CLAUDE.md` y `docs/historia-clinica.md`.

### VI. Enzo decide las reglas de negocio
Ningún agente ni spec asume reglas de negocio, valores clínicos ni textos legales.
Si falta una definición, se pregunta a Enzo y se anota como pendiente antes de
implementar.

## Gobierno
Esta constitución se modifica solo con aprobación de Enzo. Cada spec y plan debe
verificar que cumple estos principios antes de avanzar.

**Versión**: 1.0.0 | **Ratificada**: 2026-10-31 | **Última enmienda**: 2026-10-31
