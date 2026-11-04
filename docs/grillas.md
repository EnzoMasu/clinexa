# Grillas: orden y filtros por columna

Todos los listados paginados de `/admin` usan `App\Support\Grilla\Grilla`: cada columna ordena (asc/desc) desde su
título y tiene su filtro en la fila de abajo (texto, número, rango de fechas o desplegable). Todo se resuelve en la
consulta a la base y respeta la paginación; el navegador solo manda `orden`, `dir` y `f[clave]` en la URL.

## Uso

```php
$grilla = Grilla::de($request)
    ->texto('nombre', 'Nombre', 'paises.nombre')
    ->numero('departamentos', 'Departamentos', 'departamentos_count', filtro: fn ($q, $n) => $q->has('departamentos', '=', (int) $n))
    ->fecha('alta', 'Fecha de alta', 'pacientes.fecha_alta')          // utc: true para instantes (emitida_en, fecha_hora)
    ->estado('estado', 'Estado', 'paises.estado_id', Pais::class);    // o Factura::ETIQUETAS

$registros = $grilla->aplicar($query, fn ($q) => $q->orderBy('nombre'))->paginate(20)->withQueryString();
// vista: <x-admin.table :grilla="$grilla" :paginator="$registros">
```

- Ayudas: `textoDe` (nombre de un registro relacionado, por subconsulta), `persona`/`documento` (persona por id,
  directa o por la tabla del rol con `via`), `nombrePersona`/`documentoPersona` (personas ya unida a la consulta).
- `orden: false` o `filtro: false` quitan una de las dos cosas. Sin orden quedan solo las columnas con una lista
  (especialidades, categorías, perfiles, medios, planes, módulos de un tipo de documento): se filtra por un valor.
- **Lista blanca**: solo cuentan las claves declaradas. Una columna, dirección o valor que no corresponde se ignora
  (orden habitual, sin filtro). Con un orden pedido se desempata por la clave primaria.
- La tabla del listado debe estar dentro de `x-admin.listado` (los campos de la fila son de su formulario por el
  atributo `form`). Pantallas sin buscador general: `:buscador="false"`. Si la tabla es un partial con otro nombre,
  el controlador responde con `listadoEn()`.

## Filtros de arriba

Los que repetían una columna (fechas, estado, profesional, aseguradora, tipo, convenio, usuario, acción, módulo)
pasaron a la fila. El controlador sigue aceptando sus parámetros viejos (enlaces existentes). Quedan arriba los que
no son una columna: "Mostrar vigentes / históricos", "Pendientes de reparto", "Con aviso de cobertura". En las
grillas con "Mostrar", el estado solo ordena.

## Volver a la grilla como estaba

`App\Http\Middleware\RecordarGrilla` guarda orden, filtros, búsqueda y página de cada grilla en la sesión del
servidor (nada en el navegador). Se recuperan solo al volver de un registro de la misma sección: el pedido llega sin
parámetros y su Referer es una página que cuelga de la grilla (`/admin/paises/5/edit` para `/admin/paises`), como
"Cancelar", "Volver" o la redirección después de guardar. Desde el menú (enlaces con `referrerpolicy="no-referrer"`)
o desde otra sección la grilla arranca limpia.

## Auditoría

Ninguna columna de la grilla de Auditoría muestra contenido: filtrar por ellas no revela lo clínico, de recetas ni lo
económico. El texto libre sigue sin buscar en los eventos ocultos (`AuditoriaController::tablasOcultas`).

## Tests

`tests/Feature/Grillas/GrillaTest.php` (orden, lista blanca, filtros, paginación, recordar) y `GrillasTodasTest.php`
(cada columna de cada grilla ordena y filtra sin errores). La lista de grillas está en `tests/Feature/Grillas/rutas.php`:
agregar ahí las nuevas. `GrillasDesplegablesTest` (grupo `lento`, con los datos demo) elige cada opción de cada desplegable
por lo que dice y verifica que cada fila del resultado sea de ese registro: un valor que no es el id o el código real (p. ej.
el índice 0, 1, 2…) lo hace fallar. Un desplegable nuevo necesita su línea en `grillasDesplegablesEtiquetas`.

## Scroll

La página nunca se desplaza de costado: la grilla (`x-admin.table`) se desplaza sola en horizontal. Su contenedor es
`relative`; sin eso, los elementos `absolute` de la tabla (los `sr-only` de las columnas de la derecha) escapaban del
recorte y ensanchaban la página. Arriba de los títulos hay una barra horizontal duplicada y sincronizada
(`resources/js/grilla-barra.js`), visible solo si la tabla desborda; se rehace al cambiar la ventana y al recargar la
tabla en vivo. No hay scroll vertical propio ni títulos fijos: la página baja normal.

## Anchos de columna

Todo en el componente común (`resources/css/app.css`, sobre `[data-grilla-scroll]`), sin tocar las vistas: celdas con
0,75 rem de relleno lateral, tabla `w-max` (cada columna toma el ancho de su contenido; si no entra todo, la grilla se
desplaza) y texto muy largo partido recién a 24 rem. Los filtros de fecha son más angostos (`Desde`/`Hasta`; el formato
va en el `title`). La última columna (acciones) queda **fija a la derecha** (`sticky right-0`) con fondo opaco, y con
sombra cuando la grilla desborda (`data-desborda`, lo marca `grilla-barra.js`). Elegido en vez de solo achicar porque
con muchas acciones por fila (Confirmar, Cancelar, constancias) ninguna cantidad de ajuste garantiza que entren.
