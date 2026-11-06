# Cobertura de los objetivos específicos

Auditoría del 05/11/2026, rama `cobertura-objetivos`; revisada contra `main` en el commit 78bafdc (planilla de aceptación), con una segunda pasada a fondo sobre los objetivos 3, 7, 8, 9 y 10. **Solo lectura**: se revisaron rutas
(`php artisan route:list`), migraciones, modelos, `app/Support`, vistas, `docs/` y los nombres de los tests. No se tocó ninguna base ni
funcionalidad, y **no se corrieron los tests** en esta auditoría: "Tests: sí" significa que existen archivos de test del tema, no que hoy pasen.

Criterio: **HECHO** = hay algo usable de punta a punta (pantalla, permiso y reglas), no solo tablas o modelos. **PARCIAL** = usable en
parte; se dice qué falta. **FALTA** = no hay pantalla ni lógica usable. **A VERIFICAR en pantalla** = no se puede confirmar leyendo el código.
Los objetivos se tomaron del pedido (el repo no los trae en ningún documento); se numeran 1 a 14.

## Resumen

| Estado | Objetivos | Cantidad |
|---|---|---|
| HECHO | 1, 3, 4, 6, 7, 9, 11, 13 | 8 |
| PARCIAL | 2, 5, 8, 10 | 4 |
| FALTA | 12, 14 | 2 |

Informes del objetivo 14 (11 ítems): **PARCIAL 7**, **FALTA 4**, HECHO 0. No existe un módulo de informes: ni rutas, ni menú, ni totales;
el Dashboard es el de Laravel por defecto.

Lo más importante que falta:

1. **Informes (14)**: ninguno consolidado (ventas/cobrado/pendiente, procedimientos, por equipo, comparativos, reparto por beneficiario).
2. **Cuentas por pagar (12)**: la compra a crédito guarda `vencimiento` y `anticipo`, pero no hay pagos a proveedores, saldos ni reversiones.
3. ~~Liquidaciones a profesionales y a dueños de equipo (7)~~: hecho (`docs/liquidacion-beneficiarios.md`); el objetivo 14 debe usar su
   servicio de consulta (`ResumenBeneficiarios`) y necesitará la sucursal de la prestación (hoy no la tiene).
4. Caja sin salidas ni gastos (10); saldos y pagos parciales de pacientes (8).

## 1. Gestionar personas (pacientes, profesionales, usuarios, proveedores, beneficiarios, responsables de pago) — HECHO

| Qué | Dónde | Permiso | Tests |
|---|---|---|---|
| Persona física o jurídica (razón social, representante, RUC con dígito) | `/admin/personas` | `PERSONAS` | sí (`PersonaTest`, `RucPersonaTest`, `RolesPersonaTest`) |
| Pacientes, Profesionales, Personal de salud | `/admin/pacientes`, `/admin/profesionales`, `/admin/personal-salud` | `PACIENTES`, `PROFESIONALES`, `PERSONAL_SALUD` | sí |
| Usuarios | `/admin/usuarios` | `USUARIOS` | sí (`UsuarioTest`) |
| Proveedores (con categorías, contactos, redes) | `/admin/proveedores` | `PROVEEDORES` | sí (`Proveedor*Test`) |
| Responsables de pago | `/admin/responsables-pago` | `RESPONSABLES_PAGO` | sí |
| Alta rápida de persona desde otras pantallas | ventana en Turnos, Urgencias, Atención, etc. | el del módulo de destino | sí (`AltaRapidaPersonaTest`, JS) |

Notas: **beneficiario** no es un rol aparte: es la paciente con cobertura de tipo `BENEFICIARIO` (ver 3). A VERIFICAR en pantalla que
quede registrado el titular cuando la paciente es beneficiaria.

## 2. Historial clínico completo según el instructivo del MSPyBS — PARCIAL

