# Liquidación a beneficiarios del reparto (objetivo 7)

Lo que le corresponde a cada **profesional tratante** y a cada **dueño de equipo** según el reparto de cada prestación
(`docs/reparto-economico.md`): armar la liquidación por beneficiario y fecha de corte, revisarla, aprobarla, pagarla (total o en partes),
anular pagos, anularla, imprimir la planilla y consultarlo todo, con auditoría y trazabilidad hasta la prestación. Reutiliza el diseño de
la liquidación a aseguradoras (`docs/liquidaciones-aseguradoras.md`) en tablas propias: no toca esa liquidación ni lo ya cargado. Nada se
borra.

**Fuera de alcance** (decidido): impuestos y retenciones (la clínica no es agente de retención), deducciones manuales, anticipos, la
factura del beneficiario, sueldos o nómina, cuentas por pagar (objetivo 12) y la sucursal (la prestación no tiene sucursal propia).

## Beneficiarios

- Solo los del reparto: **profesional tratante** (línea PROFESIONAL), **dueño del equipo** si el procedimiento lo usó (línea EQUIPO, un
  proveedor) y la **clínica** (el resto). No hay otros beneficiarios.
- **La clínica no se liquida** (no se le paga): el servicio de consulta y los reportes muestran por período su parte devengada y
  percibida. **Esa parte no es ganancia**: la ganancia necesitaría gastos y sueldos, y es del objetivo 14 (no se calcula aquí).
- **Funcionario con sueldo**: un profesional sin porcentaje lleva un **acuerdo general de 0 %**, cargado por la pantalla de acuerdos de
  siempre (Económico → Acuerdos de reparto → Nuevo acuerdo, porcentaje 0). Su línea del reparto queda en 0, la clínica recibe
  100 − equipo y el dueño del equipo lo suyo; la liquidación **ignora las líneas en 0**. Sin acuerdo, el reparto sigue **pendiente**
  ("Falta el acuerdo del profesional para el dd/mm/aaaa."): el aviso se conserva para los olvidos reales y el cálculo no cambió.
- **Profesional que también es dueño de equipo**: dos liquidaciones separadas (una por rol). La del dueño junta todas sus máquinas.

## Qué entra (`LiquidacionesBeneficiarios\Elegibilidad`)

Una línea del reparto PROFESIONAL o EQUIPO, con monto mayor que cero, de un reparto **REPARTIDO** (no anulado) de una prestación no
anulada, es **percibida** (liquidable) cuando sus dos partes están cumplidas:

- **la parte de la paciente** (si es mayor que cero): con un **cobro vigente**;
- **la parte de la aseguradora** (si es mayor que cero): con su liquidación a la aseguradora **PAGADA** (primero se cobra, después se paga a
  las partes). **Los pagos parciales de la aseguradora no se asignan por prestación**: mientras su liquidación esté aprobada o pagada en
  parte, ninguna de sus prestaciones entra.
- Con **copago**, la línea entra **entera** cuando las dos partes están cumplidas (el reparto es siempre sobre el total).

Una prestación pendiente de reparto, sin cobrar o con la aseguradora sin pagar no entra: queda en "Pendientes de liquidar" con el motivo.

## Armar (`ArmarLiquidacionBeneficiario`)

Facturación y convenios (o Gerencia) elige el **beneficiario** y la **fecha de corte** (hoy o antes): vista previa y confirmar (con la
cantidad y el total de la vista previa: si la lista cambió, pide revisarla). Entra todo, en una sola liquidación:

- **ORDINARIA**: cada línea percibida y libre con fecha de servicio hasta el corte (también lo pendiente anterior);
- **AJUSTE en contra**: por cada ordinaria suya **ya aprobada** que dejó de corresponder (ver "Cambios después de liquidar"), sin importar el
  corte;
- **SALDO_ANTERIOR**: el total negativo de una liquidación suya aprobada que todavía no se trasladó.

Hace falta al menos una ordinaria o un ajuste. **Una sola abierta** (en preparación o pendiente de aprobación) por beneficiario.

**Snapshot** de cada línea: N.º de prestación, fecha de servicio, paciente (**solo en la del profesional**), procedimiento, equipo, monto
base, porcentaje y monto. Sin nada clínico.

## Estados y flujo (`FlujoLiquidacionBeneficiario`)

**EN_PREPARACION** → **PENDIENTE_APROBACION** → **APROBADO** → **PARCIAL** → **PAGADO**, o **ANULADO**; la devuelta vuelve a EN_PREPARACION.
El modelo rechaza cualquier otro cambio y el borrado.

