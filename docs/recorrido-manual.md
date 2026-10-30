# Recorrido manual: un día de trabajo de la clínica

Guion para recorrer Clinexa **a mano, por el navegador**, simulando **un día de trabajo** de Plenitud Mujer con todos sus incidentes.
El estado inicial se carga con un comando; **todo lo que se genera atendiendo (turnos, consultas, cobros, facturas, cierres,
liquidaciones) se tipea durante el recorrido**, con los datos de la sección 2. Datos inventados, siempre.

Complementa a `docs/verificacion.md` (guía por módulo con casillas, matriz real de permisos, instalación limpia y registro de
defectos): **no la repite**. Cuando un paso cae en una regla ya descrita allá, se cita su código (por ejemplo `R2-3`, `CV-8`).
Los defectos se anotan con el formato de "Registro de defectos" de esa guía.

Convenciones: `[ ]` casilla para marcar; **NIEGA** = el sistema tiene que rechazarlo (si lo deja pasar, es un defecto de gravedad A);
`(usuario)` = con quién entrar; fechas dd/mm/aaaa; montos en guaraníes enteros (₲).

## Quién hace qué

Usuarios `@prueba.test` (uno por perfil; la contraseña es la de 1.2). **Principal** = quien lo hace en el guion; **alternativo** = otro perfil que
también tiene el permiso en la matriz real (`PerfilesPredefinidos::MATRIZ`, `docs/perfiles.md`). `admin` tiene todos los permisos, pero el guion
solo lo usa para Auditoría (3.5) y para las pruebas negativas. Donde dice "nunca", la regla es del sistema aunque el perfil tenga el módulo.

| Acción | Principal | Alternativo | Dónde |
|---|---|---|---|
| Turnos: nuevo, confirmar, cancelar, ausente, retiro y rechazo, urgencias, constancia de retiro | `recepcion` | — (`gerencia` solo ve) | Turnos |
| Cupo entre pacientes y disponibilidades | `recepcion` | — (`gerencia` solo ve) | Turnos → "Cupo entre pacientes"; Disponibilidades |
| Personas y pacientes (alta, búsqueda, desactivar) | `recepcion` | — | Personas; Pacientes |
| Cobertura y visaciones (cargar) | `recepcion` | `facturacion` | Cobertura y visaciones; botón "Visación" del turno |
| Aplicar visación, marcar la firma | `recepcion` | `facturacion` | Prestación → "Aplicar visación", "Firma recibida" |
| Ticket de la visación (PDF) | `recepcion` | `facturacion`, `gerencia` | Prestación → "Ticket" |
| Triaje | `enfermeria` | un médico (`medica1`, `medico2`) | Triaje |
| Atender, recetas, indicaciones, reposo, órdenes, constancia de estado, finalizar | `medica1` o `medico2`, el del turno | nadie más (ni `admin` escribe en la consulta de otro) | Consulta |
| "No se presentó", "Pasar a ausente", "Cerrar jornada", nota de retiro | el médico, solo de sus turnos | — | Consulta |
| Adenda a una consulta cerrada | el médico que la atendió | `supervision` | Consulta cerrada → "Adenda" |
| Desbloqueo y corrección de una consulta cerrada | `supervision` | — (nunca quien la atendió) | Consulta cerrada → "Desbloquear" |
| Tarifas, acuerdos de reparto y tasas de IVA | `gerencia` | — | Económico → Tarifas; Acuerdos de reparto; Facturas → Tasas de IVA |
| Tarifar, repartir y anular una prestación | `gerencia` | — | Prestaciones → "Tarifar", "Repartir" |
| Datos de la clínica y timbrados | `gerencia` | — | Facturas → "Datos de la clínica"; Timbrados |
| Abrir y cerrar caja | `caja1`, `caja2` | — (`gerencia` solo ve) | Cajas → "Mi caja" |
| Facturar y cobrar | `caja1`, `caja2` | — | Cobros → Cobrar → "Facturar y cobrar" |
| Anular una factura dentro de los 10 minutos | quien la emitió (`caja1`, `caja2`) | — | Facturas → la factura → "Anular la factura" |
| Pedir anular pasados los 10 minutos | `caja1`, `caja2` | — | Facturas → "Solicitar la anulación" |
| Aprobar o rechazar la solicitud de anulación | `gerencia` | — | Facturas → "Solicitudes de anulación" |
| Aprobar o devolver el cierre de una caja | `gerencia` | — (nunca el cajero, el suyo) | Cajas → el cierre |
| Armar, quitar líneas, enviar y anular una liquidación; pagos y planilla | `facturacion` | — (`gerencia` también anula y saca la planilla) | Liquidaciones |
| Aprobar o devolver una liquidación | `gerencia` | — (nunca quien la armó) | Liquidaciones → "Aprobar", "Devolver" |
| Auditoría | `auditor` | `gerencia`, `supervision` (cada uno con su contenido), `admin` | Auditoría |

## 1. Preparar y reiniciar el recorrido

La base es **`clinexa_recorrido`**, aparte de `clinexa_dev` y de `clinexa_test`. El comando se niega a correr el modo `--recorrido` en
esas dos. El `.env` **no se toca**: la aplicación se apunta a la base con la variable `DB_DATABASE` al levantar el servidor.

### 1.1 Armarla desde cero

Se hizo una vez (la base ya existe). Para repetirlo en una base nueva, desde la carpeta del proyecto; la clave de PostgreSQL es la
`DB_PASSWORD` del `.env` (`export PGPASSWORD=...` o la pide):

```bash
createdb -h 127.0.0.1 -U clinexa clinexa_recorrido
export DB_DATABASE=clinexa_recorrido        # vale solo para esta terminal
php artisan migrate --force
for s in ModuloSistemaSeeder MediosPagoSeeder MotivosAnulacionFacturaSeeder ModulosSensiblesSeeder PerfilAdministradorSeeder \
         PerfilesPredefinidosSeeder GeografiaSeeder DatosRealesClinicaSeeder CatalogoCie10Seeder; do
  php artisan db:seed --class="Database\\Seeders\\$s" --force
done
php artisan db:seed --class='Database\Seeders\Demo\BaseClinica' --force
php artisan clinexa:crear-admin admin@prueba.test --documento=9000000 --apellidos="Administrador Prueba" --nombres="Admin" \
  --fecha-nacimiento=1980-01-01 --telefono="0981 900 000" --direccion="Calle Prueba 100, Concepción"
```

- Son los seeders de `DatabaseSeeder` **menos `PersonasDemoSeeder`** (sus personas de ejemplo ensuciarían el recorrido).
- `btree_gist` (índices de turnos y de tarifas) lo crea la propia migración. Si en otra máquina falla por permisos, un superusuario
  corre una vez: `sudo -u postgres psql -d clinexa_recorrido -c 'CREATE EXTENSION IF NOT EXISTS btree_gist;'`.
- El administrador no tiene contraseña todavía (`MAIL_MAILER=log`): en el login, "¿Olvidó su contraseña?" con `admin@prueba.test`, y el
  link queda al final de `storage/logs/laravel.log`.

### 1.2 Cargar el estado inicial

```bash
DB_DATABASE=clinexa_recorrido php artisan clinexa:datos-demo --confirmo-desarrollo --recorrido
```

Pide la contraseña por consola (mínimo 10 caracteres; es la de **los 11 usuarios** `@prueba.test`, no está en ningún archivo). Solo
desarrollo, idempotente (correrlo de nuevo no duplica ni cambia contraseñas) y **nunca borra**. Carga, con la fecha real de hoy y sin
simular días pasados:

| Qué | Contenido |
|---|---|
| Personas | 11 usuarios `@prueba.test` (uno por perfil; `medica1` y `medico2` son profesionales, `enfermeria` personal de salud) y 12 pacientes (CI 9000012 a 9000023) |
| Catálogos | 6 estudios, 3 dietas de prueba, tipo "Ecógrafo" y "Ecógrafo Mindray MX7" (dueña: la Dra. Benítez, con su RUC `9000007-2`) |
| Economía | Tarifas generales (**sin PAP-COLPO**), acuerdos de reparto (Dra. Benítez 60 % y 40 % en ECO-CROMO; ecógrafo 30 %; **el Dr. Duarte, ninguno**) |
| IVA | ECO-TV y ECO-OBST-DOP al **5 %**, PAP-COLPO **exenta**, el resto 10 % |
| Seguros | Dos aseguradoras con RUC válido, cada una con convenio, 2 planes y tarifas del convenio; coberturas de las pacientes 1 a 4 (la 4, vencida). **Sin visaciones** |
| Facturación | Clínica de prueba (RUC `80900010-5`) y timbrado simulado `19990001`, 001-001, vigente |
| Agenda | Disponibilidades de lunes a viernes (08:00 a 12:00 y 14:00 a 18:00, turnos de 20 min) para los dos médicos |

**No** carga turnos, consultas, prestaciones, cobros, facturas, cajas, liquidaciones ni documentos clínicos.

### 1.3 Levantar la aplicación contra esa base y volver

```bash
DB_DATABASE=clinexa_recorrido php artisan serve --no-reload --port=8001
npm run dev        # en otra terminal, como siempre
```