| Qué | Dónde | Permiso | Tests |
|---|---|---|---|
| Consulta, anamnesis por bloques, signos, diagnósticos CIE-10, indicaciones, dietas | `/admin/consulta`, `/admin/historias-clinicas` | `HISTORIA_CLINICA` (más catálogos: `CIE10`, `TIPOS_BLOQUE_ANAMNESIS`, `TIPOS_INDICACION`, `DIETAS`) | sí (`tests/Feature/HistoriaClinica/*`, `tests/Postgres/HistoriaClinicaTest`) |
| Recetas | desde la consulta; PDF con dompdf | `RECETAS` (A VERIFICAR el código exacto en la matriz) | sí (`RecetasTest`) |
| Estudios: órdenes, informes | `/admin/estudios`, órdenes e informes desde la consulta | `ESTUDIOS`; órdenes e informes: A VERIFICAR código | sí (`OrdenEstudio`, `InformeEstudio`) |
| Reposo, constancias, indicaciones generales, triaje, PDF clínico | desde la consulta | `PDF_CLINICO` (EXPORTAR) y los del módulo | sí |
| Cierre, adenda y desbloqueo auditados | `docs/historia-clinica.md` | — | sí (`ConsultaCerradaTest`, `CorreccionesTest`) |

Falta: **ninguna referencia al instructivo del MSPyBS** en el código ni en `docs/`; no hay una tabla que contraste los campos del
instructivo con los del sistema. **A VERIFICAR en pantalla**, con el instructivo en mano, que estén todos los campos y el orden exigidos.

## 3. Seguro, plan, beneficiario y vencimiento; imprimir aceptación de consulta — HECHO

| Qué | Dónde | Permiso | Tests |
|---|---|---|---|
| Convenios y planes de la aseguradora | `/admin/convenios` | `CONVENIOS` | sí (`ConveniosVisacionesTest`) |
| Cobertura de la paciente: plan, número de asegurado, titular/beneficiario, vigente desde/hasta | `/admin/pacientes/{id}/cobertura` | `COBERTURAS` | sí |
| Vencimiento: la cobertura vencida no cubre y la prestación guarda el aviso ("La cobertura de la paciente venció el …") | al finalizar la consulta | — | sí |
| Visación y ticket firmable (PDF) | detalle de la prestación | `VISACIONES` (EXPORTAR del ticket) | sí |
| **Planilla de aceptación de consulta** (evolución del ticket): A5 y ticket de 80 mm, con vencimiento, conformidad, aclaración y sello; cada generación queda en Auditoría | botón "Planilla de aceptación" en Turnos (`/admin/turnos/{id}/aceptacion-consulta`), en la pantalla de Atención y en la ficha de cobertura (`/admin/consultas/{id}/ticket-aseguradora`); código en `app/Support/Convenios/TicketVisacion.php` y `PlanillaTurnos.php`, vistas `pdf-ticket-visacion/hoja.blade.php` y `hoja-80mm.blade.php` | `VISACIONES` (EXPORTAR) en ambas rutas | sí (`ConveniosVisacionesTest`) |

Nota: la aceptación se había dado por inexistente porque no se llama así en el código de la primera pasada; es la evolución del ticket de
visación (commit 78bafdc). Avisos sin bloquear. A VERIFICAR en pantalla si la ficha de la paciente destaca la cobertura vencida.

Mejora futura (no pendiente del objetivo): aviso proactivo (agenda o recepción) de una cobertura **próxima a vencer**; hoy solo hay aviso
al finalizar la consulta y en la planilla.

## 4. Agendas, disponibilidades y estados de turnos por profesional — HECHO

| Qué | Dónde | Permiso | Tests |
|---|---|---|---|
| Disponibilidades por profesional y consultorio | `/admin/disponibilidades` | `DISPONIBILIDAD` | sí |
| Turnos (con horario, entre pacientes, urgencias), estados, retiros | `/admin/turnos`, `/admin/turnos/urgencias` | `TURNOS` | sí (`AgendaTest`, `Urgencias`, `Retiros`, `tests/Postgres/Turnos*`) |
| Agenda general (grilla de recepción) y cupos | `/admin/agenda-general`, `/admin/turnos/cupos` | `TURNOS`, `DISPONIBILIDAD` | sí (`AgendaGeneralTest`) |
| Duración por procedimiento, sin superposición | reglas de la base | — | sí |

Pendiente declarado en `docs/agenda-urgencias.md`: reserva de equipos en la agenda (ver 5).

