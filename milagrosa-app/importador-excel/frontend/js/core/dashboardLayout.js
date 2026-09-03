/**
 * Inicialización común del layout dashboard: sidebar, submenús, help modal,
 * scroll enhancer y botones de cerrar sesión.
 *
 * Reemplaza el código duplicado que existía en cada módulo de página.
 */
import { initSidebar, initSubmenus } from './sidebar.js';
import { initScrollEnhancer } from './scrollEnhancer.js';
import { initHelpModal } from '../ui/modals.js';
import { confirmLogout } from '../ui/dialogs.js';
import { PHP_LOGOUT } from '../config/apiEndpoints.js';

export function initDashboardLayout() {
  initSidebar();
  /* initSubmenus: debe ir después de mCustomScrollbar (window load) o el plugin recrea el DOM y se pierden los listeners */
  initScrollEnhancer(() => {
    initSubmenus();
  });
  initHelpModal();

  document.querySelectorAll('.btn-exit-system').forEach(el => {
    el.addEventListener('click', (e) => {
      e.preventDefault();
      confirmLogout(PHP_LOGOUT);
    });
  });
}
