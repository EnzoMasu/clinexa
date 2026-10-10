/**
 * Pantalla de atención y formulario de preparación (consultas EN_PREPARACION o EN_CURSO): paneles por
 * sección y autoguardado.
 *
 * Autoguardado:
 * - Envía el formulario entero por fetch (PATCH con CSRF): el formulario solo tiene los campos de los
 *   grupos que el usuario puede escribir, y el servidor lo vuelve a comprobar.
 * - 3 s después del último cambio, no más de un envío cada 10 s, un solo pedido en vuelo (si hubo cambios
 *   mientras tanto, otro al terminar) y "Guardar ahora".
 * - 409 (versión vieja o consulta cerrada): cartel fijo y se detiene; nunca se pisa nada.
 * - 422: los errores en su sección. 401/403/419 o sesión vencida: cartel fijo, SIN navegar (lo escrito
 *   queda en pantalla). Red o 429/5xx: reintentos con espera creciente (máx. 5) y luego "No se pudo guardar".
 * - Aviso al salir con cambios sin guardar.
 * - El contenido clínico NO se guarda en el navegador (ni localStorage, ni sessionStorage, ni IndexedDB, ni
 *   cookies): solo vive en el formulario y en el servidor.
 *
 * Sin autoguardado (consulta finalizada): solo los paneles; se guarda con "Guardar cambios".
 */
const DEBOUNCE_MS = 3000;
const INTERVALO_MINIMO_MS = 10000;
const REINTENTOS = 5;

