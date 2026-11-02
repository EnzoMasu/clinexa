# Feature Specification: Compras y Gastos

**Feature Branch**: `prueba-speckit` (sin rama nueva; la spec vive en `specs/001-compras-gastos`)

**Created**: 2026-11-02

**Status**: Draft

**Input**: User description: "Módulo Compras/Gastos de Clinexa. Registrar compras y gastos operativos de la clínica con sus comprobantes, categorías, condiciones y estados, para después alimentar Cuentas por Pagar y los informes de compras, gastos y obligaciones con proveedores. Fuera de alcance: pagos a proveedores, saldos y reversiones."

## Lo que ya existe (punto de partida)

- **Proveedores**: ya se administran (persona con su RUC, categorías de proveedor, condiciones comerciales y datos bancarios en texto libre, contactos, redes, estado activo/inactivo). Perfil `COMPRAS_TESORERIA` con acceso total a Proveedores y Categorías de proveedor.
- **Categorías de gasto**: catálogo ya creado (nombre único, activo/inactivo, auditado), pero **sin uso**: ningún registro las referencia y no se siembran categorías base. `COMPRAS_TESORERIA` hoy solo tiene VER sobre ellas.
- **Catálogos reutilizables**: monedas y medios de pago existen (usados por cobros).
- **No existe**: ninguna compra, gasto, comprobante recibido de proveedor ni cuentas por pagar. Las facturas existentes (`Factura`, `Timbrado`) son las que la clínica **emite**, no las que recibe. Los docs de cobros y liquidaciones ya declaran compras y gastos como fase futura.
- **Reglas transversales** que este módulo hereda: nada se borra (se anula o desactiva), todo cambio se audita, fechas dd/mm/aaaa y textos en "usted".

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Registrar una compra o gasto con su comprobante (Priority: P1)

Una persona de Compras registra lo que la clínica compró o gastó: a qué proveedor, qué comprobante respalda el hecho (tipo, número, fecha), en qué categoría de gasto cae, el importe y la condición (por ejemplo contado o a crédito).

**Why this priority**: es el dato base. Sin registros confiables no hay Cuentas por Pagar ni informes.

**Independent Test**: cargar una compra de un proveedor activo con comprobante y categoría, y verla en el listado con todos sus datos.

**Acceptance Scenarios**:

1. **Given** un proveedor activo y una categoría de gasto activa, **When** Compras registra la compra con comprobante, fecha, importe y condición, **Then** queda guardada, visible en el listado y auditada.
2. **Given** un comprobante ya registrado para el mismo proveedor, **When** se intenta cargar otra vez, **Then** el sistema lo impide e indica cuál es el registro existente.
3. **Given** un proveedor o categoría inactivos, **When** se registra una compra nueva, **Then** no se pueden elegir; las compras antiguas con ellos se siguen viendo.

---

### User Story 2 - Seguir el estado de cada compra o gasto (Priority: P2)

Compras y Gerencia ven en qué estado está cada registro y lo hacen avanzar o lo anulan, con motivo.

**Why this priority**: Cuentas por Pagar solo debe tomar registros válidos y confirmados.

**Independent Test**: cambiar el estado de un registro y comprobar que el listado, el historial de auditoría y el filtro por estado lo reflejan.

**Acceptance Scenarios**:

1. **Given** un registro confirmado, **When** Gerencia lo anula indicando el motivo, **Then** queda anulado, no se borra y se conserva el motivo y quién lo hizo.
2. **Given** un registro confirmado o anulado, **When** alguien intenta editarlo, **Then** el sistema lo impide (solo lectura).
3. **Given** un borrador cargado por una persona, **When** esa misma persona intenta confirmarlo, **Then** el sistema lo impide; lo confirma Gerencia.

---

### User Story 3 - Consultar y filtrar compras y gastos (Priority: P2)

Gerencia y Compras buscan registros por proveedor, categoría, período, estado y condición, y ven totales.

**Why this priority**: es lo que después alimenta los informes de compras, gastos y obligaciones con proveedores.

**Independent Test**: con registros de distintos proveedores, categorías y fechas, el filtro devuelve exactamente los que coinciden y el total coincide con la suma.

**Acceptance Scenarios**:

1. **Given** registros de varios meses, **When** se filtra por un período, **Then** solo aparecen los de ese período y el total corresponde a ellos.
2. **Given** una persona sin permiso sobre Compras, **When** intenta entrar, **Then** no ve el módulo ni sus montos.

---

### User Story 4 - Dejar listos los datos para Cuentas por Pagar (Priority: P3)

Cada registro a crédito conserva lo necesario (proveedor, importe, condición, vencimiento, estado) para que la fase de Cuentas por Pagar lo tome sin volver a cargarlo.

**Why this priority**: es el objetivo posterior, pero no requiere pantallas propias en esta fase.

**Independent Test**: un registro a crédito expone proveedor, importe adeudado, vencimiento y estado sin datos adicionales.

**Acceptance Scenarios**:

