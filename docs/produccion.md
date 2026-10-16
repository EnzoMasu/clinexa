# Antes de usar con pacientes reales

Lista de control previa a cargar pacientes reales. Sin datos personales: marcar cada punto cuando esté hecho.

- [ ] **Repositorio privado.** Verificar que el repositorio no sea público.
- [ ] **Parámetros clínicos.** Los valores de referencia (normal, muy alterado) cargados y aprobados por un médico, sin
  valores de ejemplo. Mientras estén PENDIENTE no marcan alertas (`docs/triaje.md`).
- [ ] **Administrador sin lectura clínica ni correcciones.** Hoy el Administrador recibe por diseño todos los módulos,
  incluido `CORRECCIONES_HC`: puede desbloquear consultas de otros y leer la historia clínica. Quitarle la lectura clínica
  (`HISTORIA_CLINICA`, `RECETAS`) y `CORRECCIONES_HC`, y dejar esas funciones en perfiles de supervisión.
- [ ] **Al menos dos supervisores** con `CORRECCIONES_HC` (EDITAR): quien atendió una consulta no puede desbloquearla.
- [ ] **Trigger contra `detach()` masivo de `usuario_perfil`**, para que no se pierdan perfiles de muchos usuarios de una vez.
- [ ] **Evento `INTENTO_RECHAZADO`** en la auditoría para los intentos bloqueados (por ejemplo, escribir en una consulta cerrada).
- [ ] **Borrar `users.perfil_acceso_id`** (columna vieja, reemplazada por varios perfiles por usuario).
- [ ] **Respaldos cifrados y fuera de la PC** (`docs/respaldo.md`), con una restauración de prueba.
- [ ] **Desactivar los dos perfiles viejos sin usuarios.**
- [ ] **PDF clínico.** Revisar el aspecto en papel de los PDF de triaje, de consulta y de la receta (`docs/pdf-clinico.md`) y quiénes tienen `PDF_CLINICO`.
- [ ] **Revisión legal de los textos** que salen impresos: reposo (`config/reposo.php`), orden de estudio (`config/ordenes_estudio.php`) y constancias de retiro y de estado (`config/constancias.php`); luego poner `borrador` en `false` en cada uno.
