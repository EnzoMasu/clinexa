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
| SUPERVISION_MEDICA | VER: HISTORIA_CLINICA, RECETAS, PACIENTES, PROFESIONALES, AUDITORIA |
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
- La pantalla **Matriz de permisos** (Seguridad, VER sobre PERFILES_ACCESO) muestra la matriz real de la
  base: perfiles en columnas y módulos en filas. Es de solo lectura y abrirla registra VER, porque
  PERFILES_ACCESO es sensible.

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

## Seguridad de la asignación

### Nunca sin administrador

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

## Pendiente

- **Antes de producción, restringir la lectura clínica del Administrador.** Hoy el Administrador ve todo,
  también la historia clínica y las recetas, porque tiene todos los permisos. Es una decisión tomada por
  ahora. Antes de usar el sistema con pacientes reales hay que separar la administración técnica de la
  lectura clínica (por ejemplo, sacar HISTORIA_CLINICA y RECETAS del perfil Administrador, con su propio
  perfil de auditoría clínica).
- El perfil **"Personal de salud"** para el triaje llega con la etapa de triaje.
- El módulo PREPARACION se va a **renombrar**; por ahora se lo referencia por su código actual.
- El permiso de **desbloqueo** de una consulta cerrada, para Supervisión médica, llega con la etapa de adenda
  (`docs/historia-clinica.md`).
- **Eliminar `users.perfil_acceso_id`** en una migración posterior.