1. **Given** una compra a crédito vigente, **When** Cuentas por Pagar la consulte, **Then** encuentra proveedor, importe, vencimiento y estado.

---

### Edge Cases

- Comprobante repetido del mismo proveedor (ver FR-005).
- Compra con fecha futura o de un período ya informado.
- Proveedor desactivado después de cargada la compra.
- Categoría de gasto renombrada o desactivada con registros existentes.
- Importe cero o negativo (devoluciones, notas de crédito: ver aclaración de alcance).
- Compra sin comprobante (gasto menor).
- Registro anulado que ya había sido tomado por Cuentas por Pagar.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: El sistema DEBE permitir registrar una compra o gasto indicando proveedor, categoría de gasto, fecha, importe, condición y comprobante.
- **FR-002**: Solo se DEBEN poder elegir proveedores y categorías de gasto activos; los registros existentes conservan los datos aunque luego se desactiven.
- **FR-003**: El sistema DEBE guardar de cada comprobante su tipo, su número y su fecha. Los tipos aceptados son factura legal, ticket, recibo y "sin comprobante" (gastos menores). Se PUEDE adjuntar imagen del comprobante, pero no es obligatorio.
- **FR-004**: El sistema DEBE registrar la condición de la compra: contado o crédito con una sola fecha de vencimiento. Una compra al contado se considera pagada al registrarla y no pasa a Cuentas por Pagar. Una compra a crédito PUEDE registrar un anticipo (importe entregado al momento de la compra); lo que queda adeudado es el importe menos el anticipo.
- **FR-005**: El sistema DEBE impedir cargar dos veces el mismo comprobante del mismo proveedor.
- **FR-006**: Cada registro DEBE tener uno de tres estados: borrador, confirmada o anulada. Compras carga y edita el borrador; la confirmación la hace Gerencia (persona distinta de quien cargó). Solo las confirmadas las toma Cuentas por Pagar y los informes. Una compra confirmada no se corrige: se anula y se vuelve a cargar. Solo Gerencia puede anular.
- **FR-007**: Un registro anulado DEBE conservar motivo, fecha y responsable, y quedar de solo lectura. Nada se borra.
- **FR-008**: Todo alta, edición y anulación DEBE quedar auditada.
- **FR-009**: El sistema DEBE permitir listar y filtrar por proveedor, categoría, período, estado y condición, con total del filtro.
- **FR-010**: El acceso DEBE regirse por la matriz de permisos; Compras y tesorería carga borradores; Gerencia confirma y anula.
- **FR-013**: Todo importe se registra en guaraníes. Cada registro DEBE discriminar el IVA (10 %, 5 % y exento) y el total debe coincidir con la suma de las partes. No se admiten compras en moneda extranjera.
- **FR-011**: Las fechas DEBEN mostrarse dd/mm/aaaa y todo texto visible dirigirse en "usted".
- **FR-012**: Los datos de cada registro DEBEN quedar disponibles, sin recarga, para Cuentas por Pagar y para los informes de compras, gastos y obligaciones.

*Fuera de alcance*: pagos a proveedores, saldos, reversiones y cualquier pantalla de Cuentas por Pagar; control de stock o inventario; emisión de facturas propias.

### Key Entities *(include if feature involves data)*

- **Compra/Gasto**: hecho económico de la clínica con un proveedor. Atributos: proveedor, categoría de gasto, fecha, importe, condición, vencimiento (si es a crédito), estado, observaciones.
- **Comprobante**: respaldo del hecho (tipo: factura legal, ticket, recibo o sin comprobante; número, fecha, imagen adjunta opcional). Vinculado a una compra o gasto.
- **Proveedor** (existente): a quién se compra.
- **Categoría de gasto** (existente, sin uso): clasificación del gasto para los informes.
- **Condición** y **Estado**: ver aclaraciones pendientes.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Compras registra una compra completa con comprobante en menos de 2 minutos.
- **SC-002**: El 100 % de los registros tiene proveedor, categoría, comprobante y estado; ninguno queda sin auditar.
- **SC-003**: Es imposible duplicar un mismo comprobante de un mismo proveedor.
- **SC-004**: Gerencia obtiene el total de gastos de un mes por categoría en menos de 30 segundos y la suma coincide con los registros.
- **SC-005**: Cuentas por Pagar puede tomar todas las compras a crédito vigentes sin cargar ningún dato adicional.

## Assumptions

- Compras y tesorería carga los borradores; Gerencia confirma y anula; los demás perfiles no ven el módulo.
- Sin número fijo de módulos, permisos ni rutas en pruebas, y con datos inventados.
- Los proveedores se siguen administrando como hoy; este módulo no los modifica.
- Una compra pertenece a un único proveedor y a una única categoría de gasto.
- El anticipo es un dato de la compra a crédito, informado al registrarla; los pagos posteriores, los saldos y sus reversiones siguen en Cuentas por Pagar.
- Moneda única: guaraníes (FR-013).
- No hay migración de datos históricos (no existen compras previas).
