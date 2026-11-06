# Facturación (fases 5a y 5b económicas)

La **clínica** como contribuyente emisor, el **timbrado**, la **factura individual CONTADO**, el **IVA** de cada procedimiento, el cobro
de Caja **contra factura** y la **factura CRÉDITO de la liquidación** a la aseguradora (ver "Factura de la liquidación"). Para la
facultad la emisión es **SIMULADA**: funciona como la factura electrónica, pero no se envía a ningún lado ni tiene validez fiscal. Sin
recibos, notas de crédito ni de débito, intereses, autofactura, retenciones, liquidación a profesionales ni al dueño del equipo, CDC ni QR
reales, ni el paquete mensual en Excel. Nada se borra. Ver también `docs/cobros-caja.md`, `docs/tarifas-prestaciones.md`,
`docs/convenios-seguros.md` y `docs/liquidaciones-aseguradoras.md`.

> **Sin conexión con la facturación electrónica real.** El sistema **no se conecta** al SIFEN ni a ningún proveedor de facturación
> electrónica: no envía documentos a la administración tributaria, no recibe CDC ni QR y ninguna factura tiene validez fiscal. Es
> **trabajo futuro**: un emisor real implementaría `EmisorDocumentos` sin tocar la numeración, el IVA, el cobro ni la anulación. Mientras
> tanto, el PDF de cada factura simulada lleva la **marca de agua** "SIMULACIÓN, SIN VALIDEZ FISCAL", para que una factura impresa no se
> confunda con una válida.

## La factura va primero

En Caja, **una sola acción** ("Facturar y cobrar", la misma pantalla de Cobrar): el cajero elige las prestaciones de la paciente (solo
su parte, como antes) y `Cobros\Cobrar`, en **una transacción**, emite la factura (`Facturacion\EmitirFactura`, con su número) y después
registra el cobro contra ella (`cobros.factura_id`), que la deja **COBRADA**. Si algo falla (sin timbrado, el emisor rechaza, otro cobro
ganó), no queda nada: ni la factura, ni el número usado, ni el cobro, ni el movimiento. Mismos medios de pago, cobro completo y reglas de
caja que antes. Hacen falta CREAR sobre COBROS y sobre FACTURAS.

Desde el hito **FACTURACION** (`hitos_sistema`, lo pone la migración) cada cobro nuevo lleva factura. Los **anteriores** no tienen, no se
facturan nunca ("Sin factura (anterior a la facturación)") y se siguen revirtiendo con las reglas de la fase 3b.

## La clínica (`clinicas`, `Clinica`)

