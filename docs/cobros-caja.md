# Cobros y caja (fase 3a económica)

Los **medios de pago**, la **caja** de cada cajero (apertura, cierre diario y aprobación) y el **cobro** de las prestaciones. Sin
reversiones, devoluciones, recibo imprimible, factura, saldos, convenios, egresos, compras ni gastos: son fases futuras. Nada se
borra. Ver también `docs/tarifas-prestaciones.md` y `docs/reparto-economico.md`.

## Reglas

- La paciente paga **siempre después de finalizar la consulta**, cuando la prestación ya existe. Sin cobros anticipados. La
  responsable de pago es siempre la paciente (sin convenios, seguros, saldos ni crédito).
- Se cobran prestaciones **con monto** (tarifadas), **no anuladas**, de la **misma paciente**. Pueden estar pendientes de reparto: el
  cobro no depende del reparto. Las pendientes de tarifar se cobran después de tarifarlas.
- Una prestación tiene **a lo sumo un cobro vigente** (`cobro_prestaciones_un_cobro_vigente`).
- El cobro es **siempre completo**: el total es la suma exacta de los montos de las prestaciones elegidas y la suma de las líneas
  de pago es igual al total. Sin pagos parciales. El vuelto no se registra (se carga el monto exacto). Pueden combinarse medios.
  Una vez hecho, **no se edita**.
- **Anular una prestación cobrada no se permite** ("Esta prestación está cobrada: no se puede anular mientras su cobro esté
  vigente."). La reversión del cobro llega en la fase 3b.

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
  inicial, en Efectivo, si no es cero) y COBRO (una por línea de cobro). Los egresos y las reversiones de fases futuras entran como
  movimientos negativos. **Lo esperado** de cada medio es la suma de sus movimientos (`Caja::esperadoPorMedio`).
- **Cierre** (`Cobros\CerrarCaja`): el cajero declara lo contado en cada medio (los activos y los que tienen movimientos; cero si
  no hay nada). Se guarda un `cierres_caja` (PENDIENTE de aprobación) con `cierre_caja_medios` (esperado, contado, diferencia =
  contado − esperado). Si algún medio tiene diferencia, el **motivo** es obligatorio (5 a 300 caracteres). La caja pasa a CERRADO
  y ya no recibe cobros.
- **Revisión** (`Cobros\RevisarCierre`, Gerencia): **aprobar** (nota opcional) o **devolver** con nota (5 a 300). Nadie revisa el
  cierre de su propia caja. La **devuelta** solo se vuelve a cerrar (otro cierre; el anterior se conserva): no recibe cobros y no
  impide abrir la caja del día.
- Los modelos rechazan cualquier otro cambio y el borrado (`RegistroEconomicoInmutable`).

## Cobro (`cobros`, `cobro_prestaciones`, `lineas_cobro`)

- Caja, paciente, fecha (`cobrado_en`), total en guaraníes enteros, moneda, cajero y estado **VIGENTE**.
- `cobro_prestaciones`: cada prestación cubierta con su monto. `revertido_en` vacío = vigente (lo usará la reversión de la fase 3b).
- `lineas_cobro`: medio, monto (> 0) y referencia opcional (hasta 100 caracteres: número de transferencia o del POS).
- `Cobros\Cobrar` bloquea las prestaciones mientras cobra (también frente a una anulación simultánea) y verifica todo; el índice
  único parcial frena un segundo cobro aunque algo se saltee la verificación.
- Solo PostgreSQL: `cobros_total_positivo`, `cobro_prestaciones_monto_positivo`, `lineas_cobro_monto_positivo`,
  `cajas_monto_inicial_valido`, `cierre_caja_medios_valores_validos` y `movimientos_caja_tipo_valido`.

## Estado de cobro de la prestación

**Cobrada** o **Pendiente**, derivado de si tiene un cobro vigente (`Prestacion::estadoCobroTexto`); vacío en una anulada sin cobro.
No toca los estados de la prestación (PENDIENTE, TARIFADO, ANULADO). Se ve en el listado de Prestaciones (columna "Cobro"), en el
detalle (con el enlace al cobro para quien ve los cobros) y en las demás prestaciones de la consulta.

## Pantallas (grupo "Económico")

- **Cobros** (`admin.cobros.*`): listado (búsqueda por paciente, filtro de fechas) y detalle (prestaciones y pagos). **"Cobrar"**,
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

| | CAJAS | CIERRES_CAJA | COBROS |
|---|---|---|---|
| Acciones | VER (las propias), CREAR (abrir), EDITAR (cerrar la propia) | VER (todas las cajas), EDITAR (aprobar o devolver) | VER, CREAR (cobrar) |
| Administrador | todo (solo) | todo (solo) | todo (solo) |
| Caja | VER, CREAR, EDITAR | nada | VER, CREAR |
| Gerencia | VER | VER, EDITAR | VER |
| Facturación y convenios | nada | nada | VER |
| Recepción, Médico, Enfermería y los demás | nada | nada | nada |

Recepción ve el estado de cobro de las prestaciones (tiene VER sobre PRESTACIONES), pero no cobra ni ve cajas ni cobros. Médico y
Enfermería no ven montos: no tienen los módulos económicos. No existía una aprobación genérica para reutilizar (la de parámetros
clínicos es propia de los médicos): la de cierres es su propio módulo.

## Auditoría

CREAR de la caja ("Apertura de caja"), del cobro, de cada prestación cubierta y de cada línea ("Cobro de N prestaciones"), del
cierre y de sus medios ("Cierre de la caja N"); EDITAR de la caja al cerrarla ("Cierre de caja"), aprobarla ("Cierre aprobado") o
devolverla ("Cierre devuelto"), y del cierre revisado (con la nota). Los movimientos se derivan de la apertura y de las líneas: no
se auditan aparte. Los montos se ocultan ("Contenido económico", `Auditoria::TABLAS_ECONOMICAS`): `cajas`, `cierres_caja` y
`cierre_caja_medios` a quien no tiene VER sobre CAJAS; `cobros`, `cobro_prestaciones` y `lineas_cobro` a quien no tiene VER sobre
COBROS. Al finalizar la consulta no se agrega ningún evento.

## Migración

`create_cobros_y_caja`: `medios_pago.codigo`, siete tablas, los estados **ABIERTO**, **CERRADO** y **DEVUELTO** (los demás ya
existían: PENDIENTE, VIGENTE, APROBADO), los tres módulos con sus estados, los medios de referencia y los permisos de Caja, Gerencia
y Facturación (`PerfilesPredefinidos::PERMISOS_COBROS_Y_CAJA`, lo mismo que la matriz). Crea los estados que falten, no lee filas
durante `migrate --pretend`, no duplica nada, **no crea cobros** (las prestaciones existentes quedan pendientes de cobro) y en una
instalación nueva deja los módulos y los medios a los seeders (`ModuloSistemaSeeder`, `MediosPagoSeeder`). El `down` deja los
módulos, los permisos, los estados y los medios.

## Tests

`tests/Feature/Economico/CobrosCajaTest.php`, `MigracionCobrosTest.php` y `tests/Postgres/CobrosCajaTest.php` (los dos índices únicos
parciales, los CHECK y la migración con prestaciones existentes).
