# Cobros y caja (fases 3a y 3b económicas)

Los **medios de pago**, la **caja** de cada cajero (apertura, cierre diario y aprobación), el **cobro** de las prestaciones y su
**reversión** (fase 3b). Desde la fase 5a (`docs/facturacion.md`) cada cobro nuevo se registra **contra una factura** emitida en la
misma transacción, y un cobro con factura solo se revierte anulando la factura; los anteriores siguen con estas reglas. Sin recibo
imprimible, notas de crédito, pagos parciales, saldos, compras ni gastos: son fases futuras. Nada se borra. Ver también `docs/tarifas-prestaciones.md` y `docs/reparto-economico.md`.

## Reglas

- La paciente paga **siempre después de finalizar la consulta**, cuando la prestación ya existe. Sin cobros anticipados. Sin saldos
  ni crédito. Con una visación de aseguradora (fase 4a, `docs/convenios-seguros.md`) se cobra **solo la parte de la paciente**; una
  prestación con su parte en cero no se ofrece ("A cargo de aseguradora").
- Se cobran prestaciones **con monto** (tarifadas), **no anuladas**, de la **misma paciente**. Pueden estar pendientes de reparto: el
  cobro no depende del reparto. Las pendientes de tarifar se cobran después de tarifarlas.
- Una prestación tiene **a lo sumo un cobro vigente** (`cobro_prestaciones_un_cobro_vigente`).
- El cobro es **siempre completo**: el total es la suma exacta de lo que paga la paciente en las prestaciones elegidas (su parte; en
  las particulares, el monto) y la suma de las líneas
  de pago es igual al total. Sin pagos parciales. El vuelto no se registra (se carga el monto exacto). Pueden combinarse medios.
  Una vez hecho, **no se edita**.
