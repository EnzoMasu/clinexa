# Clinexa: reglas permanentes

## Git
- Se trabaja en la rama de trabajo actual (hoy `rediseno-atencion`); verificarla con `git branch --show-current`.
- `main` no se toca sin confirmación expresa: ni merge, ni push, ni commits.
- Commits WIP chicos. Ningún commit final ni merge sin confirmación.

## Base de datos
- La base real (`clinexa`) solo se toca con confirmación expresa, y siempre antes con
  `php artisan clinexa:respaldo --probar`.
- Antes de cambiarla: verificación de solo lectura y mostrar qué va a pasar.
- Comandos destructivos prohibidos (`migrate:fresh`, `migrate:reset`, `db:wipe`, DROP, TRUNCATE, borrados
  masivos), salvo lo autorizado expresamente y caso por caso.
- No borrar bases ni datos fuera de lo autorizado. En el servidor hay bases ajenas.

## Texto y fechas
- Todo texto visible, en "usted".
- Fechas dd/mm/aaaa, siempre con el helper `App\Support\Fecha` (también "hoy" y "ahora").

## Historia clínica
- Una consulta cerrada (FINALIZADA o ANULADA) es de solo lectura, con todo lo que cuelga de ella. Ver
  `docs/historia-clinica.md`.
- Nada de la historia clínica se elimina.
- En Auditoría, el contenido clínico y el de recetas se ocultan a quien no tiene VER sobre
  HISTORIA_CLINICA o RECETAS (`App\Support\Auditoria::permisoDeContenido`).
- Nunca guardar datos clínicos en el navegador: ni localStorage, ni sessionStorage, ni IndexedDB, ni
  cookies.

## Pruebas
- Siempre con datos inventados. Nunca datos reales, ni de pacientes ni credenciales.
- Los tests nunca usan la base real: SQLite en memoria, y `clinexa_test` para `tests/Postgres`.
- `composer test:rapido`: en paralelo, sin el grupo `lento` ni PostgreSQL. Para el trabajo diario.
- `composer test:completo`: todo, en serie. Antes de cada commit que no sea WIP, de cualquier merge y de
  tocar la base real.
- Detalle en `docs/pruebas.md`.
