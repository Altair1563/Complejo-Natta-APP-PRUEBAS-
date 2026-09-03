/**
 * Talón de pago: solicitud/cancelación de talones.
 */
import { initDashboardLayout } from '../core/dashboardLayout.js';
import { initNotificationsPanel } from '../core/notificationsPanel.js';
import { initPaymentModal } from '../ui/paymentModal.js';
import {
  AJAX_SOLICITAR_TALON,
  AJAX_CANCELAR_SOLICITUDES
} from '../config/apiEndpoints.js';

export function initTalonDePagoPage(data) {
  let cuotasPorAlumno = data.cuotasPorAlumno || {};
  const alumnos = data.alumnos || [];
  const saldoTotalFamiliar = Number(data.saldoTotalFamiliar) || 0;
  const nroFamilia = data.nroFamilia ?? '';
  const csrfToken = data.csrfToken ?? '';
  for (const legajo in cuotasPorAlumno) {
    cuotasPorAlumno[legajo].sort((a, b) => Number(a.numero_cuota) - Number(b.numero_cuota));
  }

  initDashboardLayout();
  initNotificationsPanel(undefined, csrfToken);
  initPaymentModal({ alumnos, saldoTotalFamiliar, nroFamilia, csrfToken });

  function attachCancelarSolicitudHandler(button, legajo) {
    button.addEventListener('click', (ev) => {
      ev.preventDefault();
      window.cancelarSolicitudesAlumno(legajo);
    });
  }

  function buildSolicitudBadge(legajo, fechaStr) {
    const badge = document.createElement('div');
    badge.className = 'solicitud-badge';
    badge.title = 'Ultima solicitud: pendiente';

    const texto = document.createElement('span');
    texto.textContent = `📅 Solicitado: ${fechaStr} (pendiente) `;
    badge.appendChild(texto);

    const btnCancelar = document.createElement('button');
    btnCancelar.type = 'button';
    btnCancelar.className = 'btn-cancelar-solicitud';
    btnCancelar.setAttribute('data-legajo-cancel', legajo);
    btnCancelar.title = 'Cancelar solicitud';
    btnCancelar.textContent = '❌';
    attachCancelarSolicitudHandler(btnCancelar, legajo);
    badge.appendChild(btnCancelar);

    return badge;
  }

  // --- Selección de cuota ---
  window.seleccionarCuota = function (elemento) {
    if (elemento.classList.contains('cuota-deshabilitada')) {
      mostrarMensajeAlumno(elemento.dataset.legajo, 'info', 'Esta cuota ya tiene una solicitud pendiente o el alumno ya tiene una solicitud activa.');
      return;
    }
    const legajo = elemento.dataset.legajo;
    const alumnoCard = document.querySelector(`.alumno-card[data-legajo="${legajo}"]`);
    if (alumnoCard.dataset.tieneSolicitudActiva === '1') {
      mostrarMensajeAlumno(legajo, 'info', 'Ya existe una solicitud activa para este alumno. Cancélala para poder solicitar nuevamente.');
      return;
    }
    document.querySelectorAll(`.cuota-item[data-legajo="${legajo}"]`).forEach(el => el.classList.remove('cuota-seleccionada'));
    elemento.classList.add('cuota-seleccionada');
    const cuotaId = elemento.dataset.cuotaId;
    const pagada = elemento.dataset.pagada === '1';
    if (pagada) {
      mostrarMensajeAlumno(legajo, 'info', 'La cuota seleccionada ya está pagada. No es necesario solicitar talón.');
      return;
    }
    const cuotas = cuotasPorAlumno[legajo] || [];
    const idxSel = cuotas.findIndex(c => c.id == cuotaId);
    if (idxSel === -1) return;
    const cuotasASolicitar = [];
    for (let i = 0; i <= idxSel; i++) {
      if (!cuotas[i].pagada && !cuotas[i].solicitud_activa) cuotasASolicitar.push(cuotas[i]);
    }
    if (cuotasASolicitar.length === 0) {
      mostrarMensajeAlumno(legajo, 'info', 'No hay cuotas impagas que puedan solicitarse.');
      return;
    }
    const descripciones = cuotasASolicitar.map(c => c.descripcion).join(', ');
    if (confirm(`Se solicitarán los talones para: ${descripciones}. ¿Desea continuar?`)) {
      enviarSolicitud(legajo, cuotasASolicitar);
    }
  };

  // --- Solicitar todas impagas ---
  window.solicitarTodasImpagas = function (legajo) {
    const alumnoCard = document.querySelector(`.alumno-card[data-legajo="${legajo}"]`);
    if (alumnoCard.dataset.tieneSolicitudActiva === '1') {
      mostrarMensajeAlumno(legajo, 'info', 'Ya existe una solicitud activa para este alumno. Cancélala para poder solicitar nuevamente.');
      return;
    }
    const cuotas = cuotasPorAlumno[legajo] || [];
    const cuotasSolicitables = cuotas.filter(c => !c.pagada && !c.solicitud_activa);
    if (cuotasSolicitables.length === 0) {
      mostrarMensajeAlumno(legajo, 'info', 'No hay cuotas impagas que puedan solicitarse en este momento.');
      return;
    }
    const descripciones = cuotasSolicitables.map(c => c.descripcion).join(', ');
    if (confirm(`Se solicitarán los talones para: ${descripciones}. ¿Desea continuar?`)) {
      enviarSolicitud(legajo, cuotasSolicitables);
    }
  };

  // --- Enviar solicitud ---
  async function enviarSolicitud(legajo, cuotas) {
    try {
      const form = new FormData();
      form.append('legajo', legajo);
      form.append('cuotas', JSON.stringify(cuotas.map(c => c.id)));
      form.append('csrf_token', csrfToken);
      const resp = await fetch(AJAX_SOLICITAR_TALON, { method: 'POST', body: form });
      const result = await resp.json();
      if (result.success) {
        mostrarMensajeAlumno(legajo, 'success', 'Solicitud enviada correctamente. Los talones estarán disponibles en 48 hs hábiles.');
        const cuotasIds = cuotas.map(c => c.id);
        cuotasIds.forEach(id => {
          document.querySelectorAll(`.alumno-card[data-legajo="${legajo}"] .cuota-item[data-cuota-id="${id}"]`).forEach(el => {
            el.classList.add('cuota-deshabilitada');
            el.setAttribute('data-solicitud-activa', '1');
            el.setAttribute('title', 'Solicitud pendiente (pendiente) recién enviada');
          });
        });
        cuotas.forEach(c => {
          const index = (cuotasPorAlumno[legajo] || []).findIndex(cu => String(cu.id) === String(c.id));
          if (index !== -1) {
            cuotasPorAlumno[legajo][index].solicitud_activa = true;
            cuotasPorAlumno[legajo][index].solicitud_estado = 'pendiente';
            cuotasPorAlumno[legajo][index].solicitud_fecha = new Date().toLocaleDateString('es-AR', { day: '2-digit', month: '2-digit', year: 'numeric' });
          }
        });
        const alumnoCard = document.querySelector(`.alumno-card[data-legajo="${legajo}"]`);
        alumnoCard.dataset.tieneSolicitudActiva = '1';
        document.querySelectorAll(`.alumno-card[data-legajo="${legajo}"] .cuota-item`).forEach(el => el.classList.add('cuota-deshabilitada'));
        const botonTodos = alumnoCard.querySelector('.btn-todos');
        if (botonTodos) botonTodos.style.display = 'none';
        const headerDiv = alumnoCard.querySelector('.alumno-header div:last-child');
        if (headerDiv && !headerDiv.querySelector('.solicitud-badge')) {
          const fechaStr = new Date().toLocaleDateString('es-AR', { day: '2-digit', month: '2-digit', year: 'numeric' });
          const nuevoBadge = buildSolicitudBadge(legajo, fechaStr);
          headerDiv.appendChild(nuevoBadge);
        } else if (headerDiv) {
          const badge = headerDiv.querySelector('.solicitud-badge');
          if (badge && !badge.querySelector('.btn-cancelar-solicitud')) {
            const fechaStr = new Date().toLocaleDateString('es-AR', { day: '2-digit', month: '2-digit', year: 'numeric' });
            badge.textContent = '';
            const texto = document.createElement('span');
            texto.textContent = `📅 Solicitado: ${fechaStr} (pendiente) `;
            badge.appendChild(texto);
            const btnCancelar = document.createElement('button');
            btnCancelar.type = 'button';
            btnCancelar.className = 'btn-cancelar-solicitud';
            btnCancelar.setAttribute('data-legajo-cancel', legajo);
            btnCancelar.title = 'Cancelar solicitud';
            btnCancelar.textContent = '❌';
            attachCancelarSolicitudHandler(btnCancelar, legajo);
            badge.appendChild(btnCancelar);
          }
        }
      } else {
        mostrarMensajeAlumno(legajo, 'error', result.message || 'Error al enviar la solicitud.');
      }
    } catch (_err) {
      mostrarMensajeAlumno(legajo, 'error', 'Error de conexión al servidor.');
    }
  }

  // --- Cancelar solicitudes ---
  window.cancelarSolicitudesAlumno = async function (legajo) {
    if (!confirm('¿Está seguro de cancelar todas las solicitudes activas de este alumno? Esta acción no se puede deshacer.')) return;
    try {
      const form = new FormData();
      form.append('legajo', legajo);
      form.append('csrf_token', csrfToken);
      const resp = await fetch(AJAX_CANCELAR_SOLICITUDES, { method: 'POST', body: form });
      const result = await resp.json();
      if (result.success) {
        mostrarMensajeAlumno(legajo, 'success', 'Solicitudes canceladas correctamente.');
        const alumnoCard = document.querySelector(`.alumno-card[data-legajo="${legajo}"]`);
        alumnoCard.dataset.tieneSolicitudActiva = '0';
        document.querySelectorAll(`.alumno-card[data-legajo="${legajo}"] .cuota-item`).forEach(el => {
          if (el.dataset.pagada !== '1') {
            el.classList.remove('cuota-deshabilitada');
            el.removeAttribute('data-solicitud-activa');
            el.removeAttribute('title');
          }
        });
        if (cuotasPorAlumno[legajo]) {
          cuotasPorAlumno[legajo].forEach(c => {
            c.solicitud_activa = false;
            c.solicitud_estado = null;
            c.solicitud_fecha = null;
          });
        }
        const headerDiv = alumnoCard.querySelector('.alumno-header div:last-child');
        const badge = headerDiv?.querySelector('.solicitud-badge');
        if (badge) badge.remove();
        const botonTodos = alumnoCard.querySelector('.btn-todos');
        if (botonTodos) botonTodos.style.display = 'inline-block';
      } else {
        mostrarMensajeAlumno(legajo, 'error', result.message || 'Error al cancelar las solicitudes.');
      }
    } catch (_err) {
      mostrarMensajeAlumno(legajo, 'error', 'Error de conexión al servidor.');
    }
  };

  // --- Mensajes de feedback ---
  function mostrarMensajeAlumno(legajo, tipo, texto) {
    const container = document.getElementById('mensaje-' + legajo);
    if (!container) return;
    const clase = tipo === 'success' ? 'mensaje-exito' : (tipo === 'error' ? 'mensaje-error' : 'mensaje-info');
    container.className = 'mensaje-alumno ' + clase;
    container.textContent = texto;
    container.style.display = 'inline-block';
    setTimeout(() => { container.style.display = 'none'; }, 5000);
  }

  // --- Hover cuotas ---
  document.querySelectorAll('.cuota:not(.cuota-deshabilitada)').forEach(cuota => {
    cuota.addEventListener('mouseenter', function () {
      const legajo = this.dataset.legajo;
      const index = Number(this.dataset.index);
      document.querySelectorAll(`.cuota[data-legajo="${legajo}"]:not(.cuota-deshabilitada)`).forEach(c => {
        const i = Number(c.dataset.index);
        if (i < index) c.classList.add('hover-previa');
        if (i == index) c.classList.add('hover-actual');
      });
    });
    cuota.addEventListener('mouseleave', function () {
      const legajo = this.dataset.legajo;
      document.querySelectorAll(`.cuota[data-legajo="${legajo}"]`).forEach(c => {
        c.classList.remove('hover-previa', 'hover-actual');
      });
    });
  });
}
