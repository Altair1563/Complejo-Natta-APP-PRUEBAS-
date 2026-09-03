/**
 * Informar error: envío AJAX del formulario.
 */
import { initDashboardLayout } from '../core/dashboardLayout.js';
import { initNotificationsPanel } from '../core/notificationsPanel.js';
import { initPaymentModal } from '../ui/paymentModal.js';

export function initInformarErrorPage(data) {
  const alumnos = data?.alumnos || [];
  const saldoTotalFamiliar = Number(data?.saldoTotalFamiliar) || 0;
  const nroFamilia = data?.nroFamilia ?? '';
  const csrfToken = data?.csrfToken ?? '';

  initDashboardLayout();
  initNotificationsPanel(undefined, csrfToken);
  initPaymentModal({ alumnos, saldoTotalFamiliar, nroFamilia, csrfToken });

  const form = document.getElementById('informeForm');
  const mensajeContainer = document.getElementById('mensaje-container');
  const btnEnviar = document.getElementById('btnEnviar');
  const loading = document.getElementById('loading');

  if (form && mensajeContainer && btnEnviar && loading) {
    form.addEventListener('submit', async function (e) {
      e.preventDefault();
      btnEnviar.disabled = true;
      loading.style.display = 'inline';
      mensajeContainer.style.display = 'none';
      mensajeContainer.textContent = '';
      const formData = new FormData(form);
      try {
        const response = await fetch(window.location.href, {
          method: 'POST',
          body: formData,
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        if (!response.ok) throw new Error('Error en la petición: ' + response.status);
        const result = await response.json();
        mensajeContainer.className = result.status === 'success' ? 'mensaje-exito' : 'mensaje-error';
        mensajeContainer.textContent = result.message || '';
        mensajeContainer.style.display = 'block';
        if (result.status === 'success') form.reset();
      } catch (_error) {
        mensajeContainer.className = 'mensaje-error';
        mensajeContainer.textContent = 'Ocurrió un error al enviar el informe. Intente nuevamente.';
        mensajeContainer.style.display = 'block';
      } finally {
        btnEnviar.disabled = false;
        loading.style.display = 'none';
        mensajeContainer.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      }
    });
  }
}
