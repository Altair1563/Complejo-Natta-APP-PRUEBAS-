/**
 * Panel administrativo: toasts, AJAX (estado/eliminar/modales) y auto-refresh por pestaña.
 */
import { initAdminQrApp, initAdminQrGenerator } from './adminQrApp.js';

const ALLOWED_TOAST_TYPES = new Set(['success', 'danger', 'warning', 'info', 'primary', 'secondary', 'dark']);
const REFRESHABLE_TABS = new Set(['comunicados', 'informes', 'emails', 'talones', 'sugerencias']);
const POLL_INTERVAL_MS = 15000;

export function initAdminDashboardPage(pageData = {}) {
  const csrfToken = pageData.csrfToken || '';
  const ajaxUrl = pageData.ajaxUrl || 'admin_ajax.php';
  let currentTab = REFRESHABLE_TABS.has(pageData.initialActiveTab) ? pageData.initialActiveTab : null;
  let snapshot = null;
  let polling = false;

  if (pageData.msgFromUrl) {
    showToast(pageData.msgFromUrl, pageData.msgTypeFromUrl || 'success');
  }

  bindBatchActionToggles();
  bindSelectAllCheckboxes();
  bindEstadoSelects(csrfToken, ajaxUrl);
  bindEliminarButtons(csrfToken, ajaxUrl);
  bindMarcarLeidoButtons(csrfToken, ajaxUrl);
  bindModalLoaders(ajaxUrl);
  bindTabRefreshTracking();
  startHeartbeatPolling();
  initAdminHorizontalScroll();

  if (pageData.appPublicUrl) {
    initAdminQrApp(pageData.appPublicUrl, showToast);
  }
  initAdminQrGenerator(showToast);

  function bindBatchActionToggles() {
    const pairs = [
      ['batchActionInformes', 'estadoSelectorInformes'],
      ['batchActionEmails', 'estadoSelectorEmails'],
      ['batchActionTalones', 'estadoSelectorTalones'],
    ];
    pairs.forEach(([actionId, selectorId]) => {
      document.getElementById(actionId)?.addEventListener('change', function () {
        const el = document.getElementById(selectorId);
        if (el) el.style.display = this.value === 'estado' ? 'block' : 'none';
      });
    });
  }

  function bindSelectAllCheckboxes() {
    const pairs = [
      ['selectAllInformes', '.rowCheckboxInformes'],
      ['selectAllEmails', '.rowCheckboxEmails'],
      ['selectAllTalones', '.rowCheckboxTalones'],
      ['selectAllSugerencias', '.rowCheckboxSugerencias'],
    ];
    pairs.forEach(([masterId, rowSelector]) => {
      document.getElementById(masterId)?.addEventListener('change', function () {
        document.querySelectorAll(rowSelector).forEach(cb => { cb.checked = this.checked; });
      });
    });
  }

  function showToast(message, type = 'success') {
    const safeType = ALLOWED_TOAST_TYPES.has(type) ? type : 'info';
    const toastContainer = document.getElementById('toastContainer');
    if (!toastContainer) return;

    const toast = document.createElement('div');
    toast.className = `toast align-items-center text-white bg-${safeType} border-0`;
    toast.setAttribute('role', 'alert');
    toast.setAttribute('aria-live', 'assertive');
    toast.setAttribute('aria-atomic', 'true');

    const wrapper = document.createElement('div');
    wrapper.className = 'd-flex';

    const body = document.createElement('div');
    body.className = 'toast-body';
    body.textContent = message || '';

    const closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.className = 'btn-close btn-close-white me-2 m-auto';
    closeBtn.setAttribute('data-bs-dismiss', 'toast');

    wrapper.appendChild(body);
    wrapper.appendChild(closeBtn);
    toast.appendChild(wrapper);
    toastContainer.appendChild(toast);
    new bootstrap.Toast(toast).show();
    setTimeout(() => toast.remove(), 5000);
  }

  function clearNode(node) {
    if (node) node.textContent = '';
  }

  function appendLabeledText(container, label, value, preserveBreaks = false) {
    if (!container) return;
    const p = document.createElement('p');
    const strong = document.createElement('strong');
    strong.textContent = `${label}: `;
    p.appendChild(strong);
    if (preserveBreaks) {
      const span = document.createElement('span');
      span.style.whiteSpace = 'pre-line';
      span.textContent = value || '';
      p.appendChild(span);
    } else {
      p.appendChild(document.createTextNode(value || ''));
    }
    container.appendChild(p);
  }

  function postAjax(body) {
    return fetch(ajaxUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body,
    }).then(res => res.json());
  }

  function bindEstadoSelects(token, url) {
    document.querySelectorAll('.estado-select').forEach(select => {
      select.addEventListener('change', function () {
        const id = this.dataset.id;
        const tipo = this.dataset.tipo;
        const estado = this.value;
        postAjax(`accion=cambiar_estado&tipo=${tipo}&id=${id}&estado=${encodeURIComponent(estado)}&csrf_token=${encodeURIComponent(token)}`)
          .then(data => {
            if (data.ok) {
              showToast('Estado actualizado', 'success');
            } else {
              showToast('Error al actualizar estado', 'danger');
            }
          })
          .catch(() => showToast('Error de conexión', 'danger'));
      });
    });
  }

  function bindEliminarButtons(token, url) {
    document.querySelectorAll('.btn-eliminar').forEach(btn => {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        if (!confirm('¿Eliminar este registro?')) return;
        const id = this.dataset.id;
        const tipo = this.dataset.tipo;
        postAjax(`accion=eliminar&tipo=${tipo}&id=${id}&csrf_token=${encodeURIComponent(token)}`)
          .then(data => {
            if (data.ok) {
              document.getElementById(`row-${tipo}-${id}`)?.remove();
              showToast('Registro eliminado', 'success');
            } else {
              showToast('Error al eliminar', 'danger');
            }
          })
          .catch(() => showToast('Error de conexión', 'danger'));
      });
    });
  }

  function bindMarcarLeidoButtons(token, url) {
    document.querySelectorAll('.btn-marcar-leido').forEach(btn => {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        const id = this.dataset.id;
        postAjax(`accion=cambiar_estado&tipo=sugerencia&id=${id}&estado=leido&csrf_token=${encodeURIComponent(token)}`)
          .then(data => {
            if (data.ok) {
              const row = document.getElementById(`row-sugerencia-${id}`);
              const badge = row?.querySelector('.badge');
              if (badge) {
                badge.className = 'badge bg-success';
                badge.innerText = 'Leído';
              }
              this.disabled = true;
              showToast('Sugerencia marcada como leída', 'success');
            } else {
              showToast('Error al marcar', 'danger');
            }
          });
      });
    });
  }

  function bindModalLoaders(url) {
    document.querySelectorAll('[data-bs-target="#modalVerComunicado"]').forEach(btn => {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        const id = this.dataset.id;
        fetch(`${url}?tipo=comunicado&id=${id}`)
          .then(res => res.json())
          .then(payload => {
            const modalBody = document.getElementById('modalBodyComunicado');
            clearNode(modalBody);
            if (!payload.ok || !payload.data) {
              appendLabeledText(modalBody, 'Aviso', payload.error || 'Comunicado no encontrado.');
              return;
            }
            const title = document.createElement('h5');
            title.textContent = payload.data.titulo || '';
            modalBody.appendChild(title);
            const fechaP = document.createElement('p');
            const fechaSmall = document.createElement('small');
            fechaSmall.textContent = payload.data.fecha || '';
            fechaP.appendChild(fechaSmall);
            modalBody.appendChild(fechaP);
            const contenidoP = document.createElement('p');
            contenidoP.style.whiteSpace = 'pre-line';
            contenidoP.textContent = payload.data.contenido || '';
            modalBody.appendChild(contenidoP);
            if (payload.data.tiene_pdf) {
              const pdfP = document.createElement('p');
              pdfP.className = 'text-muted mb-0';
              pdfP.textContent = 'Incluye PDF adjunto.';
              modalBody.appendChild(pdfP);
            }
          })
          .catch(() => {
            const modalBody = document.getElementById('modalBodyComunicado');
            clearNode(modalBody);
            appendLabeledText(modalBody, 'Error', 'No se pudo cargar el comunicado.');
          });
      });
    });

    document.querySelectorAll('[data-bs-target="#modalVerInforme"]').forEach(btn => {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        const id = this.dataset.id;
        fetch(`${url}?tipo=informe&id=${id}`)
          .then(res => res.json())
          .then(payload => {
            const modalBody = document.getElementById('modalBodyInforme');
            clearNode(modalBody);
            if (!payload.ok || !payload.data) {
              appendLabeledText(modalBody, 'Aviso', payload.error || 'Informe no encontrado.');
              return;
            }
            appendLabeledText(modalBody, 'Familia', payload.data.nro_familia || '');
            appendLabeledText(modalBody, 'Fecha', payload.data.fecha_creacion || '');
            appendLabeledText(modalBody, 'Estado', payload.data.estado || '');
            appendLabeledText(modalBody, 'Descripcion', payload.data.error_descripcion || '', true);
          })
          .catch(() => {
            const modalBody = document.getElementById('modalBodyInforme');
            clearNode(modalBody);
            appendLabeledText(modalBody, 'Error', 'No se pudo cargar el informe.');
          });
      });
    });
  }

  function bindTabRefreshTracking() {
    document.querySelectorAll('button[data-bs-toggle="tab"]').forEach(btn => {
      btn.addEventListener('shown.bs.tab', (event) => {
        const target = event.target?.getAttribute('data-bs-target') || '';
        const tabId = target.startsWith('#') ? target.slice(1) : '';
        currentTab = REFRESHABLE_TABS.has(tabId) ? tabId : null;
        snapshot = null;
      });
    });
  }

  function shouldSkipPolling() {
    if (document.hidden) return true;
    if (!currentTab) return true;
    return !!document.querySelector('.modal.show');
  }

  async function checkForUpdates() {
    if (polling || shouldSkipPolling()) return;
    polling = true;
    try {
      const res = await fetch(`${ajaxUrl}?tipo=heartbeat&tab=${encodeURIComponent(currentTab)}`, { cache: 'no-store' });
      if (!res.ok) return;
      const data = await res.json();
      if (!data?.ok) return;
      const nextSnapshot = `${data.total}|${data.ts}`;
      if (snapshot !== null && snapshot !== nextSnapshot) {
        window.location.reload();
        return;
      }
      snapshot = nextSnapshot;
    } catch (_) {
      /* silencioso */
    } finally {
      polling = false;
    }
  }

  function startHeartbeatPolling() {
    checkForUpdates();
    setInterval(checkForUpdates, POLL_INTERVAL_MS);
  }
}

