---
description: Fusiona una rama en main con squash y publica. Uso: /fusionar RAMA "mensaje"
argument-hint: RAMA "mensaje del commit"
---

Fusionar en `main` la rama y el mensaje de `$ARGUMENTS` (primer argumento: rama; el resto: mensaje). Respetá CLAUDE.md. Este comando ES la confirmación expresa para tocar `main`, pero solo si se cumple el paso 1. Frená y avisá ante cualquier diferencia.

1. **Confirmación**: preguntar al usuario si `composer test:completo` pasó (lo corre el usuario, no usted). Sin un "sí" claro, frenar.
2. `git push` de la rama.
3. **main no avanzó**: `git fetch` y comprobar que `origin/main` es ancestro de la rama (`git merge-base --is-ancestor origin/main RAMA`) y que `main` local == `origin/main`. Si avanzó, frenar.
4. `git checkout main` y `git merge --squash RAMA`.
5. **Revisar** `git status` y señalar expresamente respaldos, `.dump`, `.env`, `.json` y temporales entre lo que se va a commitear. Si hay alguno que no corresponda, frenar y sacarlo del índice con consulta.
6. **Un solo commit** con el mensaje dado (más la atribución estándar).
7. `npm run build`; `php artisan optimize:clear`; `php artisan migrate:status` (nada pendiente; si hay pendientes, avisar, no migrar).
8. **Verificación de solo lectura** de la base real (módulos, permisos y conteos de las tablas afectadas), sin escribir.
9. `git push` de `main`.
10. **No borrar la rama** (ni local ni remota).

Informe final: máximo 10 líneas (commit, estado de build, migrate:status, verificación, push, rama conservada).
