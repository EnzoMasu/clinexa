# Perfiles de acceso

## Varios perfiles por usuario

- Un usuario puede tener **varios perfiles** (tabla `usuario_perfil`). Sus permisos efectivos son la
  **unión** de los permisos de sus perfiles **activos**.
  - Se calculan en un solo lugar: `User::permisosEfectivos()` / `User::tienePermiso()`.
  - Se cargan con una sola consulta por pedido.
- Un perfil **INACTIVO no aporta permisos**.
- Un usuario **ACTIVO necesita al menos un perfil activo**:
  - el formulario no deja guardarlo sin uno;
  - sin ninguno, no puede entrar ("Su usuario no tiene ningún perfil de acceso activo.");
  - si estaba adentro, se le cierra la sesión (`UsuarioActivo`, que corre antes del permiso).
- Los módulos INACTIVOS no otorgan permisos, como antes.

### La columna vieja

`users.perfil_acceso_id` quedó **sin uso**: nada la lee ni la escribe.

- La migración `2026_10_11_100001_perfiles_multiples_y_predefinidos` copió a `usuario_perfil` el perfil que
  tenía cada usuario.
- La columna se elimina en una **migración posterior**, cuando la pivote esté verificada en producción.

## Perfiles predefinidos

Cada perfil predefinido tiene un **código estable** (`perfiles_acceso.codigo`, único) y la marca
`predefinido`.

- Se lo identifica por el código, no por el nombre: se puede renombrar desde la pantalla sin que el seeder
  cree un duplicado.
- El listado de perfiles los marca como "Predefinido".

| Código | Nombre |
|---|---|
| ADMINISTRADOR | Administrador |
| GERENCIA | Gerencia |
| MEDICO | Médico |
| ENFERMERIA | Enfermería |
| RECEPCION | Recepción |
| CAJA | Caja |
| FACTURACION | Facturación y convenios |
| COMPRAS_TESORERIA | Compras y tesorería |
| SUPERVISION_MEDICA | Supervisión médica |
| AUDITOR | Auditor |

### El Administrador

- Tiene siempre **todos** los permisos, también los de los módulos futuros (`PerfilAcceso::asegurarAdministrador`).
- No se desactiva, su matriz no se edita y su nombre no cambia.

### El seeder

`PerfilesPredefinidosSeeder` crea los perfiles que falten con su matriz.

- Si ya existe un perfil **sin código** con el nombre visible (o uno de `PerfilesPredefinidos::ADOPTA`), lo
  **adopta**: le pone el código, lo marca como predefinido y le pone el nombre visible.
  - "Enfermera" pasa a ser ENFERMERIA ("Enfermería"), con sus permisos tal cual (incluye Turnos: ver).
  - "Recepcionista" pasa a ser RECEPCION ("Recepción") y, por estar en `COMPLETA_AL_ADOPTAR`, se le
    **suma** lo que le falta de la matriz (Responsables de pago: ver, crear y editar; Disponibilidades:
    desactivar). No se le quita nada (conserva Orígenes de turno: crear y editar; Sucursales: ver).
  - Los demás perfiles sin código ("Secretaria Turno Tarde", "Recepcionista - Enfermera") no se tocan.
- La adopción pasa **una sola vez**: después el perfil tiene código y no se vuelve a adoptar ni a completar.
- **Nunca** modifica los permisos de un perfil que ya existe: lo que se cambió desde la pantalla queda.
- Se puede correr las veces que sea.

## La matriz

La matriz inicial (perfil → módulo → acciones) está en **un solo lugar**:
`App\Support\PerfilesPredefinidos::MATRIZ`.

- Solo tiene acciones con efecto hoy:
  - ninguna EXPORTAR, porque no hay exportación;
  - TURNOS no tiene DESACTIVAR, porque un turno se cancela con EDITAR;
  - MODULOS_SISTEMA no tiene pantalla.
- Ningún perfil, salvo ADMINISTRADOR, escribe en USUARIOS ni en PERFILES_ACCESO.

