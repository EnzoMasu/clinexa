# RUC de la persona

Campo `personas.ruc`, aparte del documento (tipo + número).

## Reglas

- **Persona jurídica:** RUC obligatorio. No tiene CI ni pasaporte: el formulario no muestra el documento y el servidor no lo acepta
  (`tipo_documento_id` y `nro_documento` rechazados).
- **Persona física:** CI o pasaporte obligatorio. RUC opcional; si se carga, se valida igual. No tiene que coincidir con la CI.
- El selector de tipo de documento del formulario ofrece **solo CI y pasaporte** (`Persona::DOCUMENTOS_FISICA`); el RUC ya no se
  ofrece como documento. Los documentos viejos de tipo RUC se siguen mostrando.
- Es **único** entre personas: índice único parcial `personas_ruc_unico` (solo cuando no es nulo).
- Una sola pantalla (Personas): el selector de tipo de persona despliega los campos de cada tipo. La regla vive en
  `PersonaRequest` (alta y edición) y `App\Rules\RucValido`; los roles (paciente, proveedor, responsable de pago, usuario...) eligen una
  persona ya cargada, no la crean.

## Formato y dígito verificador (`App\Support\Ruc`)

6 a 8 dígitos, guion y 1 dígito verificador (DV): `80080099-0`. No se soportan los RUC antiguos que terminan en letra (A-D).

Módulo 11 de la DNIT: sin el guion y el DV, los dígitos de la base de derecha a izquierda se multiplican por los pesos 2, 3, ... 11
(con más de 10 dígitos los pesos reinician en 2); S = suma; resto = S mod 11; DV = 11 - resto si el resto es mayor que 1, y 0 si no.
Verificados a mano: `44444401-7` (S = 158) y `80080099-0` (S = 165) son válidos; `44444401-6` y `80080099-1`, no.

## Apagar el control del dígito

`config('clinexa.validar_digito_ruc')`, por defecto **activo**. Para apagarlo: `CLINEXA_VALIDAR_DIGITO_RUC=false` en `.env` (y
`php artisan config:clear` si la configuración está en caché). Apagado, el dígito puede ser cualquiera; el formato (6 a 8 dígitos,
guion y un dígito) se exige siempre.

## Lo ya guardado

No se rechaza ni se cambia el tipo de persona. En el listado de Personas se marca **"falta RUC"** (jurídica sin RUC) y **"falta CI"**
(física sin CI ni pasaporte, p. ej. con su RUC como único documento); al editar, el formulario pide lo que falta.

## Migración `2026_11_01_100001_ruc_de_la_persona`

Agrega `ruc`, hace opcionales `tipo_documento_id` y `nro_documento`, copia a `ruc` el número de quien tiene documento de tipo RUC
(el documento viejo **no se toca**) y crea el índice único. Sin el tipo RUC en el catálogo no copia nada; en `--pretend` no cambia
nada. Antes de aplicarla a una base: buscar RUC repetidos (la copia falla si hubiera dos iguales).

## Mostrar el documento de una persona

`Persona::documentoPrincipal()`: RUC para la jurídica; CI o pasaporte para la física (o su RUC si no tiene otro); vacío si no tiene
ninguno. Vistas, PDF y servicios lo usan en lugar de leer `tipoDocumento->codigo` y `nro_documento`.

## Datos demo

Aseguradoras (jurídicas, sin documento) por su RUC en el campo nuevo; la Dra. Benítez (física, CI 9000007) con su RUC `9000007-2`.
Sobre una base ya cargada, `clinexa:datos-demo` encuentra a las aseguradoras también por el RUC viejo como documento y completa el campo.
