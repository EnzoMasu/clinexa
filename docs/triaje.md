# Triaje

El triaje es lo que se carga antes de que el profesional atienda: urgente y observación, anamnesis y signos
vitales.

## Nombres: "Triaje" en pantalla, "preparación" por dentro

En pantalla se llama **Triaje**:

- URLs `/admin/triaje`;
- las URLs viejas `/admin/preparacion...` redirigen con 301 (solo GET).

**No se renombraron**, porque los usan los permisos de los perfiles, la auditoría ya registrada y el código:

- el código del módulo `PREPARACION`;
- el estado `EN_PREPARACION` (se muestra "EN TRIAJE");
- las tablas, las columnas (`consultas.preparada_en`) y las clases (`PreparacionController`, `Preparar`, `MarcarLista`);
- los nombres de ruta `admin.preparacion.*`.

| Antes | Ahora |
|---|---|
| Sin preparar / En preparación / Lista | Sin triaje / En triaje / Con triaje |
| Preparar | Iniciar triaje / Continuar triaje |
| Marcar como lista / Reabrir | Terminar triaje / Reabrir triaje |

## Quién carga el triaje

La regla está en un solo lugar: `ConsultaPolicy::cargarTriaje`.

- Puede cargarlo un usuario **ACTIVO** cuya persona sea **Profesional activo** o **Personal de salud activo**,
  **además** del permiso del módulo Triaje (PREPARACION).
- Una persona con un rol administrativo no lo carga aunque tenga el permiso por error. Recibe 403 con el
  mensaje "Solo el personal de salud puede cargar el triaje.".
- La regla aplica a:
  - iniciar el triaje;
  - retomarlo;
  - el autoguardado del triaje cuando quien escribe no es el profesional de la consulta;
  - terminarlo y reabrirlo.
- La lista de Triaje se sigue viendo con VER. A quien no es personal de salud se le avisa y no se le ofrecen los
  botones.
- El profesional de la consulta escribe el triaje como siempre, con su permiso sobre la historia clínica.

### El rol "Personal de salud"

- **Rol:** `personal_salud`, con persona física activa, cargo, registro profesional opcional y estado. Menú:
  Personas y Roles. Módulo `PERSONAL_SALUD`.
- **Cargos:** catálogo `cargos_salud`, módulo `CARGOS_SALUD`. Se siembran solo si la tabla está vacía: Médico/a,
  Licenciado/a en enfermería, Licenciado/a en obstetricia, Enfermero/a, Auxiliar de enfermería.
- **Sin backfill:** nadie se convierte en personal de salud solo; los asigna un administrador.
- **"Cargado por"** muestra el cargo y el registro, por ejemplo "Nidia Ortiz (Enfermero/a, registro 1234)".

## Urgente y observación

El bloque va al comienzo del triaje: `consultas.triaje_urgente`, `triaje_observacion` (hasta 1000 caracteres),
`triaje_urgente_por` y `triaje_urgente_en`.

- **Quién los edita:** quien carga el triaje, y también el profesional de la consulta mientras está EN_CURSO.
- **Quién y cuándo:** se guardan al marcar la urgencia y se limpian al desmarcarla. Marcar, desmarcar y la
  observación quedan en el EDITAR de la consulta.
- **Grupo de escritura:** son del grupo de triaje y se congelan con la consulta cerrada (ProtegidoPorCierre).
- **Sin el bloque, no cambian:** un pedido que no trae el bloque (`con_triaje`) no los modifica.
- **En las listas** de Consulta y de Triaje, las filas urgentes llevan la pastilla roja **URGENTE** y van primero
  dentro de su sección.
- **La observación completa** solo se ve en el formulario de triaje y en la pantalla de atención, nunca en las
  listas.
- **Información clínica:** las pastillas solo se muestran en pantallas que exigen HISTORIA_CLINICA o el permiso de
  Triaje, nunca en la agenda (Turnos).

## Signos vitales: los tres niveles

`App\Support\Atencion\EvaluadorSignos` es la clase única que decide el nivel. Usa los **parámetros clínicos**
(`parametros_clinicos`, módulo `PARAMETROS_CLINICOS`): TEMPERATURA, PRESION_SISTOLICA, PRESION_DIASTOLICA,
FRECUENCIA_CARDIACA, FRECUENCIA_RESPIRATORIA, SATURACION_OXIGENO, PESO y TALLA.

Cada parámetro tiene tres bandas anidadas: imposible ⊇ muy alterado ⊇ normal. Las dos últimas pueden faltar.

