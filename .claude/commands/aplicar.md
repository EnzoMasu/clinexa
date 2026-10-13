---
description: Aplica una rama a la base real (respaldo, migrate, verificación, push). Uso: /aplicar RAMA
argument-hint: RAMA
---

Aplicar la rama `$ARGUMENTS` a la base real. Respetá CLAUDE.md. Frená y avisá ante CUALQUIER diferencia o error; no improvises correcciones.

1. **Rama y árbol**: `git branch --show-current` debe ser `$ARGUMENTS` y no `main`; `git status --short` debe estar limpio. Si no, frená.
2. **Foto previa (solo lectura)**: `git log --oneline -5`; módulos (`modulos_sistema`), permisos y perfiles-permisos, y el contenido/conteo de las tablas que las migraciones pendientes (`php artisan migrate:status`, `migrate --pretend`) van a tocar. Guardar la foto en el directorio temporal (fuera del repo) y mostrar qué va a pasar.
3. **Respaldo**: `php artisan clinexa:respaldo --probar`. Si falla, frená.
4. **Migrar**: `php artisan migrate --force`. Seeders solo si el pedido los indica (`--seeder=...`); nunca `migrate:fresh`, `reset` ni `db:wipe`.
5. **Verificación contra la foto**: repetir la misma lectura; lo único que puede cambiar es lo que la migración declara. Cualquier otra diferencia: frenar y avisar.
6. **Push**: `git push` de la rama (nunca `main`).
7. Borrar el temporal de la foto solo si todo coincidió.

Informe final: máximo 10 líneas (rama, migraciones aplicadas, respaldo, resultado de la verificación, push).
