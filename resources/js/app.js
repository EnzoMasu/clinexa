import './bootstrap';

import Alpine from 'alpinejs';
import altaTurno from './alta-turno';
import buscadorCie10 from './buscador-cie10';
import campoFecha from './campo-fecha';
import listadoEnVivo from './listado-en-vivo';
import popupConsultas from './popup-consultas';
import selectorCiudad from './selector-ciudad';
import selectorPersona from './selector-persona';
import verificarUnico from './verificar-unico';

window.Alpine = Alpine;

Alpine.data('altaTurno', altaTurno);
Alpine.data('buscadorCie10', buscadorCie10);
Alpine.data('campoFecha', campoFecha);
Alpine.data('listadoEnVivo', listadoEnVivo);
Alpine.data('popupConsultas', popupConsultas);
Alpine.data('selectorCiudad', selectorCiudad);
Alpine.data('selectorPersona', selectorPersona);
Alpine.data('verificarUnico', verificarUnico);

Alpine.start();
