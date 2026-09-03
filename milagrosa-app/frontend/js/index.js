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
import { loadFamiliaPageContext } from './core/familiaContext.js';

const PAGE_MODULES = {
  home:              () => import('./pages/homePage.js'),
  'info-importante': () => import('./pages/infoImportantePage.js'),
  'agregar-email':   () => import('./pages/agregarEmailPage.js'),
  'talon-de-pago':   () => import('./pages/talonDePagoPage.js'),
  contratos:         () => import('./pages/contratosPage.js'),
  'libro-sugerencias': () => import('./pages/libroSugerenciasPage.js'),
  'informar-error':  () => import('./pages/informarErrorPage.js'),
  'admin-dashboard': () => import('./pages/adminDashboardPage.js'),
};

const PAGE_INIT_FNS = {
  home:              'initHomePage',
  'info-importante': 'initInfoImportantePage',
  'agregar-email':   'initAgregarEmailPage',
  'talon-de-pago':   'initTalonDePagoPage',
  contratos:         'initContratosPage',
  'libro-sugerencias': 'initLibroSugerenciasPage',
  'informar-error':  'initInformarErrorPage',
  'admin-dashboard': 'initAdminDashboardPage',
};

function readPageData() {
  const pageDataEl = document.getElementById('page-data');
  if (!pageDataEl) return {};
  try {
    return JSON.parse(pageDataEl.textContent);
  } catch (_) {
    return {};
  }
}

function init() {
  const page = document.body.dataset.page;
  const pageData = readPageData();
  const csrfToken = pageData.csrfToken ?? '';

  if (!page) {
    initDashboardLayout();
    initNotificationsPanel(undefined, csrfToken);
    return;
  }

  const loader = PAGE_MODULES[page];
  if (!loader) {
    initDashboardLayout();
    initNotificationsPanel(undefined, csrfToken);
    return;
  }

  loader()
    .then(async (mod) => {
      let merged = { ...pageData };
      try {
        const ctx = await loadFamiliaPageContext(page, csrfToken);
        merged = { ...merged, ...ctx };
      } catch (_err) {
        /* páginas sin contexto financiero siguen cargando */
      }
      const fnName = PAGE_INIT_FNS[page];
      if (mod[fnName]) {
        mod[fnName](merged);
      }
    });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init);
} else {
  init();
}
