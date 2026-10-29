# Guía de verificación del sistema

Guía para recorrer todo Clinexa con casillas para marcar. Se armó leyendo `docs/` y el código; no define reglas nuevas. Donde un
paso dice "esperado", el resultado sale de esos documentos. **No es una verificación clínica**: lo que depende de un médico está en
"Pendiente de médico" (al final).

Armada el 28/10/2026 en la rama `docs-verificacion`. La matriz de la sección "Matriz real de permisos" se leyó de `clinexa_dev` en
solo lectura ese día.

## Cómo usarla

- **Datos inventados, siempre.** Nada de pacientes ni credenciales reales. Los usuarios y los pacientes del juego de datos demo
  (`docs/datos-demo.md`) son inventados; los correos terminan en `@prueba.test`.
- **Contraseña de los usuarios demo:** la que se escribió al cargar los datos (`php artisan clinexa:datos-demo --confirmo-desarrollo`
  la pide por consola). No está en ningún archivo.
- **Marcar** cada casilla `[ ]` → `[x]` al comprobar el resultado esperado. Si no coincide, **no** marque la casilla: anote el
  defecto con el formato de la sección "Registro de defectos".
- **Cada paso lleva un código** (por ejemplo `CC-7`) para citarlo en el registro de defectos.
- Los pasos que dicen **NIEGA** comprueban lo que el sistema tiene que rechazar (permiso, regla o estado). Valen tanto como los que
  andan: un NIEGA que deja pasar es un defecto de gravedad A.
- Fechas en dd/mm/aaaa. Montos en guaraníes enteros (`₲ 150.000`).
- Entre un usuario y otro, cierre la sesión. Con dos navegadores o una ventana privada se prueba más rápido.
- **Los pasos que escriben** (turnos, consultas, cobros, liquidaciones) cambian la base donde se corra. Para no ensuciar
  `clinexa_dev`, recorra la guía en una instalación limpia con datos demo (ver "Instalación limpia") o haga antes
  `php artisan clinexa:respaldo --probar` (CLAUDE.md: la base de trabajo solo se toca con confirmación expresa).

**Recorrido de un día de trabajo.** Para recorrer el sistema como lo usaría la clínica, con un guion hora por hora, datos para tipear y
una base propia (`clinexa_recorrido`), ver `docs/recorrido-manual.md`. Esta guía sigue siendo la de consulta por módulo.

### Usuarios del juego de datos demo

| Usuario | Perfil | Notas |
|---|---|---|
| `admin@prueba.test` | Administrador | Todos los permisos. En una instalación limpia no es "administrador principal" hasta el traspaso (`docs/perfiles.md`). |
| `gerencia@prueba.test` | Gerencia | Aprueba cierres, reversiones, anulaciones de factura y liquidaciones; carga tarifas, acuerdos y timbrado. |
| `caja1@prueba.test`, `caja2@prueba.test` | Caja | Abren caja, facturan y cobran. |
| `facturacion@prueba.test` | Facturación y convenios | Convenios, liquidaciones y pagos de aseguradoras. |
| `compras@prueba.test` | Compras y tesorería | Proveedores. |
| `auditor@prueba.test` | Auditor | Solo Auditoría. |
| `medica1@prueba.test` | Médico | Profesional (Dra. Benítez): con acuerdo de reparto y el equipo. |
| `medico2@prueba.test` | Médico | Profesional (Dr. Duarte): **sin acuerdo de reparto**. |
| `enfermeria@prueba.test` | Enfermería | Personal de salud: triaje. |
| `recepcion@prueba.test` | Recepción | Turnos, pacientes, coberturas y visaciones. |
| `supervision@prueba.test` | Supervisión médica | Catálogos clínicos, correcciones (desbloqueo), lectura clínica. |

Para correr **dos supervisores** (el desbloqueo no lo hace quien atendió la consulta) hace falta crear un segundo usuario con ese
perfil o usar al Administrador (`docs/historia-clinica.md`).

### Pacientes y aseguradoras demo

- 12 pacientes adultas (CI desde 9000001 en adelante). Las pacientes 1 a 4 tienen cobertura; **la 4, vencida**. Con visaciones cargadas
  hay, por ejemplo, "Alvarenga Núñez, Gloria Estela", "Barrios Colmán, Liz Marlene" y "Cabrera Franco, Rocío Abigail".
- Aseguradoras: "Seguros Ejemplo Uno S.A." (RUC 80900001-6) y "Previsión Médica Dos S.A." (RUC 80900002-4), cada una con convenio,
  dos planes y tarifas del convenio para Consulta y ECO-TV.
- Procedimientos: Consulta (`CONS-001`, 20 min), ecografías (`ECO-ABD`, `ECO-TV`, `ECO-OBST` de 30 min; `ECO-OBST-DOP`, 40 min;
  `ECO-CROMO`, 90 min; `ECO-MORFO`, 120 min) y `PAP-COLPO` (30 min, **sin tarifa a propósito**).
- Una instalación limpia con `clinexa:datos-demo` trae además 31 cobros **con factura**, una factura anulada en el plazo, una
  solicitud de anulación pendiente y una rechazada, una liquidación aprobada con un pago parcial y otra en preparación.
- **Ojo con `clinexa_dev`:** el 28/10/2026 tiene los 31 cobros demo **sin factura** (se cargó antes de la facturación, y los días
  simulados no se repiten). Ahí no hay facturas de ejemplo: los recorridos 2 y 3 se hacen creando los cobros en el momento. En
  cambio esos cobros viejos son los que sirven para probar la reversión con solicitud (recorrido 3).

## Recorridos de punta a punta

Cada recorrido cruza varios módulos. Si uno falla, el detalle de cada tramo está en la sección del módulo.

### Recorrido 1. Copago con visación, hasta la caja y el ticket

Paciente con cobertura vigente (por ejemplo "Barrios Colmán, Liz Marlene") y un turno de hoy con el Dr. o la Dra. del demo.

- [ ] **R1-1** (`recepcion`) Turnos → "Nuevo turno": darle un turno de hoy con horario, con procedimiento Consulta. → "Turno dado para el …".
- [ ] **R1-2** (`recepcion`) En la Agenda general o en Turnos, el turno muestra "Seguro: aseguradora" y "Falta visación". Botón **Visación**. → Se abre la
  página de coberturas con el turno elegido y el procedimiento del turno como primera línea.
- [ ] **R1-3** (`recepcion`) Cargar número de visación (`V-aammdd-NNN` en el demo), resultado **Aprobado** y qué paga la paciente:
  **Porcentaje** (por ejemplo 20 %). Guardar. → Vuelve a la pantalla de origen con el aviso; el turno dice "Visación cargada".
- [ ] **R1-4** (`enfermeria`) Triaje: iniciar y terminar el triaje de la paciente. → "Con triaje".
- [ ] **R1-5** (`medica1`) Consulta → Atender → completar lo mínimo que pida el sistema → Finalizar. → Consulta FINALIZADA; el turno, ATENDIDO.
- [ ] **R1-6** (`gerencia`) Prestaciones → la prestación de la paciente. → TARIFADO; pagador **ASEGURADORA**; "monto paciente" = el
  porcentaje elegido de la tarifa del convenio (redondeado al guaraní); "monto aseguradora" = el resto; la suma es exacta; con el
  número de visación; reparto (profesional, equipo, clínica) calculado sobre el **monto total**.
- [ ] **R1-7** (`recepcion`) Página de la paciente (Cobertura y visaciones) → prestación de aseguradora → **Marcar firma recibida**. → Firma con fecha y usuario.
- [ ] **R1-8** (`recepcion`) Generar el **ticket**. → PDF (se abre en otra pestaña) con clínica, profesional, fecha y hora, paciente y cédula,
  aseguradora, plan, número de asegurado, número de visación, procedimiento y línea de firma. **Sin montos ni nada clínico.**
  En Auditoría queda un EXPORTAR sobre `visaciones`.
- [ ] **R1-9** (`caja1`) Abrir la caja (Cajas → "Mi caja") con un monto inicial. Cobrar → la paciente. → Se ofrece la prestación con la columna
  "A cobrar" = **solo la parte de la paciente**.
- [ ] **R1-10** (`caja1`) "Facturar y cobrar" con efectivo por el total exacto. → Una factura `001-001-NNNNNNN` por la parte de la paciente (la de la aseguradora **no entra**), cobro
  registrado, factura COBRADA, marcada "SIMULACIÓN, SIN VALIDEZ FISCAL".
- [ ] **R1-11** (`caja1`) Volver a Cobrar con la misma paciente. → La prestación ya no se ofrece (cobrada).
- [ ] **R1-12** (`caja1`) Una prestación cuya parte de la paciente es cero (visación con cobertura total) → NIEGA: no se ofrece; se muestra "A cargo de aseguradora".
- [ ] **R1-13** (`medica1`) NIEGA: la pantalla de atención y el informe de estudio no muestran montos; no hay menú Económico.

### Recorrido 2. Factura y cobro, anulación en 10 minutos y por solicitud

- [ ] **R2-1** (`caja1`) Cobrar una prestación particular (paciente sin visación) en efectivo. → Factura emitida y cobrada; en el detalle del cobro y de la prestación se ve la factura.
- [ ] **R2-2** (`caja1`) Facturas → detalle → **PDF**. → Primera vez "FACTURA"; la segunda vez "FACTURA (COPIA)". Con RUC, timbrado y vigencia, líneas por tasa, total del IVA, CONTADO y la marca de simulación.
- [ ] **R2-3** (`caja1`) Dentro de los 10 minutos de la emisión: "Anular la factura" con un motivo del catálogo. → La factura queda ANULADA, el cobro revertido (un egreso por línea en su caja) y el número **no se reutiliza**.
- [ ] **R2-4** (`caja1`) Desde el detalle de la anulada: "Cobrar de nuevo con una factura que reemplace a esta". → Nueva factura con el número siguiente; la anulada la muestra como reemplazada.
- [ ] **R2-5** (`caja1`) Emitir otra factura y esperar **más de 10 minutos** (`CLINEXA_PLAZO_ANULACION_FACTURA`). Intentar anular. → Ya no hay anulación directa: solo "Solicitar la anulación".
- [ ] **R2-6** (`caja1`) "Solicitar la anulación" con motivo "Otro (detallar)" **sin** detalle. → NIEGA: pide el detalle. Con detalle: solicitud PENDIENTE.
- [ ] **R2-7** (`caja1`) Solicitar otra vez la misma factura. → NIEGA: ya hay una pendiente.
- [ ] **R2-8** (`caja1`) NIEGA: abrir "Solicitudes de anulación": Caja no ve la pestaña ni puede aprobar (403).
- [ ] **R2-9** (`gerencia`) Facturas → "Solicitudes de anulación (N)" → rechazar sin nota. → NIEGA (la nota de 5 a 300 caracteres es obligatoria). Rechazar con nota: la factura sigue vigente.
- [ ] **R2-10** (`caja1`) Volver a solicitar (rechazada se puede pedir de nuevo). Con la caja de hoy abierta de quien pide, `gerencia` **aprueba**. → Se revierte el cobro y se anula la factura (`anulada_por` = quien aprueba).
- [ ] **R2-11** (`caja1`) Con la caja **cerrada**, pedir la anulación de una factura. Aprobar con `gerencia` sin que `caja1` tenga caja abierta de hoy. → No se aprueba hasta que abra la caja (aviso en el detalle).
- [ ] **R2-12** (`caja1`) NIEGA: revertir directamente un cobro que tiene factura → "Este cobro tiene factura: se revierte anulando la factura".
- [ ] **R2-13** (`caja2`) NIEGA: anular directa una factura de `caja1` → solo "Solicitar la anulación".
- [ ] **R2-14** (`admin`) Sin timbrado vigente, o vencido, o agotado, "Cobrar" avisa antes y el botón no emite (ver FT).

### Recorrido 3. Reversión de un cobro con solicitud y aprobación

Solo aplica a un **cobro sin factura** (los 31 de `clinexa_dev` o cualquier anterior al hito FACTURACION). En una instalación
limpia todos los cobros llevan factura: el equivalente es el recorrido 2.

- [ ] **R3-1** (`caja1`) Con la caja de hoy **abierta**, abrir el detalle de un cobro **de otro día** (caja aprobada). → Ofrece "Solicitar la reversión" (no "Revertir el cobro").
- [ ] **R3-2** (`caja1`) Solicitar con motivo de 3 letras. → NIEGA (5 a 300). Con motivo válido: solicitud PENDIENTE.
- [ ] **R3-3** (`caja1`) Intentar "Revertir el cobro" directo con la solicitud pendiente. → NIEGA mientras la solicitud esté pendiente.
- [ ] **R3-4** (`caja1`) NIEGA: aprobar su propia solicitud (403). Tampoco la ve en "Reversiones pendientes".
- [ ] **R3-5** (`gerencia`) Cobros → "Reversiones pendientes (N)" → "Aprobar". → El cobro queda **REVERTIDO**; sus prestaciones vuelven a "Pendiente" y se pueden cobrar de nuevo; la caja vieja **no cambia** (el cobro figura "(revertido)").
- [ ] **R3-6** (`caja1`) En su caja de hoy: "Devoluciones de cobros revertidos" con un egreso por cada línea del cobro y por su medio.
- [ ] **R3-7** (`caja1`) Un cobro **en efectivo** mayor al efectivo esperado de la caja → NIEGA: "No alcanza el efectivo de la caja para devolver ₲ X (hay ₲ Y).". Con transferencia o tarjeta se permite aunque lo esperado quede negativo.
- [ ] **R3-8** (`caja1`) NIEGA: sin caja abierta de hoy no se aprueba la solicitud (se avisa en el detalle del cobro).
- [ ] **R3-9** (`gerencia`) Rechazar otra solicitud con nota. → RECHAZADO con quien, cuándo y la nota; se puede volver a pedir.
- [ ] **R3-10** (`caja1`) Revertir directamente un cobro **propio** de una caja que sigue **abierta** y es de hoy. → Directa, sin aprobación; motivo obligatorio.
- [ ] **R3-11** (`caja1`) NIEGA: anular una prestación cobrada → "Esta prestación está cobrada: no se puede anular mientras su cobro esté vigente." Revertida, sí.

