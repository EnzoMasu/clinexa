# Liquidaciones a aseguradoras (fase 4b económica)

La **liquidación mensual** a cada aseguradora (la planilla que se le presenta), su **aprobación** y los **pagos parciales** de la
aseguradora: una cuenta por cobrar aparte de la Caja. Sin facturas, timbrado ni IVA (la factura es de la fase siguiente), notas de
crédito, glosas (rechazo de líneas), liquidaciones a profesionales ni al dueño del equipo, ni cuentas por pagar. Nada se borra. Ver
también `docs/convenios-seguros.md`, `docs/tarifas-prestaciones.md` y `docs/cobros-caja.md`.

## Qué entra

Al **armarla**, Facturación y convenios elige la **aseguradora** (un responsable de pago con convenio) y la **fecha de corte** (hoy o
antes; una futura se rechaza). Entran **todas** las prestaciones de esa aseguradora con pagador **ASEGURADORA**, pendientes de liquidar
(lo de la aseguradora mayor que cero), con la **firma recibida**, no anuladas, **sin liquidar** y con fecha de servicio hasta el corte
(`Liquidaciones\ArmarLiquidacion`). Las que no tienen la firma **no entran**: quedan para la siguiente; la pantalla muestra cuántas y
cuáles, con el enlace a la página de la paciente para marcar la firma. Las particulares (sin visación, NO_CUBIERTO, anteriores a los
convenios) y las que la paciente paga completas nunca entran.

Las prestaciones **no se liquidan solas** (sin hito): solo cuando alguien arma la liquidación. La marca `prestaciones.liquidacion`
(PENDIENTE, de la fase 4a) **no cambia**: una prestación está liquidada si tiene una línea no liberada (`Prestacion::lineaLiquidacion`).

## Estructura

- **Liquidación** (`liquidaciones`, `Liquidacion`): aseguradora, convenio (el de sus prestaciones; vacío si son de más de uno), fecha de
  corte, estado, **total** (la suma exacta de sus líneas no quitadas, en guaraníes enteros), moneda, quién y cuándo la armó, la envió, la
  aprobó y la devolvió (la última, con la nota; las anteriores en Auditoría), la marca **pendiente de facturar** y la anulación con motivo.
  El período de la planilla va de la fecha de servicio más antigua al corte.
- **Línea** (`lineas_liquidacion`, `LineaLiquidacion`): una por prestación, con el **snapshot** de la planilla: paciente, documento,
  número de asegurado, fecha de servicio, procedimiento, número de visación y lo que paga la aseguradora. Sin nada clínico. Solo se
  **libera**, una vez y con motivo: **QUITADA** de la liquidación en preparación o **ANULADA** con ella. Liberada, la prestación vuelve a
  quedar libre.
- **Pago de la aseguradora** (`pagos_aseguradora`, `PagoAseguradora`): monto en guaraníes enteros, fecha (no futura), medio activo del
  catálogo de medios de pago (transferencia u otro; para cheques, agregue "Cheque" en Catálogos → Medios de pago) y referencia opcional
  (hasta 100 caracteres). **VIGENTE** o **ANULADO** con motivo.

## Estados

**EN_PREPARACION** ("En preparación", inicial) → **PENDIENTE_APROBACION** ("Pendiente de aprobación") → **APROBADO** ("Aprobada") →
**PARCIAL** ("Pagada en parte") → **PAGADO** ("Pagada"); o **ANULADO** ("Anulada"). PENDIENTE_APROBACION es nuevo; PARCIAL, PAGADO y los
demás ya existían en el catálogo de estados. La devuelta vuelve a EN_PREPARACION. El modelo rechaza cualquier otro cambio, y el borrado.

## Flujo (`Liquidaciones\FlujoLiquidacion`)

- **Una sola abierta** (en preparación o pendiente de aprobación) por aseguradora (`liquidaciones_una_abierta`, índice único parcial
  `(responsable_pago_id) WHERE aprobada_en IS NULL AND anulada_en IS NULL`, SQLite y PostgreSQL).