- **Anular una prestación cobrada no se permite** ("Esta prestación está cobrada: no se puede anular mientras su cobro esté
  vigente."): primero se revierte el cobro, después se anula.

## Medios de pago

Catálogo editable (Catálogos → Medios de pago): nada se borra, se desactiva. Un medio inactivo no se ofrece ni se acepta al cobrar.
Sin comisiones. `medios_pago.codigo` identifica los de referencia: **Efectivo** (`EFECTIVO`), **Transferencia** (`TRANSFERENCIA`) y
**Tarjeta (POS)** (`TARJETA`); no se edita desde la pantalla. El monto inicial de la caja va en Efectivo.

`MediosPagoSeeder` los carga si faltan; si ya hay uno **sin código** con ese nombre o un alias ("efectivo"; "transferencia",
"transferencia bancaria"; "tarjeta (pos)", "tarjeta", "pos"), lo **adopta**: le pone el código sin cambiar su nombre ni su estado.

## Caja (`cajas`, modelo `Caja`)

- Cajero (el usuario que la abre), fecha (el día de la apertura, hora local), apertura, monto inicial en efectivo (guaraníes
  enteros, cero o más), moneda, estado y `cerrada_en` (el último cierre).
- Estados: **ABIERTO** ("Abierta"), **CERRADO** ("Cerrada (pendiente de aprobación)"), **APROBADO** y **DEVUELTO** ("Devuelta para
  corregir").
- **Una sola abierta por cajero** (`cajas_una_abierta_por_cajero`, índice único parcial `(usuario_id) WHERE cerrada_en IS NULL`,
  SQLite y PostgreSQL). "Ya tiene una caja abierta: ciérrela antes de abrir otra."
- Se cobra **solo con la caja abierta propia y del día de hoy**. Una abierta de un día anterior no recibe cobros: "Su caja está
  abierta desde el dd/mm/aaaa: ciérrela y abra la de hoy para cobrar." Sin caja: "Abra su caja antes de cobrar."
- **Movimientos** (`movimientos_caja`, `MovimientoCaja`): cada ingreso a la caja por medio de pago, con signo: APERTURA (el monto
  inicial, en Efectivo, si no es cero), COBRO (una por línea de cobro) y REVERSION (el egreso, en negativo, de cada línea de un
  cobro revertido). **Lo esperado** de cada medio es la suma de sus movimientos (`Caja::esperadoPorMedio`): al cerrar, descuenta lo
  devuelto. Por línea, una entrada y a lo sumo una devolución (`movimientos_caja_linea_por_tipo`).
- **Cierre** (`Cobros\CerrarCaja`): el cajero declara lo contado en cada medio (los activos y los que tienen movimientos; cero si
  no hay nada). Se guarda un `cierres_caja` (PENDIENTE de aprobación) con `cierre_caja_medios` (esperado, contado, diferencia =
  contado − esperado). Si algún medio tiene diferencia, el **motivo** es obligatorio (5 a 300 caracteres). La caja pasa a CERRADO
  y ya no recibe cobros.
- **Revisión** (`Cobros\RevisarCierre`, Gerencia): **aprobar** (nota opcional) o **devolver** con nota (5 a 300). Nadie revisa el
  cierre de su propia caja. La **devuelta** solo se vuelve a cerrar (otro cierre; el anterior se conserva): no recibe cobros y no
  impide abrir la caja del día.
- Los modelos rechazan cualquier otro cambio y el borrado (`RegistroEconomicoInmutable`).

## Cobro (`cobros`, `cobro_prestaciones`, `lineas_cobro`)

- Caja, paciente, fecha (`cobrado_en`), total en guaraníes enteros, moneda, cajero y estado **VIGENTE** o **REVERTIDO**.
- `cobro_prestaciones`: cada prestación cubierta con su monto. `revertido_en` vacío = vigente (lo pone la reversión).
- `lineas_cobro`: medio, monto (> 0) y referencia opcional (hasta 100 caracteres: número de transferencia o del POS).
- `Cobros\Cobrar` bloquea las prestaciones mientras cobra (también frente a una anulación simultánea) y verifica todo; el índice
  único parcial frena un segundo cobro aunque algo se saltee la verificación.
- Solo PostgreSQL: `cobros_total_positivo`, `cobro_prestaciones_monto_positivo`, `lineas_cobro_monto_positivo`,
  `cajas_monto_inicial_valido`, `cierre_caja_medios_valores_validos` y `movimientos_caja_tipo_valido`.

## Reversión del cobro (fase 3b, `Cobros\RevertirCobro`)

- Se revierte el cobro **completo** (nunca una sola de sus prestaciones) y **una sola vez**. Nada se borra: queda **REVERTIDO**
  ("Revertido") con `revertido_en`, `revertido_por` (quien devuelve el dinero), `motivo_reversion` (obligatorio, 5 a 300 caracteres)
  y `caja_reversion_id`. Sus prestaciones vuelven a **Pendiente** y se pueden cobrar de nuevo (o anular).
- **Directa** ("Revertir el cobro"): un cobro de la caja **propia** que sigue **ABIERTA** (y es la de hoy; una abierta de un día
  anterior se cierra primero). Sin aprobación.
- **Con aprobación** ("Solicitar la reversión"): un cobro de una caja cerrada, devuelta o aprobada, o de otro cajero.
  `solicitudes_reversion_cobro` (`SolicitudReversionCobro`): cobro, solicitante, motivo y estado **PENDIENTE**, **APROBADO** o
  **RECHAZADO** (con quién, cuándo y la nota). Gerencia (o el Administrador) la aprueba (nota opcional) o la rechaza (nota de 5 a
  300). **Nadie revisa una solicitud propia.** Una sola pendiente por cobro (`solicitudes_reversion_una_pendiente`, índice único
  parcial `(cobro_id) WHERE revisado_en IS NULL`, SQLite y PostgreSQL); mientras esté, el cobro tampoco se revierte directo.
  Rechazada, se puede volver a pedir. **Al aprobarse se revierte.** Si el solicitante no tiene abierta la caja de hoy, no se aprueba
  hasta que la abra (el aviso está en el detalle del cobro).
- **Dinero devuelto:** un **egreso** (REVERSION) por cada línea del cobro, con su medio y monto (un cobro mixto devuelve cada línea
  por su medio), en la caja **abierta de hoy** de quien devuelve: el cajero que revierte o el solicitante. Si lo esperado de algún
  medio en esa caja no alcanza, se rechaza **solo si es el efectivo** (sale del cajón: "No alcanza el efectivo de la caja para
  devolver ₲ X (hay ₲ Y)."). En transferencia, tarjeta y los demás medios se permite aunque lo esperado quede negativo. Las cajas
  cerradas, devueltas o aprobadas **no se modifican nunca**: el cobro sigue listado en su caja, marcado "(revertido)"; el egreso está
  en la caja que devolvió ("Devoluciones de cobros revertidos").
- **El medio efectivo** es el marcado con `medios_pago.es_efectivo` (a lo sumo uno, `medios_pago_un_efectivo`; lo marcan la
  migración y `MediosPagoSeeder` en Efectivo), no un nombre: `MedioPago::efectivo()` y `esEfectivo()` lo leen de ahí.
- **Cierre con negativos:** en los medios que no son efectivo lo esperado puede ser negativo y lo contado se declara con signo
  ("-150.000"; la pantalla lo explica en el medio). Si coincide, no hay diferencia; si no, la diferencia se ve y pide motivo, igual
  que siempre. El efectivo contado sigue siendo cero o más ("Lo contado en efectivo no puede ser negativo."). Cada medio del cierre
  guarda la marca (`cierre_caja_medios.es_efectivo`); en PostgreSQL atada a la del medio por clave foránea
  (`cierre_caja_medios_es_efectivo_del_medio`) y `cierre_caja_medios_valores_validos` exige esperado y contado >= 0 solo en el
  efectivo. La aprobación de Gerencia no cambia.
- Los modelos admiten solo VIGENTE → REVERTIDO (con todos sus datos), `revertido_en` una vez y PENDIENTE → APROBADO o RECHAZADO.
- Solo PostgreSQL: `cobros_reversion_completa` (los cuatro datos de la reversión van juntos), `solicitudes_reversion_revision_completa`
  y `movimientos_caja_tipo_valido` (ahora con REVERSION: monto negativo, con cobro y línea).

## Estado de cobro de la prestación

**Cobrada** o **Pendiente**, derivado de si tiene un cobro vigente (`Prestacion::estadoCobroTexto`); vacío en una anulada sin cobro.
No toca los estados de la prestación (PENDIENTE, TARIFADO, ANULADO). Se ve en el listado de Prestaciones (columna "Cobro"), en el
detalle (con el enlace al cobro para quien ve los cobros) y en las demás prestaciones de la consulta.

## Pantallas (grupo "Económico")

- **Cobros** (`admin.cobros.*`): listado (búsqueda por paciente, filtro de fechas y de estado) y detalle (prestaciones, pagos y,
  según el caso, "Revertir el cobro" o "Solicitar la reversión", con el motivo; la solicitud pendiente, con "Aprobar la reversión" y
  "Rechazar" para Gerencia; las solicitudes ya revisadas). Pestaña **"Reversiones pendientes (N)"** (`admin.cobros.reversiones`,
  solo para quien las revisa): "Aprobar" en la misma fila y "Ver el cobro" para rechazar. Sin entradas nuevas en el menú. **"Cobrar"**,
  en un solo flujo: buscar la paciente (la pantalla ya lista las que tienen prestaciones sin cobrar, con la cantidad y el total),
  elegirla, tildar sus prestaciones sin cobrar (vienen todas tildadas), cargar hasta tres pagos (medio, monto, referencia; el
  primero ya trae Efectivo y el total) y confirmar. Con JavaScript (`resources/js/pantalla-cobro.js`) suma en vivo y avisa cuánto
  falta o sobra; sin JavaScript funciona igual (el servidor verifica todo). No guarda nada en el navegador.
- **Cajas** (`admin.cajas.*`): "Mi caja" (abrir con el monto inicial, ir a cobrar, ver y cerrar), aviso de las devueltas y el
  listado (las propias; todas para Gerencia, con el aviso de las pendientes de aprobación y el filtro por estado). El detalle muestra
  lo esperado por medio, los cobros, el formulario de cierre (su cajero), "Aprobar el cierre" y "Devolver" (Gerencia) y el historial
  de cierres con sus diferencias, motivos y notas.

## Permisos

Módulos nuevos (en la matriz, grupo Económico):

| | CAJAS | CIERRES_CAJA | COBROS | REVERSIONES_COBRO |
|---|---|---|---|---|
| Acciones | VER (las propias), CREAR (abrir), EDITAR (cerrar la propia) | VER (todas las cajas), EDITAR (aprobar o devolver) | VER, CREAR (cobrar), DESACTIVAR (revertir o solicitar la reversión) | VER (las solicitudes), EDITAR (aprobar o rechazar) |
| Administrador | todo (solo) | todo (solo) | todo (solo) | todo (solo) |
| Caja | VER, CREAR, EDITAR | nada | VER, CREAR, DESACTIVAR | nada |
| Gerencia | VER | VER, EDITAR | VER | VER, EDITAR |
| Facturación y convenios | nada | nada | VER | nada |
| Recepción, Médico, Enfermería y los demás | nada | nada | nada | nada |

Recepción ve el estado de cobro de las prestaciones (tiene VER sobre PRESTACIONES), pero no cobra ni ve cajas ni cobros. Médico y
Enfermería no ven montos: no tienen los módulos económicos. No existía una aprobación genérica para reutilizar (la de parámetros
clínicos es propia de los médicos): la de cierres es su propio módulo.

## Auditoría

CREAR de la caja ("Apertura de caja"), del cobro, de cada prestación cubierta y de cada línea ("Cobro de N prestaciones"), del
cierre y de sus medios ("Cierre de la caja N"); EDITAR de la caja al cerrarla ("Cierre de caja"), aprobarla ("Cierre aprobado") o
devolverla ("Cierre devuelto"), y del cierre revisado (con la nota). Los movimientos se derivan de la apertura y de las líneas: no
se auditan aparte. Los montos se ocultan ("Contenido económico", `Auditoria::TABLAS_ECONOMICAS`): `cajas`, `cierres_caja` y
`cierre_caja_medios` a quien no tiene VER sobre CAJAS; `cobros`, `cobro_prestaciones` y `lineas_cobro` a quien no tiene VER sobre
COBROS. Reversión: REVERTIR del cobro ("Reversión del cobro. Motivo: …"; el usuario es quien revierte o quien aprueba) y EDITAR de
cada prestación cubierta ("Reversión del cobro N"); CREAR de la solicitud ("Solicitud de reversión del cobro N") y EDITAR al
revisarla ("Reversión aprobada" o "Reversión rechazada", con la nota). Los egresos se derivan del cobro revertido: no se auditan
aparte. `solicitudes_reversion_cobro` se oculta a quien no tiene VER sobre COBROS. Al finalizar la consulta no se agrega ningún evento.

## Migración

`create_cobros_y_caja`: `medios_pago.codigo`, siete tablas, los estados **ABIERTO**, **CERRADO** y **DEVUELTO** (los demás ya
existían: PENDIENTE, VIGENTE, APROBADO), los tres módulos con sus estados, los medios de referencia y los permisos de Caja, Gerencia
y Facturación (`PerfilesPredefinidos::PERMISOS_COBROS_Y_CAJA`, lo mismo que la matriz). Crea los estados que falten, no lee filas
durante `migrate --pretend`, no duplica nada, **no crea cobros** (las prestaciones existentes quedan pendientes de cobro) y en una
instalación nueva deja los módulos y los medios a los seeders (`ModuloSistemaSeeder`, `MediosPagoSeeder`). El `down` deja los
módulos, los permisos, los estados y los medios.

`reversiones_de_cobro` (fase 3b): las cuatro columnas de la reversión en `cobros` (vacías: los cobros existentes quedan vigentes),
`solicitudes_reversion_cobro` con su único parcial, el único de `movimientos_caja` por línea y tipo, `es_efectivo` en `medios_pago`
(marca el de código EFECTIVO) y en `cierre_caja_medios` (los cierres existentes solo reciben la marca; sus montos no cambian), los
CHECK de PostgreSQL (el del cierre, con negativos fuera del efectivo), el
estado **REVERTIDO** (PENDIENTE, APROBADO, RECHAZADO y VIGENTE ya existían), el módulo REVERSIONES_COBRO y los permisos
(`PerfilesPredefinidos::PERMISOS_REVERSIONES_COBRO`). Crea los estados que falten, no lee filas durante `--pretend`, no revierte
nada y en una instalación nueva deja el módulo al seeder. El `down` solo vale sin cobros revertidos y deja módulo, permisos y estados.

## Tests

`tests/Feature/Economico/CobrosCajaTest.php`, `MigracionCobrosTest.php` y `tests/Postgres/CobrosCajaTest.php` (los dos índices únicos
parciales, los CHECK y la migración con prestaciones existentes). Reversión: `tests/Feature/Economico/ReversionesCobroTest.php`,
`MigracionReversionesTest.php` y `tests/Postgres/ReversionesCobroTest.php` (el único parcial de solicitudes, el de devoluciones por
línea, los CHECK y la migración con cobros existentes).