### Recorrido 4. Cierre de caja con aprobación de Gerencia

- [ ] **R4-1** (`caja1`) Cajas → "Mi caja" → abrir con monto inicial (₲ 0 o más). → Caja ABIERTA. Intentar abrir otra → NIEGA: "Ya tiene una caja abierta: ciérrela antes de abrir otra."
- [ ] **R4-2** (`caja1`) Hacer un cobro mixto (efectivo + transferencia con referencia) y otro con tarjeta. → Lo esperado por medio suma apertura + cobros.
- [ ] **R4-3** (`caja1`) Cerrar declarando el contado en cada medio con **una diferencia** y sin motivo. → NIEGA: el motivo (5 a 300) es obligatorio cuando hay diferencia.
- [ ] **R4-4** (`caja1`) Cerrar con motivo. → Caja CERRADA ("pendiente de aprobación"); ya no recibe cobros.
- [ ] **R4-5** (`caja1`) NIEGA: intentar cobrar o aprobar el propio cierre (Caja no tiene CIERRES_CAJA).
- [ ] **R4-6** (`gerencia`) Cajas → listado: aviso de pendientes de aprobación. Abrir la caja: esperado, contado, diferencia y motivo por medio.
- [ ] **R4-7** (`gerencia`) **Devolver** sin nota → NIEGA. Con nota: DEVUELTO; la caja no recibe cobros, pero `caja1` puede abrir la de hoy.
- [ ] **R4-8** (`caja1`) Volver a cerrar la devuelta. → Segundo cierre; el anterior se conserva en el historial.
- [ ] **R4-9** (`gerencia`) **Aprobar** (nota opcional). → APROBADO. Cajas aprobadas no se modifican nunca.
- [ ] **R4-10** (`caja1`) Con una caja abierta de un día anterior, intentar cobrar. → NIEGA: "Su caja está abierta desde el dd/mm/aaaa: ciérrela y abra la de hoy para cobrar." Sin caja: "Abra su caja antes de cobrar."
- [ ] **R4-11** (`admin`) NIEGA: revisar el cierre de su propia caja (si el Administrador la abrió).
- [ ] **R4-12** (`auditor`) Auditoría → eventos "Apertura de caja", "Cierre de caja", "Cierre aprobado"/"Cierre devuelto"; los montos dicen "Contenido económico" (oculto).

### Recorrido 5. Ecografía hecha en la consulta, con el reparto del equipo

- [ ] **R5-1** (`recepcion`) Turno de hoy de una paciente particular con la Dra. Benítez (`medica1`), procedimiento **ECO-TV**. → Duración de 30 minutos.
- [ ] **R5-2** (`medica1`) Atender. En la sección **Estudios**, agregar un informe: procedimiento ECO-TV, solicitante (opcional), texto. → Autoguardado; el informe se ve con "PDF del informe".
- [ ] **R5-3** (`medica1`) Finalizar. → Una prestación de **ECO-TV** (la base cubre un informe del mismo procedimiento; no se duplica). Un segundo informe del mismo procedimiento genera la suya.
- [ ] **R5-4** (`gerencia`) Prestaciones → la prestación → sección Reparto. → Tarifa general del procedimiento; **profesional 60 %**, **equipo 30 %** (dueña: la Dra. Benítez como proveedora: **dos líneas separadas**) y **clínica 10 %**; montos al guaraní, suma exacta.
- [ ] **R5-5** (`medica1`) Repetir con **ECO-CROMO** (90 min). → Profesional **40 %** (excepción del procedimiento), equipo 30 %, clínica 30 %.
- [ ] **R5-6** (`medico2`) Finalizar una consulta del Dr. Duarte. → La prestación queda tarifada pero **pendiente de reparto** ("Falta el acuerdo del profesional para el dd/mm/aaaa."). **No** impide finalizar.
- [ ] **R5-7** (`gerencia`) Cargar el acuerdo del Dr. Duarte y, en la prestación, **Repartir**. → REPARTIDO con los acuerdos vigentes en la fecha del servicio. Repartir otra vez → "Esta prestación ya está repartida."
- [ ] **R5-8** (`medica1`) Finalizar una consulta con **PAP-COLPO**. → Prestación **Pendiente de tarifar**, sin monto, sin reparto; tampoco impide finalizar.
- [ ] **R5-9** (`gerencia`) Cargar la tarifa de PAP-COLPO y **Tarifar** la prestación. → Se usa la tarifa que rige en la **fecha del servicio**; el reparto se calcula en ese momento.
- [ ] **R5-10** (`caja1`) NIEGA: en la prestación no ve el reparto ni el aviso de pendientes (solo el monto).
- [ ] **R5-11** (`medica1`) Cobro de la ecografía por Caja (como el recorrido 2). → El reparto no cambia por cobrar.

### Recorrido 6. Liquidación a una aseguradora con pagos parciales

- [ ] **R6-1** (`facturacion`) Liquidaciones → pestaña "Prestaciones sin firma". → Las de aseguradora sin firma recibida, con "Marcar la firma" (la firma también se marca desde la página de la paciente).
- [ ] **R6-2** (`facturacion`) "Armar liquidación": aseguradora y corte en **fecha futura**. → NIEGA. Con corte de hoy: vista previa con líneas, total y las que quedan afuera por falta de firma.
- [ ] **R6-3** (`facturacion`) "Confirmar y armar la liquidación". → EN_PREPARACION. Armar otra para la **misma** aseguradora mientras esta sigue abierta → NIEGA (una sola abierta por aseguradora).
- [ ] **R6-4** (`facturacion`) "Quitar" una línea con motivo de 3 letras → NIEGA; con motivo válido: el total se recalcula y la prestación queda libre.
- [ ] **R6-5** (`facturacion`) En esa prestación liberada o en las incluidas: "Aplicar visación", desmarcar la firma o anularla. → NIEGA con el aviso de bloqueo ("… en preparación: quítela de la liquidación antes de cambiarla.").
- [ ] **R6-6** (`facturacion`) "Enviar a aprobación". → PENDIENTE_APROBACION. NIEGA: aprobar o devolver la que armó (no tiene APROBACION_LIQUIDACIONES).
- [ ] **R6-7** (`admin`) Armar y enviar una liquidación como Administrador y tratar de aprobarla. → NIEGA: "nadie aprueba ni devuelve una liquidación que armó él mismo".
- [ ] **R6-8** (`gerencia`) Devolver con nota → vuelve a EN_PREPARACION. Enviar otra vez y **Aprobar**. → APROBADO, bloqueada, "pendiente de facturar".
- [ ] **R6-9** (`facturacion`) Registrar un pago parcial (monto menor al saldo, hoy, Transferencia). → PARCIAL; saldo = total − pagos vigentes.
- [ ] **R6-10** (`facturacion`) Registrar un pago que **supera el saldo**. → NIEGA: "El pago supera el saldo de la liquidación (₲ X)."; fecha futura → NIEGA.
- [ ] **R6-11** (`facturacion`) Registrar un segundo pago por el saldo exacto. → PAGADO.
- [ ] **R6-12** (`facturacion`) Anular un pago con motivo. → El estado se recalcula (PARCIAL; sin pagos, APROBADO).
- [ ] **R6-13** (`facturacion`) "Anular la liquidación" con pagos vigentes → NIEGA ("anule los pagos antes de anularla"). Aprobada: NIEGA a Facturación; solo `gerencia` o `admin`.
- [ ] **R6-14** (`facturacion`) "Planilla (PDF)". → A4 apaisada con aseguradora y convenio, corte y período, una fila por línea, total y espacio de firma; sin diagnóstico. En Auditoría, EXPORTAR sobre `liquidaciones`.
- [ ] **R6-15** (`gerencia`) Pestaña "Por cobrar por aseguradora". → Aprobado, pagado, saldo y cuántas están pendientes de facturar.
- [ ] **R6-16** (`caja1`) NIEGA: Liquidaciones no aparece en el menú de Caja y la URL da 403. Los pagos de la aseguradora **no** tocan cajas, cierres ni cobros.

## Módulos

Los permisos de cada sección salen de la matriz real (ver "Matriz real de permisos"): **V** ver, **C** crear, **E** editar,
**D** desactivar, **X** exportar. Entre paréntesis, el módulo del sistema.

### 1. Personas y pacientes (`PE`)

**Qué hace.** Alta y edición de personas físicas y jurídicas; sobre una persona ya cargada se asignan los roles (paciente,
profesional, proveedor, responsable de pago, usuario, personal de salud). Cada paciente tiene su ficha e historia clínica.
`PERSONAS` y `PACIENTES` son módulos **sensibles**: sus lecturas quedan en Auditoría.

**Quién puede.**

| Perfil | Personas (`PERSONAS`) | Pacientes (`PACIENTES`) | Responsables de pago |
|---|---|---|---|
| Recepción | V C E | V C E | V C E |
| Médico, Caja, Supervisión médica | nada | V | Facturación: V |
| Gerencia, Enfermería, Compras, Auditor | nada | nada | nada |
| Administrador | todo | todo | todo |

**Pasos.**

- [ ] **PE-1** (`recepcion`) Personas → nueva persona **física** (CI inventada, por ejemplo 9100001, datos completos). → Alta; queda en Auditoría (CREAR).
- [ ] **PE-2** (`recepcion`) Repetir la misma CI. → NIEGA: ya existe una persona con ese documento.
- [ ] **PE-3** (`recepcion`) El selector de tipo de documento. → Ofrece **solo CI y pasaporte** (no RUC).
- [ ] **PE-4** (`recepcion`) Persona **jurídica** sin RUC. → NIEGA: "Una persona jurídica necesita su RUC." El formulario no pide documento.
- [ ] **PE-5** (`recepcion`) Convertir la persona del PE-1 en paciente (Pacientes → alta). → Paciente con ficha e historia clínica.
- [ ] **PE-6** (`recepcion`) Editar teléfono o dirección de la paciente. → Se guarda; el EDITAR en Auditoría muestra antes y después.
- [ ] **PE-7** (`recepcion`) En el listado, una persona jurídica sin RUC o una física sin CI. → Etiquetas "falta RUC" / "falta CI"; al editar se pide lo que falta.
- [ ] **PE-8** (`recepcion`) Buscar una paciente por apellido, nombre o CI en el selector. → Aparece en el selector; los formularios de turno y de urgencia piden pacientes activas.
- [ ] **PE-9** (`recepcion`) Intentar **desactivar** una paciente que tiene consultas cerradas. → NIEGA: no pasa a INACTIVO, ni su Persona.
- [ ] **PE-10** (`gerencia`) NIEGA: Personas y Pacientes no aparecen y la URL da 403.
- [ ] **PE-11** (`caja1`, `medica1`, `supervision`) Pacientes. → Pueden **ver**; NIEGA crear o editar (sin botones; 403 por URL).
- [ ] **PE-12** (`compras`) NIEGA: sin acceso a pacientes ni a personas.

### 2. Agenda: turnos, entre pacientes y urgencias (`AG`)

**Qué hace.** Turnos con horario, **entre pacientes** (con cupo diario por profesional) y **urgencias** (sin horario). Agenda general
(grilla por profesional y día). Estados: PENDIENTE, CONFIRMADO, SALTADO, EN_CONSULTA, ATENDIDO, AUSENTE, CANCELADO, RETIRADO
(`docs/agenda-urgencias.md`).

**Quién puede.**

| Perfil | Turnos (`TURNOS`) | Disponibilidades (`DISPONIBILIDAD`) |
|---|---|---|
| Recepción | V C E | V C E D |
| Médico, Gerencia | V (sin Confirmar, Cancelar ni Ausente) | V |
| Los demás | nada | nada |

El Médico no administra la agenda; sí pasa **sus** turnos a ausente o cierra la jornada desde la pantalla Consulta.

**Pasos.**