| Perfil | Módulos y acciones |
|---|---|
| GERENCIA | VER: TURNOS, DISPONIBILIDAD, CONSULTORIOS, ORIGENES_TURNO, PROFESIONALES, PROVEEDORES, CATEGORIAS_PROVEEDOR, TIPOS_RED_SOCIAL, ESPECIALIDADES, SUCURSALES, TIPOS_DOCUMENTO, PROCEDIMIENTOS, MEDIOS_PAGO, CATEGORIAS_GASTO, CIE10, TIPOS_BLOQUE_ANAMNESIS, TIPOS_INDICACION, GEOGRAFIA, AUDITORIA |
| MEDICO | VER, CREAR, EDITAR: HISTORIA_CLINICA, RECETAS, PREPARACION. VER: TURNOS, DISPONIBILIDAD, CONSULTORIOS, PACIENTES |
| ENFERMERIA | VER, CREAR, EDITAR: PREPARACION |
| RECEPCION | VER, CREAR, EDITAR: TURNOS. VER, CREAR, EDITAR, DESACTIVAR: DISPONIBILIDAD. VER: CONSULTORIOS, ORIGENES_TURNO, PROFESIONALES, ESPECIALIDADES, PROCEDIMIENTOS. VER, CREAR, EDITAR: PACIENTES, PERSONAS, RESPONSABLES_PAGO |
| CAJA | VER: PACIENTES, MEDIOS_PAGO, PROCEDIMIENTOS |
| FACTURACION | VER: RESPONSABLES_PAGO, PROCEDIMIENTOS, MEDIOS_PAGO |
| COMPRAS_TESORERIA | VER, CREAR, EDITAR, DESACTIVAR: PROVEEDORES, CATEGORIAS_PROVEEDOR. VER: CATEGORIAS_GASTO |
| SUPERVISION_MEDICA | VER: HISTORIA_CLINICA, RECETAS, PACIENTES, PROFESIONALES, AUDITORIA. VER, CREAR, EDITAR, DESACTIVAR: DIETAS |
| AUDITOR | VER: AUDITORIA |

Notas sobre la matriz:

- Los formularios de Recepción no piden permiso de los catálogos que usan: tipo de documento, país,
  departamento, ciudad y sucursal se cargan sin permiso de su módulo. Lo mismo pasa con los catálogos de la
  consulta: CIE-10, tipos de bloque y tipos de indicación. Por eso esos perfiles no los tienen.
- El Médico **no administra la agenda**: no tiene EDITAR sobre TURNOS, así que no ve ni usa los botones
  Confirmar, Cancelar y Ausente de la pantalla Turnos. Lo que hace sobre **sus** turnos desde la pantalla
  Consulta ("No se presentó", "Pasar a ausente" y "Cerrar jornada") se autoriza con CREAR sobre
  HISTORIA_CLINICA y siendo el profesional ACTIVO del turno (`ConsultaPolicy`). Las reglas de hora, estado
  y transacción son las de siempre (`Turno::pasarA`).
- En la Auditoría, quien no tiene VER de HISTORIA_CLINICA o de RECETAS no ve el contenido clínico ni el de
  las recetas. Gerencia y Auditor ven los eventos, sin ese contenido.
- La pantalla **Matriz de permisos** (Seguridad) se describe abajo.

## Matriz de permisos (Seguridad)

Se mira con VER sobre PERFILES_ACCESO y se edita con EDITAR. Abrirla registra VER (PERFILES_ACCESO es
sensible). Dos pestañas:

- **Editar un perfil.**
  - Se elige el perfil.
  - Los módulos van en filas, agrupados como en el menú (`MatrizPermisosController::GRUPOS`; los que no están
    en el menú, en "Otros").
  - Hay una casilla por cada acción que tiene efecto en el módulo (`Permiso::ACCIONES_POR_MODULO`).
  - Con JavaScript se suman los atajos por módulo ("Todo", "Ninguno", "Solo ver") y un buscador. Sin
    JavaScript funciona igual: es un formulario con casillas.