## 5. Integrar procedimientos, profesionales, equipos y datos económicos de cada atención — PARCIAL

| Qué | Dónde | Permiso | Tests |
|---|---|---|---|
| Al finalizar la consulta se genera la **prestación** (procedimiento, profesional, paciente, monto, pagador, reparto, cobro, factura, liquidación) | `/admin/prestaciones` y su detalle | `PRESTACIONES` | sí (`TarifasPrestacionesTest`, `PrestacionInformesTest`) |
| Procedimientos y equipos (el equipo activo del procedimiento) | `/admin/procedimientos`, `/admin/equipos` | `PROCEDIMIENTOS`, `EQUIPOS` | sí |

Falta: el **uso real del equipo** en la atención. El reparto toma el equipo que el procedimiento tiene asignado al calcular; no se registra
qué equipo se usó en cada turno o consulta, ni se reserva en la agenda (`turnos.equipo_id` no se usa; `docs/reparto-economico.md`). La asignación de equipos no está versionada.

## 6. Tarifas y acuerdos económicos con criterios y vigencia histórica — HECHO

| Qué | Dónde | Permiso | Tests |
|---|---|---|---|
| Tarifas por procedimiento y profesional, con vigencia y cambio sin editar (queda histórico) | `/admin/tarifas` | `TARIFAS` | sí (+ `tests/Postgres/TarifasTest`) |
| Tarifas por convenio | `/admin/convenios/tarifas` | `CONVENIOS` | sí |
| Acuerdos de reparto de profesionales (general o por procedimiento) y de equipos, con vigencia | `/admin/acuerdos-reparto` | `ACUERDOS_REPARTO` | sí (`RepartoEconomicoTest`, `Postgres/AcuerdosRepartoTest`) |

Nota: los acuerdos son **solo porcentajes** (sin montos fijos ni otros criterios); es el alcance declarado.

## 7. Cálculo y control automático de distribuciones y liquidaciones por beneficiario — HECHO

| Qué | Dónde | Permiso | Tests |
|---|---|---|---|
| Reparto automático por prestación en líneas (clínica, profesional, dueño del equipo), exacto al guaraní, inmutable | `POST /admin/prestaciones/{id}/repartir`; se ve en el detalle de la prestación | `ACUERDOS_REPARTO` (EDITAR) | sí |
| Liquidación a **aseguradoras** (armar, enviar, aprobar, pagos parciales, planilla) | `/admin/liquidaciones` | `LIQUIDACIONES`, `APROBACION_LIQUIDACIONES`, `PAGOS_ASEGURADORAS` | sí (`LiquidacionesTest`) |
| Liquidación a **profesionales y dueños de equipos** de punta a punta: lo percibido (cobrado a la paciente; la aseguradora con su liquidación pagada), armar por beneficiario y corte con vista previa, postergar (quitar), enviar, aprobar o devolver (nadie lo propio), pagos totales o parciales (con comprobante; en efectivo como salida de la caja de Tesorería), anular pagos y la liquidación, ajustes en contra automáticos y saldo negativo trasladado, planilla PDF, auditoría con montos ocultos y trazabilidad hasta la prestación | `/admin/liquidaciones-beneficiarios` (pestañas: liquidaciones, por pagar, pendientes de liquidar, resumen por período) | `LIQUIDACIONES_BENEFICIARIOS`, `APROBACION_LIQ_BENEFICIARIOS`, `PAGOS_BENEFICIARIOS` | sí (`LiquidacionBeneficiariosTest`, `...PantallasTest`, `...ConsultaTest`, `MigracionLiquidacionBeneficiariosTest`, `Postgres/LiquidacionBeneficiariosTest`) |
| "Mis liquidaciones" del profesional (solo las propias aprobadas, de solo lectura, con su planilla) | `/admin/mis-liquidaciones` | `MIS_LIQUIDACIONES` | sí |

Detalle en `docs/liquidacion-beneficiarios.md`. El funcionario con sueldo lleva un acuerdo general de 0 % (su parte queda en la clínica);
sin acuerdo el reparto sigue pendiente. A VERIFICAR en pantalla con datos reales: el recorrido completo (`docs/liquidacion-beneficiarios.md`,
"Qué probar en el navegador").