- [ ] **AG-1** (`recepcion`) Nuevo turno con horario para hoy o un día próximo. → "Turno dado para el dd/mm/aaaa a las HH:MM (consultorio)."
- [ ] **AG-2** (`recepcion`) Turno en una fecha pasada. → NIEGA: "No se agendan turnos en una fecha pasada."
- [ ] **AG-3** (`recepcion`) Turno de hoy en un horario que ya pasó. → NIEGA: "Ese horario ya pasó. Elija uno posterior."
- [ ] **AG-4** (`recepcion`) Dar a la misma paciente otro turno que se superpone. → NIEGA con el aviso de superposición.
- [ ] **AG-5** (`recepcion`) Segundo turno del día con el mismo profesional. → Pide confirmación ("segundo turno del día").
- [ ] **AG-6** (`recepcion`) Turno con procedimiento **ECO-MORFO** (120 min). → Solo ofrece inicios donde caben los 120 minutos en la franja; el campo "Duración (minutos)" admite de 5 a 480; fuera de ese rango NIEGA.
- [ ] **AG-7** (`recepcion`) Agenda general en ese día. → El turno aparece en su fila de inicio con la hora de fin y, en las filas que sigue ocupando, "Continúa: PACIENTE (hasta HH:MM)".
- [ ] **AG-8** (`recepcion`) Dos turnos del mismo profesional o consultorio en horarios que se pisan. → NIEGA: "Ese horario se acaba de ocupar. Elija otro."
- [ ] **AG-9** (`recepcion`) Turnos → **Cupo entre pacientes**: fijar un cupo de hoy (0 a 30). → Se guarda; Auditoría: CREAR/EDITAR con antes y después.
- [ ] **AG-10** (`recepcion`) Cupo en un día en que el profesional no atiende. → NIEGA: "El profesional no atiende ese día." Fecha a más de 60 días: NIEGA.
- [ ] **AG-11** (`recepcion`) Agendar "Entre pacientes" más veces que el cupo. → NIEGA al pasar el cupo; bajar el cupo por debajo de los ya agendados: NIEGA.
- [ ] **AG-12** (`medica1`) Cupo entre pacientes. → Puede **ver**; NIEGA fijarlo (sin CREAR/EDITAR).
- [ ] **AG-13** (`recepcion`) Turnos → "Registrar urgencia" con una paciente activa. → Urgencia de hoy PENDIENTE, origen PRESENCIAL; aparece en "Urgencias de hoy" **sin nada clínico**.
- [ ] **AG-14** (`recepcion`) Registrar una segunda urgencia abierta de la misma paciente con el mismo profesional. → NIEGA: "La paciente ya tiene una urgencia abierta con ese profesional."
- [ ] **AG-15** (`recepcion`) Cancelar una urgencia PENDIENTE; una EN_CONSULTA no. → La primera se cancela ("Urgencia cancelada"); la otra NIEGA.
- [ ] **AG-16** (`recepcion`) Confirmar, marcar Ausente y Cancelar un turno según el estado. → Las transiciones válidas andan; las demás NIEGAN ("No se puede … un turno …").
- [ ] **AG-17** (`recepcion`) Agenda general con filtros (fecha, profesional, especialidad, estado, tipo, búsqueda). → Sin elegir estado no se muestran los cancelados; con estado/tipo/búsqueda quedan las columnas que coinciden.
- [ ] **AG-18** (`recepcion`) Agenda general: la franja "Sin horario" y "Cupo: X de Y". → Cuenta entre pacientes y urgencias.
- [ ] **AG-19** (`recepcion`) Agenda general y Turnos. → NIEGA mostrar nada clínico: solo "Sin triaje / En triaje / Con triaje"; nunca urgente marcada por el triaje, observación ni signos.
- [ ] **AG-20** (`medica1`) Turnos. → NIEGA los botones Confirmar, Cancelar y Ausente.
- [ ] **AG-21** (`caja1`, `facturacion`) Abrir Turnos por URL. → NIEGA (403).
- [ ] **AG-22** (`recepcion`) Disponibilidades: crear una disponibilidad y desactivarla. → Se desactiva (no se borra).

### 3. Triaje y atención (`TA`)

**Qué hace.** **Triaje** (en el código, "preparación"): urgente/observación, anamnesis y signos vitales antes de la consulta. **Atención**:
la consulta del profesional (motivo, anamnesis, hallazgos, diagnósticos CIE-10, indicaciones, informes de estudio, recetas) con
**autoguardado** y **Finalizar**, que crea la prestación. Nada clínico se guarda en el navegador.

**Quién puede.**

| Perfil | Triaje (`PREPARACION`) | Historia clínica (`HISTORIA_CLINICA`) |
|---|---|---|
| Enfermería | V C E (solo si su persona es personal de salud activo) | nada |
| Médico | V C E (lo suyo) | V C E (solo el profesional activo de la consulta escribe) |
| Supervisión médica | nada | V |
| Recepción, Caja, Facturación, Gerencia, Compras, Auditor | nada | nada |
| Administrador | todo, pero **no** carga triaje (no es personal de salud) | todo |

**Pasos.**

- [ ] **TA-1** (`enfermeria`) Triaje → lista de hoy de todos los profesionales (entre pacientes y urgencias con su pastilla). Orden: urgencias, urgentes marcados, resto por hora, entre pacientes.
- [ ] **TA-2** (`enfermeria`) "Iniciar triaje": marcar **Urgente** con observación, cargar anamnesis y signos vitales. → Pastilla roja URGENTE; "Cargado por" con su cargo y registro.
- [ ] **TA-3** (`enfermeria`) Temperatura 50 °C. → NIEGA: "El valor de TEMPERATURA no es posible."
- [ ] **TA-4** (`enfermeria`) Presión "80/120" (sistólica menor o igual que la diastólica). → NIEGA (imposible).
- [ ] **TA-5** (`enfermeria`) Un valor dentro del rango "imposible" pero atípico (por ejemplo, temperatura 41,5 °C). → Con los rangos aprobados: pide "Sí, es correcto"/"Corregir". **Con los rangos PENDIENTES (estado del demo): no marca nada y se ve el banner** "Los valores de referencia todavía no fueron aprobados por un médico: el sistema no marca alertas." (Lo clínico de este paso queda en "Pendiente de médico".)
- [ ] **TA-6** (`enfermeria`) "Terminar triaje", luego "Reabrir triaje". → Con triaje / En triaje.
- [ ] **TA-7** (`admin`) Intentar iniciar el triaje como Administrador. → NIEGA (403): "Solo el personal de salud puede cargar el triaje."
- [ ] **TA-8** (`recepcion`) Abrir Triaje por URL. → NIEGA (403).
- [ ] **TA-9** (`medica1`) Consulta → Atender un turno de hoy propio. → Consulta EN_CURSO; el turno, EN_CONSULTA.
- [ ] **TA-10** (`medico2`) Atender un turno de `medica1`. → NIEGA (403): solo atiende el profesional del turno.
- [ ] **TA-11** (`medica1`) Escribir motivo, anamnesis, hallazgos y diagnóstico (con buscador CIE-10); esperar el autoguardado; recargar. → Lo escrito persiste. En el navegador (almacenamiento local, sesión, IndexedDB, cookies) **no** hay datos clínicos.
- [ ] **TA-12** (`medica1`) Un informe de estudio incompleto (sin procedimiento o sin texto). → No se guarda en el borrador; al finalizar es un error.
- [ ] **TA-13** (`medica1`) Finalizar con una **receta en borrador**. → NIEGA: "Tiene recetas en borrador. Emítalas o anúlelas antes de finalizar."
- [ ] **TA-14** (`medica1`) Finalizar. → Consulta FINALIZADA; turno ATENDIDO; prestación creada; vuelve a mostrarse "Consulta cerrada: no puede modificarse." sin botones de edición.
- [ ] **TA-15** (`medica1`) Enviar otra vez "Finalizar" (doble clic o volver atrás y reenviar). → 409, sin escribir nada.
- [ ] **TA-16** (`medica1`) Con una consulta en curso, "Atender" una urgencia. → Pide confirmar: "Tiene una consulta en curso con NOMBRE. ¿Atender la urgencia igualmente?"; la primera queda abierta y se retoma con "Continuar".
- [ ] **TA-17** (`medica1`) Botones "Entre pacientes (N)" y "Urgencias (N)". → Se actualizan cada 15 s; el popup de urgencias muestra la observación del triaje (y registra un VER en Auditoría); el aviso "Nueva urgencia: APELLIDO, NOMBRE" no suena y no reaparece por la misma urgencia.
- [ ] **TA-18** (`medica1`) "No se presentó" de un turno de hoy cuya hora ya llegó; "Pasar a ausente". → Pasa a AUSENTE; su consulta en triaje pasa a ANULADO (los datos se conservan).
- [ ] **TA-19** (`medica1`) "Cerrar jornada" con una urgencia sin atender. → La vista previa muestra "Atención: urgencias sin atender"; sin tildar "Confirmo que revisé las urgencias" NIEGA.
- [ ] **TA-20** (`recepcion`) "Retiro" o "Rechazo" de un turno cuyo **triaje ya está terminado**. → Pasa a RETIRADO y deja el cargo pendiente; **cancelar o Ausente NIEGAN** en ese caso. Con el triaje solo iniciado, cancelar sí se puede.
- [ ] **TA-21** (`medica1`) "Retiros y rechazos": cargar la nota clínica de la salida. → Se guarda **una sola vez** (inmutable). Recepción no ve la nota.
- [ ] **TA-22** (`supervision`) Leer una consulta finalizada. → Solo lectura (V); sin botones de escritura.

### 4. Receta e indicaciones generales (`RE`)

**Qué hace.** Recetas con número `RE-…` (hasta 6 medicamentos por receta y 10 recetas sin anular por consulta), cada medicamento con su
**indicación de uso** (obligatoria, hasta 500 caracteres). Se crean, editan y emiten solo con la consulta EN_CURSO. PDF de la
receta en hoja A4 de dos mitades. Indicaciones generales: texto libre con PDF en hoja aparte y catálogo de dietas ("Insertar dieta").

**Quién puede.**

| Perfil | Recetas (`RECETAS`) | Dietas (`DIETAS`) |
|---|---|---|
| Médico | V C E (el que atiende) | V (usa desde la consulta) |
| Supervisión médica | V | V C E D |
| Administrador | todo | todo |
| Los demás | nada | nada |

**Pasos.**

- [ ] **RE-1** (`medica1`) Consulta en curso → nueva receta con un medicamento y su indicación de uso → guardar borrador → "Vista previa". → PDF con la marca "VISTA PREVIA - NO VÁLIDA".
- [ ] **RE-2** (`medica1`) Guardar un medicamento **sin indicación de uso**. → NIEGA: "Escriba la indicación de uso del medicamento."
- [ ] **RE-3** (`medica1`) Receta sin medicamentos. → NIEGA: "Agregue al menos un medicamento."
- [ ] **RE-4** (`medica1`) Siete medicamentos. → NIEGA: "Una receta puede tener hasta 6 medicamentos. Divida la receta en dos."
- [ ] **RE-5** (`medica1`) Receta con medicamentos tan largos que no entran en media hoja. → NIEGA con el aviso de exceso de la hoja.
- [ ] **RE-6** (`medica1`) Emitir. → Número `RE-…`; la receta pasa a EMITIDA y ya no se edita ("Una receta emitida o anulada no se modifica. Para corregirla, anúlela y emita otra.").
- [ ] **RE-7** (`medica1`) PDF de la receta emitida. → Arriba la receta (medicamentos numerados con cantidad); abajo "Indicaciones para el paciente" con el mismo número. En Auditoría, EXPORTAR "PDF de la receta RE-…".
- [ ] **RE-8** (`medica1`) "Anular y corregir" con motivo de 3 letras → NIEGA (mínimo 5). Con motivo: la receta queda ANULADA y se crea un borrador que la reemplaza.
- [ ] **RE-9** (`medica1`) Anular una receta ya anulada. → NIEGA: "Esta receta ya está anulada."
- [ ] **RE-10** (`medica1`) Con la consulta **finalizada**: anular una receta emitida → se puede; crear, editar, emitir o "Anular y corregir" → NIEGA (409/aviso: solo en curso).
- [ ] **RE-11** (`medico2`) Crear o emitir una receta en una consulta de `medica1`. → NIEGA (403).
- [ ] **RE-12** (`supervision`) Receta emitida. → Puede ver; NIEGA crear, emitir o anular.
- [ ] **RE-13** (`recepcion`) Abrir una receta por URL. → NIEGA (403).
- [ ] **RE-14** (`medica1`) Una receta emitida **antes** de la indicación de uso (si hay en la base). → Se reimprime desde el navegador, sin indicación retroactiva.
- [ ] **RE-15** (`medica1`) Indicaciones generales: cargar una indicación de tipo "Dieta" y "Insertar dieta" (con una dieta activa del catálogo). → El texto se **copia** al final de la indicación y sigue siendo editable.
- [ ] **RE-16** (`medica1`) Insertar una dieta cuyo texto no entra en los 3000 caracteres de la indicación. → NIEGA: no se inserta ni se corta, y se avisa.
- [ ] **RE-17** (`supervision`) Editar o desactivar la dieta usada. → La indicación ya guardada **no cambia**; la dieta desactivada deja de ofrecerse.
- [ ] **RE-18** (`medica1`) "PDF de indicaciones" con indicaciones vigentes. → Hoja aparte con clínica, paciente, profesional, fecha e indicaciones vigentes (también las de tipo Reposo; no las retiradas); **nunca** medicamentos, diagnósticos, motivo ni anamnesis. EXPORTAR en Auditoría.
- [ ] **RE-19** (`medica1`) "PDF de indicaciones" sin indicaciones vigentes. → Aviso "Esta consulta no tiene indicaciones generales vigentes…", sin PDF vacío y sin registro.
- [ ] **RE-20** (`recepcion`, `medico2`) Pedir el PDF de indicaciones de esa consulta. → NIEGA (403).
- [ ] **RE-21** (`medica1`) Con la consulta cerrada: "Insertar dieta" o editar una indicación. → NIEGA (solo lectura).

