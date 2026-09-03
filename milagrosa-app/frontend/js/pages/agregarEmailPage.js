/**
 * Lógica de agregaremail.php: editar emails, solicitar cambio, cancelar solicitud.
 */
import { initDashboardLayout } from '../core/dashboardLayout.js';
import { initNotificationsPanel } from '../core/notificationsPanel.js';
import { initPaymentModal } from '../ui/paymentModal.js';
import { showError } from '../ui/dialogs.js';
import {
  AJAX_AGREGAR_EMAIL,
  AJAX_CANCELAR_SOLICITUD_EMAIL
} from '../config/apiEndpoints.js';

export function initAgregarEmailPage(data) {
  const nroFamilia = data.nroFamilia ?? '';
  const csrfToken  = data.csrfToken ?? '';
  const alumnos = data.alumnos || [];
  const saldoTotalFamiliar = Number(data.saldoTotalFamiliar) || 0;
  let posicionEditando = null;

  const overlay         = document.getElementById('overlay');
  const modalEmail      = document.getElementById('modalEmail');
  const modalEmailTitulo = document.getElementById('modalEmailTitulo');
  const emailInput      = document.getElementById('emailInput');
  const guardarBtn      = document.getElementById('guardarEmail');
  const cancelarBtn     = document.getElementById('cancelarEmail');

  initDashboardLayout();
  initNotificationsPanel(nroFamilia, csrfToken);
  initPaymentModal({ alumnos, saldoTotalFamiliar, nroFamilia, csrfToken });

  function buildSolicitudBadge(posicion, fechaTexto) {
    const badge = document.createElement('div');
    badge.className = 'solicitud-badge';

    const texto = document.createElement('span');
    texto.textContent = `📅 Cambio solicitado: ${fechaTexto} (pendiente) `;
    badge.appendChild(texto);

    const btnCancelar = document.createElement('button');
    btnCancelar.type = 'button';
    btnCancelar.className = 'btn-cancelar-solicitud';
    btnCancelar.setAttribute('data-posicion', posicion);
    btnCancelar.title = 'Cancelar solicitud';
    btnCancelar.textContent = '❌';
    badge.appendChild(btnCancelar);

    return badge;
  }

  // --- Editar mail ---
  document.querySelectorAll('.btn-mail').forEach(btn => {
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      editarMail(this.getAttribute('data-posicion'));
    });
  });

  function editarMail(posicion) {
    const emailRow = document.querySelector(`.email-row[data-posicion="${posicion}"]`);
    if (!emailRow) return;
    if (emailRow.querySelector('.solicitud-badge')) {
      alert('Ya hay una solicitud pendiente para este email. Debes cancelarla antes de realizar un nuevo cambio.');
      return;
    }
    posicionEditando = posicion;
    const emailSpan = emailRow.querySelector('span b');
    const emailActual = emailSpan && !emailSpan.classList.contains('vacio') ? emailSpan.textContent : '';
    if (modalEmailTitulo) modalEmailTitulo.textContent = emailActual ? `Modificar Email ${posicion}` : `Agregar Email ${posicion}`;
    if (emailInput) emailInput.value = emailActual;
    if (modalEmail) modalEmail.style.display = 'block';
    if (overlay) overlay.style.display = 'block';
  }

  // --- Guardar email ---
  if (guardarBtn) {
    guardarBtn.addEventListener('click', async function () {
      const nuevoEmail = emailInput ? emailInput.value.trim() : '';
      if (!nuevoEmail) { alert('Debe ingresar un correo electrónico.'); return; }
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(nuevoEmail)) { alert('El correo ingresado no es válido.'); return; }
      const form = new FormData();
      form.append('posicion', posicionEditando);
      form.append('email', nuevoEmail);
      form.append('csrf_token', csrfToken);
      try {
        const resp = await fetch(AJAX_AGREGAR_EMAIL, { method: 'POST', body: form });
        if (!resp.ok) throw new Error('Error HTTP: ' + resp.status);
        const result = await resp.json();
        if (!result.ok) { showError(result.msg || 'No se pudo guardar'); return; }
        const emailRow = document.querySelector(`.email-row[data-posicion="${posicionEditando}"]`);
        const emailInfo = emailRow ? emailRow.querySelector('.email-info') : null;
        const boton = emailRow ? emailRow.querySelector('.btn-mail') : null;
        if (emailInfo) {
          const badge = buildSolicitudBadge(posicionEditando, new Date().toLocaleDateString('es-AR'));
          emailInfo.appendChild(badge);
          badge.querySelector('.btn-cancelar-solicitud').addEventListener('click', function (e) {
            e.preventDefault();
            cancelarSolicitud(this.getAttribute('data-posicion'));
          });
        }
        if (boton) boton.disabled = true;
        if (modalEmail) modalEmail.style.display = 'none';
        if (overlay) overlay.style.display = 'none';
        posicionEditando = null;
        if (emailRow) mostrarMensajeTemporal(emailRow, 'Solicitud enviada. Será procesada en 48 hs hábiles.');
      } catch (error) {
        showError('Error de conexión: ' + (error.message || ''));
      }
    });
  }

  // --- Cancelar modal ---
  if (cancelarBtn) {
    cancelarBtn.addEventListener('click', () => {
      if (modalEmail) modalEmail.style.display = 'none';
      if (overlay) overlay.style.display = 'none';
      posicionEditando = null;
    });
  }
  if (overlay) {
    overlay.addEventListener('click', (e) => {
      const emailModalAbierto = modalEmail && modalEmail.style.display === 'block';
      if (e.target === overlay && emailModalAbierto) {
        if (modalEmail) modalEmail.style.display = 'none';
        overlay.style.display = 'none';
        posicionEditando = null;
      }
    });
  }

  // --- Cancelar solicitud delegado ---
  document.addEventListener('click', (e) => {
    if (e.target.classList.contains('btn-cancelar-solicitud')) {
      e.preventDefault();
      cancelarSolicitud(e.target.getAttribute('data-posicion'));
    }
  });

  async function cancelarSolicitud(posicion) {
    if (!confirm('¿Estás seguro de cancelar esta solicitud?')) return;
    const form = new FormData();
    form.append('posicion', posicion);
    form.append('csrf_token', csrfToken);
    try {
      const resp = await fetch(AJAX_CANCELAR_SOLICITUD_EMAIL, { method: 'POST', body: form });
      const result = await resp.json();
      if (result.ok) {
        const emailRow = document.querySelector(`.email-row[data-posicion="${posicion}"]`);
        const badge = emailRow ? emailRow.querySelector('.solicitud-badge') : null;
        if (badge) badge.remove();
        const boton = emailRow ? emailRow.querySelector('.btn-mail') : null;
        if (boton) boton.disabled = false;
        if (emailRow) mostrarMensajeTemporal(emailRow, 'Solicitud cancelada correctamente.');
      } else {
        showError(result.msg || 'Error al cancelar');
      }
    } catch (_error) {
      showError('Error de conexión');
    }
  }

  function mostrarMensajeTemporal(container, texto) {
    const emailInfo = container.querySelector('.email-info');
    if (!emailInfo) return;
    const msgDiv = document.createElement('div');
    msgDiv.className = 'mensaje-alumno';
    msgDiv.textContent = texto;
    msgDiv.style.marginLeft = '10px';
    emailInfo.appendChild(msgDiv);
    setTimeout(() => msgDiv.remove(), 4000);
  }
}