## 8. Responsables de pago, cobros, medios de pago, saldos y reversiones — PARCIAL

| Qué | Dónde | Permiso | Tests |
|---|---|---|---|
| Medios de pago con comprobante obligatorio según el medio | `/admin/medios-pago` | `MEDIOS_PAGO` | sí |
| Cobro de prestaciones (varios medios, comprobante), contra factura | `/admin/cobros/create`, `/admin/cobros` | `COBROS` + `FACTURAS` (CREAR) | sí (`CobrosCajaTest`, `ComprobanteCobrosTest`) |
| Reversión del cobro directa (Gerencia) o por solicitud y aprobación | `/admin/cobros/{id}`, `/admin/cobros/reversiones` | `COBROS` (DESACTIVAR), `REVERSIONES_COBRO` | sí (`ReversionesCobroTest`) |
| Responsable de pago / aseguradora: lo que debe pagar y sus pagos | `/admin/liquidaciones/por-cobrar`, pagos de la liquidación | `LIQUIDACIONES`, `PAGOS_ASEGURADORAS` | sí |

Si la paciente paga menos: no se puede. `Cobrar` exige que la suma de las líneas sea igual al total (la suma exacta de su parte), sin vuelto ni
saldo (`app/Support/Cobros/Cobrar.php`: "pagos parciales, saldos ni vuelto"); la prestación queda pendiente de cobro hasta cobrarse completa. El único
saldo que existe es el de la **aseguradora** (liquidaciones, por cobrar y pagos parciales); con visación se cobra solo la parte de la paciente.

Para dejarlo HECHO (mínimo): permitir cobros parciales sobre la prestación (varios cobros hasta completar), con saldo de la paciente visible en
la prestación y en un listado de deudores, y reversión que recalcule el saldo; recibo imprimible.

Falta: **saldos** de la paciente y **pagos parciales o a crédito**: el cobro es siempre completo y posterior a la consulta, sin crédito
(`docs/cobros-caja.md`). No hay cuenta corriente de la paciente. Sin recibo imprimible.

## 9. Trazabilidad entre servicios prestados y facturación individual, posterior o agrupada — HECHO

| Qué | Dónde | Permiso | Tests |
|---|---|---|---|
| Factura individual simulada con detalle por prestación (`detalles_factura.prestacion_id`), timbrado, numeración, IVA, PDF | `/admin/facturas`, `/admin/facturas/{id}`, `/admin/facturas/timbrados` | `FACTURAS`, `TIMBRADOS`, `DATOS_FISCALES` | sí (`FacturacionTest`, Postgres) |
| Anulación directa o por solicitud, con motivo | `/admin/facturas/solicitudes`, motivos | `FACTURAS` (DESACTIVAR), `ANULACIONES_FACTURA`, `MOTIVOS_ANULACION` | sí |
| La prestación facturada se bloquea; la liquidación marca "pendiente de facturar" | detalle de prestación y de liquidación | — | sí |
| Factura CRÉDITO agrupada y posterior de la liquidación aprobada, a la aseguradora: una línea por prestación (parte ASEGURADORA), IVA, mismo timbrado; sigue a los pagos; anulación por solicitud; enlaces factura ↔ liquidación ↔ prestaciones | detalle de la liquidación ("Emitir la factura"), `/admin/facturas/{id}` | `FACTURAS_LIQUIDACION`, `ANULACIONES_FACTURA` | sí (`FacturaLiquidacionTest`, Postgres) |

Hecho en la rama `factura-liquidacion` (`docs/facturacion.md`, "Factura de la liquidación"): la liquidación aprobada se factura con una
factura CRÉDITO a la aseguradora (una por liquidación), que la deja FACTURADA. Queda como mejora la **factura posterior individual** (de
una prestación ya cobrada o sin factura) y las notas de crédito.

## 10. Apertura, movimientos, conciliación y cierre diario de caja — PARCIAL