Una sola fila por instalación (`clinicas_una_sola`). No es una persona jurídica: **UNIPERSONAL** (una persona física con RUC) o
**SOCIEDAD**. Nombre o razón social, nombre de fantasía, **RUC** con su dígito (`App\Rules\RucValido`, con `App\Support\Ruc`; único),
actividad económica, dirección fiscal, ciudad, teléfono, correo y logotipo (PNG o JPG; vacío: el de `config/clinexa.php`). Las
**sucursales** apuntan a ella (`sucursales.clinica_id`); la dirección y el teléfono de cada sucursal son los del establecimiento. Al
cargarla, las sucursales sin clínica pasan a ser suyas; una sucursal nueva nace suya. Sin la clínica o sin su RUC no se emite ("Faltan los
datos fiscales de la clínica…"). Pantalla **Datos de la clínica** (`admin.clinica.edit`), sin entrada en el menú: desde Sucursales y desde
Facturas → Timbrados. La editan Gerencia y el Administrador; no se borra.

## Timbrado (`timbrados`, `Timbrado`)

Simulado: clínica, sucursal, número (8 dígitos), vigencia desde y hasta, establecimiento y punto de expedición (3 dígitos), número
inicial, final y **actual** (el último usado; inicial − 1 al cargarlo) y estado **VIGENTE** / **HISTORICO** (con quién y cuándo). Uno
vigente por sucursal, establecimiento y punto (`timbrados_uno_vigente`, único parcial `WHERE historico_en IS NULL`, SQLite y PostgreSQL).

- **Un solo punto**: se emite con el timbrado vigente de la **sucursal principal** (la activa más antigua, la de las recetas y los PDF).
  Si esa sucursal tiene más de un punto vigente, no se emite ("deje uno solo vigente"). Los cajeros comparten la numeración.
- **Numeración** `001-001-0000001` (establecimiento-punto-secuencia): la asigna el sistema, con el timbrado **bloqueado**, de a uno, sin
  saltos ni repetidos (`facturas_numero_unico`: timbrado y número). Un número **anulado no se reutiliza**.
- Sin timbrado vigente, **vencido** (fuera de su vigencia; o que todavía no rige) o **agotado** (llegó al número final), no se emite, con
  el aviso (también antes de cobrar, en la pantalla).
- `Facturacion\GestionTimbrados`: **cargar** (si ya hay uno vigente para ese punto, pasa a histórico en la misma transacción) y **pasar a
  histórico**. No se edita ni se borra.

## Factura (`facturas`, `detalles_factura`)

- Timbrado (con su número y vigencia copiados), número, fecha y hora, condición (**CONTADO**; **CREDITO** en la de la liquidación), el **snapshot del emisor** (razón social,
  nombre de fantasía, RUC, actividad, dirección fiscal, teléfono de la sucursal) y del **cliente** (la paciente: nombre en orden natural y
  `Persona::documentoPrincipal()`), moneda, totales por tasa, estado, quién la emitió, en qué caja, `simulada` y el emisor
  (`emisor_documentos`). Un cambio posterior de la clínica, la sucursal o la paciente no altera las emitidas. **Sin factura "sin nombre"**:
  sin documento, no se emite.
- Una **línea por prestación y parte** (`parte`: **PACIENTE** en la contado, **ASEGURADORA** en la de la liquidación): la descripción del
  procedimiento, cantidad 1, precio con IVA incluido (en la contado, lo que paga la paciente: particular o copago), la tasa **copiada** del
  procedimiento, el gravado y el IVA. Lo de la **aseguradora nunca entra en la contado**: va en la factura de su liquidación.
- Estados: **EMITIDO** ("Emitida", pendiente de cobro; ya existía), **COBRADO** ("Cobrada", nuevo) y **ANULADO** ("Anulada"). La contado
  pasa de EMITIDA a COBRADA en la misma operación. El estado de cobro sale del cobro registrado contra ella.
- **Cada parte de una prestación se factura una sola vez**: `detalles_factura_una_por_parte`, único parcial `(prestacion_id, parte) WHERE
  liberada_en IS NULL` (SQLite y PostgreSQL; en PostgreSQL, `detalles_factura_parte_valida`). La misma prestación puede estar en dos
  facturas de partes distintas (la de la paciente, individual; la de la aseguradora, agrupada en la fase 5b), nunca dos veces en la misma
  parte. Al anular la factura sus líneas se **liberan**.
- Una anulada puede tener otra que la **reemplaza** (`reemplaza_a_id`, a lo sumo una): "Cobrar de nuevo con una factura que reemplace a
  esta", desde su detalle.
- Los modelos admiten solo EMITIDO → COBRADO, COBRADO → EMITIDO (solo la de crédito y solo el estado: un pago anulado) y → ANULADO (con
  sus datos), y la liberación de las líneas; rechazan el resto y el borrado.

## IVA (`Facturacion\Iva`)

Precios con IVA incluido. La tasa es un atributo del procedimiento (`procedimientos.tasa_iva`: **10** por defecto, **5** o **0** =
exenta), editable por Gerencia y el Administrador (Facturas → Tasas de IVA), y se **copia** a la línea. IVA 10 % = monto / 11 e IVA 5 % =
monto / 21, redondeado al guaraní entero (la mitad, hacia arriba); gravado = monto − IVA; exenta sin IVA. Totales: gravado 10, IVA 10,
gravado 5, IVA 5, exento y total, la suma exacta de las líneas.

## Emisión aislada (`Facturacion\EmisorDocumentos`)

Una interfaz (`codigo`, `simulado`, `emitir`, `anular`) que corre dentro de la transacción. Hoy, `EmisorSimulado`; mañana, un proveedor o
el SIFEN, sin tocar la numeración, el IVA, el cobro ni la anulación. `config('clinexa.facturacion.simulada')` (`CLINEXA_FACTURACION_SIMULADA`,
activo) la elige; cada factura guarda si fue simulada (`facturas.simulada`). Apagada, todavía no hay emisor real (`EmisorNoConfigurado`):
no se emite ni se anula, con el aviso. Sin CDC ni QR.

**Marca de agua.** El PDF de una factura simulada (las individuales y las de liquidación, también las emitidas antes de este cambio,
porque el PDF se arma al vuelo) lleva la marca de agua **"SIMULACIÓN, SIN VALIDEZ FISCAL"**: en diagonal, gris claro y transparente,
detrás del texto y en cada página, para que se vea también impresa en papel (y en blanco y negro) sin tapar ni dificultar la lectura de
los datos. Las **pantallas** (Facturas, cobro, detalle, timbrados, imprimir) ya **no** llevan el recuadro rojo de aviso.

**Marca aparte del modo:** `config('clinexa.facturacion.mostrar_marca')` (`CLINEXA_FACTURACION_MOSTRAR_MARCA`, por defecto activo) controla
SOLO si el PDF lleva la marca de agua; no cambia el modo de emisión. Con `mostrar_marca=false` y `simulada=true` se emite y se anula con
normalidad, sin ninguna marca.

> **Advertencia:** sin la marca, una factura simulada impresa puede confundirse con una válida. No apagarla en un uso real.

## Factura de la liquidación (`Facturacion\FacturarLiquidacion`)

La factura **agrupada y posterior** de lo que paga la aseguradora: una factura **CRÉDITO** por liquidación (`docs/liquidaciones-aseguradoras.md`).

- **Quién y cuándo.** Facturación y convenios (o el Administrador), con CREAR sobre **FACTURAS_LIQUIDACION**, desde el detalle de la
  liquidación ("Emitir la factura", con confirmación). Solo de una liquidación **aprobada** (APROBADO, PARCIAL o PAGADO) que sigue
  **pendiente de facturar**. En preparación, pendiente de aprobación o anulada: "Solo se factura una liquidación aprobada (con o sin pagos).".
- **A nombre de la aseguradora** (el responsable de pago): el nombre (razón social) y el **RUC** de su persona, copiados. Sin RUC no se
  emite: "La aseguradora X no tiene RUC: cárguelo en los datos de su persona (Personas) antes de emitir la factura." (también como aviso en
  la pantalla, antes de intentar). Sin paciente ni caja (`paciente_id` y `caja_id` vacíos; `liquidacion_id`).
- **Detalle:** una línea por prestación de la liquidación (parte **ASEGURADORA**), sin agrupar: "Consulta (06/10/2026, visación N.º V-1)",
  cantidad 1, lo que paga la aseguradora (el monto de su línea de la liquidación) y la tasa de IVA del procedimiento. El total es el de la
  liquidación (si no coincidiera, no se emite). Sin nombres de pacientes ni nada clínico (están en la planilla).
- **El copago no cambia:** la parte de la paciente se factura en Caja (contado). El índice por parte impide facturar dos veces la misma.
- **Una por liquidación** (no se agrupan varias ni se divide una): `facturas_una_por_liquidacion`, único parcial `(liquidacion_id) WHERE
  anulada_en IS NULL` (SQLite y PostgreSQL). Emitida, la liquidación queda **FACTURADA** (`liquidaciones.facturacion`).
- **Numeración:** el mismo timbrado y la misma numeración que la contado (`EmitirFactura::registrar`), con el timbrado bloqueado: dos
  emisiones simultáneas no repiten ni saltean número (la segunda espera; `tests/Postgres/FacturaNumeracionConcurrenciaTest.php`).
- **Cobro:** sigue a la liquidación (`sincronizarCobro`, desde `Liquidaciones\PagosAseguradora`, en la misma transacción): **COBRADA**
  cuando la liquidación queda PAGADA; si se anula un pago y deja de estarlo, vuelve a **EMITIDA**. Pagada en parte sigue EMITIDA y se
  muestra "Pagada en parte (saldo ₲ X)". Emitida sobre una liquidación ya pagada, nace COBRADA.
- **Anulación:** solo con la **solicitud** (DESACTIVAR sobre FACTURAS_LIQUIDACION: Facturación y convenios) que aprueba Gerencia (o el
  Administrador; nadie la propia), sin plazo directo y **sin tocar ninguna caja**. Con **pagos vigentes** de la aseguradora no se pide ni
  se aprueba: "primero se anulan los pagos". Al aprobarse: la factura ANULADA (su número no se reutiliza), sus líneas liberadas y la
  liquidación de vuelta a **pendiente de facturar** (se puede emitir otra, con otro número). El reparto de las prestaciones no cambia.
  Mientras la factura esté vigente, **la liquidación no se anula** ("primero se anula la factura").
- **Trazabilidad:** la factura muestra "Liquidación N.º X" (enlace) y cada línea enlaza su prestación; la liquidación muestra su factura
  vigente y las anuladas; la prestación, sus dos facturas (la de la paciente y la de la aseguradora; esta, solo para quien ve las
  facturas). El PDF dice CRÉDITO y "Liquidación N.º X (prestaciones del … al …)".

## Bloqueo de la prestación facturada (`Facturacion\BloqueoFactura`)

Una prestación en una factura no anulada, **en cualquiera de sus partes**, no cambia de **visación** ni de **monto**, ni se **anula**: "Aplicar visación" y la anulación de
la prestación se rechazan con "Esta prestación está facturada (factura 001-001-…): … Primero se anula la factura.". La **firma recibida**
sigue permitida: no cambia el monto y la necesita la liquidación de la parte de la aseguradora (en una prestación con copago).

## Anulación (`Facturacion\AnularFactura`)

El reloj es el de la **emisión**: `config('clinexa.facturacion.plazo_anulacion_minutos')` (`CLINEXA_PLAZO_ANULACION_FACTURA`, 10).

- **Directa** ("Anular la factura"): dentro del plazo, el cajero anula una factura **suya**, de **su caja abierta** (la de hoy), sin
  documentos vinculados, con un motivo del catálogo (y un detalle; obligatorio en "Otro (detallar)"). En una sola acción se **revierte su
  cobro** (`RevertirCobro::porAnulacionDeFactura`: un egreso por línea en su caja, el efectivo tiene que alcanzar, como en la fase 3b) y se
  **anula** la factura; su número se conserva.
- **Con aprobación** ("Solicitar la anulación"): pasado el plazo, con la caja cerrada o si es de otro cajero.
  `solicitudes_anulacion_factura` (`SolicitudAnulacionFactura`): factura, solicitante, motivo, detalle y estado **PENDIENTE**, **APROBADO**
  o **RECHAZADO** (con quién, cuándo y la nota). Gerencia (o el Administrador) la aprueba (nota opcional) o la rechaza (nota de 5 a 300).
  **Nadie revisa la propia** (también en PostgreSQL). Una sola pendiente por factura (`solicitudes_anulacion_una_pendiente`, único parcial
  `(factura_id) WHERE revisado_en IS NULL`, SQLite y PostgreSQL). **Al aprobarse** se revierte el cobro (desde la caja abierta de hoy del
  solicitante; si no la tiene, se aprueba cuando la abra) y se anula la factura (`anulada_por`: quien aprueba).
- Un cobro **con factura** ya no se revierte por separado ("Este cobro tiene factura: se revierte anulando la factura"): ni directo ni con
  la solicitud de la fase 3b.

**Motivos** (`motivos_anulacion_factura`, Facturas → Motivos de anulación): catálogo editable, nada se borra (se desactiva), con tres de
referencia por su código (`MotivosAnulacionFacturaSeeder`): "Error en los datos del cliente", "Error en el procedimiento o el monto" y
"Otro (detallar)" (pide el detalle). Ningún otro dato en los seeders.

## PDF (`Facturacion\PdfFactura`)

Con dompdf, A4, **al vuelo** (no se guarda; `Cache-Control: no-store`), cada generación en Auditoría (EXPORTAR sobre `facturas`, sin
montos). Clínica y logotipo, RUC, timbrado y su vigencia, número, fecha y hora, cliente, líneas (exentas, 5 % y 10 %), subtotales, totales
por tasa, total del IVA, la condición (CONTADO o CRÉDITO; la de crédito, con "Liquidación N.º X (prestaciones del … al …)") y la marca de
agua de la simulación. Se genera de nuevo mientras la factura exista: desde la segunda,
"FACTURA (COPIA)"; la anulada sale marcada "FACTURA ANULADA". Sin nada clínico.

## Pantallas

Un solo módulo **Facturas** (`admin.facturas.*`, grupo Económico, una entrada en el menú) con pestañas: **Facturas** (listado con
búsqueda por número, cliente o documento, filtro de fechas y estado; detalle con líneas, totales, su cobro, el PDF y la anulación o la
solicitud; en la de crédito, su liquidación), **Solicitudes de anulación (N)** (para quien las revisa: "Aprobar" en la fila y "Ver la factura" para rechazar),
**Timbrados** (con el aviso si no se puede emitir, "Cargar timbrado" y "Pasar a histórico"), **Tasas de IVA** y **Motivos de anulación**.
Cada pestaña, para quien tiene su permiso. **Cobrar** sigue siendo una sola pantalla: avisa antes si no se puede emitir y el botón dice
"Facturar y cobrar". En el detalle del **cobro** y de la **prestación** se ve su factura (con el enlace para quien ve las facturas).

## Permisos

| | FACTURAS | ANULACIONES_FACTURA | TIMBRADOS | DATOS_FISCALES | MOTIVOS_ANULACION |
|---|---|---|---|---|---|
| Acciones | VER, CREAR (emitir al cobrar), DESACTIVAR (anular o solicitar), EXPORTAR (PDF) | VER, EDITAR (aprobar o rechazar) | VER, CREAR (cargar), EDITAR (pasar a histórico) | VER, EDITAR (la clínica y las tasas de IVA) | VER, CREAR, EDITAR, DESACTIVAR |
| Administrador | todo (solo) | todo (solo) | todo (solo) | todo (solo) | todo (solo) |
| Caja | VER, CREAR, DESACTIVAR, EXPORTAR | nada | nada | nada | nada |
| Gerencia | VER, EXPORTAR | VER, EDITAR | VER, CREAR, EDITAR | VER, EDITAR | todo |
| Facturación y convenios | VER, EXPORTAR | nada | nada | nada | nada |
| Recepción, Médico, Enfermería, Compras y los demás | nada | nada | nada | nada | nada |

**FACTURAS_LIQUIDACION** (la factura de la liquidación): VER, CREAR (emitir), DESACTIVAR (solicitar la anulación). Solo Facturación y
convenios (las tres) y el Administrador. Caja, Gerencia y los demás, nada: Gerencia aprueba la anulación con ANULACIONES_FACTURA y ve la
factura y la liquidación con VER sobre FACTURAS y LIQUIDACIONES, que ya tenía. La migración `permisos_factura_liquidacion` solo suma esos
permisos (repetible).

Caja saca el PDF para entregárselo a la paciente. Recepción ve en la prestación el número de la factura de la paciente (tiene VER sobre
PRESTACIONES), sin el enlace; la de la aseguradora, no.

## Auditoría

CREAR de la factura y de cada línea ("Factura 001-001-0000001"), EDITAR de la factura al cobrarse ("Factura … cobrada"); ANULAR de la
factura ("Factura … anulada. Motivo: …"), EDITAR de cada línea liberada ("Liberada por la anulación de la factura …") y REVERTIR del cobro
("Reversión del cobro. Motivo: Anulación de la factura …"); CREAR de la solicitud ("Solicitud de anulación de la factura …") y EDITAR al
revisarla ("Anulación aprobada" o "Anulación rechazada", con la nota); EXPORTAR del PDF ("PDF de la factura …", "(copia)"); CREAR del
timbrado ("Alta del timbrado N.º X"), EDITAR del que pasa a histórico ("Reemplazado por el timbrado N.º X" o "Timbrado pasado a
histórico"); CREAR y EDITAR de la clínica, de los motivos y de la tasa del procedimiento ("Cambio de la tasa de IVA"). La numeración del
timbrado avanza sin evento propio (queda en cada factura). Los montos se ocultan ("Contenido económico", `Auditoria::TABLAS_ECONOMICAS`):
`facturas`, `detalles_factura` y `solicitudes_anulacion_factura` a quien no tiene VER sobre FACTURAS. Al finalizar la consulta no se
agrega ningún evento; al cobrar, los de la factura (solo desde el hito).

La de la liquidación: CREAR de la factura y de cada línea; EDITAR de la liquidación ("Liquidación facturada: factura …"); EDITAR de la
factura cuando la liquidación queda pagada ("Factura … cobrada") y cuando deja de estarlo ("Factura … vuelve a pendiente de cobro"); la
solicitud y su revisión como las demás; ANULAR de la factura y EDITAR de la liquidación ("Vuelve a pendiente de facturar: factura … anulada").

## Migración

`create_facturacion_individual`: cinco tablas nuevas y el catálogo de motivos, `sucursales.clinica_id`, `procedimientos.tasa_iva` (10 en
los existentes), `cobros.factura_id` (vacío en los existentes), los tres únicos parciales, los CHECK de PostgreSQL (`clinicas_instalacion_unica`,
`_tipo_valido`, `_ruc_formato`; `timbrados_numeracion_valida`, `_vigencia_valida`, `_historico_completo`; `facturas_numeracion_valida`,
`_condicion_valida`, `_totales_validos`, `_anulacion_completa`, `_no_se_reemplaza_a_si_misma`; `detalles_factura_montos_validos` (el IVA de
la línea al guaraní) y `_parte_valida`; `procedimientos_tasa_iva_valida`; `solicitudes_anulacion_revision_completa`), el hito FACTURACION, el estado
**COBRADO** (EMITIDO, ANULADO y los demás ya existían), los cinco módulos y los permisos (`PerfilesPredefinidos::PERMISOS_FACTURACION`, lo
mismo que la matriz). Crea los estados que falten, no lee filas durante `--pretend`, **no factura** ni cambia cobros, cajas ni prestaciones,
carga los tres motivos si faltan y en una instalación nueva deja los módulos al seeder. El `down` solo vale sin facturas.

`factura_de_liquidacion` (aditiva): `facturas.paciente_id` y `facturas.caja_id` dejan de ser obligatorios (en PostgreSQL, `DROP NOT NULL`:
las filas no se tocan), `facturas.liquidacion_id` (vacío en las existentes), `facturas_una_por_liquidacion`, y en PostgreSQL
`facturas_condicion_valida` (CONTADO o CREDITO), `facturas_cliente_segun_condicion` (contado con paciente y caja; crédito con la
liquidación, sin paciente, caja ni reemplazo; las existentes lo cumplen) y `liquidaciones_facturacion_valida` (PENDIENTE o FACTURADA). El
módulo FACTURAS_LIQUIDACION en una instalación existente. No cambia facturas, cobros, cajas ni liquidaciones ya cargadas. El `down` solo
vale sin facturas de liquidación. `permisos_factura_liquidacion`: solo suma los permisos (repetible).

## Datos demo

`FacturacionDemo` (en la víspera, por Gerencia): una clínica de **prueba** solo si no hay ninguna (RUC `80900010-5`, calculado con `Ruc`, que
no es de ninguna persona demo) y un timbrado **simulado** (`19990001`, 001-001) vigente desde hace dos meses si ese punto no tiene uno. En
una base **nueva**, el hito FACTURACION se adelanta con los demás y los cobros de los días simulados salen con su factura; las reversiones
del demo pasan a ser anulaciones de factura (una solicitud aprobada y otra rechazada; hoy, una anulada en el plazo por la Caja 2 y una
solicitud pendiente), cada anulada con la que la reemplaza. En una base **ya cargada**, los cobros existentes no se facturan (los días
simulados no se repiten): solo se agregan la clínica y el timbrado.

## Tests

`tests/Feature/Economico/FacturacionTest.php`, `MigracionFacturacionTest.php` y `tests/Postgres/FacturacionTest.php` (los tres únicos
parciales, los CHECK de numeración y de totales, y la migración con cobros existentes). La de la liquidación:
`tests/Feature/Economico/FacturaLiquidacionTest.php`, `MigracionFacturaLiquidacionTest.php`, `tests/Postgres/FacturaLiquidacionTest.php`
(la migración con facturas, cobros y liquidaciones existentes, los CHECK y el índice) y `tests/Postgres/FacturaNumeracionConcurrenciaTest.php`
(dos conexiones). Datos demo: `tests/Feature/DatosDemoTest.php`.
