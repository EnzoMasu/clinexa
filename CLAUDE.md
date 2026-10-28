# Clinexa: reglas permanentes

## Git
- Se trabaja en la rama que se indique en cada pedido, nunca en `main`; verificarla con `git branch --show-current`.
- `main` no se toca sin confirmación expresa: ni merge, ni push, ni commits.
- Commits WIP chicos. Ningún commit final ni merge sin confirmación.

## Base de datos
- La base de trabajo (`clinexa_dev`, la única de esta máquina; la de tests es `clinexa_test`) solo se toca con confirmación expresa, y siempre antes con
  `php artisan clinexa:respaldo --probar`.
- Antes de cambiarla: verificación de solo lectura y mostrar qué va a pasar.
- Comandos destructivos prohibidos (`migrate:fresh`, `migrate:reset`, `db:wipe`, DROP, TRUNCATE, borrados
  masivos), salvo lo autorizado expresamente y caso por caso.
- No borrar bases ni datos fuera de lo autorizado. En el servidor hay bases ajenas.

## Texto y fechas
- Todo texto visible, en "usted".
- Fechas dd/mm/aaaa, siempre con el helper `App\Support\Fecha` (también "hoy" y "ahora").

## Historia clínica
- Una consulta cerrada (FINALIZADA o ANULADA) es de solo lectura, con todo lo que cuelga de ella. Ver
  `docs/historia-clinica.md`.
- Nada de la historia clínica se elimina.
- En Auditoría, el contenido clínico y el de recetas se ocultan a quien no tiene VER sobre
  HISTORIA_CLINICA o RECETAS (`App\Support\Auditoria::permisoDeContenido`).
- Nunca guardar datos clínicos en el navegador: ni localStorage, ni sessionStorage, ni IndexedDB, ni
  cookies.

## Pruebas
- Siempre con datos inventados. Nunca datos reales, ni de pacientes ni credenciales.
- Los tests nunca usan la base real: SQLite en memoria, y `clinexa_test` para `tests/Postgres`.
- `composer test:rapido`: en paralelo, sin el grupo `lento` ni PostgreSQL. Para el trabajo diario.
- `composer test:completo`: todo (Unit y Feature en paralelo con `lento`, y PostgreSQL en serie después).
  Antes de cada commit que no sea WIP, de cualquier merge y de tocar la base real. `composer test:completo-serial`
  es el anterior, todo en serie.
- Los helpers de test definidos como funciones globales llevan el prefijo del archivo (p. ej.
  `perfilesPredefinidosExistente` en `PerfilesPredefinidosTest`): los choques de nombre ya rompieron la
  suite dos veces.
- Detalle en `docs/pruebas.md`.

## Producción
- Antes de usar con pacientes reales: la lista de `docs/produccion.md`.

## Mapa del proyecto
Laravel + Blade + Tailwind + Vite (JS plano, sin framework). PDF nuevos con dompdf (`barryvdh/laravel-dompdf`), también la receta nueva; recetas anteriores y
constancias ya hechas siguen imprimiéndose desde el navegador. Docs por tema en `docs/` (pdf-clinico, historia-clinica,
agenda-urgencias, triaje, retiros, constancias, reposo-medico, indicaciones-generales, ordenes-estudio, informes-estudio, tarifas-prestaciones, reparto-economico, cobros-caja, convenios-seguros, liquidaciones-aseguradoras, perfiles, respaldo, datos-demo, pruebas).
- `app/Models/`: Eloquent. Clave: `Persona`, `Paciente`, `Profesional`, `Turno`, `Consulta`,
  `HistoriaClinica`, `Receta`, `PerfilAcceso`, `Permiso`, `ModuloSistema`, `LogAuditoria`,
  `CupoEntrePacientes`, `Disponibilidad`, `ReposoMedico`, `OrdenEstudio`, `Estudio`, `InformeEstudio`, `Moneda`, `Tarifa`,
  `Prestacion`, `AcuerdoReparto`, `Equipo`, `RepartoPrestacion`, `LineaReparto`, `MedioPago`, `Caja`, `CierreCaja`,
  `Cobro`, `CobroPrestacion`, `LineaCobro`, `MovimientoCaja`, `SolicitudReversionCobro`, `Convenio`, `PlanConvenio`,
  `TarifaConvenio`, `CoberturaPaciente`, `Visacion`, `LineaVisacion`, `Liquidacion`, `LineaLiquidacion`, `PagoAseguradora`.