- Abra **http://127.0.0.1:8001** (no `localhost`): así las cookies de sesión no se mezclan con las de `clinexa_dev` (si el servidor
  habitual está en `localhost:8000`, ese sigue apuntando a `clinexa_dev`).
- `--no-reload` es necesario: sin él, `artisan serve` descarta las variables que no están en el `.env` y volvería a `clinexa_dev`.
- **Volver:** `Ctrl+C` y el `php artisan serve` de siempre. Nada del `.env` cambió.
- Comandos de consola contra el recorrido: anteponer `DB_DATABASE=clinexa_recorrido`.

### 1.4 Reiniciar desde cero

El comando no borra, así que reiniciar es rehacer la base. **Esto borra solo `clinexa_recorrido`; lo corre usted** (nunca sobre
`clinexa_dev`):

```bash
dropdb -h 127.0.0.1 -U clinexa clinexa_recorrido
# ...y de nuevo 1.1 y 1.2
```

### 1.5 Si hoy es fin de semana o empieza a otra hora

Las disponibilidades son de lunes a viernes, de 08:00 a 12:00 y de 14:00 a 18:00. Ver la sección 4.

## 2. Datos para tipear

Todo lo de esta sección es inventado. Donde dice "(prueba)" el texto es literal. **No hay valores clínicos aprobados**: los rangos de
signos vitales siguen pendientes (`MED-1` de la guía) y el triaje solo valida lo imposible; los valores de abajo son de relleno.

### 2.1 Las pacientes y su historia del día

**Ya viene cargado** por el estado inicial (1.2): las 12 pacientes, sus coberturas (pacientes 1 a 4) y los catálogos (procedimientos, estudios,
dietas, aseguradoras, convenios, tarifas, acuerdos, disponibilidades). **Lo que se carga a mano** son los **turnos** (2.2) y las
**visaciones** (2.3), los dos por **Recepción** (`recepcion`); lo demás se genera atendiendo.

Apellidos y nombres como en el sistema (orden de la ficha). El nombre corto se usa en el guion.

| N.º | Paciente | CI | Seguro | Historia del día | Escenarios |
|---|---|---|---|---|---|
| 1 | Alvarenga Núñez, Gloria Estela (**Alvarenga**) | 9000012 | Ejemplo Uno, Plan Oro, `UNO-000101` | Mañana: consulta, el seguro cubre todo. Tarde: ecografía que el seguro no cubre | E3, E6 |
| 2 | Barrios Colmán, Liz Marlene (**Barrios**) | 9000013 | Ejemplo Uno, Plan Plata, `UNO-000102` | Consulta con copago de monto fijo; sin firma; su factura se pide anular y se rechaza | E4, E14 |
| 3 | Cabrera Franco, Rocío Abigail (**Cabrera**) | 9000014 | Previsión Dos, Plan Integral, `DOS-000201` | Mañana: consulta con copago de porcentaje. Tarde: ecografía sin visar; visación tarde | E5, E7 |
| 4 | Dávalos Pereira, Mirtha Graciela (**Dávalos**) | 9000015 | Previsión Dos, Plan Básico, **vencida** | Consulta como particular, con aviso | E8 |
| 5 | Escobar Giménez, Tania Soledad (**Escobar**) | 9000016 | — | Mañana: consulta particular, cobro equivocado y corregido, desbloqueo. Tarde: ecografía de 120 min | E1, E12, E13, E14 |
| 6 | Fernández Ocampos, Lourdes Beatriz (**Fernández**) | 9000017 | — | Consulta + ecografía hecha en la consulta; todos los documentos clínicos | E2, E13 |
| 7 | Godoy Ramírez, Elva Cristina (**Godoy**) | 9000018 | — | Consulta con el Dr. Duarte (sin acuerdo); factura anulada por solicitud | E10, E14 |
| 8 | Insaurralde Paiva, Karina Noelia (**Insaurralde**) | 9000019 | — | Pap + Colposcopía (sin tarifa) | E9 |
| 9 | Jara Villalba, Dahiana Yesica (**Jara**) | 9000020 | — | Mañana: triaje terminado y se retira. Tarde: vuelve, triaje iniciado, se va | E11 |
| 10 | Lugo Benegas, Viviana Raquel (**Lugo**) | 9000021 | — | Mañana: rechaza la atención. Tarde: turno al que no viene | E11 |
| 11 | Maidana Torales, Nancy Beatriz (**Maidana**) | 9000022 | — | Mañana: no se presentó. Después llega como urgencia | E11 |
| 12 | Narváez Sosa, Carmen Liliana (**Narváez**) | 9000023 | — | Atendida entre pacientes | E11 |

Profesionales: **Dra. Benítez** (`medica1`, consultorio 101; con acuerdo de reparto y dueña del ecógrafo) y **Dr. Duarte** (`medico2`,
consultorio 102; **sin acuerdo**).

### 2.2 Turnos (los carga Recepción antes de abrir)

"Nuevo turno" → paciente, profesional, procedimiento y horario libre. Origen: Presencial. Las horas son las del guion con inicio a las
08:00 (sección 4 para otra hora). Dar a una paciente un **segundo turno** con el mismo profesional el mismo día pide confirmación:
confirme (es `AG-5`).

| Turno | Hora | Profesional | Paciente | Procedimiento | Duración |
|---|---|---|---|---|---|
| T01 | 08:20 | Benítez | Escobar | Consulta (`CONS-001`) | 20 |
| T02 | 08:40 | Benítez | Alvarenga | Consulta | 20 |
| T03 | 09:00 | Benítez | Fernández | Consulta | 20 |
| T04 | 09:20 | Benítez | Barrios | Consulta | 20 |
| T05 | 09:40 | Benítez | Cabrera | Consulta | 20 |
| T06 | 10:00 | Benítez | Insaurralde | Pap + Colposcopía (`PAP-COLPO`) | 30 |
| T07 | 08:20 | Duarte | Godoy | Consulta | 20 |
| T08 | 08:40 | Duarte | Jara | Consulta | 20 |
| T09 | 09:00 | Duarte | Lugo | Consulta | 20 |
| T10 | 09:20 | Duarte | Maidana | Consulta | 20 |
| T11 | 09:40 | Duarte | Dávalos | Consulta | 20 |
| T12 | sin hora | Duarte | Narváez | Consulta, **Entre pacientes** | — |
| T13 | 14:00 | Benítez | Escobar | Ecografía Morfológica (`ECO-MORFO`) | **120** |
| T14 | 14:20 | Duarte | Alvarenga | Ecografía Transvaginal (`ECO-TV`) | 30 |
| T15 | 16:00 | Duarte | Lugo | Consulta | 20 |
| T16 | 16:20 | Duarte | Jara | Consulta | 20 |
| T17 | 16:20 | Benítez | Cabrera | Ecografía Transvaginal | 30 |

- **Cupo entre pacientes** de hoy: Dr. Duarte = **1** (Turnos → "Cupo entre pacientes").
- La **urgencia** de Maidana se registra a media mañana (H11); no es un turno previo.

### 2.3 Visaciones (las carga Recepción desde el turno, botón "Visación")

Aprobadas con el número, el resultado y "Paga la paciente" de la tabla. Procedimiento: el del turno. Fecha de la visación: hoy.

| N.º visación | Paciente / turno | Aseguradora, plan | Resultado | Paga la paciente | Total (tarifa del convenio) | Paciente | Aseguradora | Cuándo |
|---|---|---|---|---|---|---|---|---|
| `V-R01` | Alvarenga, T02 (Consulta) | Ejemplo Uno, Plan Oro | Aprobado | **Ninguno** | 200.000 | 0 | 200.000 | Antes de abrir |
| `V-R02` | Barrios, T04 (Consulta) | Ejemplo Uno, Plan Plata | Aprobado | **Monto fijo 100.000** | 200.000 | 100.000 | 100.000 | Antes de abrir |
| `V-R03` | Cabrera, T05 (Consulta) | Previsión Dos, Plan Integral | Aprobado | **Porcentaje 20** | 220.000 | 44.000 | 176.000 | Antes de abrir |
| `V-R04` | Alvarenga, T14 (ECO-TV) | Ejemplo Uno, Plan Oro | **No cubierto** | (la paciente paga todo) | tarifa general 350.000 | 350.000 | 0 | Antes de abrir |
| `V-R05` | Cabrera, T17 (ECO-TV) | Previsión Dos, Plan Integral | Aprobado | **Porcentaje 20** | 320.000 | 64.000 | 256.000 | **Tarde, después de finalizar** (E7) |

Sin visación a propósito: **Cabrera, T17** (hasta `V-R05`) y **Dávalos, T11** (cobertura vencida). Las demás pacientes no tienen seguro.
Repetir el número `V-R01` en otra visación de **Ejemplo Uno** → NIEGA (`CV-8`).

### 2.4 Tarifas, IVA y reparto (lo que se espera ver)

Tarifas generales: Consulta 250.000; ECO-ABD, ECO-TV y ECO-OBST 350.000; ECO-OBST-DOP 450.000; ECO-CROMO 600.000; ECO-MORFO 700.000;
**PAP-COLPO sin tarifa**. IVA incluido en el precio: 10 % = monto/11; 5 % = monto/21; exenta = 0; al guaraní entero.

