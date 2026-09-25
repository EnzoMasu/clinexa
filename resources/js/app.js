import './bootstrap';

import Alpine from 'alpinejs';
import listadoEnVivo from './listado-en-vivo';

window.Alpine = Alpine;

Alpine.data('listadoEnVivo', listadoEnVivo);

Alpine.start();