const ADMIN_TABLE_SCROLL_SELECTORS = [
  'body[data-page="admin-dashboard"] .card-body > table.table',
  'body[data-page="admin-dashboard"] .card-body > form > table.table',
  'body[data-page="admin-dashboard"] .listado-familias-admin .familia-card > table',
  'body[data-page="admin-dashboard"] .ig-card-body > table.ig-table',
  'body[data-page="admin-dashboard"] .ig-tab-pane-inner > table.ig-table',
];

function initAdminHorizontalScroll() {
  document.querySelectorAll('body[data-page="admin-dashboard"] .table-responsive').forEach((el) => {
    el.classList.add('admin-h-scroll');
    wrapScrollWithFade(el);
  });

  document.querySelectorAll(ADMIN_TABLE_SCROLL_SELECTORS.join(', ')).forEach((table) => {
    if (table.closest('.table-responsive, .admin-h-scroll')) return;
    const wrapper = document.createElement('div');
    wrapper.className = 'table-responsive admin-h-scroll';
    table.parentNode.insertBefore(wrapper, table);
    wrapper.appendChild(table);
    wrapScrollWithFade(wrapper);
  });

  document.querySelectorAll('.admin-nav-sub, .ig-tabs-scroll').forEach((el) => {
    el.classList.add('admin-h-scroll');
    updateScrollFadeState(el);
    el.addEventListener('scroll', () => updateScrollFadeState(el), { passive: true });
  });

  document.querySelectorAll('body[data-page="admin-dashboard"] .batch-bar').forEach((el) => {
    el.classList.add('admin-h-scroll');
    updateScrollFadeState(el);
    el.addEventListener('scroll', () => updateScrollFadeState(el), { passive: true });
  });

  if (typeof ResizeObserver !== 'undefined') {
    const ro = new ResizeObserver((entries) => {
      entries.forEach((entry) => {
        const el = entry.target;
        if (el.classList.contains('admin-h-scroll-wrap')) {
          const scrollEl = el.querySelector('.admin-h-scroll');
          if (scrollEl) updateScrollFadeState(scrollEl);
        } else if (el.classList.contains('admin-h-scroll')) {
          updateScrollFadeState(el);
        }
      });
    });
    document.querySelectorAll('.admin-h-scroll-wrap, .admin-h-scroll').forEach((el) => ro.observe(el));
  }
}

function wrapScrollWithFade(scrollEl) {
  if (scrollEl.parentElement?.classList.contains('admin-h-scroll-wrap')) return;
  const wrap = document.createElement('div');
  wrap.className = 'admin-h-scroll-wrap';
  scrollEl.parentNode.insertBefore(wrap, scrollEl);
  wrap.appendChild(scrollEl);
  updateScrollFadeState(scrollEl);
  scrollEl.addEventListener('scroll', () => updateScrollFadeState(scrollEl), { passive: true });
}

function updateScrollFadeState(scrollEl) {
  const wrap = scrollEl.closest('.admin-h-scroll-wrap') || scrollEl;
  const maxScroll = scrollEl.scrollWidth - scrollEl.clientWidth;
  const canScroll = maxScroll > 4;
  wrap.classList.toggle('is-scrollable', canScroll);
  wrap.classList.toggle('is-scrollable-start', canScroll && scrollEl.scrollLeft > 4);
  wrap.classList.toggle('is-scrollable-end', canScroll && scrollEl.scrollLeft < maxScroll - 4);
}