| Prestación | Monto que paga la paciente | IVA | Reparto (sobre el monto total) |
|---|---|---|---|
| Consulta particular, Benítez, 250.000 | 250.000 | 10 %: gravado 227.273, IVA 22.727 | Profesional 60 % = 150.000; clínica 100.000 |
| ECO-TV, Benítez, 350.000 | 350.000 | 5 %: gravado 333.333, IVA 16.667 | Profesional 210.000; **equipo 30 % = 105.000** (dueña: Benítez, línea aparte); clínica 35.000 |
| Copago fijo (V-R02), total 200.000 | 100.000 | 10 %: gravado 90.909, IVA 9.091 | 120.000 / 80.000 |
| Copago 20 % (V-R03), total 220.000 | 44.000 | 10 %: gravado 40.000, IVA 4.000 | 132.000 / 88.000 |
| PAP-COLPO (tarifa que carga Gerencia: 300.000) | 300.000 | **exenta**: exento 300.000, IVA 0 | Profesional 180.000; clínica 120.000 |
| ECO-TV no cubierto (V-R04), Duarte con acuerdo 50 % | 350.000 | 5 %: IVA 16.667 | Profesional 175.000; equipo 105.000; clínica 70.000 |
| ECO-TV con V-R05, total 320.000 | 64.000 | 5 %: gravado 60.952, IVA 3.048 | 192.000 / 96.000 / 32.000 |
| ECO-MORFO, Benítez, 700.000 | 700.000 | 10 %: gravado 636.364, IVA 63.636 | 420.000 / 210.000 / 70.000 |

Datos que carga Gerencia durante el día:

- **Tarifa** (Económico → Tarifas → Nueva): procedimiento `PAP-COLPO`, profesional vacío (general), monto **300.000**, vigente desde **hoy**.
- **Acuerdo de reparto** (Acuerdos de reparto → Nuevo): tipo Profesional, **Dr. Duarte**, procedimiento vacío (todos), **50 %**, desde **hoy**.

### 2.5 Cobros y cajas

Apertura: **Caja 1 = 500.000**, **Caja 2 = 300.000** (efectivo). Un cobro por paciente con "Facturar y cobrar". Referencias de
transferencia: `TR-R-0001`, `TR-R-0002`... en orden.

| Cobro | Caja | Paciente | Medio y monto | Notas |
|---|---|---|---|---|
| C1a | Caja 1 | Escobar | **Tarjeta 250.000** | **Error a propósito** (era efectivo): se anula en los 10 minutos |
| C1b | Caja 1 | Escobar | Efectivo 250.000 | "Cobrar de nuevo" con la factura que reemplaza |
| C2 | Caja 1 | Fernández (Consulta + ECO-TV) | Efectivo 300.000 + Tarjeta 200.000 + Transferencia 100.000 (`TR-R-0001`) | Cobro mixto, total 600.000 |
| C3 | Caja 1 | Barrios | Efectivo 100.000 | Su factura se pide anular más tarde: se rechaza |
| C4 | Caja 1 | Cabrera (consulta) | Transferencia 44.000 (`TR-R-0002`) | |
| C5a | Caja 2 | Godoy | Transferencia 250.000 (`TR-R-0003`) | Se pide anular pasados los 10 minutos: se aprueba |
| C5b | Caja 2 | Godoy | Transferencia 250.000 (`TR-R-0004`) | Cobro nuevo con la factura que reemplaza |
| C6 | Caja 2 | Dávalos | Tarjeta 250.000 | Particular, con aviso |
| C7 | Caja 2 | Maidana (urgencia) | Efectivo 250.000 | |
| C8 | Caja 2 | Narváez | Efectivo 250.000 | |
| C9 | Caja 1 | Insaurralde | Efectivo 300.000 | Después de tarifar |
| C10 | Caja 2 | Alvarenga (ECO-TV) | Tarjeta 350.000 | La consulta de la mañana (seguro cubre todo) **no se cobra** |
| C11 | Caja 1 | Cabrera (ECO-TV) | Efectivo 64.000 | Después de aplicar `V-R05` |
| C12 | Caja 1 | Escobar (ECO-MORFO) | Tarjeta 700.000 | |

**Esperado al cierre** (apertura + cobros − devoluciones de lo anulado):

| | Efectivo | Transferencia | Tarjeta |
|---|---|---|---|
| Caja 1 | 500.000 + 250.000 + 300.000 + 100.000 + 300.000 + 64.000 = **1.514.000** | 100.000 + 44.000 = **144.000** | (250.000 − 250.000) + 200.000 + 700.000 = **900.000** |
| Caja 2 | 300.000 + 250.000 + 250.000 = **800.000** | (250.000 − 250.000) + 250.000 = **250.000** | 250.000 + 350.000 = **600.000** |

**Lo que se declara al cerrar**:

- **Caja 1**: Efectivo **1.509.000** (faltante de 5.000), Transferencia 144.000, Tarjeta 900.000. Motivo de la diferencia: `Faltante de billetes chicos, se revisa mañana (prueba)`.
- **Caja 2, primer cierre**: Efectivo 800.000, Tarjeta 600.000, Transferencia **205.000** (error de tipeo, −45.000). Motivo: `Posible error de tipeo en transferencias (prueba)`. Gerencia la **devuelve** con la nota `Revise el total de transferencias contra el extracto (prueba)`.
- **Caja 2, segundo cierre**: Efectivo 800.000, Tarjeta 600.000, Transferencia **250.000**, sin diferencia.

### 2.6 Datos clínicos de relleno

Triaje (Enfermería), siempre el mismo juego: temperatura 36,5; frecuencia cardíaca 78; frecuencia respiratoria 16; presión 110/70;
saturación 98; peso 62; talla 162; anamnesis: `Control de rutina (prueba)`.

Consulta (médico) mínima para finalizar: motivo `Control ginecológico de rutina (prueba)`; anamnesis `Sin antecedentes relevantes (prueba)`;
hallazgos `Examen sin particularidades (prueba)`; diagnóstico CIE-10 `Z01.4` (buscador). Para la urgencia: motivo `Dolor pélvico (prueba)`, diagnóstico `R10.2`.

Documentos (todos de **Fernández**, H6):

| Documento | Datos |
|---|---|
| Receta | Medicamento 1: `Medicamento de prueba A`, cantidad `1 caja`, indicación de uso `Tomar uno cada 8 horas por 5 días (prueba)`. Medicamento 2: `Medicamento de prueba B`, `1 frasco`, `Una cucharada cada 12 horas (prueba)`. Motivo de "Anular y corregir": `Corrección de la cantidad (prueba)` |
| Indicaciones generales | Tipo "Dieta" → "Insertar dieta": `Dieta de prueba A (demo)`; agregar al final: `Control en 15 días (prueba)` |
| Informe de estudio | Procedimiento `ECO-TV`, solicitante `Dra. Benítez (prueba)`, texto `Informe de prueba del recorrido, sin valor clínico.` |
| Reposo | 3 días, desde **hoy**, modalidad reposo absoluto; **prórroga**: 2 días, desde hoy + 3, indicando el `RM-…` anterior. Motivo de anulación: `Emitido por error de prueba` |
| Orden de estudio | Del catálogo `Hemograma completo` y a mano `Densitometría ósea`; indicación clínica `Control de rutina (prueba)` |
| Constancia de estado | Una `CE-…` del turno atendido |
| Adenda | Motivo `Aclaración posterior al cierre (prueba)`, texto `Nota agregada después de cerrar la consulta (prueba).` |
| Desbloqueo (sobre la consulta de **Escobar**) | Motivo `Corregir la anamnesis cargada por error (prueba del recorrido)`; corrección: anamnesis `Anamnesis corregida (prueba)` |
| Nota de retiro (Jara) | `Nota de prueba: la paciente se retiró antes de la consulta.` |

### 2.7 Motivos, notas y otros textos

| Dónde | Texto |
|---|---|
| Anular factura en 10 min (Escobar) | Motivo "Otro (detallar)", detalle `Medio de pago equivocado: pagó en efectivo (prueba)` |
| Solicitar anulación (Godoy, pasado el plazo) | Motivo "Error en el procedimiento o el monto" |
| Solicitar anulación (Barrios) | Motivo "Otro (detallar)": primero **sin** detalle (NIEGA), luego `La paciente dice que pagó de más (prueba)`. Nota de rechazo de Gerencia: `El monto cobrado es el de la visación V-R02 (prueba)` |
| Aplicar visación tarde (Cabrera) | Motivo `Visación llegó después de la consulta (prueba)` |
| Quitar línea de liquidación | Motivo `Falta revisar la visación (prueba)` |
| Motivo de 3 letras (para los NIEGA de "mínimo 5") | `abc` |
| Pago de aseguradora | Transferencia, referencia `PAG-R-0001`, `PAG-R-0002`... |

### 2.8 Personas y RUC (E15)

Recepción, Personas → nueva. Datos de relleno: nombres `Prueba Recorrido`, dirección `Calle Prueba 400`, teléfono `0981 000 400`.