- **Comparar.** Todos los perfiles en columnas, solo lectura. En un perfil predefinido se marcan las celdas que
  difieren de `PerfilesPredefinidos::MATRIZ` (la matriz predefinida no se cambia desde acá).

### Guardar

1. "Guardar cambios" muestra primero una **confirmación** con lo que se agrega y lo que se quita. Todavía no
   se guarda nada.
2. Si se agrega algo **delicado**, la confirmación pide además una casilla, que el servidor también exige. Es
   delicado lo de un módulo sensible, o un permiso de escritura sobre USUARIOS o PERFILES_ACCESO, que en la
   práctica hace administradores a los usuarios del perfil.
3. "Confirmar y guardar" aplica todo en **una transacción**.

### Reglas

- **Concurrencia:** la pivote no toca `updated_at`, así que se usa una huella (hash) del conjunto de permisos
  del perfil. Si cambió desde que se abrió la pantalla: "El perfil fue modificado desde otra ventana. Recargue
  la página."
- Las de siempre, desde un solo lugar (`App\Support\PermisosDePerfil`, la misma lógica que Perfiles de acceso):
  - sin escalada: solo se agregan permisos que quien edita ya tiene; quitar siempre se puede;
  - el Administrador no se edita;
  - un módulo inexistente, una acción inexistente o sin efecto en el módulo, o un id manipulado se rechazan;
  - desde acá no se crean ni se desactivan perfiles.
- Los cambios valen desde el **siguiente pedido** de cada usuario del perfil: los permisos se calculan en cada
  pedido y no quedan en caché ni en sesión.
- Un perfil inactivo se puede editar y sigue sin dar permisos.

### Auditoría y lo predefinido

- **Auditoría:** un solo evento EDITAR del perfil por guardado, con lo agregado y lo quitado por módulo
  (`permisos_agregados` / `permisos_quitados`, como "RECETAS: ver, crear"), quién lo hizo y el motivo opcional
  (hasta 300 caracteres) en el detalle. Un guardado sin cambios no registra nada. Perfiles de acceso registra
  igual.
- **Restablecer a lo predefinido:** en un perfil predefinido, muestra tildada su matriz predefinida (sin
  guardar). Se guarda con la misma confirmación y las mismas reglas.

## Cómo se suma un módulo nuevo

1. Agregarlo a `ModuloSistemaSeeder`, con sus estados. Si solo admite algunas acciones, sumarlo a
   `Permiso::ACCIONES_POR_MODULO`.
2. En la **migración (o el seeder) que introduce el módulo**, una sola vez, darle los permisos a los
   perfiles que corresponda:

   ```php
   PerfilesPredefinidos::sumarModulo('CAJA_DIARIA', ['CAJA' => ['VER', 'CREAR'], 'GERENCIA' => ['VER']]);
   ```

   - Solo suma: no quita nada y no toca otros módulos.
   - El Administrador lo recibe completo, solo.
3. Si el módulo debe ir en la matriz de los perfiles que se creen **en adelante**, sumarlo también a
   `PerfilesPredefinidos::MATRIZ`.
   - La matriz solo se aplica al **crear** un perfil.
   - Por eso el paso 2 hace falta para los perfiles que ya existen.

## Administrador principal

Hay **un único** administrador principal (`users.administrador_principal`), inamovible. Los demás usuarios
con el perfil Administrador administran como siempre, pero no pueden tocarlo. La regla está en
`App\Support\AdministradorPrincipal` y vale en los modelos y en los servicios, no solo en la pantalla.

### La marca

- Columna booleana con un **índice único parcial** (`users_un_solo_administrador_principal`): a lo sumo un
  usuario en true. Lo admiten PostgreSQL y SQLite, y además lo verifica el modelo.