- **Quitar una línea** (en preparación, motivo de 5 a 300 caracteres): es la forma de **postergar**; queda libre para la siguiente.
- **Enviar a aprobación**: con al menos una línea y **sin líneas marcadas**.
- **Aprobar o devolver** (con nota): Gerencia; **nadie aprueba ni devuelve la que armó** (la de Gerencia, el Administrador; también en
  PostgreSQL). Tampoco se aprueba con líneas marcadas: se devuelve para quitarlas.
- **Total**: la suma exacta de las líneas no quitadas. **Negativo** (los ajustes superan lo nuevo): se aprueba igual, **no se paga** y su
  saldo pasa a la siguiente como SALDO_ANTERIOR ("Aprobada (saldo en contra)"). En cero: "Aprobada (sin saldo a pagar)".
- **Anular** (motivo): sin pagos vigentes y sin que otra dependa de ella (un ajuste de sus líneas, una línea que volvió a entrar después de
  un ajuste suyo, o su saldo ya trasladado: "anule aquella primero"). Sus líneas quedan ANULADA y libres. La no aprobada la anula
  Facturación o Gerencia; la aprobada, solo Gerencia (o el Administrador).

## Cambios después de liquidar

Cobro revertido, factura anulada (revierte su cobro), visación aplicada (anula el reparto y calcula otro) o prestación anulada. Nada se
bloquea hacia la paciente:

- **En una liquidación abierta**: la línea queda **marcada** ("dejó de corresponder", con el motivo) y hay que quitarla antes de enviar o
  aprobar. Un ajuste cuya prestación volvió a cobrarse también queda marcado.
- **En una aprobada o pagada**: en la siguiente liquidación del beneficiario entra un **ajuste en contra** automático, visible, con el
  motivo y la liquidación original, que pasa por la aprobación normal.
- **Si después se vuelve a cobrar**, la línea entra de nuevo (`vez` 2, 3…), solo cuando el ajuste está aprobado. Con la visación aplicada,
  el reparto nuevo trae líneas nuevas: entran como cualquier otra.

## Pagos (`PagosBeneficiario`)

Sobre una liquidación **aprobada con saldo**: monto (no supera el saldo), fecha (no futura), medio activo del catálogo y el comprobante
cuando el medio lo pide (`MedioPago::exigeComprobante`). **Siempre aparte de la caja de cobros.** Saldo = total − pagos vigentes; con pagos,
PARCIAL; sin saldo, PAGADO. Los registra **Compras y tesorería**.