- **Una prestación se liquida una sola vez** (`lineas_liquidacion_una_por_prestacion`, índice único parcial `(prestacion_id) WHERE
  liberada_en IS NULL`, SQLite y PostgreSQL). Los dos índices frenan lo que se saltee la verificación.
- En preparación, Facturación **quita líneas** con motivo (5 a 300 caracteres; el total se recalcula) y la **envía a aprobación** (con al
  menos una línea).
- Gerencia (o el Administrador) la **aprueba** o la **devuelve** con nota (5 a 300). **Nadie aprueba ni devuelve una liquidación que armó
  él mismo** (también en PostgreSQL: `liquidaciones_aprobacion_completa`).
- **Aprobada, queda bloqueada** y marcada **pendiente de facturar** (`facturacion` = PENDIENTE; la factura es otra fase).
- **Anular** exige motivo y que no tenga pagos vigentes ("anule los pagos antes de anularla"); sus líneas quedan ANULADA y sus
  prestaciones, libres. La que no está aprobada la anula Facturación o Gerencia; la aprobada, solo quien aprueba (Gerencia o el
  Administrador).

## Bloqueo de la prestación (`Liquidaciones\BloqueoLiquidacion`)

Una prestación en una liquidación no anulada **no cambia de visación, firma ni monto, ni se anula**: "Aplicar visación", marcar o
desmarcar la firma y anular la prestación se rechazan con el aviso:

- en preparación: "… en preparación: quítela de la liquidación antes de cambiarla.";
- pendiente de aprobación: "… pendiente de aprobación: pida que la devuelvan y quítela antes de cambiarla.";
- aprobada (o pagada): "… aprobada: no se pueden cambiar su visación, su firma ni su monto.".

La página de la paciente no ofrece esas acciones en una liquidada. El cobro de la parte de la paciente (y su reversión) no cambia.

## Pagos (`Liquidaciones\PagosAseguradora`)