| Caso | Tipo | Documento / RUC | Esperado |
|---|---|---|---|
| a | Física `Prueba Recorrido, Ana Lucía`, nac. 15/05/1990 | CI `9100001` | Alta |
| b | Física, la misma CI | CI `9100001` | NIEGA (ya existe) |
| c | Física con RUC propio | CI `9100002`, RUC `9100002-5` | Alta (RUC aparte del CI) |
| d | Física, RUC con dígito malo | CI `9100003`, RUC `9100003-0` | NIEGA (dígito verificador) |
| e | Jurídica `Laboratorio Prueba S.R.L.` | RUC `80080099-0` | Alta |
| f | Jurídica | RUC `80080099-1` | NIEGA (dígito) |
| g | Jurídica | RUC `44444401-7` | Alta |
| h | Jurídica | RUC `44444401-6` | NIEGA (dígito) |
| i | Jurídica | RUC `80080099-0` (el de e) | NIEGA: "Ya hay una persona registrada con ese RUC." |
| j | Jurídica | sin RUC | NIEGA: "Una persona jurídica necesita su RUC." |
| k | Jurídica | RUC `123-4` (corto) | NIEGA (formato) |
| l | Tipo de documento | abrir el selector | Solo CI y pasaporte |

### 2.9 Liquidaciones (otro día)

Corte: hoy (o el último día del mes si se hace a fin de mes; nunca una fecha futura). Pagos: hoy, Transferencia.

| Liquidación | Aseguradora | Prestaciones esperadas | Total |
|---|---|---|---|
| L1 | Seguros Ejemplo Uno S.A. | Alvarenga, consulta (200.000 de aseguradora) y Barrios, consulta (100.000). **Barrios sin firma** | Vista previa: solo Alvarenga = 200.000 |
| L2 | Previsión Médica Dos S.A. | Cabrera consulta (176.000) y Cabrera ECO-TV (256.000) | 432.000; al quitar la ecografía, 176.000 |

Pagos de L1: 120.000 (queda PARCIAL, saldo 80.000); 100.000 (NIEGA, supera el saldo); 80.000 (PAGADO).

## 3. El día, hora por hora

La línea de tiempo (con inicio a las 08:00). El detalle de cada historia está en 3.2.

| Hora | Qué pasa |
|---|---|
| 07:30 | **Antes de abrir**: Gerencia revisa; Recepción prepara turnos, cupo y visaciones (3.1) |
| 07:55 | Cajas (H0) |
| 08:20 | Llegan Escobar (H5), Godoy (H7); 08:40 Alvarenga (H1), Jara (H9) |
| 09:00 | Fernández (H6), Lugo (H10); 09:20 Barrios (H2), Maidana **no se presenta** (H11); 09:40 Cabrera (H3), Dávalos (H4) |
| 10:00 | Insaurralde (H8); entre pacientes Narváez (H12) |
| 10:30 | Gerencia: pendientes de tarifar y de reparto (H8, H7); solicitud de anulación de Godoy pasados los 10 minutos (H7) |
| 11:00 | Urgencia de Maidana (H11) |
| 11:30 | Recepción: firmas y tickets; solicitud de Barrios (H2); mediodía: E12, E15, E16 (3.3) |
| 14:00 | Escobar, ecografía de 120 min (H13); 14:20 Alvarenga, ecografía no cubierta (H1) |
| 16:00 | Lugo no viene (H10); 16:20 Jara vuelve (H9), Cabrera, ecografía sin visar (H3) |
| 17:30 | Cierre de jornada de los médicos (3.4) |
| 18:00 | Cierre de cada caja; 18:30 Gerencia revisa (3.4) |
| 19:00 | Auditoría (3.5) |
| Otro día | Reversión de un cobro de una caja cerrada y liquidaciones (3.6) |

### 3.1 Antes de abrir (07:30)

**Gerencia** (`gerencia`) revisa lo que usará todo el día (solo mirar y cargar la configuración; no atiende ni cobra):

- [ ] **A1** (`gerencia`) Facturas → "Datos de la clínica" (módulo Facturas). → "Clínica de Prueba (demo) S.A.", RUC `80900010-5`. Cambiar el RUC por `80900010-0` → NIEGA (dígito); no guarde.
- [ ] **A2** (`gerencia`) Facturas → Timbrados. → Uno VIGENTE: `19990001`, 001-001, rango 1 a 9.999.999, vigente hoy. Solo mirar: los casos de timbrado vencido o agotado (`FT-3`, `FT-4`) dejarían la base sin timbrado para el resto del día; pruébelos al final o en otra pasada.
- [ ] **A3** (`gerencia`) Facturas → Tasas de IVA. → ECO-TV 5 %, ECO-OBST-DOP 5 %, PAP-COLPO Exenta, el resto 10 %.
- [ ] **A4** (`gerencia`) Económico → Tarifas. → Las de 2.4; **PAP-COLPO no está**. Auditoría ya muestra las altas de hoy.
- [ ] **A5** (`gerencia`) Acuerdos de reparto. → Benítez 60 % general y 40 % ECO-CROMO; equipo Mindray 30 %; **Duarte, ninguno**.

**Recepción** (`recepcion`) prepara el día:

- [ ] **A6** (`recepcion`) Turnos → "Nuevo turno" para **hoy** con una fecha pasada → NIEGA ("No se agendan turnos en una fecha pasada"). Para hoy con un horario que ya pasó → NIEGA ("Ese horario ya pasó").
- [ ] **A7** (`recepcion`) Turnos → "Cupo entre pacientes" (se fija con Disponibilidades; Gerencia solo lo ve). Cupo de hoy: Dr. Duarte = **1** (E11). Cupo 31 → NIEGA (0 a 30).
- [ ] **A8** (`recepcion`) Cargar T01 a T17 (2.2). T01 (Benítez, consultorio 101) y T07 (Duarte, 102) a las 08:20 **conviven** (profesionales y consultorios distintos). Dar a otra paciente un turno con Benítez a las 08:20 (ya ocupado) → no se ofrece ese horario o NIEGA ("Ese horario se acaba de ocupar"). Dar a Escobar otro turno que se superponga con T01 → NIEGA (`AG-4`).
- [ ] **A9** (`recepcion`; alternativo `facturacion`) Visaciones `V-R01` a `V-R04` desde el turno (botón **Visación**, 2.3). Antes de cargar: el turno dice "Seguro: …" y "Falta visación"; después, "Visación cargada". `V-R05` **no**: queda para la tarde.
- [ ] **A10** (`recepcion`) Visación con el número repetido `V-R01` para Ejemplo Uno (otra paciente de Uno, por ejemplo Barrios en otra línea) → NIEGA (`CV-8`). Una sexta línea en una visación, o un porcentaje de 101 → NIEGA (`CV-9`).
- [ ] **A11** (`recepcion`; también la ve `gerencia`) Agenda general de hoy. → Los 16 turnos con hora en sus filas y el entre pacientes de Duarte en "Sin horario" ("Cupo: 1 de 1"); "Seguro: …" y "Visación cargada" en T02, T04, T05 y T14, y "Falta visación" en T17; sin nada clínico (`AG-18`, `AG-19`).

### H0. Las cajas (07:55)

- [ ] **H0.1** (`caja1`) Cobros → Cobrar sin caja abierta. → NIEGA: "Abra su caja antes de cobrar."
- [ ] **H0.2** (`caja1`) Cajas → "Mi caja": abrir con monto inicial `-1`. → NIEGA. Abrir con **500.000**. → ABIERTA.
- [ ] **H0.3** (`caja1`) Abrir otra. → NIEGA: "Ya tiene una caja abierta: ciérrela antes de abrir otra."
- [ ] **H0.4** (`caja2`) Abrir con **300.000**. → ABIERTA, distinta de la de Caja 1.
- [ ] **H0.5** (`caja2`) Cajas. → Solo ve la suya (`CC-12`).

### 3.2 Las historias

**Antes de empezar cada una**: la paciente llega (Recepción (`recepcion`) puede **Confirmar** el turno en Turnos), Enfermería hace el triaje, el médico atiende. Pasos de rutina:

- **Triaje** (`enfermeria`; alternativo, un médico, que también tiene Preparación): Triaje → paciente → "Iniciar triaje" → anamnesis y signos de 2.6 → "Terminar triaje".
- **Atender** (el médico del turno: `medica1` o `medico2`, nadie más): Consulta → paciente → "Atender" → motivo, anamnesis, hallazgos, diagnóstico de 2.6; esperar el autoguardado → "Finalizar".
- **Cobrar** (`caja1` o `caja2`; solo Caja cobra): Cobros → Cobrar → buscar a la paciente → tildar sus prestaciones → medios y montos de 2.5 → "Facturar y cobrar".

#### H5. Escobar, 08:20: consulta particular hasta el cobro en efectivo (E1), cobro equivocado (E14) y desbloqueo (E13)

