---
description: Fusiona una rama en main con squash y publica. Uso: /fusionar RAMA "mensaje"
argument-hint: RAMA "mensaje del commit"
---

Fusionar en `main` la rama y el mensaje de `$ARGUMENTS` (primer argumento: rama; el resto: mensaje). Respetá CLAUDE.md. Este comando ES la confirmación expresa para tocar `main` (merge, commit y push). Ante cualquier comando con error: detenete y mostrá el error.

1. **Rama y push**: `git branch --show-current` debe ser la rama y no `main`; árbol limpio; `git push` de la rama.
2. **Tests**: `composer test:completo` UNA sola vez, en segundo plano, sin consultar el avance más de una vez cada 5 minutos. Si hay una falla o más: NO sigas; mostrá las fallas agrupadas por causa y frená.
3. **Main**:
   - `git switch main`; `git pull` (si trae cambios, frená).
   - `git merge --squash RAMA`; mostrá `git status` y verificá que no entren `.dump`, `.env`, `.sql`, `.sqlite`, `storage/`, `Tmp` ni `foto_`. Si entra alguno, frená.
   - Commit con el mensaje dado (más la atribución estándar).
   - `git diff main RAMA` debe quedar vacío.
   - `npm run build`; `php artisan optimize:clear`; `php artisan migrate:status` sin Pending (si hay, avisar, no migrar).
   - Verificación de solo lectura de la base real (módulos, permisos, conteos), sin escribir.
   - `git push` de `main`.
4. **No borrar la rama** (ni local ni remota).

Informe final: máximo 10 líneas (commit, tests, build, migrate:status, verificación, push, rama conservada).
