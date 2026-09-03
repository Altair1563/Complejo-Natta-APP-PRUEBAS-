/**
 * Panel de notificaciones reutilizable: toggle, carga AJAX, badge y marcado
 * como leídas.
 *
 * Uso:
 *   import { initNotificationsPanel } from '../core/notificationsPanel.js';
 *   initNotificationsPanel(undefined, csrfToken);          // POST con CSRF
 */
import {
  AJAX_NOTIFICACIONES,
  AJAX_NOTIFICACIONES_LEER
} from '../config/apiEndpoints.js';

function resolveCsrfToken(explicit) {
  if (explicit) return explicit;
  const el = document.getElementById('page-data');
  if (!el) return '';
  try {
    return JSON.parse(el.textContent).csrfToken || '';
  } catch (_) {
    return '';
  }
}

export function initNotificationsPanel(_nroFamilia, csrfToken = '') {
  const token = resolveCsrfToken(csrfToken);
  const btn     = document.querySelector('.btn-Notifications-area');
  const panel   = document.getElementById('notificacionesPanel');
  const content = document.getElementById('notificacionesContent');
  const badge   = document.getElementById('notificationBadge');
  if (!btn || !panel) return;

  function renderNotificationItem(notif) {
    const fecha = notif.fecha || 'Sin fecha';
    const esNueva = notif.leido == 0;
    const mensaje = notif.mensaje || 'Sin contenido';
    const esComunicado = /comunicado|📢|Nuevo comunicado/i.test(mensaje);

    const item = document.createElement('a');
    item.href = 'info-importante.php';
    item.className = 'notif-item ' + (esNueva ? 'notif-item-new ' : '') + (esComunicado ? 'notif-comunicado' : '');
    item.style.display = 'block';
    item.style.textDecoration = 'none';
    item.style.color = 'inherit';
    item.style.position = 'relative';
    item.style.paddingRight = '20px';

    const messageDiv = document.createElement('div');
    messageDiv.style.fontSize = '13px';
    messageDiv.style.marginBottom = '6px';
    messageDiv.textContent = mensaje;

    const fechaSmall = document.createElement('small');
    fechaSmall.style.color = '#666';
    fechaSmall.textContent = fecha;

    item.appendChild(messageDiv);
    item.appendChild(fechaSmall);

    if (esComunicado) {
      const indicador = document.createElement('span');
      indicador.style.position = 'absolute';
      indicador.style.right = '10px';
      indicador.style.top = '10px';
      indicador.style.fontSize = '12px';
      indicador.style.color = '#0c3484';
      indicador.textContent = '🔗';
      item.appendChild(indicador);
    }

    return { item, esNueva };
  }

  function renderPanelStatus(text) {
    if (!content) return;
    content.textContent = '';
    const empty = document.createElement('div');
    empty.className = 'notif-empty';
    empty.textContent = text;
    content.appendChild(empty);
  }

  async function cargarNotificaciones() {
    if (!content) return;
    renderPanelStatus('Cargando notificaciones...');
    try {
      if (!token) throw new Error('Token CSRF no disponible');
      const form = new FormData();
      form.append('csrf_token', token);
      const response = await fetch(AJAX_NOTIFICACIONES, { method: 'POST', body: form });
      if (!response.ok) throw new Error('Error HTTP: ' + response.status);
      const notificaciones = await response.json();
      if (!Array.isArray(notificaciones)) throw new Error('Respuesta inválida');
      if (notificaciones.length === 0) {
        renderPanelStatus('No hay notificaciones.');
        return;
      }

      content.textContent = '';
      let nuevas = 0;
      const fragment = document.createDocumentFragment();
      notificaciones.forEach(notif => {
        const { item, esNueva } = renderNotificationItem(notif);
        if (esNueva) nuevas++;
        fragment.appendChild(item);
      });
      content.appendChild(fragment);

      if (badge) {
        if (nuevas > 0) {
          badge.textContent = nuevas;
          badge.style.display = 'inline';
        } else {
          badge.style.display = 'none';
        }
      }
      if (nuevas > 0) {
        try {
          const form = new FormData();
          form.append('csrf_token', token);
          await fetch(AJAX_NOTIFICACIONES_LEER, { method: 'POST', body: form });
        } catch (e) { /* ignore */ }
      }
    } catch (_error) {
      renderPanelStatus('Error al cargar notificaciones.');
    }
  }

  window.cargarNotificaciones = cargarNotificaciones;

  function toggle() {
    if (panel.style.display === 'block') {
      panel.style.display = 'none';
    } else {
      panel.style.display = 'block';
      cargarNotificaciones();
    }
  }

  btn.addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();
    toggle();
  });

  document.addEventListener('click', (e) => {
    const inside = panel.contains(e.target) || btn.contains(e.target);
    if (!inside && panel.style.display === 'block') {
      panel.style.display = 'none';
    }
  });
}