- [ ] **H5.1** (`enfermeria`) Triaje completo. → "Con triaje".
- [ ] **H5.2** (`medica1`) Atender y **Finalizar**. → Consulta FINALIZADA; turno ATENDIDO; prestación creada.
- [ ] **H5.3** (`gerencia`) Prestaciones → la de Escobar. → TARIFADO, 250.000, pagador PARTICULAR, REPARTIDO: profesional 150.000, clínica 100.000.
- [ ] **H5.4** (`caja1`) Cobrar a Escobar con **C1a** (Tarjeta 250.000, el error). → Factura emitida y cobrada (la primera del día, `001-001-0000001`); IVA 22.727.
- [ ] **H5.5** (`caja1`) **Dentro de los 10 minutos**: Facturas → la factura → "Anular la factura", motivo "Otro (detallar)" con el detalle de 2.7. → ANULADA; cobro revertido (un egreso de tarjeta en su caja); el número no se reutiliza (`R2-3`).
- [ ] **H5.6** (`caja1`) Desde la factura anulada: "Cobrar de nuevo con una factura que reemplace a esta" con **C1b** (Efectivo 250.000). → Factura `…0002`, COBRADA; la anulada la muestra como reemplazada (`R2-4`).
- [ ] **H5.7** (`caja1`) Facturas → PDF de la nueva. → "FACTURA", con la marca de simulación. Abrirlo otra vez → "FACTURA (COPIA)" (`R2-2`).
- [ ] **H5.8** (`medica1`) En la consulta cerrada de Escobar: cualquier modificación → NIEGA ("La consulta está cerrada…").
- [ ] **H5.9** (`supervision`, el único perfil que desbloquea; nunca quien atendió) Desbloquear esa consulta (motivo de 2.6, 20 a 500 caracteres). → Ventana VIGENTE por 24 horas. Corregir la anamnesis. → "Corregida 1 vez"; versión guardada; la consulta sigue cerrada (`CO-6`, `CO-9`). Un segundo desbloqueo con la ventana vigente → NIEGA (`CO-8`).
- [ ] **H5.10** (`medica1`) Intentar desbloquear su propia consulta → NIEGA (no tiene el módulo).

#### H7. Godoy, 08:20: Dr. Duarte sin acuerdo (E10), y factura anulada por solicitud (E14)

- [ ] **H7.1** (`enfermeria`, `medico2`) Triaje, atender y finalizar. → FINALIZADA.
- [ ] **H7.2** (`gerencia`) Prestaciones → la de Godoy. → TARIFADO 250.000, pero **Pendiente de reparto**: "Falta el acuerdo del profesional para el dd/mm/aaaa." La consulta se finalizó igual (`R5-6`).
- [ ] **H7.3** (`gerencia`) Prestaciones → filtro "Pendientes de reparto". → Aparece. **Repartir** sin acuerdo → NIEGA.
- [ ] **H7.4** (`gerencia`) Acuerdos de reparto → Nuevo (2.4: Duarte, 50 %, desde hoy). Volver a la prestación → **Repartir**. → REPARTIDO: profesional 125.000, clínica 125.000. Repartir otra vez → "Esta prestación ya está repartida." (`R5-7`).
- [ ] **H7.5** (`caja2`) Cobrar a Godoy con **C5a** (Transferencia 250.000, `TR-R-0003`). Anotar la hora de emisión.
- [ ] **H7.6** (`caja2`) **Pasados los 10 minutos** (siga con otras historias mientras tanto): Facturas → la factura. → Ya no hay "Anular": solo "Solicitar la anulación" (`R2-5`).
- [ ] **H7.7** (`caja2`) Solicitar con motivo "Otro (detallar)" y sin detalle → NIEGA. Solicitar con "Error en el procedimiento o el monto". → PENDIENTE. Solicitar de nuevo → NIEGA (ya hay una).
- [ ] **H7.8** (`caja2`) Con la solicitud pendiente, la pestaña "Solicitudes de anulación" no aparece y la URL `/admin/facturas/solicitudes` da 403 (`R2-8`).
- [ ] **H7.9** (`gerencia`) Facturas → "Solicitudes de anulación (N)" → **Rechazar** sin nota → NIEGA. Aprobar con la Caja 2 abierta. → Factura ANULADA y cobro revertido (egreso de transferencia en la Caja 2); `anulada_por` = Gerencia.
- [ ] **H7.10** (`caja2`) "Cobrar de nuevo con una factura que reemplace a esta" con **C5b** (`TR-R-0004`). → Nueva factura COBRADA.

#### H1. Alvarenga, 08:40: el seguro cubre todo (E3); a las 14:20, ecografía no cubierta (E6)

- [ ] **H1.1** (`enfermeria`, `medica1`) Triaje, atender y finalizar la consulta (T02).
- [ ] **H1.2** (`gerencia`) Prestaciones → la de Alvarenga. → TARIFADO; pagador **ASEGURADORA**; total 200.000; paciente **0**; aseguradora 200.000; con `V-R01`; reparto sobre 200.000: 120.000 / 80.000.
- [ ] **H1.3** (`caja1`) Cobrar a Alvarenga. → La consulta **no se ofrece**: se muestra "A cargo de aseguradora" (`R1-12`). No hay cobro ni factura.
- [ ] **H1.4** (`recepcion`; alternativo `facturacion`) Cobertura y visaciones de Alvarenga → la prestación de aseguradora → **Firma recibida**; luego el **Ticket**. → La firma con fecha y usuario; el ticket (PDF) con clínica, profesional, paciente, aseguradora, plan, `UNO-000101`, `V-R01` y la línea de firma, **sin montos ni nada clínico**. (La firma y el ticket valen para toda prestación de aseguradora, también con la parte de la paciente en cero: la liquidación exige la firma.)
- [ ] **H1.5** (14:20, `enfermeria`, `medico2`) Triaje, atender y finalizar la ecografía (T14, con `V-R04`). → Prestación de **ECO-TV, 350.000, pagador PACIENTE** (tarifa **general**, con referencia a la visación), no 300.000 (`CV-10`).
- [ ] **H1.6** (`caja2`) Cobrar con **C10** (Tarjeta 350.000). → Factura con IVA **5 %**: IVA 16.667.
- [ ] **H1.7** (`gerencia`) La prestación de la ecografía: reparto con acuerdo del Dr. Duarte (ya cargado): 175.000 / equipo 105.000 / 70.000, repartido solo al finalizar.

#### H6. Fernández, 09:00: ecografía en la consulta, factura con dos tasas (E2) y todos los documentos (E13)

- [ ] **H6.1** (`enfermeria`) Triaje completo.
- [ ] **H6.2** (`medica1`) Atender. Cargar lo mínimo de 2.6. En **Estudios**, agregar el informe de 2.6 (ECO-TV). → Autoguardado; "PDF del informe" disponible.
- [ ] **H6.3** (`medica1`) **Receta**: nueva; medicamento 1 sin indicación de uso → NIEGA; completar los dos de 2.6; "Vista previa" (PDF con "VISTA PREVIA - NO VÁLIDA"); **Emitir** (número `RE-…`). Dejar una segunda receta en **borrador** y tocar **Finalizar** → NIEGA ("Tiene recetas en borrador…", `TA-13`). Anularla con "Anular y corregir" (motivo `abc` NIEGA; con el de 2.6 sí): se crea otra; emitirla.
- [ ] **H6.4** (`medica1`) **Indicaciones generales**: tipo Dieta → "Insertar dieta" `Dieta de prueba A (demo)`; el texto se copia y se puede editar; agregar la línea de 2.6. "PDF de indicaciones". → Hoja aparte, sin medicamentos ni diagnósticos.
- [ ] **H6.5** (`medica1`) **Finalizar**. → Consulta FINALIZADA y **dos prestaciones**: Consulta (250.000) y ECO-TV (350.000) con origen "Informe de estudio".
- [ ] **H6.6** (`medica1`) Con la consulta cerrada: **Reposo médico** (otra pestaña): 3 días desde hoy, reposo absoluto → `RM-…`; "hasta" = hoy + 2. "Desde" ayer → NIEGA. Días 0 → NIEGA. **Prórroga**: 2 días desde hoy + 3 indicando el número anterior → se emite; con "desde" = hoy + 2 → NIEGA (`RM-4`). PDF: marca **BORRADOR SUJETO A REVISIÓN**. Anular el primero con motivo `abc` → NIEGA; con el de 2.6 → ANULADO.
- [ ] **H6.7** (`medica1`) **Orden de estudio** ("Pedir estudio"): catálogo + a mano (2.6). → `OE-…`, el escrito a mano no entra al catálogo; PDF con la marca de borrador.
- [ ] **H6.8** (`medica1`) **Constancia de estado**: una `CE-…` del turno atendido. → Con profesional y matrícula, sin diagnóstico.
- [ ] **H6.9** (`medica1`, que atendió; alternativo `supervision`) **Adenda** a la consulta cerrada: motivo de 5 caracteres → NIEGA; con el de 2.6 → se ve debajo de lo original (`CO-2`).
- [ ] **H6.10** (`medico2`, `enfermeria`, `recepcion`) Abrir por URL la receta, el reposo y la orden de Fernández. → NIEGA (403/404).
- [ ] **H6.11** (`gerencia`) Prestaciones → las dos de Fernández. → Consulta: profesional 150.000, clínica 100.000. **ECO-TV: profesional 210.000, equipo 105.000 (línea aparte, dueña Benítez), clínica 35.000** (`R5-4`).
- [ ] **H6.12** (`caja1`) Cobrar a Fernández las dos prestaciones con **C2** (mixto: Efectivo 300.000 + Tarjeta 200.000 + Transferencia 100.000, `TR-R-0001`). Antes: con los pagos sumando 599.000 → NIEGA (`CC-4`). → **Una** factura con dos líneas: Consulta 10 % (IVA 22.727) y ECO-TV 5 % (IVA 16.667); total 600.000; IVA total 39.394; totales por tasa que suman.