### 5. Reposo médico (`RM`)

**Qué hace.** Certificado `RM-0000001`, documento **aparte de la consulta**, inmutable, con snapshot y huella sha256; nace emitido y
solo se anula con motivo. Se imprime en PDF al vuelo.

**Quién puede** (`REPOSO_MEDICO`).

| | Emitir | Ver y PDF | Anular |
|---|---|---|---|
| Profesional activo de la consulta (Médico: V C E) | sí | sí | los que emitió |
| Administrador | **no** | sí | sí |
| Supervisión médica (V) | no | sí | no |
| Otro médico, Enfermería, Recepción y los demás | no | no | no |

**Pasos.**

- [ ] **RM-1** (`medica1`) En la barra del paciente, "Reposo médico" (otra pestaña) → emitir: días 3, desde hoy, modalidad "reposo absoluto". → `RM-…`, "hasta" = desde + 3 − 1, ambas inclusive.
- [ ] **RM-2** (`medica1`) "Desde" anterior a hoy. → NIEGA. Días 0 → NIEGA.
- [ ] **RM-3** (`medica1`) Marcar un diagnóstico para imprimir. → Sale con código y descripción del CIE-10; por defecto **se omite**. Nunca salen motivo, anamnesis, signos ni medicamentos.
- [ ] **RM-4** (`medica1`) Prórroga: indicar el número de un certificado anterior del mismo paciente con "desde" posterior al "hasta" anterior. → Se emite. Con "desde" no posterior, o de otro paciente: NIEGA.
- [ ] **RM-5** (`medica1`) PDF. → Lleva la marca **BORRADOR SUJETO A REVISIÓN** (cartel, pie y diagonal) mientras `reposo.borrador` sea `true`. EXPORTAR en Auditoría.
- [ ] **RM-6** (`medica1`) Anular con motivo de 3 letras → NIEGA; con 5 a 300 letras: ANULADO, el PDF sale marcado ANULADO.
- [ ] **RM-7** (`medica1`) Emitir con la consulta en triaje o anulada. → NIEGA. Con la consulta finalizada: se puede.
- [ ] **RM-8** (`supervision`) Ver y sacar el PDF. → Se puede; NIEGA emitir (aunque tuviera CREAR) y anular.
- [ ] **RM-9** (`admin`) Anular un certificado emitido por otro. → Se puede; NIEGA emitir.
- [ ] **RM-10** (`medico2`, `enfermeria`, `recepcion`) Abrir un certificado ajeno por URL. → NIEGA (403/404).
- [ ] **RM-11** (`auditor`) Auditoría de `reposos_medicos`. → Sin VER sobre HISTORIA_CLINICA los valores están ocultos.

### 6. Órdenes de estudio e informes de estudio (`OE`)

**Qué hace.** **Orden de estudio** `OE-0000001`: documento aparte, inmutable, con snapshot y huella, hasta 30 estudios (del catálogo o escritos
a mano), indicación clínica opcional y diagnóstico presuntivo opcional. **Informe de estudio**: lo escribe el profesional dentro de la
consulta (texto libre hasta 5000 caracteres), hasta 10 vigentes, con PDF por informe; cada informe vigente con procedimiento genera
su prestación al finalizar. Catálogo de estudios (`ESTUDIOS`).

**Quién puede.**

| Perfil | Órdenes (`ORDENES_ESTUDIO`) | Estudios (`ESTUDIOS`) | Informes |
|---|---|---|---|
| Médico | V C E (emite y anula las suyas) | V | Los escribe solo el profesional activo de la consulta |
| Supervisión médica | V | V C E D | V (lectura) |
| Administrador | V, anular; **no** emite | todo | no escribe |
| Los demás | nada | nada | Recepción: nada |

**Pasos.**

- [ ] **OE-1** (`supervision`) Catálogos → Estudios: alta, edición y desactivación. → Se desactiva (no se borra); queda en Auditoría.
- [ ] **OE-2** (`medica1`) "Pedir estudio" → orden con un estudio del catálogo y uno escrito a mano. → `OE-…`; los de catálogo primero, los escritos a mano después; el escrito a mano **no** se agrega al catálogo.
- [ ] **OE-3** (`medica1`) Orden sin estudios, o con 31. → NIEGA.
- [ ] **OE-4** (`medica1`) Desactivar luego un estudio del catálogo. → La orden emitida **no cambia** (el nombre quedó en el snapshot).
- [ ] **OE-5** (`medica1`) PDF de la orden. → Marca **BORRADOR SUJETO A REVISIÓN** en el cartel y el pie, **sin** diagonal; sin motivo, anamnesis, signos, recetas ni indicaciones. EXPORTAR en Auditoría.
- [ ] **OE-6** (`medica1`) Anular con motivo de 3 letras → NIEGA; con motivo válido: ANULADA, PDF marcado ANULADA.
- [ ] **OE-7** (`supervision`, `admin`) Emitir. → NIEGA (Supervisión y Administrador no emiten). Ver y PDF: sí.
- [ ] **OE-8** (`medico2`, `enfermeria`, `recepcion`) Abrir la orden por URL. → NIEGA.
- [ ] **OE-9** (`medica1`) En la sección **Estudios** de la atención, agregar un informe con procedimiento de tipo ESTUDIO (activo), solicitante opcional y texto. → Autoguardado; "PDF del informe" disponible.
- [ ] **OE-10** (`medica1`) Informe sin procedimiento o sin texto. → Fila incompleta: no se guarda en borrador; al finalizar es error.
- [ ] **OE-11** (`medica1`) Retirar un informe y reponerlo. → Se retira (`activo = false`), no se borra; se puede reponer.
- [ ] **OE-12** (`medica1`) PDF del informe. → Clínica, paciente, profesional, fecha, estudio, solicitante, texto tal cual y líneas de firma; **nunca** diagnósticos ni medicamentos. EXPORTAR en Auditoría.
- [ ] **OE-13** (`enfermeria`, `medico2`, `admin`, `recepcion`) Escribir un informe en una consulta ajena. → NIEGA.
- [ ] **OE-14** (`recepcion`) Ver el informe en la consulta. → NIEGA: no ve nada clínico.
- [ ] **OE-15** (`medica1`) Informe con la consulta cerrada. → Solo lectura; solo se corrige por adenda o desbloqueo.

### 7. Constancias y retiros (`CT`)

**Qué hace.** Dos constancias inmutables con snapshot y huella: **de retiro** `CR-0000001` (Recepción, sobre un turno RETIRADO) y **de estado
del turno** `CE-0000001` (profesional del turno o Supervisión médica; turnos ATENDIDO, AUSENTE o RETIRADO). Nada clínico. Salen con
la marca **BORRADOR SUJETO A REVISIÓN** mientras `constancias.borrador` sea `true`. Una sola EMITIDA por turno y tipo.
Los **retiros y rechazos** pasan el turno a RETIRADO (`docs/retiros.md`).

**Quién puede.**

| Perfil | `CONSTANCIAS_RETIRO` | `CONSTANCIAS_ESTADO` |
|---|---|---|
| Recepción | V C E | nada |
| Médico (profesional activo del turno) | nada | V C E |
| Supervisión médica | nada | V C E |
| Otro médico, Caja, Facturación y los demás | nada | nada |

**Pasos.**

- [ ] **CT-1** (`recepcion`) Turnos → un turno con **Retiro** → "Constancia de retiro". → `CR-…` con paciente (nombre y documento), clínica, fecha y hora del hecho, fecha y hora del turno, y si fue retiro o rechazo. **Nada clínico.**
- [ ] **CT-2** (`recepcion`) Emitir de nuevo la del mismo turno. → Abre la existente (no duplica).
- [ ] **CT-3** (`recepcion`) Anular con motivo y emitir otra. → Se puede (anulada, nueva emitida).
- [ ] **CT-4** (`recepcion`) Constancia de retiro de un turno que **no** está RETIRADO. → NIEGA.
- [ ] **CT-5** (`medica1`) Constancia de estado de un turno **ATENDIDO** propio. → `CE-…` con profesional (nombre y matrícula); sin diagnóstico.
- [ ] **CT-6** (`medica1`) Constancia de estado de un turno PENDIENTE, CONFIRMADO, EN_CONSULTA o CANCELADO. → NIEGA.
- [ ] **CT-7** (`medico2`) Constancia de estado de un turno de `medica1` (emitir, leer o anular). → NIEGA.
- [ ] **CT-8** (`supervision`) Emitir desde la pantalla de la consulta. → Se puede (no ve Turnos).
- [ ] **CT-9** (`recepcion`) Constancia de estado. → NIEGA (no tiene el módulo).
- [ ] **CT-10** (cualquiera) Abrir la hoja de una constancia. → Muestra la marca de borrador; si la huella no coincide, avisa y no imprime.
- [ ] **CT-11** (`recepcion`) Retiro: botones "Retiro" y "Rechazo" solo en turnos PENDIENTE, CONFIRMADO o SALTADO de hoy o días anteriores. → En otros estados NIEGA.

### 8. Correcciones de la historia clínica (`CO`)

**Qué hace.** Una consulta cerrada (FINALIZADA o ANULADA) es de **solo lectura**, con todo lo que cuelga de ella; nada se borra. Las
correcciones autorizadas son la **adenda** (nota aparte) y el **desbloqueo** (ventana de un solo uso, 24 horas). Módulo
`CORRECCIONES_HC` (sensible).

**Quién puede.**

| Perfil | `CORRECCIONES_HC` |
|---|---|
| Supervisión médica | V C E |
| Administrador | todo (nunca desbloquea lo que atendió) |
| Médico | no tiene el módulo, pero agrega adenda a **su** consulta (CREAR sobre `HISTORIA_CLINICA` y ser el profesional) |
| Los demás | nada |

**Pasos.**

- [ ] **CO-1** (`medica1`) En una consulta propia finalizada, intentar modificar cualquier campo (por la pantalla o repitiendo un envío). → NIEGA: "La consulta está cerrada y no puede modificarse." (409).
- [ ] **CO-2** (`medica1`) Agregar una **adenda** con motivo de 10 a 300 caracteres y texto de hasta 3000. → Se ve debajo de lo original con autor y fecha; no se edita ni se borra.
- [ ] **CO-3** (`medica1`) Adenda con motivo de 5 caracteres. → NIEGA.
- [ ] **CO-4** (`medico2`) Adenda en una consulta de `medica1`. → NIEGA.
- [ ] **CO-5** (`supervision`) Adenda en cualquier consulta finalizada. → Se puede (CREAR sobre `CORRECCIONES_HC`).
- [ ] **CO-6** (`supervision`) **Desbloquear** una consulta que atendió `medica1` con motivo de 20 a 500 caracteres. → Ventana VIGENTE por 24 horas.
- [ ] **CO-7** (`medica1`) Desbloquear su propia consulta (si tuviera el permiso). → NIEGA: "Esta consulta la atendió usted: otro supervisor tiene que desbloquearla." El Administrador tampoco desbloquea lo que atendió.
- [ ] **CO-8** (`supervision`) Segundo desbloqueo de la misma consulta con la primera vigente. → NIEGA (una por consulta a la vez). Cancelar la primera: se puede.
- [ ] **CO-9** (`supervision`) Con la ventana vigente, corregir motivo, anamnesis, hallazgos, indicaciones, diagnósticos o informes de estudio y guardar. → La ventana pasa a REALIZADO; la consulta queda cerrada; se guarda una **versión** con el antes; la lectura dice "Corregida N veces".
- [ ] **CO-10** (`supervision`) Intentar cambiar signos vitales, triaje, recetas, paciente, profesional, fechas o estado. → NIEGA entero (lista blanca).
- [ ] **CO-11** (`supervision`) Guardar la corrección sin cambiar nada. → La ventana **no se consume**.
- [ ] **CO-12** (`supervision`) Usar una ventana pasadas las 24 horas. → NIEGA: VENCIDO.
- [ ] **CO-13** (`supervision`) "Historial de correcciones" con el antes de cada versión y el motivo. → Visible con VER sobre `CORRECCIONES_HC`.
- [ ] **CO-14** (`auditor`) Auditoría de adendas, desbloqueos y versiones. → Hay eventos pero el contenido está oculto (sin VER sobre `HISTORIA_CLINICA`).

### 9. PDF clínico (`PD`)

**Qué hace.** PDF con dompdf, **al vuelo** (no se guarda en el servidor ni en el navegador: `no-store`): triaje, consulta, indicaciones, informe
de estudio, receta, reposo, orden, ticket de visación, planilla de liquidación y factura. Cada generación deja un EXPORTAR en Auditoría.

**Quién puede** (PDF de triaje y de consulta: `PDF_CLINICO` X **y** ser Administrador, Supervisión médica o el profesional activo; el PDF de
triaje, además, el personal de salud que cargó algo del triaje).

| Perfil | `PDF_CLINICO` |
|---|---|
| Médico, Enfermería, Supervisión médica | X |
| Administrador | todo |
| Recepción y los demás | nada |

