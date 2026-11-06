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

## Comprobante del pago

- Los medios que **piden comprobante** exigen su número al cobrar: Transferencia (código), Tarjeta (POS: voucher), **Depósito** (boleta) y
  **Cheque** (número). El efectivo no lo pide (en PostgreSQL, `medios_pago_efectivo_sin_comprobante`). No hay que repetirlo ni es único:
  distintos bancos repiten números.
- Se define en el **catálogo** (Catálogos → Medios de pago): `medios_pago.requiere_comprobante` y `nombre_comprobante` ("Voucher del POS");
  no hay nombres fijos en el código. `MedioPago::exigeComprobante()` es la única pregunta. Un medio nuevo que lo pida lo define ahí.
  `MediosPagoSeeder` carga Depósito (`DEPOSITO`) y Cheque (`CHEQUE`) y marca los cuatro (solo los que aún no se configuraron).
- Se guarda en la `referencia` de la línea: sin migrar datos. **Rige solo para cobros nuevos** (`Cobrar` y el formulario): los cobros
  anteriores quedan como están ("Sin comprobante cargado" en el detalle).
- El formulario de cobro marca el campo como obligatorio según el medio elegido y muestra el error en esa fila ("Indique el comprobante
  del pago con Cheque (Número del cheque)."); el servidor lo verifica igual.
- Se ve en el detalle del cobro, en la grilla de Cobros (columna "Comprobantes", con filtro) y en los cobros de la caja, para el arqueo.
- **Corregirlo** (`Cobros\CorregirComprobante`, permiso EDITAR sobre COBROS): ver "Arqueo" abajo. Solo en un arqueo **devuelto por
  diferencias** y solo **su cajero**: el comprobante y el **medio de pago** de cada línea; nunca el monto, y no se anula ni se revierte
  nada por aquí.

## Caja (`cajas`, modelo `Caja`)

- Cajero (el usuario que la abre), **sucursal** (`sucursal_id`, fija), fecha (el día de la apertura, hora local), apertura, monto
  inicial en efectivo (guaraníes enteros, cero o más), moneda, estado y `cerrada_en` (el último cierre).
- **Sucursal:** al abrir, si hay una sola activa se asigna sola; si hay más, el cajero la elige entre las activas ("Elija la
  sucursal de la caja."); sin ninguna activa no se abre. La impresión del arqueo usa la de la caja.
- Estados: **ABIERTO** ("Abierta"), **CERRADO** ("Pendiente de recepción"), **DEVUELTO** ("Devuelto por diferencias") y **APROBADO**
  ("Recibido"). Los códigos son los de siempre; cambiaron los nombres.
- **Una sola abierta por cajero** (`cajas_una_abierta_por_cajero`, índice único parcial `(usuario_id) WHERE cerrada_en IS NULL`,
  SQLite y PostgreSQL). "Ya tiene una caja abierta: ciérrela antes de abrir otra." Con una caja **devuelta** tampoco abre otra
  ("Tiene una caja devuelta por diferencias: corríjala y reenvíela antes de abrir otra."); con una **pendiente de recepción**, sí.
- Se cobra **solo con la caja abierta propia y del día de hoy**. Una abierta de un día anterior no recibe cobros: "Su caja está
  abierta desde el dd/mm/aaaa: ciérrela y abra la de hoy para cobrar." Sin caja: "Abra su caja antes de cobrar."
- **Movimientos** (`movimientos_caja`, `MovimientoCaja`): cada ingreso a la caja por medio de pago, con signo: APERTURA (el monto
  inicial, en Efectivo, si no es cero), COBRO (una por línea de cobro), REVERSION (el egreso, en negativo, de cada línea de un
  cobro revertido), SALIDA (el egreso en efectivo de una salida de caja) y ANULACION_SALIDA (su reingreso al anularla). **Lo esperado** de cada medio es la suma de sus movimientos (`Caja::esperadoPorMedio`): al cerrar, descuenta lo
  devuelto. Por línea, una entrada y a lo sumo una devolución (`movimientos_caja_linea_por_tipo`).
- **Cierre** (`Cobros\CerrarCaja`): ver "Arqueo".
- Los modelos rechazan cualquier otro cambio y el borrado (`RegistroEconomicoInmutable`).

## Arqueo (cierre, recepción y corrección)

1. **El cajero cierra** (`Cobros\CerrarCaja`) desde una **pantalla propia** (`cajas.cierre`; el detalle solo tiene el botón, para no cerrar por error):
   al continuar (`cajas.cierre.revisar`, que aplica las reglas sin cerrar) ve una **confirmación** con el aviso de que la caja deja de
   recibir cobros y queda pendiente de recepción del gerente; recién ahí `cajas.cerrar` (exige `confirmar`; sin él no cierra). El reenvío del
   arqueo devuelto usa la misma pantalla. El formulario trae **precargado lo esperado** de cada medio (los activos y los que
   tienen movimientos); el cajero lo cambia si contó otra cosa. Muestra en vivo la diferencia por medio y el total
   (`resources/js/arqueo-caja.js`; sin JavaScript funciona igual). La **observación** es obligatoria (5 a 300) **solo si hay
   diferencia**; sin diferencia el campo queda bloqueado y vacío, y el servidor descarta lo que llegue, sin error. Se guarda un
   `cierres_caja` (PENDIENTE) con `cierre_caja_medios` (esperado, declarado = `contado`, diferencia = declarado − esperado). La caja
   queda **Pendiente de recepción** y ya no recibe cobros.
2. **Gerencia acepta o rechaza** (`Cobros\RevisarCierre`, EDITAR sobre CIERRES_CAJA): aceptar (nota opcional; antes, una pantalla de
   confirmación `cajas.recibir`, y `cajas.aprobar` exige `confirmar`) la deja **Recibido**;
   rechazar exige la **observación con el inconveniente** (5 a 300; "Indique la observación con el inconveniente…") y la deja
   **Devuelto por diferencias**. Nadie revisa su propia caja.
3. **El cajero corrige y reenvía**: otro cierre (el anterior se conserva) con el **nuevo esperado** precargado; vuelve a Pendiente.
   Se repite hasta que Gerencia acepte.

- **Historial** ("Historial del arqueo" en el detalle): cada intento con lo declarado por medio, la observación del cajero, quién lo
  devolvió o recibió, cuándo y el motivo. Son los `cierres_caja` de la caja.
- **Bloqueo:** Pendiente o Devuelta, la caja no recibe cobros (solo cobra la abierta). `Cobrar`, `CerrarCaja`, `RevisarCierre` y
  `CorregirComprobante` toman la fila de la caja con `FOR UPDATE`: un cobro y un cierre, una corrección y una recepción, no se cruzan.
  `RevisarCierre` y `AbrirCaja` toman además la fila del cajero: una devolución y una apertura del mismo cajero tampoco.
- **Corrección en un arqueo devuelto** (`Cobros\CorregirComprobante`, desde el detalle de cada cobro, "Corregir"): solo el cajero de
  esa caja; del cobro, solo el **comprobante** y el **medio de pago** de cada línea (a un medio **activo**); nunca el monto, y no se
  anula ni se revierte (la anulación sigue su regla: en el plazo de la factura el cajero, después Gerencia). Motivo obligatorio (5 a
  300). Al cambiar el medio, la línea y su movimiento de caja pasan al nuevo (el mismo registro, sin revertir) y **lo esperado se
  recalcula solo**; si el nuevo medio exige comprobante se pide, y en efectivo queda vacío. Un cobro puede tener varias líneas del
  mismo medio, cada una con su comprobante (el límite de tres líneas por cobro no cambia). Auditoría: EDITAR de la línea con quién,
  cuándo, el valor anterior y el nuevo (`medio_pago_id` y `referencia`) y "Corrección del medio de pago (o del comprobante) del
  cobro N. Motivo: …". Ningún reparto, liquidación ni factura guarda el medio del cobro: no hay nada más que actualizar.
- **Impresión** (`Cobros\PdfArqueo`, "Imprimir el arqueo (PDF)", ruta `admin.cajas.arqueo`, VER sobre CAJAS: el cajero la suya,
  Gerencia cualquiera; desde el primer cierre): clínica, fecha, cajero, sucursal, apertura y cierre, estado, monto inicial, las
  facturas cobradas, cada transacción (cobro, factura, paciente, medio, comprobante, monto; los revertidos, marcados), las
  devoluciones hechas desde esa caja, los totales por medio del último arqueo (esperado, declarado, diferencia y total), la
  observación (en blanco si no hay), la recepción o devolución de Gerencia y el espacio para la firma del cajero y del gerente. Al
  vuelo con dompdf, sin guardarse (no-store); cada generación queda en Auditoría (EXPORTAR sobre `cajas`).

## Cobro (`cobros`, `cobro_prestaciones`, `lineas_cobro`)

- Caja, paciente, fecha (`cobrado_en`), total en guaraníes enteros, moneda, cajero y estado **VIGENTE** o **REVERTIDO**.
- `cobro_prestaciones`: cada prestación cubierta con su monto. `revertido_en` vacío = vigente (lo pone la reversión).
- `lineas_cobro`: medio, monto (> 0) y referencia (hasta 100 caracteres): el **comprobante** del pago (ver abajo).
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

## Salidas de caja (`salidas_caja`, `Cobros\RegistrarSalida` y `Cobros\AnularSalida`)

Retiros, gastos y pagos menores que salen de la caja, además de la apertura, los cobros y las devoluciones.

- **Quién y dónde:** el cajero, en **su caja abierta de hoy** (CREAR sobre SALIDAS_CAJA: Caja, Gerencia y el Administrador). Sin
  aprobación previa ni tope por monto. Una caja pendiente de recepción, devuelta o recibida, o abierta de otro día, **no admite
  salidas ni anulaciones**.
- **Datos:** categoría de gasto (el catálogo de Compras, solo las activas; el formulario las lista sin pedir permiso sobre
  CATEGORIAS_GASTO, así que Caja las elige pero no las administra), beneficiario (texto libre), comprobante o recibo (opcional),
  concepto (5 a 300 caracteres) y monto (guaraníes enteros, mayor que cero). **Siempre en efectivo.**
- **Lo esperado:** el egreso es un movimiento SALIDA, en negativo y en efectivo: baja lo esperado en efectivo del cierre. **El
  efectivo no puede quedar negativo:** "No alcanza el efectivo de la caja para esta salida de ₲ X (hay ₲ Y): la caja no puede quedar
  con efectivo negativo."
- **Anulación** (el esquema de la factura): **dentro de los 10 minutos** desde el registro (`config('clinexa.caja.plazo_anulacion_salida_minutos')`,
  `CLINEXA_PLAZO_ANULACION_SALIDA`) la anula quien la registró, con motivo obligatorio. **Pasado el plazo**, quien la registró la
  pide con motivo y **Gerencia** la aprueba o la rechaza con nota; nadie revisa la propia; una sola pendiente por salida. La solicitud
  es la misma de las reversiones de cobro (`solicitudes_reversion_cobro` con `salida_caja_id` y sin `cobro_id`), con el mismo
  permiso (EDITAR sobre REVERSIONES_COBRO) y en la misma pestaña de Cobros. Solo mientras la caja de la salida siga abierta y sea la
  de hoy: con la caja ya cerrada no se anula (una solicitud pendiente se rechaza). Nada se borra: la salida queda ANULADA con quién,
  cuándo y el motivo, y un movimiento ANULACION_SALIDA (positivo) compensa su egreso en la misma caja. **No hay edición:** se
  corrige anulando y registrando otra.
- **Pantallas:** en el detalle de la caja ("Mi caja" y el arqueo), la sección **"Salidas de caja"** (hora, categoría, beneficiario,
  comprobante, concepto, monto y el total de las vigentes; las anuladas, tachadas y con el motivo), el formulario "Registrar una
  salida" y, por fila, "Anular" o "Solicitar la anulación". En "Mi caja" del listado, el enlace "Registrar una salida". La impresión
  del arqueo trae la misma sección.
- **Compras:** independiente por ahora. `salidas_caja.compra_id` queda **vacío**, previsto para vincular el contado y el anticipo de
  una compra en una fase posterior (docs/compras-gastos.md).
- Solo PostgreSQL: `salidas_caja_valida` (monto > 0, beneficiario y concepto), `salidas_caja_en_efectivo` (clave foránea al medio
  efectivo), `salidas_caja_anulacion_completa`, `movimientos_caja_tipo_valido` (con SALIDA y ANULACION_SALIDA, ligadas a la salida
  y sin cobro), `movimientos_caja_salida_por_tipo` (uno de cada tipo por salida), `solicitudes_reversion_objeto` (cobro o salida,
  nunca los dos) y `solicitudes_anulacion_salida_una_pendiente` (también en SQLite).

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
  elegirla, tildar sus prestaciones sin cobrar (vienen todas tildadas), cargar hasta tres pagos (medio, monto, comprobante; el
  primero ya trae Efectivo y el total) y confirmar. Con JavaScript (`resources/js/pantalla-cobro.js`) suma en vivo y avisa cuánto
  falta o sobra; sin JavaScript funciona igual (el servidor verifica todo). No guarda nada en el navegador.
- **Cajas** (`admin.cajas.*`): "Mi caja" (abrir con el monto inicial y, con varias sucursales, la sucursal; ir a cobrar, ver y
  cerrar), aviso de las devueltas y el listado (las propias; todas para Gerencia, con el aviso de las pendientes de recepción y el
  filtro por estado). El detalle muestra la sucursal, lo esperado por medio, los cobros, el formulario de cierre o de reenvío (su
  cajero), "Aceptar (recibido)" y "Rechazar" (Gerencia), "Imprimir el arqueo (PDF)" y el historial del arqueo.

## Permisos

Módulos nuevos (en la matriz, grupo Económico):

| | CAJAS | CIERRES_CAJA | COBROS | REVERSIONES_COBRO | SALIDAS_CAJA |
|---|---|---|---|---|---|
| Acciones | VER (las propias; imprimir el arqueo), CREAR (abrir), EDITAR (cerrar la propia) | VER (todas las cajas), EDITAR (aceptar o rechazar el arqueo) | VER, CREAR (cobrar), EDITAR (corregir medio y comprobante en un arqueo devuelto propio), DESACTIVAR (revertir o solicitar la reversión) | VER (las solicitudes, también las de anulación de salidas), EDITAR (aprobar o rechazar) | VER, CREAR (registrar en la caja propia), DESACTIVAR (anular en el plazo o solicitar la anulación) |
| Administrador | todo (solo) | todo (solo) | todo (solo) | todo (solo) | todo (solo) |
| Caja | VER, CREAR, EDITAR | nada | VER, CREAR, EDITAR, DESACTIVAR | nada | VER, CREAR, DESACTIVAR |
| Gerencia | VER | VER, EDITAR | VER | VER, EDITAR | VER, CREAR, DESACTIVAR (solo sirve si además abre una caja) |
| Facturación y convenios | nada | nada | VER | nada | nada |
| Recepción, Médico, Enfermería y los demás | nada | nada | nada | nada | nada |

Recepción ve el estado de cobro de las prestaciones (tiene VER sobre PRESTACIONES), pero no cobra ni ve cajas ni cobros. Médico y
Enfermería no ven montos: no tienen los módulos económicos. No existía una aprobación genérica para reutilizar (la de parámetros
clínicos es propia de los médicos): la de cierres es su propio módulo.

## Auditoría

CREAR de la caja ("Apertura de caja"), del cobro, de cada prestación cubierta y de cada línea ("Cobro de N prestaciones"), del
cierre y de sus medios ("Cierre de la caja N"); EDITAR de la caja al cerrarla ("Cierre de caja"), aceptarla ("Cierre recibido") o
devolverla ("Cierre devuelto por diferencias"), y del cierre revisado (con la nota); EDITAR de la línea corregida (medio y
comprobante, antes y después, con el motivo); EXPORTAR de la caja al imprimir el arqueo. Los movimientos se derivan de la apertura y de las líneas: no
se auditan aparte. Los montos se ocultan ("Contenido económico", `Auditoria::TABLAS_ECONOMICAS`): `cajas`, `cierres_caja` y
`cierre_caja_medios` a quien no tiene VER sobre CAJAS; `cobros`, `cobro_prestaciones` y `lineas_cobro` a quien no tiene VER sobre
COBROS. Reversión: REVERTIR del cobro ("Reversión del cobro. Motivo: …"; el usuario es quien revierte o quien aprueba) y EDITAR de
cada prestación cubierta ("Reversión del cobro N"); CREAR de la solicitud ("Solicitud de reversión del cobro N") y EDITAR al
revisarla ("Reversión aprobada" o "Reversión rechazada", con la nota). Los egresos se derivan del cobro revertido: no se auditan
aparte. `solicitudes_reversion_cobro` se oculta a quien no tiene VER sobre COBROS. Salidas: CREAR de la salida ("Salida de caja:
categoría, a beneficiario", con quién, cuándo, monto, categoría, beneficiario, comprobante y concepto); ANULAR ("Anulación de la
salida de caja. Motivo: …"; el usuario es quien la anula o quien aprueba); CREAR de la solicitud ("Solicitud de anulación de la salida
de caja N") y EDITAR al revisarla ("Anulación de la salida de caja aprobada" o "… rechazada", con la nota). `salidas_caja` se oculta
a quien no tiene VER sobre CAJAS; sus movimientos se derivan de la salida y no se auditan aparte. Al finalizar la consulta no se agrega ningún evento.

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

`sucursal_de_la_caja` (2026_11_05_100001): `cajas.sucursal_id`. Las cajas existentes toman **la única sucursal** que hay; si hay cajas
y no hay exactamente una sucursal, se detiene sin cambiar nada. En PostgreSQL queda NOT NULL; en SQLite lo exige el modelo (y la
migración restaura el índice único parcial, que SQLite perdería al reconstruir la tabla). No toca cobros ni importes. El `down`
quita la columna.

`salidas_de_caja` (2026_11_09_100001): la tabla `salidas_caja`, `movimientos_caja.salida_caja_id` con su único por tipo, los tipos
SALIDA y ANULACION_SALIDA en el CHECK de PostgreSQL (las filas existentes lo cumplen sin cambios), `solicitudes_reversion_cobro.cobro_id`
pasa a admitir vacío (en PostgreSQL solo se quita el NOT NULL; en SQLite se reconstruye la tabla y se restaura el índice único
parcial) y suma `salida_caja_id`, y el módulo SALIDAS_CAJA (VIGENTE y ANULADO) en una instalación existente. Aditiva: no cambia
cajas, cobros, cierres, movimientos ni solicitudes ya cargados (lo verifica `tests/Postgres/SalidasCajaTest.php`). El `down` solo
vale sin salidas cargadas. `permisos_salidas_caja` (2026_11_09_100002): suma VER, CREAR y DESACTIVAR sobre SALIDAS_CAJA a Caja y
Gerencia (`PerfilesPredefinidos::PERMISOS_SALIDAS_CAJA`); solo suma y se puede repetir.

## Tests

Arqueo: `tests/Feature/Economico/ArqueoCajaTest.php` (transiciones, rechazo sin observación, observación solo con diferencia,
bloqueo de cobros y de la apertura, corrección con auditoría, sucursal, migración e impresión) y `tests/Postgres/ArqueoCajaTest.php`
(NOT NULL y concurrencia con dos conexiones).

Comprobante: `tests/Feature/Economico/ComprobanteCobrosTest.php` y `tests/Postgres/ComprobanteCobrosTest.php`. Migración `comprobante_de_cobros` (2026_11_04_100001): las dos columnas del medio, el CHECK, Depósito y Cheque, y EDITAR sobre COBROS para Caja; no toca ningún cobro.

`tests/Feature/Economico/CobrosCajaTest.php`, `MigracionCobrosTest.php` y `tests/Postgres/CobrosCajaTest.php` (los dos índices únicos
parciales, los CHECK y la migración con prestaciones existentes). Reversión: `tests/Feature/Economico/ReversionesCobroTest.php`,
`MigracionReversionesTest.php` y `tests/Postgres/ReversionesCobroTest.php` (el único parcial de solicitudes, el de devoluciones por
línea, los CHECK y la migración con cobros existentes).

Salidas de caja: `tests/Feature/Economico/SalidasCajaTest.php` (salida válida, efectivo insuficiente, caja cerrada, devuelta o de
otro día, anulación dentro y fuera de los 10 minutos, nadie revisa la propia, lo esperado, el cierre y la impresión con salidas,
permisos, migración de permisos y auditoría) y `tests/Postgres/SalidasCajaTest.php` (los CHECK, los únicos y la migración sobre datos
existentes).
