/**
 * Lógica de la página home: modal de pagos, historial, cuotas, notificaciones.
 */
import { initDashboardLayout } from '../core/dashboardLayout.js';
import { initNotificationsPanel } from '../core/notificationsPanel.js';
import { initPaymentModal } from '../ui/paymentModal.js';
import { setButtonLoading } from '../ui/submitLoading.js';
import { AJAX_HISTORIAL } from '../config/apiEndpoints.js';
import { initContratoEnlacesHome } from './homeContracts.js';

export function initHomePage(data) {
  const alumnos            = data.alumnos || [];
  const saldoTotalFamiliar = Number(data.saldoTotalFamiliar) || 0;
  const nroFamilia         = data.nroFamilia ?? '';
  const autolegajo         = data.autolegajo ?? '';
  const csrfToken          = data.csrfToken ?? '';

  initDashboardLayout();
  initNotificationsPanel(undefined, csrfToken);
  initPaymentModal({ alumnos, saldoTotalFamiliar, nroFamilia, csrfToken });
  void initContratoEnlacesHome({ csrfToken });

  const contenedorHistorial = document.getElementById('historial-container');
  const badgeLegajo         = document.getElementById('legajo-actual');

  function setHistorialStatus(texto) {
    if (!contenedorHistorial) return;
    contenedorHistorial.textContent = '';
    const p = document.createElement('p');
    p.textContent = texto;
    contenedorHistorial.appendChild(p);
  }

  function sanitizeServerHtml(html) {
    const parser = new DOMParser();
    const parsed = parser.parseFromString(String(html ?? ''), 'text/html');

    parsed.body.querySelectorAll('script, iframe, object, embed, link, style, meta').forEach((el) => {
      el.remove();
    });

    parsed.body.querySelectorAll('*').forEach((el) => {
      Array.from(el.attributes).forEach((attr) => {
        const name = attr.name.toLowerCase();
        const value = String(attr.value || '').trim().toLowerCase();
        const isEventHandler = name.startsWith('on');
        const isJavascriptUrl = (name === 'href' || name === 'src') && value.startsWith('javascript:');
        if (isEventHandler || isJavascriptUrl) {
          el.removeAttribute(attr.name);
        }
      });
    });

    const fragment = document.createDocumentFragment();
    while (parsed.body.firstChild) {
      fragment.appendChild(parsed.body.firstChild);
    }
    return fragment;
  }

  function setActivo(btn) {
    document.querySelectorAll('.btn-alumno').forEach((b) => {
      if (b !== btn) setButtonLoading(b, false);
      b.classList.remove('active');
    });
    if (btn) btn.classList.add('active');
  }

  async function cargarHistorial(legajo, nombre, btn) {
    if (!contenedorHistorial) return;
    document.querySelectorAll('.btn-alumno').forEach((b) => setButtonLoading(b, false));
    if (btn) setButtonLoading(btn, true);
    setHistorialStatus('Cargando historial...');
    setActivo(btn);
    window.legajoSeleccionado = legajo;
    window.nombreSeleccionado = nombre;
    if (badgeLegajo) badgeLegajo.textContent = legajo || '—';
    try {
      const form = new FormData();
      form.append('legajo', legajo);
      if (csrfToken) form.append('csrf_token', csrfToken);
      const resp = await fetch(AJAX_HISTORIAL, { method: 'POST', body: form });
      if (!resp.ok) throw new Error('Error al obtener historial: ' + resp.status);
      const html = await resp.text();
      contenedorHistorial.classList.add('fade-enter');
      contenedorHistorial.replaceChildren(sanitizeServerHtml(html));
      requestAnimationFrame(() => {
        contenedorHistorial.classList.add('fade-enter-active');
        setTimeout(() => contenedorHistorial.classList.remove('fade-enter', 'fade-enter-active'), 250);
      });
    } catch (_e) {
      setHistorialStatus('Error al cargar el historial. Revisá la consola.');
    } finally {
      if (btn) setButtonLoading(btn, false);
    }
  }

  document.querySelectorAll('.btn-alumno').forEach(btn => {
    btn.addEventListener('click', () => {
      cargarHistorial(
        btn.getAttribute('data-legajo'),
        btn.getAttribute('data-nombre'),
        btn
      );
    });
  });

  if (autolegajo) {
    const btn = Array.from(document.querySelectorAll('.btn-alumno'))
      .find(b => b.getAttribute('data-legajo') === autolegajo);
    cargarHistorial(autolegajo, btn ? btn.getAttribute('data-nombre') : '', btn || null);
  }
}