**Pasos.**

- [ ] **PD-1** (`medica1`) "PDF de la consulta" de una consulta propia. → Secciones con motivo, urgente/observación, anamnesis, signos (con la etiqueta guardada), hallazgos y diagnósticos; secciones vacías omitidas; encabezado con quién lo generó y cuándo.
- [ ] **PD-2** (`enfermeria`) "PDF de triaje" de una consulta donde cargó algo. → Se genera; sin motivo ni diagnósticos.
- [ ] **PD-3** (`enfermeria`) "PDF de la consulta". → NIEGA (403).
- [ ] **PD-4** (`recepcion`) Cualquier PDF clínico. → NIEGA (403), y no queda registro de generación.
- [ ] **PD-5** (`medico2`) PDF de una consulta de `medica1`. → NIEGA.
- [ ] **PD-6** (cualquiera) Generar dos veces el mismo PDF. → Dos eventos EXPORTAR en Auditoría (no se agrupan).
- [ ] **PD-7** (cualquiera) Pedir un PDF de una consulta anulada. → 404.
- [ ] **PD-8** (cualquiera) Inspeccionar las cabeceras de un PDF. → `Cache-Control: no-store, private`, `Pragma: no-cache`, `X-Content-Type-Options: nosniff`.
- [ ] **PD-9** (cualquiera) Revisar los PDF **en papel** (receta, consulta, triaje, informe, reposo, orden, factura, ticket, planilla). → El diseño es sobrio y provisorio (ver "Pendiente de médico" y `docs/produccion.md`).

### 10. Tarifas y prestaciones (`TP`)

**Qué hace.** **Tarifas** versionadas por procedimiento y profesional (vacío = general) en guaraníes; **cambiar tarifa** no edita: cierra
la vigente y abre otra desde hoy o después. **Prestación**: nace al FINALIZAR la consulta, con el monto de la tarifa del día del
servicio (TARIFADO) o sin tarifa (PENDIENTE de tarifar); se anula con motivo; nunca impide finalizar.

**Quién puede.**

| Perfil | Tarifas (`TARIFAS`) | Prestaciones (`PRESTACIONES`) |
|---|---|---|
| Gerencia | V C E | V E D |
| Recepción, Caja, Facturación | V | V |
| Médico, Enfermería, Supervisión, Auditor, Compras | nada | nada |
| Administrador | todo | todo |

**Pasos.**

- [ ] **TP-1** (`gerencia`) Económico → Tarifas: alta de una tarifa general para PAP-COLPO. → VIGENTE; en Auditoría, CREAR.
- [ ] **TP-2** (`gerencia`) Alta de una segunda tarifa para el mismo procedimiento y profesional. → NIEGA: "Use Cambiar tarifa".
- [ ] **TP-3** (`gerencia`) "Cambiar tarifa" con **el mismo monto**. → NIEGA. Con otro monto y fecha de hoy o futura: la anterior pasa a HISTORICO con "hasta" = el día anterior.
- [ ] **TP-4** (`gerencia`) Cambiar con fecha anterior a hoy, o antes de que empiece la que se cierra. → NIEGA.
- [ ] **TP-5** (`gerencia`) "Historial" del procedimiento. → Todas las versiones, sin huecos ni solapes.
- [ ] **TP-6** (`gerencia`) Tarifa del profesional (Dra. Benítez) para un procedimiento. → Se usa la del profesional; sin ella, la general.
- [ ] **TP-7** (`recepcion`, `caja1`, `facturacion`) Tarifas. → Solo ver; NIEGAN crear y cambiar.
- [ ] **TP-8** (`medica1`) Menú Económico. → NIEGA: no tiene los módulos; la pantalla de atención no muestra montos.
- [ ] **TP-9** (`gerencia`) Prestaciones: filtros (fecha de servicio, profesional, estado, origen, paciente). → Origen "Consulta/Turno" o "Informe de estudio".
- [ ] **TP-10** (`gerencia`) Prestación PENDIENTE de tarifar → "Tarifar". → Con la tarifa de su **fecha de servicio**; el monto no cambia si la tarifa cambia después.
- [ ] **TP-11** (`gerencia`) "Consultas sin prestación" (solo finalizadas desde el hito): botón "Generar". → Crea la prestación con el servicio de siempre; hacerlo dos veces no duplica. Lo ven todos los que ven Prestaciones, pero "Generar" solo Gerencia y Administrador.
- [ ] **TP-12** (`gerencia`) Anular una prestación con motivo de 3 letras → NIEGA; con 5 a 300 caracteres: ANULADO (no se borra).
- [ ] **TP-13** (`gerencia`) Anular una prestación **cobrada**, **facturada** o **en una liquidación**. → NIEGA con el aviso que corresponde.
- [ ] **TP-14** (`auditor`) Auditoría de `tarifas` y `prestaciones`. → "Contenido económico" oculto (no tiene VER sobre esos módulos).
- [ ] **TP-15** (`recepcion`) Prestaciones. → Ve el estado de cobro, no el reparto ni la sección de reparto.

### 11. Reparto económico y equipos (`RP`)

**Qué hace.** **Acuerdos de reparto** (porcentajes) del profesional (general o por procedimiento) y del equipo; **equipos** con dueño (un
proveedor) y los procedimientos que usan; **reparto** de cada prestación, fijado al crearse. Clínica = 100 − profesional − equipo.

**Quién puede.**

| Perfil | `ACUERDOS_REPARTO` | `REPARTOS` | `EQUIPOS` | `TIPOS_EQUIPO` |
|---|---|---|---|---|
| Gerencia | V C E | V | V C E D | V C E D |
| Administrador | todo | todo | todo | todo |
| Los demás | nada | nada | nada | nada |

**Pasos.**

- [ ] **RP-1** (`gerencia`) Acuerdos de reparto → acuerdos vigentes. → Dra. Benítez: 60 % general y 40 % en ECO-CROMO; ecógrafo: 30 %. Dr. Duarte: ninguno.
- [ ] **RP-2** (`gerencia`) "Nuevo acuerdo" duplicado (misma clave). → NIEGA: "Use Cambiar porcentaje".
- [ ] **RP-3** (`gerencia`) Porcentaje mayor que 100 o con más de dos decimales. → NIEGA. "Cambiar porcentaje" con el mismo valor: NIEGA.
- [ ] **RP-4** (`gerencia`) Cambiar el porcentaje. → El anterior pasa a HISTORICO; **los repartos ya hechos no cambian**.
- [ ] **RP-5** (`gerencia`) Equipos: asignar el mismo procedimiento a un segundo equipo **activo**. → NIEGA: un procedimiento usa como máximo un equipo activo. Un equipo inactivo no reparte.
- [ ] **RP-6** (`gerencia`) Prestación con acuerdo del profesional y del equipo: ver las líneas. → Profesional, equipo y clínica con porcentajes y montos; la suma da el total; si el profesional es también el dueño del equipo, **dos líneas**.
- [ ] **RP-7** (`gerencia`) Redondeo: un monto cuyo reparto no es exacto. → Profesional y equipo al guaraní más cercano (mitad hacia arriba); la clínica recibe el resto.
- [ ] **RP-8** (`gerencia`) Prestación sin acuerdo del profesional (Dr. Duarte). → "Pendiente de reparto" con el motivo; aviso y filtro "Pendientes de reparto"; **Repartir** tras cargar el acuerdo.
- [ ] **RP-9** (`gerencia`) Anular la prestación. → Su reparto queda ANULADO ("Prestación anulada: motivo") y las líneas se conservan.
- [ ] **RP-10** (`gerencia`) "Aplicar visación" que cambia el total. → El reparto anterior se anula y se calcula otro sobre el total nuevo.
- [ ] **RP-11** (`recepcion`, `caja1`, `medica1`) Ver porcentajes o reparto. → NIEGA (sin el módulo).
- [ ] **RP-12** (`auditor`) Auditoría de `acuerdos_reparto`, `repartos_prestacion` y `lineas_reparto`. → "Contenido económico" oculto.

### 12. Cobros y caja (`CC`)

**Qué hace.** **Medios de pago** (Efectivo, Transferencia, Tarjeta; catálogo editable), **caja** de cada cajero (abrir, cobrar, cerrar), **cobro**
completo de prestaciones de una paciente (hasta tres pagos combinados) y **revisión** del cierre por Gerencia. Desde el hito FACTURACION
cada cobro lleva su factura (ver `FT`). Los recorridos 3 y 4 cubren la reversión y el cierre.

**Quién puede.**

| Perfil | `CAJAS` | `CIERRES_CAJA` | `COBROS` |
|---|---|---|---|
| Caja | V C E (las propias) | nada | V C D |
| Gerencia | V (todas) | V E | V |
| Facturación y convenios | nada | nada | V |
| Recepción, Médico y los demás | nada | nada | nada |

**Pasos.**

- [ ] **CC-1** (`caja1`) Cobrar sin caja abierta. → NIEGA: "Abra su caja antes de cobrar."
- [ ] **CC-2** (`caja1`) Abrir la caja con un monto inicial negativo. → NIEGA (cero o más).
- [ ] **CC-3** (`caja1`) Cobrar: buscar la paciente (la pantalla lista las que tienen prestaciones sin cobrar), tildar sus prestaciones y cargar hasta tres pagos. → El total es la suma exacta de lo que paga la paciente; con JavaScript se ve lo que falta o sobra.
- [ ] **CC-4** (`caja1`) Pagos que **no suman** el total. → NIEGA (sin pagos parciales ni vuelto).
- [ ] **CC-5** (`caja1`) Referencia de transferencia de más de 100 caracteres. → NIEGA.
- [ ] **CC-6** (`caja1`) Con el JavaScript desactivado. → El flujo funciona igual (el servidor verifica todo).
- [ ] **CC-7** (`caja1`) Cobrar una prestación de otra paciente mezclada, una anulada o una pendiente de tarifar. → NIEGA (misma paciente, con monto, no anulada).
- [ ] **CC-8** (`caja1`) Cobrar la misma prestación desde dos pestañas a la vez. → Solo gana uno; el otro NIEGA (un cobro vigente por prestación).
- [ ] **CC-9** (`caja1`) Desactivar un medio de pago (como Administrador o desde Catálogos) y cobrar con él. → NIEGA: un medio inactivo no se acepta.
- [ ] **CC-10** (`caja1`) Cerrar con diferencia y sin motivo → NIEGA; con motivo, CERRADA (ver R4).
- [ ] **CC-11** (`caja1`) Cierre con un medio no efectivo en negativo (si hubo devoluciones). → Se declara con signo ("-150.000"); el efectivo contado negativo NIEGA: "Lo contado en efectivo no puede ser negativo."
- [ ] **CC-12** (`caja1`) NIEGA ver la caja de otro cajero (solo las propias).
- [ ] **CC-13** (`gerencia`) Ver todas las cajas, filtrar por estado y revisar. → Aprobar/Devolver (ver R4). NIEGA: abrir caja o cobrar (solo V).
- [ ] **CC-14** (`caja1`) Cobrar con la caja de un **día anterior** abierta. → NIEGA (ver R4-10).
- [ ] **CC-15** (`auditor`) Auditoría de `cajas`, `cobros` y `lineas_cobro`. → Montos ocultos ("Contenido económico").
- [ ] **CC-16** (`recepcion`) En Prestaciones. → Ve el estado de cobro (Cobrada, Pendiente, A cargo de aseguradora); NIEGA ver Cobros y Cajas.

### 13. Reversiones de cobro (`RV`)

**Qué hace.** Revertir un cobro **completo y una sola vez**; queda REVERTIDO con motivo (5 a 300). **Directa** si es un cobro de la caja propia
que sigue abierta y es de hoy; **con aprobación** (solicitud) en los demás casos. Solo para cobros **sin factura** (los con factura se
revierten anulando la factura). Detalle en el recorrido 3.

**Quién puede.**

| Perfil | `COBROS` (revertir o solicitar) | `REVERSIONES_COBRO` (aprobar o rechazar) |
|---|---|---|
| Caja | D | nada |
| Gerencia | V | V E |
| Administrador | todo | todo |
| Los demás | nada | nada |

**Pasos.**

- **RV-1 a RV-11** son los pasos **R3-1 a R3-11** (recorrido 3); se marcan allá.
- [ ] **RV-12** (`caja1`) Revertir directamente un cobro de una caja abierta de un **día anterior**. → NIEGA: primero se cierra esa caja.
- [ ] **RV-13** (`caja1`) Revertir un cobro ya REVERTIDO. → NIEGA (una sola vez).
- [ ] **RV-14** (`caja1`) Revertir solo una de las prestaciones de un cobro. → NIEGA: se revierte completo.
- [ ] **RV-15** (`auditor`) Auditoría. → REVERTIR "Reversión del cobro. Motivo: …", CREAR/EDITAR de la solicitud; el contenido está oculto.

### 14. Convenios, coberturas y visaciones (`CV`)

**Qué hace.** **Convenio** con una aseguradora (responsable de pago) → planes → tarifas del convenio (versionadas); **cobertura** de la paciente
(plan, número de asegurado, vigencia); **visación** (autorización por caso: número, resultado APROBADO o NO_CUBIERTO y qué paga la
paciente: NINGUNO, PORCENTAJE o MONTO_FIJO). Define cómo se parte la prestación (paciente/aseguradora). **Firma recibida** y **ticket**.