- `app/Enums/`: `TipoTurno`, `AccionAuditoria`, `TipoDiagnostico`.
- `app/Http/Controllers/Admin/`: un controlador por pantalla (`TurnoController`, `UrgenciaController`,
  `AtencionController`, `ConsultaController`, `HistoriaClinicaController`, `RecetaController`,
  `AuditoriaController`, `MatrizPermisosController`, `PerfilAccesoController`).
- `app/Support/Fecha.php`: único origen de fechas, "hoy" y "ahora".
- `app/Support/Atencion/`: flujo de la consulta (`Preparar`, `Atender`, `AtenderSinTurno`, `MarcarLista`,
  `Finalizar`, `DeshacerAtencion`, `CerrarJornada`, `GuardarSecciones`, `Autoguardado`, `EvaluadorSignos`).
- `app/Support/Turnos/`: `AgendarEntrePacientes`, `RegistrarUrgencia`. `app/Support/Agenda.php`.
- `app/Support/`: `Permisos`, `PermisosDePerfil`, `PerfilesPredefinidos`, `Auditoria`,
  `ContextoAuditoria`, `Respaldos`, `HojaReceta`, `PdfClinico/GenerarPdf` (PDF de triaje y de consulta, al vuelo), `PdfClinico/PdfIndicaciones` (indicaciones generales en hoja aparte), `PdfClinico/PdfInformeEstudio` (informe de estudio), `Reposo/` (certificado de reposo: emitir, anular, PDF), `OrdenEstudio/` (orden de estudio: emitir, anular, PDF), `Tarifas/` (buscar, alta, cambiar), `Prestaciones/` (generar al finalizar, tarifar, anular), `Repartos/` (acuerdos: buscar, alta, cambiar; calcular y repartir la prestación), `Cobros/` (abrir, cobrar, cerrar y revisar la caja; revertir cobros), `Convenios/` (convenios, coberturas, visaciones, calcular quién paga, aplicar visación, firma, ticket), `Liquidaciones/` (armar, flujo y aprobación, bloqueo de la prestación, pagos de la aseguradora, planilla), `BuscadorPersonas`, `Unicidad`.
- `app/Console/Commands/`: `Respaldo` (`clinexa:respaldo`), `DatosDemo` (`clinexa:datos-demo`, solo desarrollo; etapas en `database/seeders/Demo/`), `CrearAdmin`, `TraspasarPrincipal`,
  `MigrarUsuariosAPersona`.
- `app/Policies/`, `app/Http/Middleware/`, `app/Http/Requests/`, `app/Rules/`: autorización y validación.
- `routes/web.php`: todas las rutas del panel; `routes/auth.php`: login.
- `database/migrations`, `database/seeders`: esquema y datos base (módulos, permisos, perfiles, CIE10).
- `resources/views/admin/`: vistas por módulo; `resources/views/components/`: componentes Blade.
- `resources/js/`: un script por comportamiento (`popup-consultas`, `consulta-autoguardado`,
  `listado-en-vivo`, `alta-turno`, `selector-persona`, `buscador-cie10`, `pantalla-cobro`). Sin datos clínicos en el navegador.
- `tests/Feature` y `tests/Unit` (SQLite en memoria); `tests/Postgres` (`clinexa_test`).
- `.claude/commands/`: `/aplicar RAMA` (migrar la base real con foto y respaldo) y
  `/fusionar RAMA "mensaje"` (squash a main).

## Protocolo
- Una fase = una rama. Commits WIP y push al terminar cada etapa.
- Nunca force push, nunca borrar ramas.
- Nunca tocar la base real fuera de `/aplicar`; nunca correr seeders no pedidos.
- Durante el desarrollo, solo `composer test:rapido`.
- El usuario prueba en el navegador antes de `/fusionar`.
- Informes de máximo 10 líneas.
- Los tests no comparan contra números fijos de módulos, permisos ni rutas.