| Qué | Dónde | Permiso | Tests |
|---|---|---|---|
| Apertura de caja por cajero y sucursal | `/admin/cajas` | `CAJAS` (CREAR) | sí (`ArqueoCajaTest`, Postgres) |
| Movimientos: apertura, cobro, reversión (por medio de pago) | detalle de la caja | `CAJAS` (VER) | sí |
| Arqueo y cierre: esperado vs declarado por medio, diferencia con observación obligatoria, recepción o devolución por Gerencia, reenvío corregido, PDF | `/admin/cajas/{id}/arqueo` | `CAJAS`, `CIERRES_CAJA` | sí |

Solo hay tres tipos de movimiento (`MovimientoCaja`: APERTURA, COBRO, REVERSION). El único egreso es la **reversión** de un cobro (devolución de dinero
a la paciente); no hay retiros, gastos, pagos a proveedores ni depósitos.

Para dejarlo HECHO (mínimo): un tipo de movimiento SALIDA (retiro o gasto, con medio, motivo y comprobante, y opcionalmente vinculado a una compra),
que entre en el esperado del arqueo; sin eso la conciliación cubre solo ingresos.

Falta: **salidas de caja** (gastos, pagos a proveedores): las compras de contado y los anticipos "no tocan la caja" (`docs/compras-gastos.md`),
así que la conciliación cubre solo ingresos. Sin conciliación con extractos bancarios, ni depósitos de lo recaudado.

## 11. Compras y gastos operativos con comprobantes, categorías, condiciones y estados — HECHO

| Qué | Dónde | Permiso | Tests |
|---|---|---|---|
| Alta, edición del borrador, detalle y listado con grilla | `/admin/compras` | `COMPRAS` | sí (`tests/Feature/Compras/*`, `Postgres/ComprasPostgresTest`) |
| Confirmar (por otra persona), anular con motivo, descartar | `/admin/compras/{id}` | `CONFIRMACION_COMPRAS`, `COMPRAS` | sí |
| Tipos de comprobante, categorías de gasto, categorías de proveedor | `/admin/tipos-comprobante-compra`, `/admin/categorias-gasto`, `/admin/categorias-proveedor` | `TIPOS_COMPROBANTE_COMPRA`, `CATEGORIAS_GASTO`, `CATEGORIAS_PROVEEDOR` | sí |
| Condición CONTADO o CRÉDITO, IVA discriminado | en la compra | — | sí |

Pendiente declarado: imagen del comprobante y "Trabajo futuro" de `docs/compras-gastos.md`. Anotación: `PATCH compras/{id}/anular` usa el
permiso `COMPRAS` VER en la ruta (la regla fina está en el servicio: quien la cargó o Gerencia); A VERIFICAR en pantalla que un perfil solo con VER no pueda anular.

## 12. Cuentas por pagar: vencimientos, pagos, saldos y reversiones a proveedores — FALTA

| Qué existe | Dónde |
|---|---|
| Datos de la compra a crédito: `vencimiento` y `anticipo` | tabla `compras`, formulario de compras |

Qué no está: **no hay** pantalla de cuentas por pagar, pagos a proveedores, saldo por compra ni por proveedor, vencimientos próximos o
vencidos, ni reversión de pagos; tampoco modelo ni módulo de permisos. `docs/compras-gastos.md` lo declara fuera de alcance
("solo se dejan los datos listos"). Tests: ninguno.

## 13. Restringir y auditar acceso, consulta y modificación de documentos sensibles según la función — HECHO

| Qué | Dónde | Permiso | Tests |
|---|---|---|---|
| Perfiles, matriz de permisos por módulo y acción (VER, CREAR, EDITAR, DESACTIVAR, EXPORTAR), varios perfiles por usuario | `/admin/perfiles-acceso`, `/admin/matriz-permisos` | `PERFILES_ACCESO` | sí (`tests/Feature/Perfiles/*`, `PermisosTest`) |
| Auditoría de altas, cambios, lecturas, exportaciones y accesos, inmutable; filtros por usuario, acción, módulo y fecha | `/admin/auditoria` | `AUDITORIA` | sí (`tests/Feature/Auditoria/*`, `Postgres/LogAuditoriaInmutableTest`) |
| Contenido clínico y de recetas oculto en Auditoría a quien no tiene VER sobre esos módulos | misma pantalla | `HISTORIA_CLINICA`, `RECETAS` | sí (`AuditoriaClinicaTest`, `RecetasAuditoriaTest`) |
| Consulta cerrada de solo lectura; corrección solo por adenda o desbloqueo autorizado | `docs/historia-clinica.md` | — | sí |
| Documentos de caja, factura, reversión: separación de funciones (nadie revisa lo propio) | ver 8, 9, 10, 11 | varios | sí |

