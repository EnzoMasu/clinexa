# Geografía (países, departamentos y ciudades)

- Un solo módulo, `GEOGRAFIA` (estados ACTIVO e INACTIVO). Administrador: todo. Gerencia: VER, CREAR, EDITAR y DESACTIVAR.
- Nada se borra: se desactiva. Lo inactivo no se ofrece en los selectores nuevos; las fichas que ya lo usan lo conservan y lo muestran como "(inactivo)".
- Renombrar se ve en todas las fichas (se guarda la referencia por id). Cada cambio queda en Auditoría con valor anterior y nuevo.
- Jerarquía: no se desactiva un País con departamentos activos ni un Departamento con ciudades activas (mensaje con la cantidad); primero se desactivan los hijos.
- `GeografiaSeeder`: países por código ISO; departamentos y ciudades por `clave_origen` (`PY:Departamento`, `PY:Departamento/Ciudad`). Solo agrega lo que falta: nunca pisa un nombre ni un estado editado ni duplica un registro renombrado. Los cargados a mano tienen `clave_origen` nulo.
- Pendiente de decidir: reactivar un hijo cuyo padre está inactivo (hoy se permite).