#### H2. Barrios, 09:20: copago de monto fijo (E4); su factura se pide anular y Gerencia rechaza (E14)

- [ ] **H2.1** (`enfermeria`, `medica1`) Triaje, atender y finalizar.
- [ ] **H2.2** (`gerencia`) Prestación. → Total 200.000: **paciente 100.000**, aseguradora 100.000, `V-R02`; reparto 120.000 / 80.000.
- [ ] **H2.3** (`caja1`) Cobrar: se ofrece **solo 100.000**. Cobrar con **C3** (Efectivo 100.000). → Factura con **solo la parte de la paciente** (IVA 9.091); la de la aseguradora no entra (`FT-10`).
- [ ] **H2.4** (`recepcion`; alternativo `facturacion`) **No** marcar la firma de Barrios (para E7/liquidación L1). Sí generar el ticket (`CV-16`).
- [ ] **H2.5** (`caja1`) Pasados los 10 minutos: "Solicitar la anulación" con "Otro (detallar)" sin detalle → NIEGA; con el detalle de 2.7 → PENDIENTE.
- [ ] **H2.6** (`gerencia`) **Rechazar** con la nota de 2.7. → La factura sigue vigente y el cobro también. La nota de 3 letras → NIEGA (5 a 300).
- [ ] **H2.7** (`caja1`) Volver a solicitar la misma. → Se puede (rechazada se puede pedir de nuevo); dejar pendiente o rechazar otra vez (`R2-9`, `R2-10`).

#### H3. Cabrera, 09:40: copago de porcentaje (E5); 16:20, ecografía sin visar y visación tarde (E7)

- [ ] **H3.1** (`enfermeria`, `medica1`) Triaje, atender y finalizar la consulta (T05, `V-R03`).
- [ ] **H3.2** (`gerencia`) Prestación. → Total 220.000: **paciente 44.000** (20 %), aseguradora 176.000; reparto 132.000 / 88.000.
- [ ] **H3.3** (`caja1`) Cobrar **C4** (Transferencia 44.000, `TR-R-0002`). → Factura con IVA 4.000 (44.000/11).
- [ ] **H3.4** (`recepcion`; alternativo `facturacion`) Marcar **firma recibida** y generar el ticket. → Firma con fecha y usuario.
- [ ] **H3.5** (16:20, `enfermeria`, `medica1`) Triaje, atender y finalizar la ecografía (T17). **Sin visación**.
- [ ] **H3.6** (`recepcion`) Cobertura y visaciones de Cabrera → la prestación de ECO-TV. → **Particular, 350.000, con aviso** "no hay visación"; no frenó la atención (`CV-11`).
- [ ] **H3.7** (`recepcion`) Cargar `V-R05` (2.3) y **"Aplicar visación"** a esa prestación, con el motivo de 2.7. → Se recalcula: total 320.000, **paciente 64.000**, aseguradora 256.000; la firma vuelve a pedirse; el reparto anterior queda ANULADO y se calcula otro sobre 320.000: 192.000 / 96.000 / 32.000 (`CV-12`, `RP-10`).
- [ ] **H3.8** (`recepcion`) Marcar la firma y generar el ticket de la ecografía.
- [ ] **H3.9** (`caja1`) Cobrar **C11** (Efectivo 64.000). → Factura con IVA 3.048 (5 %).
- [ ] **H3.10** (`recepcion`) **Después del cobro**: "Aplicar visación" otra vez a esa prestación (o a la consulta de la mañana, ya cobrada). → NIEGA: ya está cobrada y facturada ("Primero se anula la factura", `CV-13`, `FT-11`). La firma recibida sí se puede marcar.

#### H4. Dávalos, 09:40: cobertura vencida (E8)

- [ ] **H4.1** (`enfermeria`, `medico2`) Triaje, atender y finalizar.
- [ ] **H4.2** (`gerencia`) Prestación. → **Particular**, 250.000, con el aviso "La cobertura de la paciente venció el …". La atención no se frenó (`CV-11`). Pendiente de reparto o ya repartido según la hora en que se cargó el acuerdo del Dr. Duarte (H7.4): si lo cargó antes de finalizar, sale repartido; si no, "Repartir".
- [ ] **H4.3** (`caja2`) Cobrar **C6** (Tarjeta 250.000). → Factura 10 %.
- [ ] **H4.4** (`recepcion`) Cobertura y visaciones de Dávalos. → La cobertura figura vencida; la prestación es particular: sin firma ni ticket.

#### H8. Insaurralde, 10:00: Pap + Colposcopía sin tarifa (E9)

- [ ] **H8.1** (`enfermeria`, `medica1`) Triaje, atender y finalizar. → Finaliza sin problema.
- [ ] **H8.2** (`gerencia`) Prestaciones. → **Pendiente de tarifar**: sin monto ni reparto, con el aviso. Filtro de pendientes.
- [ ] **H8.3** (`caja1`) Cobrar a Insaurralde. → NIEGA: no se ofrece (sin monto, `CC-7`). Recepción y Caja ven la prestación sin tarifa.
- [ ] **H8.4** (`gerencia`) Tarifas → Nueva (2.4: PAP-COLPO, 300.000, hoy). Segunda tarifa para el mismo procedimiento → NIEGA ("Use Cambiar tarifa"). En la prestación: **Tarifar**. → TARIFADO 300.000 con la tarifa de su fecha de servicio; reparto 180.000 / 120.000 (sin equipo).
- [ ] **H8.5** (`caja1`) Cobrar **C9** (Efectivo 300.000). → Factura **exenta**: exento 300.000, IVA 0.

#### H9. Jara: retiro con triaje terminado (08:40) y cierre de jornada (16:20) (E11)

- [ ] **H9.1** (08:40, `enfermeria`; alternativo un médico) Iniciar y **terminar** el triaje.
- [ ] **H9.2** (`recepcion`) En Turnos intentar **Cancelar** y **Ausente** ese turno. → NIEGA (el triaje terminado solo admite retiro o rechazo).
- [ ] **H9.3** (`recepcion`) Turnos → botón **Retiro**. → RETIRADO; "cargo pendiente" (una marca, sin monto). La consulta en triaje pasa a ANULADA con sus datos (`TA-20`).
- [ ] **H9.4** (`recepcion`) **Constancia de retiro**. → `CR-…` sin nada clínico; emitir de nuevo → abre la existente; anular con motivo y emitir otra (`CT-1` a `CT-3`).
- [ ] **H9.5** (`medico2`) Consulta → "Retiros y rechazos": la nota de 2.6. → Se guarda **una sola vez**. `recepcion` no ve la nota (`TA-21`).
- [ ] **H9.6** (16:20, `enfermeria`) Iniciar el triaje del turno T16 (**sin terminarlo**). Queda "En triaje". La paciente se va. El resto se resuelve en el cierre de jornada (3.4).

#### H10. Lugo: rechazo (09:00) y turno al que no viene (16:00) (E11)

- [ ] **H10.1** (`recepcion`) En T09, botón **Rechazo**. → RETIRADO de tipo rechazo. Constancia de retiro: dice rechazo, no retiro. **Retiro/Rechazo sobre un turno ATENDIDO** (por ejemplo Escobar) → NIEGA (`CT-11`).
- [ ] **H10.2** (16:00) T15 queda sin triaje ni visita; se resuelve en el cierre de jornada.

#### H11. Maidana: no se presentó (09:20) y urgencia (11:00) (E11)

- [ ] **H11.1** (`medico2`) Antes de las 09:20: Consulta → su turno de las 09:20 → "No se presentó". → NIEGA (todavía no llegó la hora).
- [ ] **H11.2** (`medico2`) **Desde las 09:20**: "No se presentó", y después "Pasar a ausente". → AUSENTE.
- [ ] **H11.3** (11:00, `recepcion`) Turnos → "Registrar urgencia": Maidana con el Dr. Duarte. → Urgencia de hoy PENDIENTE; en "Urgencias de hoy" **sin nada clínico**. Registrar otra con el mismo médico → NIEGA ("ya tiene una urgencia abierta") (`AG-13`, `AG-14`).
- [ ] **H11.4** (`enfermeria`) Triaje: marcar **Urgente** con la observación `Dolor pélvico (prueba)`. → Pastilla roja.
- [ ] **H11.5** (`medico2`) Aviso "Nueva urgencia: MAIDANA TORALES, NANCY BEATRIZ" (no suena). Ver el popup de urgencias (queda un VER en Auditoría). Con una consulta en curso, "Atender" la urgencia → pide confirmar (`TA-16`). Atender y finalizar (R10.2).
- [ ] **H11.6** (`caja2`) Cobrar **C7** (Efectivo 250.000).

#### H12. Narváez: entre pacientes (10:20) (E11)

- [ ] **H12.1** (`recepcion`) Con el cupo de Duarte = 1 y T12 cargado: dar **otro** turno "Entre pacientes" con el Dr. Duarte (a cualquier paciente). → NIEGA (pasó el cupo, `AG-11`). Bajar el cupo a 0 → NIEGA (hay uno agendado).
- [ ] **H12.2** (`enfermeria`, `medico2`) Triaje (aparece al final de la lista del día, tras urgencias y turnos con hora); botón "Entre pacientes (1)" de la pantalla Consulta; atender y finalizar.
- [ ] **H12.3** (`caja2`) Cobrar **C8** (Efectivo 250.000).

