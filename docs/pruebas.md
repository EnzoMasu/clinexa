# Pruebas

Hay dos comandos. Los dos usan datos inventados: la suite corre en SQLite en memoria y los tests de
`tests/Postgres` usan la base de prueba `clinexa_test`. Ninguno toca la base de trabajo `clinexa_dev` (lo frena
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
- Tiempo: lo medido el 19/10/2026 con el grupo lento incluido fue de 9 a 13 min en esta máquina; sin el grupo
  `lento` baja bastante (no se volvió a medir tras sumar los tests nuevos).
- No usa PostgreSQL. No crea bases en el servidor.

## Comando completo: antes de lo importante

```bash
composer test:completo
```

Hace dos pasos seguidos:

1. `php vendor/bin/pest --parallel --exclude-testsuite=Postgres`: Unit y Feature en paralelo (8 procesos),
   **incluido el grupo `lento`**: 1495 tests.
2. `php vendor/bin/pest --testsuite=Postgres`: los 47 tests de PostgreSQL, **en serie**, contra `clinexa_test`.

Total: **1542 tests**. Tiempos medidos (tres corridas seguidas, mismos resultados, 0 fallas): 19, 11 y 32 min
en total (paralelo 776 / 564 / 1702 s, PostgreSQL 85 / 81 / 187 s). La variación es de la máquina (carga y
suspensión de Windows), no de los tests. Antes, en serie, eran unos 35 min.

Córralo siempre:

- antes de cada commit que no sea WIP;
- antes de cualquier merge;
- antes de tocar la base real (migraciones, seeders, comandos de mantenimiento).

Si hay que volver atrás o se sospecha del paralelo: `composer test:completo-serial` (todo en serie, un solo
`pest`, unos 35 min).

Los tests de escapado por mutación (`EscapadoAtencionTest`, "mutación: si se rompe el escapado...") son
de **seguridad**: están en el grupo `lento` solo para que el comando rápido sea rápido. Antes de un merge
no se omiten: el comando completo los incluye.

## Vistas compiladas por proceso

`Tests\TestCase` compila las vistas Blade de cada proceso en `storage/framework/testing/views/proceso-N`
(`serie` si no hay paralelo). Antes todos compartían `storage/framework/views` y dos procesos (o
`artisan serve`) pisaban el mismo archivo: `rename(...): Acceso denegado`.

## Tiempos en los tests

No se afirma un tope de segundos en un test: en paralelo depende de la carga y falla de forma intermitente
(pasó en `GeografiaCrudTest`). Se mide la cantidad de consultas SQL.

## Qué queda fuera del comando rápido

| Grupo | Qué tiene | Por qué |
|---|---|---|
| `lento` | `RespaldoTest` (Feature) | genera y verifica archivos de respaldo simulados |
| `lento` | Mutación de escapado (`EscapadoAtencionTest`, `EscapadoUrgenciasTest`) | copia todas las vistas a una carpeta temporal por caso |
| `lento` | `RendimientoTest`, `RendimientoAtencionTest`, `RendimientoUrgenciasTest` | cargan cientos de registros para contar consultas SQL |
| `lento` | `AuditoriaVolumenTest` | simula una consulta de 10 minutos (78 guardados) |
| `lento` | Auditoría con 50.000 registros (`Auditoria\PantallaTest`) | inserta 50.000 eventos |
| `lento` | Recorrido de rutas de `PermisosTest` (sin permisos y Administrador) | recorre las 221 rutas de /admin |
| `postgres` | `tests/Postgres` | necesitan PostgreSQL de verdad; corren solo en serie |

Para marcar un test nuevo como lento: `->group('lento')` al final del test, o `uses()->group('lento');`
al principio del archivo si todo el archivo lo es.

## PostgreSQL: solo en serie

`tests/Postgres/EntrePacientesConcurrenciaTest` prueba la concurrencia con **dos conexiones** a `clinexa_test`:
para que la segunda vea los datos, el test los confirma fuera de la transacción de RefreshDatabase y al final borra,
por id, solo lo que creó (ver `docs/agenda-urgencias.md`).

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
