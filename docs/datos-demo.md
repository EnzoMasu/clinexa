# Datos de desarrollo (`clinexa:datos-demo`)

Juego de datos inventados, reproducible, para recorrer todo el sistema. **Solo desarrollo.**

```bash
php artisan clinexa:datos-demo --confirmo-desarrollo            # pide la contraseña por consola
php artisan clinexa:datos-demo --confirmo-desarrollo --password=...
```

- Se niega a correr con `APP_ENV=production` y sin `--confirmo-desarrollo`.
- La contraseña de los usuarios demo se pide por consola (o `--password=`, mínimo 10 caracteres). No está en ningún
  archivo. Se usa solo al crear un usuario: a uno que ya existe no se le cambia.
- **Idempotente**: cada registro se identifica por una clave natural (CI, correo, código, profesional+consultorio+día+franja,
  marca del turno). Correrlo de nuevo no duplica nada. Todo corre en una transacción: si falla, no queda nada a medias.
- **No toca lo que no es demo** (ni al administrador): solo agrega.
- Se reconoce a simple vista: correos `@prueba.test`, CI 9000001 en adelante (11 usuarios, después 12 pacientes),
  turnos con la observación "Turno de prueba (demo)", aseguradoras con RUC 80900001 y 80900002, textos "(demo)" en todo lo
  clínico y lo económico, visaciones `V-aammdd-NNN`.
- Los cambios quedan en Auditoría a nombre de los usuarios demo, cada uno con lo suyo (Gerencia: usuarios, disponibilidades,
  equipo, tarifas, acuerdos, revisión de cierres y reversiones y la aprobación de la liquidación; Recepción: pacientes, turnos, aseguradoras, coberturas,
  visaciones, retiros, firma y ticket; Supervisión médica: estudios, dietas y el desbloqueo; Compras: la proveedora;
  Facturación: convenios, liquidaciones y el pago de la aseguradora; Enfermería: triaje; los médicos: la atención; Caja 1 y Caja 2: cajas, cobros con su factura y anulaciones; Gerencia, además, la clínica, el timbrado y la revisión de las anulaciones). Las personas de
  Gerencia misma se cargan sin autor.
- Al final imprime la cantidad por tabla (antes y después) y la lista de usuarios demo con su perfil.

## Etapas (`database/seeders/Demo/`)

| Clase | Qué carga |
|---|---|
| `BaseClinica` | **Datos reales** (sirven también en producción: `php artisan db:seed --class='Database\Seeders\Demo\BaseClinica' --force`). Sucursal Plenitud Mujer, Ginecología y Obstetricia, consultorios 101 y 102, medios de pago y los 8 procedimientos. Solo agrega lo que falta: no renombra lo existente. |
| `UsuariosDemo` | 11 usuarios, uno por perfil y con UN perfil: gerencia, caja1, caja2, facturacion, compras, auditor, medica1, medico2, enfermeria, recepcion, supervision (`@prueba.test`). Médica 1 y Médico 2 son profesionales (matrícula inventada); Enfermería, personal de salud. |
| `PacientesDemo` | 12 pacientes adultas con ficha, teléfonos, direcciones y ciudades variadas. |
| `AgendaDemo` | Disponibilidades lun-vie (mañana 08-12 y tarde 14-18, bloques de 20 min; Médica 1 en el 101, Médico 2 en el 102, vigentes desde hace dos meses), y para los próximos 10 días: consultas y estudios de 30, 40, 90 y 120 min (por `Agenda`, sin pisarse), cupos y turnos entre pacientes, y una urgencia (hoy). |
| `EconomiaDemo` | Los 6 estudios del catálogo de órdenes, 3 dietas de prueba, el tipo "Ecógrafo" y el "Ecógrafo Mindray MX7" (dueña: la Dra. Benítez como proveedora, "Equipos médicos", sobre su misma persona), usado por las 6 ecografías. Tarifas generales (Consulta ₲ 250.000, ecografías de ₲ 350.000 a ₲ 700.000; **PAP-COLPO sin tarifa**). Acuerdos: Dra. Benítez 60 % general y 40 % en ECO-CROMO, ecógrafo 30 %; **el Dr. Duarte sin acuerdo**. Todo vigente desde hace dos meses. |
| `SegurosDemo` | Dos aseguradoras (personas jurídicas con RUC válido) como responsables de pago, cada una con su convenio, dos planes y tarifas del convenio para Consulta y ECO-TV. Coberturas de las pacientes 1 a 4 (la 4, vencida). |
| `FacturacionDemo` | Una clínica de **prueba** como emisor (solo si no hay ninguna; RUC `80900010-5`, que no es de ninguna persona demo) y un timbrado **simulado** (`19990001`, 001-001) vigente desde hace dos meses, por Gerencia (`docs/facturacion.md`). |
| `DiasDemo` | Los **10 días hábiles anteriores a hoy**, uno por uno, y hoy (ver abajo). |
| `LiquidacionesDemo` | Con corte **hoy**, sobre las prestaciones de seguro ya firmadas: la de la primera aseguradora armada y enviada por Facturación, aprobada por Gerencia y con un pago parcial por transferencia (la mitad, al millar); la de la segunda, en preparación. Con los servicios. Una aseguradora que ya tiene alguna liquidación no recibe otra: corre también sobre una base ya cargada. |