| Nivel | Cuándo | Qué pasa |
|---|---|---|
| IMPOSIBLE | fuera de la banda imposible, o negativo | se rechaza (422): "El valor de TEMPERATURA no es posible." Rige **siempre**, aprobado o no. |
| MUY_ALTERADO | fuera de la banda muy alterado | pide **confirmación** |
| FUERA_DE_RANGO | fuera de la banda normal | se guarda y se marca "Fuera de lo normal" |
| NORMAL | dentro de lo normal, o el parámetro no está aprobado | nada |

- Los bordes están incluidos: un valor igual al límite está dentro de la banda.
- La presión "120/80" se evalúa en sus dos parámetros y queda el peor nivel.
- **Regla nueva de esta etapa:** una sistólica menor o igual que la diastólica es imposible. Antes el formulario
  solo validaba el formato 120/80.

### Aprobación médica

- **Lo sembrado:** solo los límites **imposible** que ya validaba el formulario (temperatura 30 a 45, frecuencia
  cardíaca 20 a 300, frecuencia respiratoria 5 a 80, saturación 0 a 100, peso 0 a 500 y mayor que 0, talla 20 a
  250; la presión, sin límites numéricos). Normal y muy alterado quedaron **vacíos** y todo **PENDIENTE**. Los
  valores clínicos no se inventaron: los carga y los aprueba un médico.
- **Aprobar:** con EDITAR sobre el módulo y siendo Profesional activo. Guarda quién y cuándo.
- **Editar uno aprobado** lo devuelve a PENDIENTE; lo tiene que volver a aprobar un médico.
- **Solo los APROBADOS** marcan "fuera de lo normal" o piden confirmación.
- **Banner** en la pantalla de parámetros y en el formulario de triaje mientras falte alguno aprobado: "Los valores
  de referencia todavía no fueron aprobados por un médico: el sistema no marca alertas."
- **Perfiles**, sumados una sola vez desde la migración (`sumarModulo`):
  - Supervisión médica: ver y editar.
  - Médico y Gerencia: ver.

### Confirmación de un valor muy alterado

1. **Autoguardado:** el servidor responde 422 con `codigo: requiere_confirmacion` y los campos. Los demás campos ya
   se guardaron.
2. **La pantalla** muestra "Valor muy alto: 41,5 °C. ¿Está seguro de que es correcto?" con "Sí, es correcto" y
   "Corregir". El campo queda "Pendiente de confirmar".
3. **Al confirmar,** se reenvía con `confirmaciones[campo]=valor`. Un valor sin esa confirmación **exacta** no se
   guarda.
4. **Sin JavaScript,** con "Guardar triaje": vuelve con el error y una casilla de confirmación por campo.
   Finalizar tampoco guarda nada sin la confirmación y pide la casilla.
5. **Lo confirmado** no se vuelve a pedir mientras el valor no cambie.

### `examenes_fisicos.signos_alertas`

- Guarda el nivel de cada signo marcado **al guardar**, y si fue confirmado (quién y cuándo).
- Se recalcula en cada guardado mientras la consulta está abierta.
- Queda **congelado** al cerrarse: cambios futuros de los rangos no reescriben historias cerradas.
- La auditoría del EDITAR incluye las confirmaciones, por ejemplo "Confirmó valor muy alto: Temperatura 41,5 °C".

### Cómo se ve

- **Etiquetas:** junto a cada signo, una etiqueta con **texto** y color: "Fuera de lo normal" en ámbar y "Muy
  alterado" en rojo.
- **Pantalla de atención:** una franja "Signos alterados: ...".
- **Listas:** la pastilla "Signos alterados". Solo marca: los signos alterados **no** cambian el orden de las
  listas, que solo cambia URGENTE.

## Auditoría y seguridad

- **Contenido clínico:** los campos nuevos de `consultas` y `examenes_fisicos` caen bajo la regla de contenido
  clínico (ocultos sin VER sobre HISTORIA_CLINICA, y el buscador de Auditoría no busca ahí).
- **No es contenido clínico:** personal de salud, cargos y parámetros. Los ve quien ve la Auditoría.
- **Como siempre:** todo escapado, `SinAlmacenar` en las pantallas del flujo y VER donde corresponde.

## Límite conocido

Hay **un solo juego de rangos, para adultos**. Las pacientes embarazadas o pediátricas pueden necesitar otros, y
eso no se resuelve en esta etapa.
