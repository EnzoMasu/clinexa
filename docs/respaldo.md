# Respaldo y restauración de la base

> **Advertencia.** Los respaldos contienen datos de pacientes: datos de salud. **No se suben a GitHub
> ni se mandan por correo.** Si se copian a un pendrive o a la nube, van **cifrados** (por ejemplo, en
> un contenedor de VeraCrypt o un archivo 7-Zip con AES-256 y una contraseña fuerte que no viaje junto
> al archivo). El repositorio ignora `*.dump` y la carpeta `respaldos/` para que no se suban por error.

## Respaldar

```bash
php artisan clinexa:respaldo
```

- Respalda la base configurada (`DB_DATABASE` del `.env`) con `pg_dump` en formato custom (`-Fc`).
- Guarda el archivo en la carpeta de la variable `CLINEXA_RESPALDOS` del `.env` (en este servidor,
  `/home/enzo/clinexa-respaldos`), fuera del repositorio. Se crea si no existe. **En Linux hay que
  definirla:** si falta, se usa el valor por defecto de `config/clinexa.php`, que es una ruta de Windows.
- El nombre es `clinexa-AAAAMMDD-HHMM.dump`, en hora de Paraguay. Si ya hay uno de ese mismo
  minuto, agrega `-2`, `-3`, etc.; nunca pisa uno existente.
- Al terminar verifica el archivo con `pg_restore --list` y muestra la ruta, el tamaño y la cantidad
  de tablas. Si el archivo está vacío o dañado, lo informa y termina con error.
- No borra respaldos viejos: solo informa cuántos hay. Borrarlos es una decisión manual.
- La contraseña se toma de la configuración y se pasa a `pg_dump` solo por la variable de entorno
  `PGPASSWORD` del proceso: no aparece en la línea de comandos, en archivos ni en logs.
- `pg_dump` y las demás herramientas se toman del `PATH` (en Linux, `/usr/bin`, del paquete de
  PostgreSQL). Otra carpeta se indica con `CLINEXA_PG_BIN`. En Windows, sin esa variable, se busca la
  versión más nueva en `C:\Program Files\PostgreSQL\<versión>\bin`.

### Probar que el respaldo se puede restaurar

```bash
php artisan clinexa:respaldo --probar
```

Además de respaldar, restaura el archivo en una base temporal `clinexa_restore_test`, compara la
cantidad de filas de `personas`, `pacientes`, `historias_clinicas`, `consultas`, `logs_auditoria` y
`users` con la base, y elimina la base temporal (siempre, aunque algo falle).

- Si solo `logs_auditoria` tiene más filas en la base, es porque alguien usó el sistema mientras se
  respaldaba: se informa como aviso, no como error.
- Cualquier otra diferencia es un error: no confíe en ese respaldo hasta revisarlo.
- Por seguridad, la única base que este comando crea y elimina es exactamente `clinexa_restore_test`,
  y nunca la configurada, la de tests (`clinexa_test`) ni las del sistema.

Conviene correr `--probar` de vez en cuando: un respaldo que nunca se probó no es un respaldo.

## Restaurar

Restaurar reemplaza datos: hágalo con calma, con el sistema detenido y con un respaldo recién hecho
de la base actual (aunque esté dañada), por si hay que volver atrás.

Las herramientas (`createdb`, `pg_restore`, `psql`) están en el `PATH`. En los ejemplos, `-W` hace que
pidan la contraseña por teclado: así no queda en el historial de la consola. El usuario es el dueño de
las bases de la clínica (`clinexa`, que tiene permiso para crear bases).

1. Respaldar la base actual:

   ```bash
   php artisan clinexa:respaldo
   ```

2. Restaurar primero en una base nueva y revisarla (no toca la base en uso):

   ```bash
   createdb -h 127.0.0.1 -U clinexa -W clinexa_restaurada
   pg_restore -h 127.0.0.1 -U clinexa -W --no-owner --no-privileges -d clinexa_restaurada /home/enzo/clinexa-respaldos/clinexa-AAAAMMDD-HHMM.dump
   ```

   Para revisarla, se puede apuntar una copia del sistema a `clinexa_restaurada` (`DB_DATABASE` en
   un `.env` aparte) o consultarla con `psql`.

3. Si está bien, ponerla en lugar de la actual. Con el sistema detenido, desde
   `psql -h 127.0.0.1 -U clinexa -W -d postgres`:

   ```sql
   ALTER DATABASE clinexa_dev RENAME TO clinexa_anterior;
   ALTER DATABASE clinexa_restaurada RENAME TO clinexa_dev;
   ```

   `clinexa_anterior` se conserva hasta estar seguro; después se elimina a mano.

Notas:

- `migrate:fresh`, `migrate:refresh`, `migrate:reset` y `db:wipe` están bloqueados fuera de los
  tests (`AppServiceProvider`): no sirven para "limpiar" la base antes de restaurar, y es a propósito.
- Un respaldo de una versión anterior del sistema puede no tener las tablas más nuevas: después de
  restaurarlo, `php artisan migrate` las agrega.
