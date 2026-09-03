/**
 * Libro de sugerencias: envío AJAX del formulario.
 */
import { initDashboardLayout } from '../core/dashboardLayout.js';
import { initNotificationsPanel } from '../core/notificationsPanel.js';
import { initPaymentModal } from '../ui/paymentModal.js';

export function initLibroSugerenciasPage(data) {
  const alumnos = data?.alumnos || [];
  const saldoTotalFamiliar = Number(data?.saldoTotalFamiliar) || 0;
  const nroFamilia = data?.nroFamilia ?? '';
  const csrfToken = data?.csrfToken ?? '';
  initDashboardLayout();
  initNotificationsPanel(undefined, csrfToken);
  initPaymentModal({ alumnos, saldoTotalFamiliar, nroFamilia, csrfToken });

  const form = document.getElementById('formSugerencia');
  const mensajeContainer = document.getElementById('mensajeContainer');

  function setMensaje(clase, texto) {
    if (!mensajeContainer) return;
    mensajeContainer.textContent = '';
    const mensajeDiv = document.createElement('div');
    mensajeDiv.className = clase;
    mensajeDiv.textContent = texto;
    mensajeContainer.appendChild(mensajeDiv);
  }

  if (form && mensajeContainer) {
    form.addEventListener('submit', async function (e) {
      e.preventDefault();
      mensajeContainer.textContent = '';
      const formData = new FormData(form);
      formData.append('ajax', '1');
      try {
        const response = await fetch(window.location.href, { method: 'POST', body: formData });
        const result = await response.json();
        setMensaje(result.status === 'success' ? 'mensaje-exito' : 'mensaje-error', result.message || '');
        if (result.status === 'success') form.reset();
      } catch (_error) {
        setMensaje('mensaje-error', 'Error de conexión. Intente nuevamente.');
      }
    });
  }
}
