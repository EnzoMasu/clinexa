import './bootstrap';

import Alpine from 'alpinejs';
import listadoEnVivo from './listado-en-vivo';
import selectorCiudad from './selector-ciudad';
import selectorPersona from './selector-persona';

window.Alpine = Alpine;

Alpine.data('listadoEnVivo', listadoEnVivo);
Alpine.data('selectorCiudad', selectorCiudad);
Alpine.data('selectorPersona', selectorPersona);

Alpine.start();