Varios pagos parciales sobre una liquidación **aprobada** con saldo. La suma de los pagos vigentes no supera el total ("El pago supera el
saldo de la liquidación (₲ X)."). **Saldo = total − pagos vigentes**: se calcula, no se guarda. Con pagos y saldo, PARCIAL; sin saldo,
PAGADO. Un pago mal cargado se **anula** con motivo y el estado se recalcula (sin pagos, vuelve a APROBADO). **No pasa por Caja**: no
toca cajas, cierres, movimientos ni cobros.

## Planilla (`Liquidaciones\PlanillaLiquidacion`)

PDF con dompdf, A4 apaisada, **al vuelo** (no se guarda; `Cache-Control: no-store`); cada generación queda en Auditoría (EXPORTAR sobre
`liquidaciones`). Clínica y logotipo, aseguradora (con su documento) y convenio, fecha de corte y período, estado, una fila por línea
(paciente, documento, número de asegurado, fecha, procedimiento, número de visación y monto), el total y el espacio de firma (por la
clínica y la recepción de la aseguradora). **Sin diagnóstico ni nada clínico.** No se genera de una anulada.

## Pantallas

Un solo módulo **Liquidaciones** (`admin.liquidaciones.*`, grupo Económico, una entrada en el menú) con tres pestañas:

- **Liquidaciones**: listado (filtro por aseguradora y estado, búsqueda por número), con los avisos de las pendientes de aprobación (para
  quien aprueba) y de las prestaciones sin firma. **"Armar liquidación"**, en una sola pantalla: aseguradora y corte → la vista previa de
  las líneas con el total y las que quedan afuera por falta de firma → "Confirmar y armar la liquidación" (lleva la cantidad y el total de
  la vista previa: si la lista cambió, pide revisarla). El detalle: datos, líneas (con "Quitar" en preparación y el enlace a la
  prestación), "Enviar a aprobación", "Aprobar la liquidación" y "Devolver" (quien no la armó), "Planilla (PDF)", los pagos con
  "Registrar el pago" (trae el saldo, hoy y Transferencia) y "Anular", y "Anular la liquidación".
- **Por cobrar por aseguradora**: por aseguradora, lo aprobado, lo pagado, el saldo y cuántas están pendientes de facturar, con sus
  liquidaciones con saldo.
- **Prestaciones sin firma**: las de aseguradora, libres, sin la firma recibida (las 100 más antiguas; la cantidad, siempre), con filtro
  por aseguradora y "Marcar la firma".

En la **prestación** se ve a qué liquidación pertenece (con el enlace y su estado) para quien ve las liquidaciones; los demás ven "Lo de
la aseguradora ya está en una liquidación".

## Permisos

| | LIQUIDACIONES | APROBACION_LIQUIDACIONES | PAGOS_ASEGURADORAS |
|---|---|---|---|
| Acciones | VER, CREAR (armar), EDITAR (quitar líneas, enviar), DESACTIVAR (anular), EXPORTAR (planilla) | VER, EDITAR (aprobar, devolver, anular la aprobada) | VER, CREAR (registrar), DESACTIVAR (anular) |
| Administrador | todo (solo) | todo (solo) | todo (solo) |
| Gerencia | VER, DESACTIVAR, EXPORTAR | VER, EDITAR | VER |
| Facturación y convenios | todo | nada | VER, CREAR, DESACTIVAR |
| Recepción, Caja, Médico, Enfermería, Compras y los demás | nada | nada | nada |

Recepción, Caja, Médico, Enfermería y Compras no ven liquidaciones ni montos de aseguradoras.

## Auditoría

CREAR de la liquidación y de cada línea ("Armado de la liquidación (N prestaciones, corte al dd/mm/aaaa)"); EDITAR de la liquidación al
enviarla ("Enviada a aprobación"), aprobarla ("Liquidación aprobada"), devolverla ("Liquidación devuelta. Nota: …") y al cambiar su
estado o su total por un pago, una anulación de pago o una línea quitada; EDITAR de la línea quitada ("Quitada de la liquidación N.º X.
Motivo: …"); CREAR del pago ("Pago de la aseguradora, liquidación N.º X"); ANULAR del pago ("Pago anulado. Motivo: …") y de la
liquidación ("Liquidación anulada. Motivo: …", con el EDITAR de cada línea liberada); EXPORTAR de la planilla. Los montos se ocultan
("Contenido económico", `Auditoria::TABLAS_ECONOMICAS`): `liquidaciones` y `lineas_liquidacion` a quien no tiene VER sobre
LIQUIDACIONES; `pagos_aseguradora`, sobre PAGOS_ASEGURADORAS. Al finalizar la consulta no se agrega ningún evento.

## Migración

`create_liquidaciones_aseguradoras`: tres tablas, los dos índices únicos parciales, los CHECK de PostgreSQL
(`liquidaciones_total_valido`, `_envio_completo`, `_aprobacion_completa`, `_devolucion_completa`, `_facturacion_valida`,
`_anulacion_completa`; `lineas_liquidacion_monto_positivo`, `_liberacion_completa`; `pagos_aseguradora_monto_positivo`,
`_anulacion_completa`), el estado PENDIENTE_APROBACION, los tres módulos y los permisos (`PerfilesPredefinidos::PERMISOS_LIQUIDACIONES`,
lo mismo que la matriz). Crea el estado si falta, no lee filas durante `--pretend`, no liquida ninguna prestación ni toca prestaciones,
cobros, cajas ni visaciones, y en una instalación nueva deja los módulos al seeder. El `down` solo vale sin liquidaciones y deja módulos,
permisos y estados.

## Tests

`tests/Feature/Economico/LiquidacionesTest.php`, `MigracionLiquidacionesTest.php` y `tests/Postgres/LiquidacionesTest.php` (los dos
únicos parciales, los CHECK y la migración con prestaciones existentes). Datos demo: `tests/Feature/DatosDemoTest.php`.