**Quién puede.**

| Perfil | `CONVENIOS` | `COBERTURAS` | `VISACIONES` |
|---|---|---|---|
| Recepción | nada | V C E | V C E D X |
| Facturación y convenios | V C E D | V C E | V C E D X |
| Gerencia | V C E D | V | V X |
| Caja, Médico, Enfermería y los demás | nada | nada | nada |

**Pasos.**

- [ ] **CV-1** (`facturacion`) Convenios → "Nuevo convenio" con planes y tarifa de Consulta. → Alta en un paso; CREAR en Auditoría.
- [ ] **CV-2** (`facturacion`) Cargar una tarifa del convenio para un procedimiento que ya tiene una. → Cierra la vigente (HISTORICO) y abre otra desde hoy o después.
- [ ] **CV-3** (`facturacion`) "Dar de baja" la tarifa. → Se cierra con su último día; el procedimiento pasa a la tarifa general.
- [ ] **CV-4** (`facturacion`) Desactivar un plan y "Pasar a histórico" el convenio. → Se desactiva/pasa a histórico (no se borra).
- [ ] **CV-5** (`recepcion`) Cobertura y visaciones de una paciente: cargar una cobertura nueva. → Cierra la vigente (queda en el historial); una sola abierta por paciente.
- [ ] **CV-6** (`recepcion`) Cargar una cobertura nueva con fechas que pisan la anterior. → Nunca quedan dos abiertas ni rangos solapados de la misma paciente.
- [ ] **CV-7** (`recepcion`) Cargar una visación sin cobertura vigente (los datos de la cobertura van arriba del mismo formulario). → Se guardan cobertura y visación en **una sola transacción** (si falla una, no queda la otra).
- [ ] **CV-8** (`recepcion`) Visación con **número repetido** para la misma aseguradora (entre las no anuladas). → NIEGA.
- [ ] **CV-9** (`recepcion`) Visación con **más de cinco líneas**, o con porcentaje mayor que 100. → NIEGA.
- [ ] **CV-10** (`recepcion`) Visación NO_CUBIERTO finalizada la consulta. → La prestación se cobra completa a la paciente (tarifa **general**, pagador PACIENTE) con referencia a la visación.
- [ ] **CV-11** (`recepcion`) Paciente con cobertura **vencida** (la 4) o sin visación para el procedimiento. → Prestación particular con **aviso** ("La cobertura de la paciente venció el …" / "La visación N.º X no incluye este procedimiento" / "… no hay visación"). Sin cobertura: sin aviso. Nunca frena la atención.
- [ ] **CV-12** (`recepcion`) "Aplicar visación" a una prestación ya finalizada, con motivo. → Se recalcula el snapshot; el estado anterior queda en Auditoría; la firma vuelve a pedirse.
- [ ] **CV-13** (`recepcion`) "Aplicar visación" sobre una prestación **ya cobrada**, **facturada** o **en una liquidación no anulada**. → NIEGA (revertir el cobro / anular la factura / quitarla de la liquidación primero).
- [ ] **CV-14** (`recepcion`) Anular una visación con motivo. → ANULADA; la prestación ya aplicada queda como está con un aviso (corrección manual).
- [ ] **CV-15** (`recepcion`) Marcar y desmarcar la firma recibida. → Se desmarca con motivo y solo mientras no esté en una liquidación no anulada.
- [ ] **CV-16** (`recepcion`) Ticket. → PDF sin montos ni nada clínico; EXPORTAR en Auditoría (ver R1-8).
- [ ] **CV-17** (`recepcion`) En Agenda general, Turnos y Urgencias de hoy: botón "Visación" y las etiquetas "Seguro: …", "Visación cargada" o "Falta visación". → Sin montos ni bloqueo de las acciones del turno. "Cupo entre pacientes" no lleva el botón.
- [ ] **CV-18** (`recepcion`) Convenios y tarifas del convenio. → NIEGA (sin el módulo).
- [ ] **CV-19** (`caja1`, `medica1`) Visaciones y coberturas. → NIEGA.
- [ ] **CV-20** (`gerencia`) Visaciones. → Solo ver y exportar el ticket; NIEGA cargar o anular.
- [ ] **CV-21** (`auditor`) Auditoría de `convenios`, `tarifas_convenio`, `coberturas_paciente` y `visaciones`. → "Contenido económico" oculto.

### 15. Liquidaciones a aseguradoras (`LQ`)

**Qué hace.** **Liquidación** mensual por aseguradora de las prestaciones con pagador ASEGURADORA, con firma recibida y sin liquidar; flujo
EN_PREPARACION → PENDIENTE_APROBACION → APROBADO → PARCIAL → PAGADO (o ANULADO); **pagos** parciales de la aseguradora (no pasan por Caja);
planilla en PDF. El recorrido 6 cubre el flujo.

**Quién puede.**

| Perfil | `LIQUIDACIONES` | `APROBACION_LIQUIDACIONES` | `PAGOS_ASEGURADORAS` |
|---|---|---|---|
| Facturación y convenios | V C E D X | nada | V C D |
| Gerencia | V D X | V E | V |
| Administrador | todo | todo | todo |
| Recepción, Caja, Médico, Enfermería, Compras | nada | nada | nada |

**Pasos.**

- **LQ-1 a LQ-16** son los pasos **R6-1 a R6-16** (recorrido 6); se marcan allá.
- [ ] **LQ-17** (`facturacion`) Armar una liquidación sin prestaciones que cumplan (sin firma o ya liquidadas). → NIEGA / vista previa vacía; "Enviar a aprobación" necesita al menos una línea.
- [ ] **LQ-18** (`facturacion`) Prestaciones particulares, NO_CUBIERTO o con la parte de la paciente completa. → Nunca entran.
- [ ] **LQ-19** (`facturacion`) Anular una liquidación no aprobada con motivo. → ANULADO; sus líneas ANULADA y las prestaciones quedan libres.
- [ ] **LQ-20** (`gerencia`) Anular una liquidación aprobada con motivo y sin pagos vigentes. → Se puede (solo Gerencia o Administrador).
- [ ] **LQ-21** (`facturacion`) Confirmar el armado cuando la lista cambió desde la vista previa. → Pide revisar la vista previa.
- [ ] **LQ-22** (`caja1`) Cobrar la parte de la paciente de una prestación ya liquidada. → Se puede (el cobro de la paciente no cambia).
- [ ] **LQ-23** (`auditor`) Auditoría de `liquidaciones`, `lineas_liquidacion` y `pagos_aseguradora`. → Montos ocultos.

### 16. Facturación y timbrado (`FT`)

**Qué hace.** La **clínica** como emisor (datos fiscales), el **timbrado**, la **factura individual CONTADO** con **IVA** (10 %, 5 % o exenta) y
el cobro de Caja **contra factura**. La emisión es **simulada** ("SIMULACIÓN, SIN VALIDEZ FISCAL"). Anulación directa dentro de los
10 minutos o con solicitud aprobada por Gerencia.

**Quién puede.**

| Perfil | `FACTURAS` | `ANULACIONES_FACTURA` | `TIMBRADOS` | `DATOS_FISCALES` | `MOTIVOS_ANULACION` |
|---|---|---|---|---|---|
| Caja | V C D X | nada | nada | nada | nada |
| Gerencia | V X | V E | V C E | V E | V C E D |
| Facturación y convenios | V X | nada | nada | nada | nada |
| Administrador | todo | todo | todo | todo | todo |
| Recepción, Médico y los demás | nada | nada | nada | nada | nada |

**Pasos.**

- [ ] **FT-1** (`gerencia`) Facturas → Datos de la clínica (RUC con dígito, razón social, actividad, dirección fiscal). → Se guarda; RUC con dígito verificador incorrecto NIEGA; una sola clínica por instalación.
- [ ] **FT-2** (`gerencia`) Facturas → Timbrados: cargar un timbrado (8 dígitos, vigencia, 001-001, rango inicial y final) con otro vigente en el mismo punto. → El anterior pasa a histórico en la misma operación.
- [ ] **FT-3** (`gerencia`) Cobrar sin timbrado vigente (pasarlo a histórico antes). → La pantalla **avisa antes** y no emite; no queda factura, número usado, cobro ni movimiento.
- [ ] **FT-4** (`gerencia`) Cargar un timbrado **vencido** o que todavía no rige. → No se emite con él. Un timbrado **agotado** (llegó al número final): tampoco.
- [ ] **FT-5** (`caja1`) Cobrar a una paciente **sin documento**. → NIEGA: sin factura "sin nombre".
- [ ] **FT-6** (`caja1`) Cobrar sin datos fiscales de la clínica. → NIEGA: "Faltan los datos fiscales de la clínica…".
- [ ] **FT-7** (`caja1`) Numeración: emitir dos facturas seguidas. → `001-001-0000001`, `001-001-0000002`, sin saltos ni repetidos; un número anulado no se reutiliza.
- [ ] **FT-8** (`caja1`) IVA: cobrar un procedimiento de ₲ 350.000 con 10 %. → IVA = monto / 11 al guaraní entero (₲ 31.818); gravado ₲ 318.182; totales por tasa que suman el total.
- [ ] **FT-9** (`gerencia`) Facturas → Tasas de IVA: cambiar la tasa de un procedimiento a 5 %. → Las facturas emitidas **no cambian** (la tasa se copió a la línea); la nueva factura usa 5 % (monto / 21). Auditoría: "Cambio de la tasa de IVA".
- [ ] **FT-10** (`caja1`) Factura de una prestación con **copago**. → La factura lleva solo la parte de la paciente; nunca la de la aseguradora.
- [ ] **FT-11** (`caja1`) "Aplicar visación" o anular una prestación **facturada**. → NIEGA: "Esta prestación está facturada (factura 001-001-…): … Primero se anula la factura." La **firma recibida** sí se puede marcar.
- [ ] **FT-12** (`caja1`) Una misma parte de una prestación en dos facturas vigentes. → NIEGA (una parte se factura una sola vez; liberada al anular la factura).
- **FT-13** Anulación directa y por solicitud, reemplazo y bloqueos: son los pasos **R2-3 a R2-13** (recorrido 2); se marcan allá.
- [ ] **FT-14** (`gerencia`) Motivos de anulación: agregar uno y desactivar otro. → Se desactiva (no se borra); los tres de referencia existen ("Error en los datos del cliente", "Error en el procedimiento o el monto", "Otro (detallar)").
- [ ] **FT-15** (`caja1`) Pasos de pantalla: Facturas → búsqueda por número, cliente o documento y filtro de fechas y estado. → Listado correcto; el detalle muestra líneas, totales, su cobro, PDF y anulación o solicitud.
- [ ] **FT-16** (`facturacion`) Facturas. → Ve y saca el PDF; NIEGA anular o solicitar la anulación (sin DESACTIVAR).
- [ ] **FT-17** (`recepcion`) En la prestación ve el número de factura **sin enlace**. NIEGA abrir Facturas.
- [ ] **FT-18** (`caja1`) NIEGA: Timbrados, Datos de la clínica, Tasas de IVA y Motivos de anulación (403).
- [ ] **FT-19** (`admin`) Con `CLINEXA_FACTURACION_SIMULADA=false` (solo en una instalación de prueba). → No hay emisor real: no se emite ni se anula, con el aviso.
- [ ] **FT-20** (`auditor`) Auditoría de `facturas` y `detalles_factura`. → Montos ocultos; el EXPORTAR del PDF no lleva montos.

### 17. RUC (`RU`)

**Qué hace.** Campo `personas.ruc` aparte del documento: obligatorio en jurídicas, opcional en físicas; formato de 6 a 8 dígitos, guion y dígito
verificador (módulo 11); único entre personas.

**Quién puede.** Quien edita Personas: Recepción y Administrador (ver el módulo 1). Datos fiscales de la clínica: Gerencia y Administrador.

**Pasos.**

- [ ] **RU-1** (`recepcion`) Persona jurídica con RUC `80080099-0`. → Se guarda (válido).
- [ ] **RU-2** (`recepcion`) El mismo RUC con dígito `80080099-1`. → NIEGA (dígito verificador).
- [ ] **RU-3** (`recepcion`) RUC `44444401-7` (válido) y `44444401-6` (inválido). → El primero se acepta; el segundo NIEGA.
- [ ] **RU-4** (`recepcion`) RUC con letra final (A-D) o con menos de 6 o más de 8 dígitos. → NIEGA el formato.
- [ ] **RU-5** (`recepcion`) Repetir el RUC de otra persona. → NIEGA: "Ya hay una persona registrada con ese RUC."
- [ ] **RU-6** (`recepcion`) Persona física con RUC distinto de su CI. → Se acepta (no tiene que coincidir). Física sin CI ni pasaporte: "falta CI" en el listado.
- [ ] **RU-7** (`recepcion`) Jurídica con CI o pasaporte. → NIEGA (el servidor no lo acepta).
- [ ] **RU-8** (`admin`) Documentos viejos de tipo RUC. → Se siguen mostrando.
- [ ] **RU-9** (`admin`) Con `CLINEXA_VALIDAR_DIGITO_RUC=false` (solo prueba). → El dígito puede ser cualquiera; el formato se sigue exigiendo.
- [ ] **RU-10** (`gerencia`) RUC de la clínica con dígito verificador incorrecto. → NIEGA; el RUC de la clínica de prueba del demo es `80900010-5`.

