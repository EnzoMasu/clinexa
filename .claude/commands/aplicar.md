---
description: Aplica una rama a la base real (respaldo, migrate, verificación, push). Uso: /aplicar RAMA
argument-hint: RAMA
---

Aplicar la rama `$ARGUMENTS` a la base real. Respetá CLAUDE.md. Este comando ES la confirmación expresa para tocar la base real, y solo para esto. Ante cualquier error o diferencia: frená, mostrá el error y NO reviertas ni improvises correcciones.

1. **Rama y árbol**: `git branch --show-current` debe ser `$ARGUMENTS` y no `main`; `git status --short` limpio. Si no, frená.
2. **Foto previa (solo lectura)**: `git log --oneline -5`; log de auditoría (conteo y últimos registros); módulos; permisos del Administrador; conteo de las tablas que las migraciones pendientes van a tocar; `php artisan migrate:status` y `php artisan migrate --pretend` (la simulación de la fase). Guardar la foto en el temporal (fuera del repo).
3. **Respaldo**: `php artisan clinexa:respaldo --probar`. Si no coincide o falla, frená.
4. **Migrar**: `php artisan migrate --force`. Sin seeders salvo que el pedido los indique. Nunca `migrate:fresh`, `reset` ni `db:wipe`.
5. **Verificación**: repetir la misma lectura y comparar contra la foto previa y contra la simulación. Lo único que puede cambiar es lo que la migración declara. Si algo no coincide: frená, informá y NO reviertas por tu cuenta.
6. **Push** de la rama (nunca `main`).
7. Borrar el temporal de la foto solo si todo coincidió.

Informe final: máximo 10 líneas (rama, migraciones aplicadas, respaldo, verificación, push).