#### H13. Escobar, 14:00: estudio de 120 minutos en la agenda (E12)

- [ ] **H13.1** (`recepcion`, al mediodía) Agenda general de hoy con T13 cargado (14:00, 120 min con Benítez). → Aparece a las 14:00 con su hora de fin (16:00) y, en las filas que sigue ocupando, "Continúa: ESCOBAR … (hasta 16:00)" (`AG-7`).
- [ ] **H13.2** (`recepcion`) Nuevo turno con Benítez, Consulta, para hoy a las 15:00 o a las 14:40. → Ese horario **no se ofrece**; se ofrece desde las 16:00. Con el Dr. Duarte sí hay horarios (otro profesional y otro consultorio) (`AG-8`).
- [ ] **H13.3** (`recepcion`) Un turno de ECO-MORFO nuevo (otra paciente, solo para mirar, no lo guarde): solo ofrece inicios donde caben los 120 minutos dentro de la franja (el último, 16:00). Duración 481 → NIEGA (5 a 480) (`AG-6`).
- [ ] **H13.4** (14:00, `enfermeria`, `medica1`) Triaje, atender y finalizar. → Prestación **ECO-MORFO, 700.000**, reparto 420.000 / 210.000 / 70.000.
- [ ] **H13.5** (`caja1`) Cobrar **C12** (Tarjeta 700.000). → Factura 10 %, IVA 63.636.

#### 3.3 Mediodía (11:30 a 13:30): personas, permisos y comprobaciones (E15, E16)

- [ ] **M1** (`recepcion`) Los casos de 2.8 (E15). → Cada uno como dice la tabla; los NIEGA con el mensaje exacto.
- [ ] **M2** (`recepcion`) Buscar a Alvarenga en el selector por CI `9000012`, por apellido y por nombre. → La encuentra.
- [ ] **M3** (`recepcion`) Desactivar a una paciente **con consulta cerrada** (por ejemplo Escobar). → NIEGA (`PE-9`).
- [ ] **M4** (E16) Permisos negativos por perfil. Entrar con cada usuario y probar por menú y por URL (la sesión se cierra entre uno y otro):

| Usuario | Prueba | Esperado |
|---|---|---|
| `caja1` | Abrir `/admin/turnos`, `/admin/auditoria`, `/admin/liquidaciones`, `/admin/facturas/timbrados` | 403; no aparecen en el menú |
| `caja2` | Anular directa una factura de Caja 1 | Solo "Solicitar la anulación" (`R2-13`) |
| `recepcion` | `/admin/cajas`, `/admin/triaje`, `/admin/convenios`, `/admin/tarifas/create`; el PDF de una consulta | 403; en Prestaciones ve el estado de cobro pero no el reparto |
| `medica1` | `/admin/tarifas`, `/admin/cajas`, `/admin/matriz-permisos`; botones Confirmar, Cancelar y Ausente en Turnos | 403; sin botones (`AG-20`) |
| `medico2` | Atender un turno de `medica1`; crear una receta en su consulta; adenda en su consulta | 403 / NIEGA (`TA-10`, `RE-11`, `CO-4`) |
| `enfermeria` | PDF de la consulta; `/admin/pacientes` | 403 (`PD-3`) |
| `gerencia` | `/admin/personas`, `/admin/matriz-permisos`; abrir una caja; cobrar | 403; solo ver (`PE-10`, `CC-13`) |
| `facturacion` | `/admin/turnos`; anular una factura | 403; solo ver y PDF (`AG-21`, `FT-16`) |
| `compras` | `/admin/pacientes`, `/admin/personas` | 403 |
| `supervision` | Crear una receta, emitir un reposo o una orden | NIEGA; solo lectura (`RE-12`, `RM-8`, `OE-7`) |
| `auditor` | Todo menos Auditoría | 403 |
| `admin` | Iniciar un triaje; emitir un reposo | 403 / NIEGA (`TA-7`, `RM-9`) |

- [ ] **M5** (`caja1`) Facturas. → La numeración `001-001-NNNNNNN` sale correlativa, sin saltos; la anulada de Escobar y la de Godoy conservan su número (`FT-7`). Búsqueda por número, cliente y documento (`FT-15`).

### 3.4 Cierre del día

**17:30. Cierre de jornada de los médicos** (E11)

- [ ] **C1** (`medica1`) Consulta → "Cerrar jornada". → Vista previa sin pendientes (todo atendido). Confirmar.
- [ ] **C2** (`medico2`) Consulta → "Cerrar jornada". → Vista previa: T15 (Lugo, sin triaje) → **AUSENTE**; T16 (Jara, triaje iniciado) → **RETIRO** (origen cierre de jornada). Si queda una urgencia sin atender: sección roja "Atención: urgencias sin atender" y sin tildar "Confirmo que revisé las urgencias" → NIEGA (`TA-19`). Confirmar.
- [ ] **C3** (`recepcion`) Turnos. → T15 AUSENTE; T16 RETIRADO.

**18:00. Cierre de cada caja** (con los valores de 2.5)

- [ ] **C4** (`caja1`) Cajas → Mi caja: esperado por medio = 1.514.000 / 144.000 / 900.000. Cerrar con Efectivo **1.509.000** y **sin** motivo → NIEGA (diferencia). Con el motivo de 2.5 → CERRADA, "pendiente de aprobación"; ya no recibe cobros (`R4-3`, `R4-4`).
- [ ] **C5** (`caja1`) Intentar aprobar su propio cierre → NIEGA (`R4-5`).
- [ ] **C6** (`caja2`) Esperado = 800.000 / 250.000 / 600.000. Cerrar con el **primer cierre** de 2.5 (Transferencia 205.000, con motivo). → CERRADA.

**18:30. Revisión de Gerencia** (`gerencia`)

- [ ] **C7** Cajas → aviso de pendientes. Abrir la de Caja 1: esperado, contado y diferencia −5.000 con su motivo. **Aprobar** (nota opcional). → APROBADO; no se modifica nunca (`R4-9`).
- [ ] **C8** Abrir la de Caja 2. **Devolver** sin nota → NIEGA; con la nota de 2.5 → DEVUELTO.
- [ ] **C9** (`caja2`) Cajas → vuelve a cerrar la devuelta con el **segundo cierre** (Transferencia 250.000). → Segundo cierre; el anterior queda en el historial (`R4-8`).
- [ ] **C10** (`gerencia`) **Aprobar** la de Caja 2. → APROBADO.
- [ ] **C11** (`caja1`) Con la caja cerrada, Cobrar. → NIEGA ("Abra su caja antes de cobrar.").

### 3.5 Auditoría (E17, 19:00)

- [ ] **U1** (`auditor`) Auditoría: filtrar por usuario `caja1`, acción y módulo; fechas dd/mm/aaaa. → Aparecen "Apertura de caja", "Cierre de caja", los cobros y las anulaciones; los montos dicen **"Contenido económico"** (oculto) (`AU-1`, `AU-4`).
- [ ] **U2** (`auditor`) Abrir eventos de la consulta de Fernández, de su receta, del reposo y de la orden. → Se ven los campos que cambiaron, **no sus valores** (`AU-2`). El buscador no encuentra texto clínico (`AU-3`).
- [ ] **U3** (`gerencia`) Los mismos. → Clínico y recetas ocultos; tarifas, cobros y cierres visibles (`AU-5`).
- [ ] **U4** (`supervision`) Los mismos. → Clínico y recetas visibles; económico oculto (`AU-6`).
- [ ] **U5** (`admin`) Todo visible. Buscar: el cambio de tasa de IVA, las tarifas y acuerdos de hoy, "Cierre devuelto"/"Cierre aprobado", los EXPORTAR de cada PDF, el desbloqueo (`AU-7`, `AU-11`).
- [ ] **U6** (`admin`) El VER de los eventos de módulos sensibles quedó a nombre de quien los miró (`AU-9`). No hay pantalla ni ruta para editar o borrar un evento (`AU-12`).
- [ ] **U7** (cualquiera) Un login con un correo inexistente (`nadie@prueba.test`). → Evento sin usuario (`AU-10`).

### 3.6 Otro día o fin de mes

Con **otra fecha real** (el día siguiente, o a fin de mes). Conviene haber cerrado ayer las dos cajas.

**Reversión de un cobro de una caja ya cerrada, por solicitud (E14)**

En esta base todos los cobros llevan factura, así que la reversión de un cobro de otro día es **anular su factura con solicitud**
(la reversión de un cobro sin factura, `R3-1` a `R3-11`, solo existe en bases cargadas antes de la facturación).