- **No se cambia desde la aplicación**: no está en `$fillable` y el modelo la rechaza en cualquier pedido web.
- La puso una sola vez la migración `2026_10_12_100001_administrador_principal`: el usuario con el perfil
  Administrador cuya persona tiene la CI de `DOCUMENTO`. Si hay usuarios y no es exactamente uno, la migración
  falla con un mensaje claro y no marca a nadie. Una base sin usuarios (instalación nueva, tests) no marca a
  nadie: el primer principal se asigna con el traspaso.

### Qué no se puede (en cualquier pedido web, para todos, también para él mismo)

Mensaje: "Es el administrador principal y no puede modificarse."

- **Estado:** no se lo desactiva, bloquea ni se le cambia el estado (Usuarios, "Desactivar mi cuenta",
  `User::booted`).
- **Perfiles:** no se le asignan ni se le quitan perfiles (`UsuarioController`, `UsuarioPerfil`), y un perfil
  que él tenga no se desactiva (`PerfilAcceso::booted`). Siempre conserva el perfil Administrador, que además
  nunca se desactiva ni pierde permisos.
- **Persona:** nadie la desactiva (`Persona::booted`).
- **Credenciales:** los datos de su persona (correo, documento, nombres, ...) solo los edita **él**; tampoco se
  cambia su correo o su persona en `users`. Desde Usuarios no se le manda invitación ni restablecimiento de
  contraseña (`User::enviarLinkContrasena`). Motivo: si otro pudiera cambiar su correo y pedir un
  restablecimiento, se quedaría con la cuenta.
- En Usuarios se lo ve con la marca "Administrador principal"; su formulario es de solo lectura (sin casillas,
  estado ni invitación) y no se ofrece desactivarlo. En Personas, a los demás no se les ofrece editar ni
  desactivar su persona.

Lo que **sí** puede él: editar los datos de su persona, cambiar su contraseña desde su perfil (con la actual) o
con "¿Olvidó su contraseña?" (el link le llega a su correo).

La **consola** queda afuera, como en las otras guardas (no hay usuario logueado): por eso existe el traspaso.

### Solo el principal asigna el perfil Administrador

Solo el administrador principal asigna o quita el perfil Administrador a otros usuarios (formulario y
`UsuarioPerfil`); para los demás, esa casilla queda fija. La condición es **una constante**:
`AdministradorPrincipal::SOLO_EL_PRINCIPAL_ASIGNA_ADMINISTRADOR = true`. Para que lo haga cualquier
Administrador, pasarla a `false` (y ajustar los tests de `AdministradorPrincipalTest`).

Cualquier otro Administrador puede ser modificado o desactivado (por el principal o por otro Administrador,
salvo su perfil Administrador), y como todos, no se desactiva ni cambia sus propios perfiles.

### Traspaso: `php artisan clinexa:traspasar-principal {correo}`

Es la **única vía** para mover la marca.

- Pide confirmación: hay que escribir el correo del nuevo principal; si no coincide, no hace nada.
- Exige que el nuevo esté ACTIVO (usuario y persona) y tenga el perfil Administrador activo.
- Mueve la marca en una transacción (primero la quita del anterior, por el índice único) y deja un evento
  EDITAR del nuevo usuario en la auditoría, con el anterior en el detalle.
- El anterior sigue siendo Administrador, ya como uno más.

### Plan de recuperación

Si el administrador principal pierde el acceso a su correo, **no hay vía por pantalla**: nadie puede cambiarle
el correo ni mandarle un restablecimiento desde la aplicación (es justamente lo que lo protege). Se recupera
**por consola**, con acceso al servidor. Antes de cualquier paso: `php artisan clinexa:respaldo --probar`.

1. Confirmar la identidad de la persona por fuera del sistema (en persona, con su documento).
2. Actualizar el correo de su persona desde la consola (`php artisan tinker`, con el modelo `Persona`: la
   consola queda afuera de las guardas). El correo de `users` se sincroniza solo desde la persona.