### 18. Auditoría (`AU`)

**Qué hace.** Registro de quién hizo qué (CREAR, EDITAR, ANULAR, REVERTIR, EXPORTAR, VER, FALLO…), con filtros. Es módulo **sensible**: mirar la
Auditoría también queda registrado. El contenido clínico, el de recetas y el económico se ocultan a quien no tiene VER sobre el
módulo correspondiente (`App\Support\Auditoria::permisoDeContenido`).

**Quién puede** (`AUDITORIA`).

| Perfil | Ve eventos | Contenido clínico (`HISTORIA_CLINICA`) | Recetas (`RECETAS`) | Contenido económico |
|---|---|---|---|---|
| Auditor | sí | **oculto** | **oculto** | **oculto** |
| Gerencia | sí | **oculto** | **oculto** | visible según sus módulos (tarifas, cobros, etc.) |
| Supervisión médica | sí | visible | visible | **oculto** (no tiene módulos económicos) |
| Administrador | sí | visible | visible | visible |
| Los demás | nada | — | — | — |

**Pasos.**

- [ ] **AU-1** (`auditor`) Auditoría → listado y filtros (usuario, acción, módulo, desde/hasta, búsqueda por detalle o id). → Filtra; fechas en dd/mm/aaaa.
- [ ] **AU-2** (`auditor`) Abrir un evento de una consulta, una receta, un reposo o una orden. → Se ven los campos que cambiaron pero **no sus valores** (contenido clínico oculto).
- [ ] **AU-3** (`auditor`) El buscador no encuentra texto dentro del contenido clínico oculto.
- [ ] **AU-4** (`auditor`) Evento de tarifa, cobro, factura, liquidación. → "Contenido económico" oculto.
- [ ] **AU-5** (`gerencia`) Mismos eventos. → Clínico y recetas ocultos; económico visible donde tiene VER del módulo (por ejemplo, tarifas y cobros).
- [ ] **AU-6** (`supervision`) Mismos eventos. → Clínico y recetas visibles; económico oculto.
- [ ] **AU-7** (`admin`) Todo visible.
- [ ] **AU-8** (`medica1`, `recepcion`, `caja1`) Abrir Auditoría por URL. → NIEGA (403).
- [ ] **AU-9** (`auditor`) Abrir un evento de un módulo sensible. → Queda un VER del propio auditor; repetir antes de 5 minutos no genera otro igual.
- [ ] **AU-10** (cualquiera) Un login con un correo inexistente. → Evento sin usuario (filtro "sin usuario").
- [ ] **AU-11** (`admin`) Buscar en la pantalla un evento propio de cada tipo de documento (PDF, anulación, desbloqueo). → Aparecen con detalle ("PDF de …", "Reversión del cobro. Motivo: …", "Corrección autorizada (desbloqueo #N)").
- [ ] **AU-12** (`admin`) Buscar cómo editar o borrar un evento. → NIEGA: no hay pantalla ni ruta (el log es inmutable; hay triggers en PostgreSQL).
- [ ] **AU-13** (cualquiera) Cambios de estado de turno, cierre de jornada y urgencias revisadas. → Quedan en el detalle ("Urgencias: observación del triaje", "cierre con urgencias revisadas").

### 19. Perfiles, usuarios y matriz de permisos (`PF`)

**Qué hace.** **Perfiles de acceso** (un usuario puede tener varios; los permisos efectivos son la **unión** de los perfiles activos), usuarios,
**Matriz de permisos** (Seguridad: editar un perfil y comparar), administrador principal, y protecciones contra escalada.

**Quién puede.** `USUARIOS`, `PERFILES_ACCESO` y `MODULOS_SISTEMA`: solo el Administrador. Ningún otro perfil escribe en Usuarios ni en Perfiles.

**Pasos.**

- [ ] **PF-1** (`admin`) Seguridad → Matriz de permisos → pestaña **Comparar**. → Todos los perfiles en columnas, solo lectura; en los predefinidos se marcan las celdas distintas de `PerfilesPredefinidos::MATRIZ`. Compararla con la tabla "Matriz real de permisos".
- [ ] **PF-2** (`admin`) Abrir la matriz. → Queda un VER de `PERFILES_ACCESO` (módulo sensible).
- [ ] **PF-3** (`admin`) Pestaña **Editar un perfil**: atajos "Todo", "Ninguno", "Solo ver" y buscador. → Funcionan; sin JavaScript, el formulario con casillas anda igual.
- [ ] **PF-4** (`admin`) Cambiar un permiso de un perfil de prueba y "Guardar cambios". → Primero una confirmación con lo agregado y lo quitado; **todavía no se guarda**. "Confirmar y guardar": un solo evento EDITAR con `permisos_agregados`/`permisos_quitados`.
- [ ] **PF-5** (`admin`) Agregar un permiso de **escritura sobre USUARIOS o PERFILES_ACCESO**, o de un módulo sensible. → La confirmación exige además una casilla (y el servidor también).
- [ ] **PF-6** (`admin`) Guardar sin cambios. → No registra nada.
- [ ] **PF-7** (`admin`) Dos ventanas con la misma matriz; guardar en una y luego en la otra. → NIEGA: "El perfil fue modificado desde otra ventana. Recargue la página."
- [ ] **PF-8** (`admin`) Los cambios valen desde el **siguiente pedido** del usuario del perfil (sin caché ni sesión).
- [ ] **PF-9** (`admin`) Editar el perfil Administrador. → NIEGA (no se edita, no se desactiva, no cambia de nombre).
- [ ] **PF-10** (`admin`) "Restablecer a lo predefinido" en un perfil predefinido. → Muestra tildada su matriz (sin guardar); se guarda con la misma confirmación.
- [ ] **PF-11** (`admin`) Usuarios: crear uno **sin ningún perfil activo**. → NIEGA. Dejar un usuario activo con un perfil inactivo: sin perfil activo "Su usuario no tiene ningún perfil de acceso activo." al entrar.
- [ ] **PF-12** (`admin`) Desactivar un perfil con usuarios. → El perfil inactivo no aporta permisos; a quien quede sin ninguno se le cierra la sesión.
- [ ] **PF-13** (`admin`) Cambiarse a sí mismo los perfiles, bloquearse o desactivarse desde Usuarios. → NIEGA: "No puede cambiar su propio perfil de acceso."
- [ ] **PF-14** (`admin`) Dejar el sistema sin ningún Administrador activo (quitar el perfil al último o desactivarlo). → NIEGA.
- [ ] **PF-15** (`admin`) Un usuario con **dos perfiles** (por ejemplo Médico + Caja). → Ve la unión de ambos.
- [ ] **PF-16** (administrador principal, si está asignado) Desactivarlo, cambiarle perfiles o su correo, mandarle invitación. → NIEGA: "Es el administrador principal y no puede modificarse." Solo él edita los datos de su persona.
- [ ] **PF-17** (administrador no principal) Asignar el perfil Administrador a otro. → NIEGA: solo el principal lo asigna (casilla fija).
- [ ] **PF-18** (consola) `php artisan clinexa:traspasar-principal CORREO` con otro correo. → Pide escribir el correo; mueve la marca en una transacción; deja un EDITAR en Auditoría.
- [ ] **PF-19** (`gerencia`, `medica1`) Seguridad, Usuarios y Perfiles por URL. → NIEGA (403).
- [ ] **PF-20** (`admin`) Sumar un módulo nuevo (procedimiento de `docs/perfiles.md`). → Se hace por migración con `PerfilesPredefinidos::sumarModulo`; el Administrador lo recibe solo.
- [ ] **PF-21** Comparar `docs/perfiles.md` con la matriz real. → Ver los hallazgos de la sección siguiente: hoy la tabla del documento está desactualizada.


## Pendiente de médico

Lo clínico que **no se puede dar por verificado** con esta guía: lo tiene que revisar y aprobar un médico (o el asesor correspondiente).
Sin eso, el sistema no se usa con pacientes reales (`docs/produccion.md`).

- [ ] **MED-1 Rangos de signos vitales.** En `parametros_clinicos` solo están sembrados los límites **imposibles** (temperatura 30 a 45,
  frecuencia cardíaca 20 a 300, frecuencia respiratoria 5 a 80, saturación 0 a 100, peso 0 a 500 y mayor que 0, talla 20 a 250; la presión sin
  límites numéricos; sistólica menor o igual que la diastólica es imposible). Los de **normal** y **muy alterado** están vacíos y todo
  está PENDIENTE: **mientras tanto no se marca ninguna alerta** (banner del triaje). Un médico debe cargarlos y aprobarlos (Supervisión
  médica con EDITAR sobre `PARAMETROS_CLINICOS`, siendo profesional activo). Editar uno aprobado lo devuelve a PENDIENTE.
- [ ] **MED-2 Un solo juego de rangos, para adultos.** Embarazadas o pediátricas pueden necesitar otros; no está resuelto.
- [ ] **MED-3 Confirmación de un valor muy alterado.** Probar con los rangos ya aprobados que "Sí, es correcto" / "Corregir" y la
  etiqueta ("Fuera de lo normal" ámbar, "Muy alterado" rojo) se disparan en los valores que el médico espera.
- [ ] **MED-4 Dietas.** El catálogo nace vacío; las 3 del demo son de prueba, con "(demo)". Las dietas reales las carga un médico o
  nutricionista; el texto se copia a la indicación (hasta 3000 caracteres).
- [ ] **MED-5 Catálogo de estudios.** Los 6 estudios del demo son de prueba; "Densitometría ósea" se escribió a mano en una orden. El catálogo real
  lo define Supervisión médica. El catálogo de estudios no está vinculado a los procedimientos (ni la orden al informe).