- [ ] **D1** (`caja2`) Abrir la caja de hoy con **200.000**. Facturas → la factura de Dávalos (**C6**, Tarjeta 250.000, de la caja de ayer, APROBADA). → "Solicitar la anulación" (no "Anular"). Motivo "Error en los datos del cliente".
- [ ] **D2** (`gerencia`) Antes de aprobar, probar con Caja 2 **sin** caja abierta hoy (si la cerró): no se aprueba hasta que abra la caja (`R2-11`). Con la caja abierta, **Aprobar**. → Factura ANULADA, cobro REVERTIDO; la caja vieja **no cambia** (el cobro figura "(revertido)"); en la Caja 2 de hoy aparece la devolución: un egreso de **Tarjeta 250.000** (`R3-6`).
- [ ] **D3** (`caja2`) "Cobrar de nuevo con una factura que reemplace a esta": Efectivo 250.000. → Factura nueva COBRADA.
- [ ] **D4** (`caja2`) Cerrar la caja de hoy: esperado Efectivo 450.000 (200.000 + 250.000), Tarjeta **−250.000**. Declarar la tarjeta con signo: `-250.000`; el efectivo en negativo → NIEGA (`CC-11`). Gerencia aprueba.

**Liquidaciones a aseguradoras (E7 final)** (2.9)

- [ ] **L1.1** (`facturacion`) Liquidaciones → "Prestaciones sin firma". → Barrios (Ejemplo Uno) figura sin firma; "Marcar la firma" disponible.
- [ ] **L1.2** (`facturacion`) "Armar liquidación": Seguros Ejemplo Uno con corte en fecha **futura** → NIEGA. Con corte de hoy: vista previa con **solo Alvarenga** (200.000) y Barrios fuera por falta de firma.
- [ ] **L1.3** (`facturacion`) "Confirmar y armar". → EN_PREPARACION. Armar otra para la **misma** aseguradora → NIEGA (una abierta por aseguradora).
- [ ] **L1.4** (`facturacion`) Intentar "Aplicar visación", desmarcar la firma o anular la prestación de Alvarenga. → NIEGA con el aviso de bloqueo ("… en preparación: quítela de la liquidación antes de cambiarla.").
- [ ] **L1.5** (`facturacion`) "Enviar a aprobación". → PENDIENTE_APROBACION. Aprobarla uno mismo → NIEGA. (`admin` que arma y envía una y trata de aprobar → NIEGA, `R6-7`.)
- [ ] **L1.6** (`gerencia`) **Devolver** con nota → EN_PREPARACION. Reenviar (`facturacion`) y **Aprobar** (`gerencia`). → APROBADO, bloqueada, "pendiente de facturar".
- [ ] **L1.7** (`facturacion`) Pagos: 120.000 (`PAG-R-0001`) → PARCIAL, saldo 80.000; 100.000 → NIEGA ("El pago supera el saldo… ₲ 80.000"); fecha futura → NIEGA; 80.000 (`PAG-R-0002`) → PAGADO. Anular un pago con motivo → se recalcula el estado. "Anular la liquidación" con pagos vigentes → NIEGA.
- [ ] **L1.8** (`facturacion`) "Planilla (PDF)". → A4 apaisada con aseguradora, convenio, corte y período, una fila por línea, total y firma; sin diagnóstico. EXPORTAR en Auditoría.
- [ ] **L2.1** (`facturacion`) Liquidación de **Previsión Médica Dos**: vista previa con las dos de Cabrera (432.000). Armarla y **quitar** la línea de la ecografía con el motivo `abc` → NIEGA; con el de 2.7 → total 176.000 y la prestación queda libre.
- [ ] **L2.2** (`facturacion`) Enviar; (`gerencia`) aprobar; pago parcial de 100.000. (`gerencia`) Pestaña "Por cobrar por aseguradora". → Aprobado, pagado, saldo y cuántas están pendientes de facturar.
- [ ] **L2.3** (`caja1`) Liquidaciones no aparece en su menú y la URL da 403; los pagos de la aseguradora no tocaron ninguna caja (`R6-16`).
- [ ] **L2.4** (`caja1`) Cobrar la parte de una paciente de una prestación ya liquidada → se puede (`LQ-22`).

## 4. Qué depende del reloj real y cómo adaptar el guion

| Qué | Regla |
|---|---|
| Turnos | No se agendan en una fecha pasada ni en una hora de hoy que ya pasó. Cárguelos **antes** de la hora del primero (por eso "Antes de abrir") |
| "No se presentó" | Solo desde la **hora de inicio** del turno (`Fecha::yaLlego`). A los de sin hora (entre pacientes, urgencias) desde las 00:00. "Pasar a ausente" también |
| Factura | 10 minutos desde la **emisión** para anularla sin aprobación (`CLINEXA_PLAZO_ANULACION_FACTURA`, no lo cambie). Pasado ese tiempo solo hay solicitud |
| Caja de "otro día" | La reversión de un cobro de una caja ya cerrada y aprobada exige **otra fecha real** y la caja de hoy abierta (3.6). Una caja abierta de un día anterior impide cobrar hasta cerrarla (`R4-10`): cierre las dos antes de terminar el día |
| Reposo | "Desde" no puede ser anterior a hoy. El desbloqueo dura 24 horas |
| Liquidación y pagos | El corte y la fecha de un pago no pueden ser futuros |
| Disponibilidades | Lunes a viernes, 08:00 a 12:00 y 14:00 a 18:00, turnos de 20 min, vigentes desde dos meses atrás |

### Si empieza a otra hora

Las horas del guion son un **desplazamiento** desde el inicio (08:00 = "S"): T01 es S + 0:20, la tarde es S + 6:00. Si empieza a
otra hora S, mueva todo lo mismo:

1. **Cargue los turnos al menos 30 minutos antes** de S + 0:20 (si no, "Ese horario ya pasó").
2. Los horarios deben caer dentro de una disponibilidad. Si S + 0:20 a S + 8:40 no entra en 08:00–12:00 y 14:00–18:00, `recepcion`
   (Disponibilidades, con CREAR y DESACTIVAR) **desactiva las franjas de hoy** de los dos médicos (ver abajo) y crea las suyas.
3. "No se presentó" (H11.1 y H11.2): ya se puede si pasó la hora del turno; para ver el NIEGA previo, cargue ese turno a una hora
   todavía futura.
4. Los 10 minutos de la factura (H5.5, H7.6) son reales: no cambian con S.

**Crear la disponibilidad de hoy** (fuera de horario, o un sábado o domingo): Disponibilidades → Nueva → Profesional (Benítez, después
Duarte) → Consultorio (101 / 102) → **Día de la semana** (el de hoy) → **Duración de cada turno 20** → Hora desde / Hora hasta →
**Vigencia desde: hoy** → Guardar. No puede superponerse con otra disponibilidad **activa** del mismo profesional ni consultorio:
si hoy es día hábil, primero **desactive** las franjas de hoy (Estado → Inactivo) o cree la nueva en un hueco (por ejemplo
12:00–14:00 o después de las 18:00). Al terminar el recorrido, vuelva a poner en "Activo" las desactivadas y desactive las nuevas.
Ejemplo para empezar a las 15:00 en un día hábil: desactivar las 4 franjas de hoy (08–12 y 14–18 de cada médico) y crear, para cada
uno en su consultorio, 15:00–19:00 y 21:00–23:00. Los turnos de la mañana (T01 a T12) pasan a las 15:20 en adelante y los de la tarde
(T13 a T17), a las 21:00 en adelante, conservando el orden y las separaciones.

## 5. Orden recomendado y tiempo estimado

Tiempos de **trabajo manual**, sin las esperas del reloj (que se llenan con otras historias). El total es una estimación: es la primera vez que se corre.

| Orden | Bloque | Quién entra | Tiempo |
|---|---|---|---|
| 1 | Preparar la base y levantar el servidor (1) | consola | 10 min |
| 2 | Antes de abrir (A1 a A11) y cajas (H0) | `gerencia`, `recepcion`, `caja1`, `caja2` | 35 min |
| 3 | Mañana, Benítez: H5, H1, H6, H2, H3 (consulta), H8 | `enfermeria`, `medica1`, `recepcion`, `gerencia`, `caja1` | 90 min |
| 4 | Mañana, Duarte: H7, H9, H10, H11, H4, H12 | `enfermeria`, `medico2`, `recepcion`, `gerencia`, `caja2` | 75 min |
| 5 | Anulaciones y solicitudes (H5.5 a H5.6, H7.6 a H7.10, H2.5 a H2.7) | `caja1`, `caja2`, `gerencia` | 25 min (dentro de 3 y 4) |
| 6 | Mediodía: E12 en la agenda, E15, E16 (3.3) | `recepcion` y cada perfil | 45 min |
| 7 | Tarde: H13, H1 (ecografía), H3 (ecografía), H10, H9 | `enfermeria`, `medica1`, `medico2`, `recepcion`, cajas | 50 min |
| 8 | Cierre: jornadas, cajas, revisión (3.4) | médicos, `caja1`, `caja2`, `gerencia` | 30 min |
| 9 | Auditoría (3.5) | `auditor`, `gerencia`, `supervision`, `admin` | 20 min |
| 10 | Otro día: reversión y liquidaciones (3.6) | `caja2`, `gerencia`, `facturacion` | 40 min |

Total estimado: **unas 6 horas de trabajo** repartidas en dos días. Tips: use **dos navegadores** (uno para los médicos, otro para
Recepción y Caja) y una ventana privada para Gerencia; así no cierra sesión a cada rato.

Al terminar, los defectos van al "Registro de defectos" de `docs/verificacion.md`. Para repetir el recorrido, vuelva a 1.4.