export default ({ url = null, autoguardado = false, panel = null, panelesConError = [] }) => ({
    panel,
    panelesConError,
    contenido: {},
    estado: 'guardado', // guardado | pendiente | guardando | error
    horaGuardado: null,
    cartel: null, // mensaje fijo que detiene el autoguardado (409, sesión)
    errores: {}, // sección => mensajes (422)
    sucio: false,
    enVuelo: false,
    otroPendiente: false,
    reintento: 0,
    ultimoEnvio: 0,
    temporizador: null,
    enviandoFormulario: false,
    historialAbierto: false, // panel "Historial del paciente" en pantallas chicas

    init() {
        this.calcularContenido();
        if (!this.panel) {
            this.panel = this.$root.querySelector('[data-panel]')?.dataset.panel ?? null;
        }
        window.addEventListener('beforeunload', (evento) => {
            if (autoguardado && !this.enviandoFormulario && (this.sucio || this.enVuelo)) {
                evento.preventDefault();
                evento.returnValue = '';
            }
        });
    },

    ver(panel) {
        this.panel = panel;
    },

    // Un punto en cada botón: la sección tiene algo cargado.
    calcularContenido() {
        const contenido = {};
        this.$root.querySelectorAll('[data-panel]').forEach((panel) => {
            const campos = [...panel.querySelectorAll('textarea, input[type=text], input[name$="[codigo_cie10]"]')];
            contenido[panel.dataset.panel] = campos.some((campo) => campo.value.trim() !== '');
        });
        this.contenido = contenido;
    },

    // Cualquier cambio en el formulario (escribir, elegir, agregar, retirar o descartar una fila).
    cambio() {
        this.$nextTick(() => this.calcularContenido());
        if (!autoguardado || this.cartel) {
            return;
        }
        this.sucio = true;
        this.estado = 'pendiente';
        this.programar(DEBOUNCE_MS);
    },

    programar(espera) {
        clearTimeout(this.temporizador);
        const minimo = this.ultimoEnvio + INTERVALO_MINIMO_MS - Date.now();
        this.temporizador = setTimeout(() => this.guardar(), Math.max(espera, minimo, 0));
    },

    guardarAhora() {
        if (!autoguardado || this.cartel) {
            return;
        }
        clearTimeout(this.temporizador);
        this.reintento = 0;
        this.guardar(true);
    },

    async guardar(ahora = false) {
        if (this.cartel) {
            return;
        }
        if (this.enVuelo) {
            this.otroPendiente = true;
            return;
        }
        if (!ahora && Date.now() - this.ultimoEnvio < INTERVALO_MINIMO_MS) {
            this.programar(0);
            return;
        }

        const formulario = this.$refs.formulario;
        const datos = new FormData(formulario);
        datos.set('_method', 'PATCH');
        this.enVuelo = true;
        this.sucio = false;
        this.estado = 'guardando';
        this.ultimoEnvio = Date.now();

        let respuesta;
        try {
            respuesta = await fetch(url, {
                method: 'POST',
                body: datos,
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
        } catch (error) {
            this.enVuelo = false;
            this.sucio = true;
            this.reintentar();
            return;
        }
        this.enVuelo = false;

        if (respuesta.redirected || [401, 403, 419].includes(respuesta.status)) {
            this.detener('Su sesión venció o perdió el permiso. Inicie sesión en otra pestaña y recargue; lo guardado hasta ahora no se pierde.');
            return;
        }
        if (respuesta.status === 409) {
            const cuerpo = await respuesta.json().catch(() => ({}));
            this.detener(cuerpo.message || 'Esta consulta se modificó desde otra ventana. Recargue la página.');
            return;
        }
        if (respuesta.status === 422) {
            const cuerpo = await respuesta.json().catch(() => ({}));
            this.mostrarErrores(cuerpo.errors ?? {});
            this.estado = 'error';
            return;
        }
        if (!respuesta.ok) {
            this.sucio = true;
            this.reintentar();
            return;
        }

        const cuerpo = await respuesta.json();
        formulario.querySelector('input[name=version]').value = cuerpo.version;
        this.horaGuardado = cuerpo.guardado.slice(-5);
        this.errores = {};
        this.panelesConError = [];
        this.reintento = 0;
        this.estado = this.sucio ? 'pendiente' : 'guardado';
        // Las secciones toman el id de sus filas nuevas y marcan las incompletas.
        window.dispatchEvent(new CustomEvent('consulta-guardada', { detail: { ids: cuerpo.ids ?? {}, incompletas: cuerpo.incompletas ?? {} } }));

        if (this.otroPendiente || this.sucio) {
            this.otroPendiente = false;
            this.programar(DEBOUNCE_MS);
        }
    },

    reintentar() {
        if (this.reintento >= REINTENTOS) {
            this.estado = 'error';
            return;
        }
        this.reintento++;
        this.estado = 'pendiente';
        this.programar(2000 * 2 ** this.reintento); // 4, 8, 16, 32, 64 s
    },

    detener(mensaje) {
        clearTimeout(this.temporizador);
        this.cartel = mensaje;
        this.estado = 'error';
    },

    // Errores de validación (422) agrupados por sección.
    mostrarErrores(errores) {
        const seccionDe = (campo) => {
            if (campo.startsWith('anamnesis')) return 'anamnesis';
            if (campo.startsWith('examen')) return 'examen';
            if (campo.startsWith('indicaciones')) return 'indicaciones';
            return 'motivo';
        };
        const porSeccion = {};
        Object.entries(errores).forEach(([campo, mensajes]) => {
            const seccion = seccionDe(campo);
            porSeccion[seccion] = [...new Set([...(porSeccion[seccion] ?? []), ...mensajes])];
        });
        this.errores = porSeccion;
        this.panelesConError = Object.keys(porSeccion);
    },

    // Envío normal del formulario (Finalizar, "Marcar como lista", "Reabrir", "Guardar cambios"). Con
    // autoguardado, primero se guarda lo pendiente y se espera al pedido en vuelo (para mandar la versión
    // nueva); si el guardado falla, no se envía y queda el aviso.
    enviar(evento) {
        if (this.enviandoFormulario) {
            evento.preventDefault(); // doble clic
            return;
        }
        if (autoguardado && (this.enVuelo || this.sucio)) {
            evento.preventDefault();
            if (this.cartel) {
                return;
            }
            const boton = evento.submitter;
            if (!this.enVuelo) {
                this.guardarAhora();
            }
            const esperar = setInterval(() => {
                if (this.enVuelo) {
                    return;
                }
                clearInterval(esperar);
                if (this.cartel || this.estado === 'error' || this.sucio) {
                    return; // no se pudo guardar: el usuario ve el estado y decide
                }
                this.$refs.formulario.requestSubmit(boton); // vuelve a pasar por acá, ya sin nada pendiente
            }, 200);
            return;
        }
        clearTimeout(this.temporizador);
        this.enviandoFormulario = true;
    },
});
