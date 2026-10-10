# Pruebas

Hay dos comandos. Los dos usan datos inventados: la suite corre en SQLite en memoria y los tests de
`tests/Postgres` usan la base de prueba `clinexa_test`. Ninguno toca la base real `clinexa` (lo frena
`Tests\TestCase` si la configuración apuntara a otra base).

## Comando rápido: para el trabajo diario

```bash
composer test:rapido
```

Equivale a:

```bash
php vendor/bin/pest --parallel --exclude-testsuite=Postgres --exclude-group=lento
```

- Corre en paralelo (un proceso por núcleo) todo menos el grupo `lento` y la suite de PostgreSQL.
- Tiempo medido: unos **2 min 40 s** (951 tests, 8 procesos).
- No usa PostgreSQL. No crea bases en el servidor.

## Comando completo: antes de lo importante

```bash
composer test:completo
```

Equivale a `php vendor/bin/pest`: todos los tests, **en serie**, incluidos el grupo `lento` y
`tests/Postgres`. Tiempo medido: unos **6 minutos y medio** (1009 tests).

Córralo siempre:

- antes de cada commit que no sea WIP;
- antes de cualquier merge;
- antes de tocar la base real (migraciones, seeders, comandos de mantenimiento).

Los tests de escapado por mutación (`EscapadoAtencionTest`, "mutación: si se rompe el escapado...") son
de **seguridad**: están en el grupo `lento` solo para que el comando rápido sea rápido. Antes de un merge
no se omiten: el comando completo los incluye.

## Qué queda fuera del comando rápido

| Grupo | Qué tiene | Por qué |
|---|---|---|
| `lento` | `RespaldoTest` (Feature) | genera y verifica archivos de respaldo simulados |
| `lento` | Mutación de escapado (`EscapadoAtencionTest`) | copia todas las vistas a una carpeta temporal por caso |
| `lento` | `RendimientoTest`, `RendimientoAtencionTest` | cargan cientos de registros para contar consultas SQL |
| `lento` | `AuditoriaVolumenTest` | simula una consulta de 10 minutos (78 guardados) |
| `lento` | Auditoría con 50.000 registros (`Auditoria\PantallaTest`) | inserta 50.000 eventos |
| `lento` | Recorrido de rutas de `PermisosTest` (sin permisos y Administrador) | recorre las 192 rutas de /admin |
| `postgres` | `tests/Postgres` | necesitan PostgreSQL de verdad; corren solo en serie |

Para marcar un test nuevo como lento: `->group('lento')` al final del test, o `uses()->group('lento');`
al principio del archivo si todo el archivo lo es.

## PostgreSQL: solo en serie

Los tests de `tests/Postgres` no pueden correr en paralelo: Laravel crearía una base por proceso
(`clinexa_test_test_1`, `_2`...) que queda suelta en el servidor. `Tests\PostgresTestCase` lo frena con
un error antes de crear nada. Por eso el comando rápido usa `--exclude-testsuite=Postgres`. Indicar la
carpeta o el grupo no alcanza: en paralelo, `--exclude-group=postgres` no excluye esa suite.

Para correr solo esos tests:

```bash
php vendor/bin/pest --testsuite=Postgres
```

## Equipo suspendido

Si la suite parece colgada (poca CPU, no avanza), es probable que Windows haya entrado en modo de espera
moderno. No es un problema de los tests: el equipo tiene que estar despierto mientras corren.