- [ ] **MED-6 Textos fijos de los documentos.** Sin revisión legal ni médica: `config/reposo.php` (título, modalidades, "Ambas fechas
  inclusive", leyenda "Se extiende a solicitud del/de la paciente…", firma y aclaración), `config/ordenes_estudio.php` y
  `config/constancias.php`. Hoy los tres tienen `borrador = true`: cada documento nuevo sale con **BORRADOR SUJETO A REVISIÓN**. Aprobado
  el texto, reemplazarlo y poner `borrador` en `false`. Los ya emitidos conservan texto y marca.
- [ ] **MED-7 Reposo.** Sin tope clínico de días (el máximo es técnico, 36.500); el "origen del reposo" (accidente de trabajo, enfermedad
  profesional, maternidad) está fuera hasta su revisión; qué diagnósticos conviene imprimir queda a criterio del profesional.
- [ ] **MED-8 Orden de estudio.** Sin campos clínicos específicos (semanas de gestación, lateralidad, etc.); el informe de estudio es texto libre, sin
  medidas ni rangos de referencia.
- [ ] **MED-9 Aspecto en papel.** Imprimir y revisar los PDF de triaje, consulta, receta, indicaciones e informe de estudio. Receta: indicación de uso por
  medicamento (hasta 500 caracteres) y hoja en dos mitades.
- [ ] **MED-10 Quién puede leer lo clínico.** Hoy el Administrador ve la historia clínica y las recetas y puede desbloquear consultas ajenas. La
  separación entre administración técnica y lectura clínica está pendiente (`docs/produccion.md`), así como tener al menos **dos
  supervisores** con `CORRECCIONES_HC`.
- [ ] **MED-11 CIE-10.** El catálogo cargado (12.436 filas) y los diagnósticos que los médicos esperan encontrar con el buscador.
- [ ] **MED-12 Triaje urgente.** Que el criterio de "urgente" (marca manual del personal de salud, sin cálculo automático) y el aviso al profesional sean los
  que la clínica quiere.

## Registro de defectos

Un defecto por fila (copie la plantilla al final del recorrido o en una planilla). **Gravedad:**

- **A**: impide usar el sistema, pierde o altera datos, deja pasar algo que debía negarse (permiso o regla) o muestra datos clínicos o
  económicos a quien no corresponde.
- **B**: una función no anda como dice el documento, pero hay un rodeo.
- **C**: texto, formato, fecha, aspecto o molestia sin consecuencia sobre los datos.

| N.º | Paso | Pantalla | Usuario | Pasos para repetirlo | Esperado | Obtenido | Gravedad (A/B/C) | Estado |
|---|---|---|---|---|---|---|---|---|
| D-001 | (código, p. ej. `CC-7`) | | | 1. … 2. … 3. … | | | | Abierto |

Para cada defecto anote también la fecha (dd/mm/aaaa), la base (limpia o `clinexa_dev`) y, si hay, el mensaje exacto en pantalla. **No**
pegue datos reales ni capturas con datos reales.

## Matriz real de permisos

Leída de `clinexa_dev` el 28/10/2026, **solo lectura** (transacción de solo lectura sobre `perfiles_acceso`, `permisos`, `perfil_permiso` y
`modulos_sistema`). Hay 10 perfiles, 60 módulos, 300 permisos posibles y 517 asignaciones. Una instalación limpia con los seeders base da
**exactamente la misma matriz** (comparadas las 517 asignaciones).

Acciones: **V** ver, **C** crear, **E** editar, **D** desactivar, **X** exportar; `·` = sin permisos. **⚠** = el perfil tiene permisos
sobre ese módulo y el módulo **no figura para ese perfil en `docs/perfiles.md`**. El Administrador tiene todo por diseño y no se marca.

| Módulo | ADMIN | GEREN | MÉDICO | ENFER | RECEP | CAJA | FACTU | COMPR | SUPERV | AUDIT |
|---|---|---|---|---|---|---|---|---|---|---|
| `ACUERDOS_REPARTO` | VCEDX | VCE | · | · | · | · | · | · | · | · |
| `ANULACIONES_FACTURA` | VCEDX | VE ⚠ | · | · | · | · | · | · | · | · |
| `APROBACION_LIQUIDACIONES` | VCEDX | VE | · | · | · | · | · | · | · | · |
| `AUDITORIA` (sensible) | VCEDX | V | · | · | · | · | · | · | V | V |
| `CAJAS` | VCEDX | V | · | · | · | VCE | · | · | · | · |
| `CARGOS_SALUD` | VCEDX | V ⚠ | · | · | · | · | · | · | V ⚠ | · |
| `CATEGORIAS_GASTO` | VCEDX | V | · | · | · | · | · | V | · | · |
| `CATEGORIAS_PROVEEDOR` | VCEDX | V | · | · | · | · | · | VCED | · | · |
| `CIE10` | VCEDX | V | · | · | · | · | · | · | · | · |
| `CIERRES_CAJA` | VCEDX | VE | · | · | · | · | · | · | · | · |
| `COBERTURAS` | VCEDX | V | · | · | VCE | · | VCE | · | · | · |
| `COBROS` | VCEDX | V | · | · | · | VCD | V | · | · | · |
| `CONSTANCIAS_ESTADO` | VCEDX | · | VCE ⚠ | · | · | · | · | · | VCE ⚠ | · |
| `CONSTANCIAS_RETIRO` | VCEDX | · | · | · | VCE ⚠ | · | · | · | · | · |
| `CONSULTORIOS` | VCEDX | V | V | · | V | · | · | · | · | · |
| `CONVENIOS` | VCEDX | VCED | · | · | · | · | VCED | · | · | · |
| `CORRECCIONES_HC` (sensible) | VCEDX | · | · | · | · | · | · | · | VCE ⚠ | · |
| `DATOS_FISCALES` | VCEDX | VE ⚠ | · | · | · | · | · | · | · | · |
| `DIETAS` | VCEDX | · | V | · | · | · | · | · | VCED | · |
| `DISPONIBILIDAD` | VCEDX | V | V | · | VCED | · | · | · | · | · |
| `EQUIPOS` | VCEDX | VCED | · | · | · | · | · | · | · | · |
| `ESPECIALIDADES` | VCEDX | V | · | · | V | · | · | · | · | · |
| `ESTUDIOS` | VCEDX | · | V | · | · | · | · | · | VCED | · |
| `FACTURAS` | VCEDX | VX ⚠ | · | · | · | VCDX ⚠ | VX ⚠ | · | · | · |
| `GEOGRAFIA` | VCEDX | V | · | · | · | · | · | · | · | · |
| `HISTORIA_CLINICA` (sensible) | VCEDX | · | VCE | · | · | · | · | · | V | · |
| `LIQUIDACIONES` | VCEDX | VDX | · | · | · | · | VCEDX | · | · | · |
| `MEDIOS_PAGO` | VCEDX | V | · | · | · | V | V | · | · | · |
| `MODULOS_SISTEMA` | VCEDX | · | · | · | · | · | · | · | · | · |
| `MOTIVOS_ANULACION` | VCEDX | VCED ⚠ | · | · | · | · | · | · | · | · |
| `ORDENES_ESTUDIO` | VCEDX | · | VCE | · | · | · | · | · | V | · |
| `ORIGENES_TURNO` | VCEDX | V | · | · | V | · | · | · | · | · |
| `PACIENTES` (sensible) | VCEDX | · | V | · | VCE | V | · | · | V | · |
| `PAGOS_ASEGURADORAS` | VCEDX | V | · | · | · | · | VCD | · | · | · |
| `PARAMETROS_CLINICOS` | VCEDX | V ⚠ | V ⚠ | · | · | · | · | · | VE ⚠ | · |
| `PDF_CLINICO` | VCEDX | · | X ⚠ | X ⚠ | · | · | · | · | X ⚠ | · |
| `PERFILES_ACCESO` (sensible) | VCEDX | · | · | · | · | · | · | · | · | · |
| `PERSONAL_SALUD` | VCEDX | V ⚠ | · | · | · | · | · | · | VCE ⚠ | · |
| `PERSONAS` (sensible) | VCEDX | · | · | · | VCE | · | · | · | · | · |
| `PREPARACION` | VCEDX | · | VCE | VCE | · | · | · | · | · | · |
| `PRESTACIONES` | VCEDX | VED | · | · | V | V | V | · | · | · |
| `PROCEDIMIENTOS` | VCEDX | V | · | · | V | V | V | · | · | · |
| `PROFESIONALES` | VCEDX | V | · | · | V | · | · | · | V | · |
| `PROVEEDORES` | VCEDX | V | · | · | · | · | · | VCED | · | · |
| `RECETAS` (sensible) | VCEDX | · | VCE | · | · | · | · | · | V | · |
| `REPARTOS` | VCEDX | V | · | · | · | · | · | · | · | · |
| `REPOSO_MEDICO` | VCEDX | · | VCE ⚠ | · | · | · | · | · | V ⚠ | · |
| `RESPONSABLES_PAGO` | VCEDX | · | · | · | VCE | · | V | · | · | · |
| `REVERSIONES_COBRO` | VCEDX | VE ⚠ | · | · | · | · | · | · | · | · |
| `SUCURSALES` | VCEDX | V | · | · | · | · | · | · | · | · |
| `TARIFAS` | VCEDX | VCE | · | · | V | V | V | · | · | · |
| `TIMBRADOS` | VCEDX | VCE ⚠ | · | · | · | · | · | · | · | · |
| `TIPOS_BLOQUE_ANAMNESIS` | VCEDX | V | · | · | · | · | · | · | · | · |
| `TIPOS_DOCUMENTO` | VCEDX | V | · | · | · | · | · | · | · | · |
| `TIPOS_EQUIPO` | VCEDX | VCED | · | · | · | · | · | · | · | · |
| `TIPOS_INDICACION` | VCEDX | V | · | · | · | · | · | · | · | · |
| `TIPOS_RED_SOCIAL` | VCEDX | V | · | · | · | · | · | · | · | · |
| `TURNOS` | VCEDX | V | V | · | VCE | · | · | · | · | · |
| `USUARIOS` (sensible) | VCEDX | · | · | · | · | · | · | · | · | · |
| `VISACIONES` | VCEDX | VX | · | · | VCEDX | · | VCEDX | · | · | · |

### Hallazgos de la matriz

1. **Perfiles con permisos sobre módulos que `docs/perfiles.md` no menciona para ese perfil: 24 pares.** Ninguno es un permiso sin
   documentar: todos figuran en el documento del módulo (abajo), porque `docs/perfiles.md` no se actualizó cuando se sumaron esos módulos.
   Hay que actualizar la tabla de `docs/perfiles.md`, no quitar permisos.

| Perfil | Módulo | Acciones asignadas | ¿Figura en otro doc? |
|---|---|---|---|
| GERENCIA | `ANULACIONES_FACTURA` | VE | `facturacion.md` |
| GERENCIA | `CARGOS_SALUD` | V | `triaje.md` |
| SUPERVISION_MEDICA | `CARGOS_SALUD` | V | `triaje.md` |
| MEDICO | `CONSTANCIAS_ESTADO` | VCE | `constancias.md` |
| SUPERVISION_MEDICA | `CONSTANCIAS_ESTADO` | VCE | `constancias.md` |
| RECEPCION | `CONSTANCIAS_RETIRO` | VCE | `constancias.md` |
| SUPERVISION_MEDICA | `CORRECCIONES_HC` | VCE | `constancias.md`, `historia-clinica.md`, `ordenes-estudio.md`, `pdf-clinico.md`, `reposo-medico.md`, `produccion.md` |
| GERENCIA | `DATOS_FISCALES` | VE | `facturacion.md` |
| GERENCIA | `FACTURAS` | VX | `facturacion.md` |
| CAJA | `FACTURAS` | VCDX | `facturacion.md` |
| FACTURACION | `FACTURAS` | VX | `facturacion.md` |
| GERENCIA | `MOTIVOS_ANULACION` | VCED | `facturacion.md` |
| GERENCIA | `PARAMETROS_CLINICOS` | V | `triaje.md` |
| MEDICO | `PARAMETROS_CLINICOS` | V | `triaje.md` |
| SUPERVISION_MEDICA | `PARAMETROS_CLINICOS` | VE | `triaje.md` |
| MEDICO | `PDF_CLINICO` | X | `indicaciones-generales.md`, `informes-estudio.md`, `pdf-clinico.md`, `produccion.md` |
| ENFERMERIA | `PDF_CLINICO` | X | `indicaciones-generales.md`, `informes-estudio.md`, `pdf-clinico.md`, `produccion.md` |
| SUPERVISION_MEDICA | `PDF_CLINICO` | X | `indicaciones-generales.md`, `informes-estudio.md`, `pdf-clinico.md`, `produccion.md` |
| GERENCIA | `PERSONAL_SALUD` | V | `triaje.md` |
| SUPERVISION_MEDICA | `PERSONAL_SALUD` | VCE | `triaje.md` |
| MEDICO | `REPOSO_MEDICO` | VCE | `reposo-medico.md` |
| SUPERVISION_MEDICA | `REPOSO_MEDICO` | V | `reposo-medico.md` |
| GERENCIA | `REVERSIONES_COBRO` | VE | `cobros-caja.md` |
| GERENCIA | `TIMBRADOS` | VCE | `facturacion.md` |

2. **Una diferencia de acciones** en un módulo que sí figura: Caja tiene en `COBROS` **V C D** (la **D** es revertir o solicitar la reversión,
   `docs/cobros-caja.md`); `docs/perfiles.md` dice "VER, CREAR".
3. **`USUARIOS`, `PERFILES_ACCESO` y `MODULOS_SISTEMA`** solo los tiene el Administrador, como dice `docs/perfiles.md`.
4. **Otros** (no son defectos de la matriz): Supervisión médica no tiene módulos económicos (sus eventos económicos salen ocultos en
   Auditoría); el Médico no ve montos; el Auditor solo tiene `AUDITORIA` (V).

## Instalación limpia

Verificada el 28/10/2026 en una base temporal propia (`clinexa_instalacion_limpia`, creada con `createdb` por el usuario `clinexa` y
**borrada con `dropdb` al terminar**; `clinexa_dev` y `clinexa_test` no se tocaron). Servidor PostgreSQL 18.6, `APP_ENV=local`. Comandos
con `DB_DATABASE=clinexa_instalacion_limpia` en el entorno:

| Paso | Comando | Resultado | Tiempo |
|---|---|---|---|
| 1 | `php artisan migrate --force` | OK; sin migraciones pendientes | 3,1 s |
| 2 | `php artisan db:seed --force` (módulos, medios de pago, motivos, perfiles, geografía, datos reales de la clínica, CIE-10 y, fuera de producción, `PersonasDemoSeeder`) | OK | 18,3 s |
| 3 | `php artisan db:seed --class='Database\Seeders\Demo\BaseClinica' --force` | OK ("nada nuevo" en lo que ya estaba) | 0,5 s |
| 4 | `php artisan clinexa:crear-admin admin@prueba.test …` (con todos los datos por opciones) | OK; con `MAIL_MAILER=log` el link para definir la contraseña queda al final de `storage/logs/laravel.log` | 1,4 s |
| 5 | `php artisan clinexa:datos-demo --confirmo-desarrollo --password=…` | OK; adelantó los cinco hitos (PRESTACIONES, PRESTACIONES_INFORMES, REPARTOS, CONVENIOS, FACTURACION) | 13 s |

Total: unos **36 segundos**. **No falló nada.**

Conteos finales (después de todo): 60 módulos, 300 permisos, 10 perfiles, 517 asignaciones de permisos, 12.436 códigos CIE-10, 12 usuarios,
33 personas, 14 pacientes, 3 profesionales, 1 personal de salud, 20 disponibilidades, 106 turnos, 34 consultas, 7 recetas, 4 reposos,
3 órdenes de estudio, 13 informes de estudio, 35 prestaciones (33 tarifadas y 2 pendientes), 33 repartos con 52 líneas, 11 cajas y
11 cierres, 31 cobros con 41 líneas, 31 facturas (1 clínica de prueba, 1 timbrado simulado), 3 solicitudes de anulación de factura,
2 liquidaciones (con 5 líneas) y 1 pago de aseguradora, 896 eventos de Auditoría.

Observaciones:

- `db:seed` fuera de producción también carga personas de ejemplo (`PersonasDemoSeeder`): la base "limpia" ya tiene 8 personas antes de los
  datos demo. En producción (`APP_ENV=production`) no se cargan.
- `clinexa_dev` (el 28/10/2026) tiene los mismos 60 módulos, 300 permisos y 12 usuarios, pero sus 31 cobros demo **no tienen factura**
  (se cargó antes de la facturación; los días simulados no se repiten). Una instalación limpia sí los trae facturados.
