import './bootstrap';

import Alpine from 'alpinejs';
import campoFecha from './campo-fecha';
import listadoEnVivo from './listado-en-vivo';
import selectorCiudad from './selector-ciudad';
import selectorPersona from './selector-persona';
import verificarUnico from './verificar-unico';

window.Alpine = Alpine;

Alpine.data('campoFecha', campoFecha);
Alpine.data('listadoEnVivo', listadoEnVivo);
Alpine.data('selectorCiudad', selectorCiudad);
Alpine.data('selectorPersona', selectorPersona);
Alpine.data('verificarUnico', verificarUnico);

Alpine.start();