3. Que la persona use "¿Olvidó su contraseña?" en el login: el link le llega a su correo nuevo.
4. Si lo que hay que cambiar es **quién** es el principal (la persona ya no está), usar el traspaso:
   `php artisan clinexa:traspasar-principal` con el correo de otro usuario Administrador activo.
5. Dejar constancia del motivo, la fecha y quién lo hizo (la consola no registra un usuario en la auditoría).

No se anotan en ningún documento correos, contraseñas ni datos personales.

## Seguridad de la asignación

### Nunca sin administrador

Queda como **respaldo**: con un administrador principal activo, siempre hay uno.

No se puede dejar el sistema sin un usuario activo con el perfil Administrador:

- quitarle el perfil al último;
- desactivarlo o bloquearlo;
- desactivar su persona.

Lo rechazan `User::booted`, `Persona::booted` y `UsuarioController` con el mensaje de `UltimoAdministrador`.

### Nadie cambia sus propios perfiles

Un usuario no puede asignarse ni quitarse perfiles ("No puede cambiar su propio perfil de acceso."), ni
bloquearse o desactivarse desde Usuarios. En su propio formulario las casillas aparecen deshabilitadas.
Lo rechazan `UsuarioController` y, en cualquier pedido web, el modelo de la pivote (`UsuarioPerfil`), así
que tampoco pasa por `attach`, `detach` o `sync` desde el código. Desde la consola (crear el primer
administrador, seeders) no hay usuario logueado y no aplica.

### Sin escalada de privilegios

- Solo se puede **asignar** a otro usuario un perfil cuyos permisos quien asigna ya tiene todos. Intentar
  saltarse la regla con el propio usuario tampoco sirve: nadie cambia sus propios perfiles.
  - Solo cuentan los perfiles que se **agregan**: los que el usuario ya tenía no.
- A un perfil solo se le **agrega** un permiso que quien edita ya tiene.
- El Administrador tiene todo, así que no queda limitado.

### Auditoría

- Cambiar los perfiles de un usuario es un **EDITAR del usuario**, con la lista de nombres antes y
  después, como las categorías de un proveedor. El alta también lo registra: de `[]` a la lista elegida.
- Los cambios de permisos de un perfil son un EDITAR del perfil, con la lista "MÓDULO: ACCIÓN" antes y
  después.

## Endurecimiento antes de producción

- **Restringir la lectura clínica del Administrador.** Hoy el Administrador ve todo, también la historia
  clínica y las recetas, porque tiene todos los permisos. Antes de usar el sistema con pacientes reales hay que
  separar la administración técnica de la lectura clínica (por ejemplo, sacar HISTORIA_CLINICA y RECETAS del
  perfil Administrador, con su propio perfil de auditoría clínica).
- **Trigger contra el `detach()` masivo de `usuario_perfil`.** La guarda de `UsuarioPerfil` corre en
  `attach`, `sync` y `detach($ids)`, pero un `detach()` sin ids borra todas las filas sin pasar por el modelo.
  Ninguna pantalla lo usa; un trigger de PostgreSQL que impida borrar las filas del administrador principal
  (y del perfil Administrador) lo cerraría también fuera de la aplicación.
- **Evento `INTENTO_RECHAZADO`.** Hoy un intento rechazado (por ejemplo, de modificar al administrador
  principal) no deja cambios ni eventos. Propuesta mínima: un caso `INTENTO_RECHAZADO` en
  `App\Enums\AccionAuditoria` (la columna `accion` es texto, no hace falta migración) y registrarlo, fuera de
  la transacción que se deshace, desde el lugar que muestra el aviso.

## Pendiente

- El perfil **"Personal de salud"** para el triaje llega con la etapa de triaje.
- El módulo PREPARACION se va a **renombrar**; por ahora se lo referencia por su código actual.
- El permiso de **desbloqueo** de una consulta cerrada, para Supervisión médica, llega con la etapa de adenda
  (`docs/historia-clinica.md`).
- **Eliminar `users.perfil_acceso_id`** en una migración posterior.
