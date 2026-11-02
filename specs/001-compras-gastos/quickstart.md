# Quickstart: validar Compras y Gastos

Con datos inventados. Nunca contra `clinexa_dev` fuera de `/aplicar`.

## Pruebas automáticas

- Diario: `composer test:rapido`.
- Antes de commit no WIP o de `/aplicar`: `composer test:completo` (incluye `tests/Postgres/ComprasPostgresTest.php`: índice único parcial y CHECK).

## Escenarios (navegador, tras `/aplicar` con confirmación de Enzo)

1. **Alta (US1)**: usuario Compras y tesorería → Compras → Nueva: proveedor activo, categoría activa, factura `001-001-0000123` con
   timbrado, montos 110.000 al 10 % y 21.000 al 5 %, contado. Esperado: queda "Borrador", IVA 10.000 y 1.000, total 131.000, en Auditoría.
2. **Duplicado (US1-2)**: repetir proveedor, tipo y número. Esperado: error que enlaza la compra existente.
3. **Inactivos (US1-3)**: desactivar el proveedor; no aparece en Nueva; la compra anterior se sigue viendo con su nombre.
4. **Quien carga no confirma (US2-3)**: el mismo usuario no ve "Confirmar"; un POST directo es rechazado con el mensaje. Gerencia la confirma.
5. **Solo lectura (US2-2)**: confirmada, no se puede editar. Gerencia la anula con motivo: queda "Anulada" con motivo, fecha y responsable.
6. **Crédito (US4)**: crédito con vencimiento y anticipo 50.000 sobre 131.000; confirmada, aparece en `aCreditoVigentes()` con adeudado 81.000.
7. **Filtros (US3)**: compras en dos meses; filtrar uno; el total coincide con la suma. Un perfil sin COMPRAS no ve el menú ni entra (403).
8. **Sin desglose (P4)**: ticket con total 25.000: sin IVA ni montos por tasa. Total cero, fecha futura, vencimiento anterior a la compra o anticipo igual al total: rechazados.
9. **Descartar (P5)**: Compras descarta su propio borrador con motivo (queda anulada); no descarta el de otra persona. Gerencia anula una confirmada que cargó ella misma (P6) pero no la confirma.
10. **Imagen (P7)**: JPG, PNG o PDF de hasta 5 MB; otro formato o más de 5 MB, rechazado.
11. Fechas dd/mm/aaaa y textos en "usted" en todas las pantallas.