Las etapas `EconomiaDemo`, `SegurosDemo` y `FacturacionDemo` se cargan con la hora movida a la víspera del primer día simulado, así en Auditoría
quedan antes de usarse.

## Días simulados (`DiasDemo`)

Cada día se simula con la hora movida (`Carbon::setTestNow`, en el comando, no en la aplicación), con los **servicios** de la
aplicación, en el orden de la clínica: Recepción da los turnos (07:00) y carga las visaciones desde el turno, Caja 1 abre,
Enfermería hace el triaje, el médico atiende y finaliza (`FormularioConsulta` con las reglas finales + `Finalizar`, que crea la
prestación, su seguro y su reparto), emite recetas, reposos y órdenes; Recepción registra cancelaciones, retiros, rechazos, la
firma y el ticket; Caja 1 cobra (17:30, solo la parte de la paciente), los médicos cierran la jornada (los que no vinieron quedan
AUSENTE), Caja 1 cierra (18:30) y Gerencia revisa (19:00). El plan de cada día está en `DiasDemo::PLAN`.

- **Clínica:** turnos atendidos, ausentes y cancelados, un retiro (con el triaje terminado: cargo pendiente), un rechazo, una
  urgencia y un entre pacientes; recetas emitidas (una anulada), indicaciones con una dieta insertada, reposos (uno anulado),
  órdenes de estudio (del catálogo y "Densitometría ósea" escrita a mano), informes de estudio (uno, una ecografía hecha en una
  consulta, con su propia prestación y el reparto del ecógrafo), una adenda y un desbloqueo con su corrección.
- **Prestaciones:** particulares; con seguro (cubre todo, copago porcentaje, copago monto fijo, NO_CUBIERTO y una ecografía sin
  visar); con aviso (cobertura vencida, cobertura sin visación); pendientes de tarifar (PAP-COLPO) y de reparto (Dr. Duarte).
- **Caja:** Caja 1 abre y cierra cada día y Gerencia aprueba (el 3.er día con un faltante y su motivo; el 5.º, devuelto y cerrado
  otra vez). Cobros en efectivo, transferencia, tarjeta y mixtos, **cada uno con su factura** (la factura primero). Anulaciones de
  factura (que revierten su cobro): una pedida y aprobada (y cobrada de nuevo con la factura que la reemplaza) y una rechazada. **Hoy:**
  Caja 2 abre, cobra lo que quedó del último día, anula en el plazo una factura suya (y cobra de nuevo) y pide la anulación de una de
  Caja 1 (queda pendiente). En una base cargada antes de la facturación, los cobros existentes quedan sin factura.
- **Sin servicio** (con los modelos y su auditoría, como el panel): estudios, dietas, tipo de equipo y equipo, el rol de
  proveedora, la persona jurídica y su rol de responsable de pago, los turnos con horario (como `AgendaDemo`), el cupo entre
  pacientes, cancelar un turno (`Turno::pasarA`) y las recetas (crear, emitir y anular: la lógica está en `RecetaController`).
- **Hitos:** lo creado antes de los hitos `PRESTACIONES`, `PRESTACIONES_INFORMES`, `REPARTOS`, `CONVENIOS` y `FACTURACION` no tiene
  prestación, seguro, reparto ni factura. El comando los **adelanta** a la víspera del primer día simulado, solo si no hay consultas finalizadas ni
  prestaciones anteriores (si las hay, falla y no carga nada: hace falta una base nueva). Lo avisa en la consola.
- Se cargan **una sola vez**: si ya hay cajas de Caja 1 o turnos demo de días pasados, la etapa no hace nada.
- Necesita el catálogo CIE-10 (`CatalogoCie10Seeder`): usa Z01.4, R10.2 y Z34.9.

`Ruc` calcula el dígito verificador del RUC (módulo 11 de la DNIT) para las personas jurídicas demo de etapas futuras.

## Cargar de nuevo

- **Mismo día**: no hace falta; correrlo no cambia nada.
- **Otro día**: correrlo agrega los turnos de los días nuevos (un día donde el profesional ya tiene turnos demo no se vuelve a llenar).
  La urgencia solo se registra para hoy. Los días simulados no se repiten.
- **Desde cero**: hace falta una base nueva (`migrate` + seeders base + `clinexa:crear-admin`). El comando nunca borra.

## Pendiente

Los rangos de signos vitales (`parametros_clinicos`) siguen sin aprobación médica; ninguna etapa los siembra y los triajes demo
no cargan signos vitales. Sin compras (no existen todavía).
