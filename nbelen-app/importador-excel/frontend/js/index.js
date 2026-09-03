/**
 * Punto de entrada del frontend.
 *
 * 1. Inicializa el layout global del dashboard (sidebar, scroll, help modal, logout).
 * 2. Detecta la página actual mediante el atributo data-page del <body>.
 * 3. Carga dinámicamente el módulo de página correspondiente.
 *
 * Cargar como:
 *   <script type="module" src="./frontend/js/index.js"></script>
 * después de jQuery y SweetAlert2.
 */
import { initDashboardLayout } from './core/dashboardLayout.js';
import { initNotificationsPanel } from './core/notificationsPanel.js';

const PAGE_MODULES = {
  home:              () => import('./pages/homePage.js'),
  'info-importante': () => import('./pages/infoImportantePage.js'),
  'agregar-email':   () => import('./pages/agregarEmailPage.js'),
  'talon-de-pago':   () => import('./pages/talonDePagoPage.js'),
  contratos:         () => import('./pages/contratosPage.js'),
  'libro-sugerencias': () => import('./pages/libroSugerenciasPage.js'),
  'informar-error':  () => import('./pages/informarErrorPage.js'),
};

const PAGE_INIT_FNS = {
  home:              'initHomePage',
  'info-importante': 'initInfoImportantePage',
  'agregar-email':   'initAgregarEmailPage',
  'talon-de-pago':   'initTalonDePagoPage',
  contratos:         'initContratosPage',
  'libro-sugerencias': 'initLibroSugerenciasPage',
  'informar-error':  'initInformarErrorPage',
};

function init() {
  const page = document.body.dataset.page;

  if (!page) {
    initDashboardLayout();
    initNotificationsPanel();
    return;
  }

  const loader = PAGE_MODULES[page];
  if (!loader) {
    initDashboardLayout();
    initNotificationsPanel();
    return;
  }

  const pageDataEl = document.getElementById('page-data');
  let pageData = {};
  if (pageDataEl) {
    try { pageData = JSON.parse(pageDataEl.textContent); } catch (_) { /* ignore */ }
  }

  loader().then(mod => {
    const fnName = PAGE_INIT_FNS[page];
    if (mod[fnName]) {
      mod[fnName](pageData);
    }
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init);
} else {
  init();
}