## 14. Informes periódicos — FALTA

No hay rutas, controladores, menú, exportaciones ni totales de informes (`route:list` sin nada de informes; solo existen los informes **de
estudio** clínicos, que son otro tema). Lo que hay son listados con grilla (orden y filtros por columna) y la pantalla de Auditoría.

| # | Informe | Estado | Qué hay y qué no está |
|---|---|---|---|
| 14.1 | Atenciones por fecha, sucursal, especialidad y profesional | PARCIAL | Listados de turnos, consultas e historias (`TURNOS`, `HISTORIA_CLINICA`) con filtros por columna. No hay informe, conteos, ni filtro por sucursal (el turno solo llega a la sucursal por el consultorio) ni por especialidad: A VERIFICAR en pantalla. Tests: de los listados, no del informe. |
| 14.2 | Procedimientos más realizados, con cantidades e ingresos | FALTA | Ninguna agrupación ni ranking. Los datos están en `prestaciones`. |
| 14.3 | Total vendido, cobrado y pendiente | FALTA | El listado de Prestaciones muestra "Cobrada/Pendiente" por fila, sin totales ni períodos. |
| 14.4 | Facturas individuales y agrupadas | PARCIAL | Listado de Facturas (`FACTURAS`) con PDF y estados; las **agrupadas no existen** (ver 9). Sin totales por período. |
| 14.5 | Lo que corresponde a clínica, profesionales y propietarios de equipos | PARCIAL | Servicio de consulta único `LiquidacionesBeneficiarios\ResumenBeneficiarios` (devengado, percibido, liquidable, liquidado, pagado y saldo por beneficiario y período, con la clínica) y su pestaña "Resumen por período". Falta el informe del objetivo 14 (exportación, comparativos). La parte de la clínica no es ganancia (faltan gastos y sueldos); la prestación no tiene sucursal propia. |
| 14.6 | Liquidaciones generadas, pagadas, parciales y pendientes | PARCIAL | Listados de liquidaciones a aseguradoras y a beneficiarios con estados (EN_PREPARACION a PAGADO), "por cobrar" y "por pagar por beneficiario". Falta el informe consolidado del objetivo 14 (usar `ResumenBeneficiarios`). |
| 14.7 | Comparativo por profesional, procedimiento, equipo y sucursal | FALTA | Nada. |
| 14.8 | Compras, gastos y saldos con proveedores | PARCIAL | Listado de Compras con filtros (proveedor, categoría, condición, estado). Sin totales por período ni **saldos** (ver 12). |
| 14.9 | Usos e importes por equipo | FALTA | Nada; además el uso del equipo no se registra por atención (ver 5). |
| 14.10 | Ingresos esperados vs declarados y diferencias por período y sucursal | PARCIAL | Cada arqueo muestra esperado, declarado y diferencia por medio, y se imprime; la caja tiene sucursal. No hay consolidado por período ni por sucursal. |
| 14.11 | Anulaciones, reversiones y modificaciones por usuario | PARCIAL | `/admin/auditoria` filtra por usuario, acción, módulo y fecha. Sin informe agrupado, sin conteos por usuario y sin exportación. A VERIFICAR en pantalla que sirva para este propósito. |

## Para confirmar en pantalla (resumen)

1. Campos de la historia clínica contra el instructivo del MSPyBS (2).
2. Titular registrado en la paciente beneficiaria; alerta de cobertura vencida o por vencer (1, 3).
3. Códigos de permiso de recetas, órdenes e informes de estudio en la matriz (2).
4. Que un perfil con solo VER sobre Compras no pueda anular (11).
5. Filtros por sucursal y especialidad en los listados de atenciones (14.1) y utilidad de Auditoría como informe (14.11).
6. Aviso al repartir cuando falta el acuerdo (7).