- **Sin caja**: queda registrado. Lo anula, con motivo, quien lo registró o Gerencia.
- **En efectivo desde una caja**: se elige la **caja abierta de hoy** de quien paga (Tesorería abre la suya para los pagos grandes; puede
  abrir caja y registrar salidas, sin COBROS ni CIERRES_CAJA: no cobra, no ve cajas ajenas ni recibe arqueos, y su caja pasa por el
  cierre y la recepción de Gerencia como las demás). El pago sale como una **salida de esa caja** (`salidas_caja`, con la categoría de gasto
  elegida, el beneficiario y el comprobante), vinculada al pago (`pagos_beneficiario.salida_caja_id`); el efectivo tiene que alcanzar.
  **Se anula con las reglas de la salida** (`docs/cobros-caja.md`): quien lo registró, dentro de los 10 minutos; después, por la solicitud
  que aprueba Gerencia (en Cobros → Reversiones pendientes); **con la caja cerrada ya no se anula** ("La caja de la que salió este pago ya
  no está abierta…"). Anular la salida desde la caja también anula el pago (`AnularSalida` llama a `PagosBeneficiario::alAnularSalida`).
- `pagos_beneficiario.cuenta_por_pagar_id`: **vacío**, previsto para el objetivo 12 (cuentas por pagar). Sin clave foránea: esa tabla no
  existe todavía. Los pagos al dueño del equipo son independientes de las compras.

## Pantallas (`admin.liquidaciones-beneficiarios.*`, `/admin/beneficiarios/liquidaciones`, grupo Económico)

- **Liquidaciones**: listado (grilla con N.º, tipo, beneficiario, corte, estado, total, saldo y quién la armó; filtro por beneficiario),
  aviso de las pendientes de aprobación y **"Armar liquidación"** en una sola pantalla: beneficiario y corte → vista previa (ordinarias,
  ajustes y saldo anterior, con el total; aviso si da negativo) → "Confirmar y armar" (lleva cantidad y total).
- **Detalle**: datos y flujo, líneas (marcadas en ámbar las que dejaron de corresponder, "Quitar" en preparación, enlace a cada
  prestación y a la liquidación del saldo anterior), "Enviar a aprobación", "Aprobar" y "Devolver" (no a quien la armó), "Planilla (PDF)",
  los pagos (con su caja y categoría si salieron de una, la solicitud pendiente) con "Registrar el pago" (trae el saldo, hoy y
  Transferencia; la caja y la categoría solo si quien paga tiene su caja abierta de hoy) y "Anular" o "Solicitar la anulación", y
  "Anular la liquidación" (o por qué no se puede).
- **Por pagar por beneficiario**: lo aprobado, lo pagado, el saldo a pagar y el saldo en contra, con sus liquidaciones con saldo.
- **Pendientes de liquidar**: las listas para liquidar por beneficiario (con "Armar"), las que todavía no (con el motivo: sin cobrar, la
  aseguradora sin liquidar o sin pagar del todo) y las pendientes de reparto (falta un acuerdo).
- **Resumen por período**: ver "Servicio de consulta".
- **Mis liquidaciones** (`admin.mis-liquidaciones.*`, menú Económico): el profesional, con su usuario vinculado a su persona, ve solo las
  suyas **aprobadas**, de solo lectura, con sus líneas, sus pagos y la planilla. Las demás (en preparación, de otros, anuladas): 404.
- **Prestación**: la sección "Liquidaciones a beneficiarios" con cada línea suya (también ajustes, quitadas y anuladas), su liquidación
  (enlace), el beneficiario y el estado, para quien ve estas liquidaciones. En la **caja**, el pago en efectivo es una salida más.

## Planilla (`PlanillaLiquidacionBeneficiario`)

PDF con dompdf, A4 apaisada, al vuelo (`no-store`); cada generación es un EXPORTAR en Auditoría. Clínica y logotipo, beneficiario con su
documento, corte, período, estado, una fila por línea (concepto, N.º de prestación, fecha, procedimiento, base, porcentaje, monto), el
total, los pagos con lo pagado y el saldo, el saldo en contra y el espacio de firma ("Recibí conforme"). **La del profesional trae la
paciente; la del dueño del equipo, solo el N.º de prestación y el equipo.** Nada clínico. No se genera de una anulada.

## Servicio de consulta para el objetivo 14 (`ResumenBeneficiarios::periodo`)

Por beneficiario (la **clínica**, cada profesional y cada dueño de equipo) y período de fecha de servicio, en guaraníes enteros:
**devengado** (el reparto vigente de prestaciones no anuladas), **percibido** (con las dos partes cumplidas), **liquidable** (percibido y
sin liquidar), **liquidado** (en liquidaciones aprobadas, con los ajustes), **pagado** (pagos con fecha en el período) y **saldo** (lo que
falta pagar hoy de sus liquidaciones aprobadas con corte hasta el fin del período). La clínica muestra solo devengado y percibido. Los
informes 14.5 y 14.6 deben usar este servicio. **Pendiente para el objetivo 14**: la ganancia de la clínica (necesita gastos y sueldos) y la
**sucursal** (la prestación no la tiene propia: solo por turno → consultorio, y las urgencias y atenciones sin turno no tienen consultorio).

## Permisos

| | LIQUIDACIONES_BENEFICIARIOS | APROBACION_LIQ_BENEFICIARIOS | PAGOS_BENEFICIARIOS | MIS_LIQUIDACIONES |
|---|---|---|---|---|
| Acciones | VER, CREAR (armar), EDITAR (quitar, enviar), DESACTIVAR (anular la no aprobada), EXPORTAR (planilla) | VER, EDITAR (aprobar, devolver, anular la aprobada) | VER, CREAR (registrar), DESACTIVAR (anular) | VER, EXPORTAR (solo las propias) |
| Administrador | todo | todo | todo | todo |
| Gerencia | todo | VER, EDITAR | VER, DESACTIVAR | — |
| Facturación y convenios | todo | — | VER | — |
| Compras y tesorería | VER, EXPORTAR | — | todo | — |
| Médico | — | — | — | VER, EXPORTAR |
| Recepción, Caja, Enfermería, Supervisión médica, Auditor | — | — | — | — |

Además, Compras y tesorería suma CAJAS (VER, CREAR, EDITAR) y SALIDAS_CAJA (VER, CREAR, DESACTIVAR) por la migración
`2026_11_11_100002_tesoreria_abre_caja` (solo suma, repetible). EDITAR sobre CAJAS solo cierra la propia (`CajaController` y
`CerrarCaja` lo exigen); ver, recibir o devolver cajas ajenas es de CIERRES_CAJA.

## Auditoría

CREAR de la liquidación y de cada línea ("Armado de la liquidación a beneficiario (N líneas, corte al dd/mm/aaaa)"); EDITAR al enviarla,
aprobarla, devolverla (con la nota), al quitar una línea (con el motivo) y al cambiar su estado por un pago; CREAR del pago (y de su
salida de caja); ANULAR del pago y de la liquidación con el motivo (y EDITAR de cada línea liberada); EXPORTAR de la planilla. Los montos
se ocultan ("Contenido económico", `Auditoria::TABLAS_ECONOMICAS`): `liquidaciones_beneficiario` y `lineas_liquidacion_beneficiario` a
quien no tiene VER sobre LIQUIDACIONES_BENEFICIARIOS; `pagos_beneficiario`, sobre PAGOS_BENEFICIARIOS. El Auditor ve los eventos sin los
montos.

## Migración

`2026_11_11_100001_create_liquidacion_beneficiarios`: tres tablas, los índices únicos parciales (SQLite y PostgreSQL)
`liquidaciones_beneficiario_una_abierta`, `lineas_liq_beneficiario_una_ordinaria` (línea del reparto y `vez`), `_un_ajuste` y `_un_saldo`;
los CHECK de PostgreSQL (`liquidaciones_beneficiario_clave_valida`, `_total_entero`, `_envio_completo`, `_aprobacion_completa`,
`_devolucion_completa`, `_anulacion_completa`; `lineas_liq_beneficiario_tipo_valido`, `_monto_entero`, `_liberacion_completa`;
`pagos_beneficiario_monto_positivo`, `_caja_completa`, `_anulacion_completa`), los cuatro módulos con sus estados y los permisos
(`PerfilesPredefinidos::PERMISOS_LIQUIDACION_BENEFICIARIOS`, lo mismo que la matriz, solo suma). No lee filas durante `--pretend`, no toca
prestaciones, repartos, cobros, cajas, facturas ni liquidaciones a aseguradoras, y en una instalación nueva deja los módulos al seeder. El
`down` se niega si hay liquidaciones a beneficiarios.

## Tests

`tests/Feature/Economico/LiquidacionBeneficiariosTest.php` (reglas, flujo, ajustes, pagos y caja), `LiquidacionBeneficiariosPantallasTest.php`
(pantallas, permisos y auditoría), `LiquidacionBeneficiariosConsultaTest.php` (planilla, Mis liquidaciones y el servicio de consulta),
`MigracionLiquidacionBeneficiariosTest.php` y `tests/Postgres/LiquidacionBeneficiariosTest.php` (únicos parciales, CHECK y la migración con
datos cargados). Datos demo: `BeneficiariosDemo` (`docs/datos-demo.md`) y `tests/Feature/DatosDemoTest.php`.

## Qué probar en el navegador

Base descartable `clinexa_liquidacion` (datos demo; usuarios `@prueba.test`):

1. **Facturación** (`facturacion@`): Liquidaciones a beneficiarios → "Pendientes de liquidar" (motivos) → "Armar" para la Dra. Benítez como
   dueña o profesional; quitar una línea; enviar. La liquidación N.º 2 (dueña del ecógrafo) está en preparación.
2. **Gerencia** (`gerencia@`): aprobar o devolver; ver que en una armada por usted no puede aprobar. Acuerdos de reparto: el Dr. Duarte al
   0 %; su prestación pendiente → "Repartir" (clínica 100 %).
3. **Compras y tesorería** (`compras@`): en la N.º 1 (aprobada, pagada en parte) registrar un pago por transferencia (pide comprobante);
   abrir su caja en Cajas y pagar en efectivo desde ella (categoría de gasto); anularlo en los 10 minutos; pasado el plazo, solicitar la
   anulación y que Gerencia la apruebe en Cobros → Reversiones pendientes; ver la salida en su caja.
4. **Caja** (`caja2@`): cobrar la prestación PAP-COLPO tarifada (pendiente) y ver que entra para el dueño externo (Equipos Ejemplo S.A.).
5. Revertir un cobro (anulando su factura) de una prestación ya liquidada y aprobada: en la siguiente, el **ajuste en contra**.
6. **Médica 1** (`medica1@`): "Mis liquidaciones" (solo la aprobada) y su planilla. **Auditor**: Auditoría, montos ocultos.

