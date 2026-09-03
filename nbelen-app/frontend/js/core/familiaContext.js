import {
  AJAX_FAMILIA_CONTEXT,
  AJAX_TALON_CUOTAS,
} from '../config/apiEndpoints.js';

const PAGES_WITH_FAMILIA_CONTEXT = new Set([
  'home',
  'info-importante',
  'agregar-email',
  'talon-de-pago',
  'contratos',
  'libro-sugerencias',
  'informar-error',
]);

/**
 * Carga datos financieros vía POST (no embebidos en #page-data).
 * @param {string} page
 * @param {string} csrfToken
 */
export async function loadFamiliaPageContext(page, csrfToken) {
  if (!PAGES_WITH_FAMILIA_CONTEXT.has(page) || !csrfToken) {
    return {};
  }

  const form = new FormData();
  form.append('csrf_token', csrfToken);

  const resp = await fetch(AJAX_FAMILIA_CONTEXT, { method: 'POST', body: form });
  if (!resp.ok) {
    throw new Error('Error al cargar contexto familiar');
  }
  const data = await resp.json();
  if (!data.ok) {
    throw new Error(data.msg || 'Error al cargar contexto familiar');
  }

  const ctx = {
    nroFamilia: data.nroFamilia,
    saldoTotalFamiliar: data.saldoTotalFamiliar,
    alumnos: data.alumnos || [],
    autolegajo: data.autolegajo ?? '',
  };

  if (page === 'talon-de-pago') {
    const talonResp = await fetch(AJAX_TALON_CUOTAS, { method: 'POST', body: form });
    if (talonResp.ok) {
      const talonData = await talonResp.json();
      if (talonData.ok) {
        ctx.cuotasPorAlumno = talonData.cuotasPorAlumno || {};
      }
    }
  }

  return ctx;
}
