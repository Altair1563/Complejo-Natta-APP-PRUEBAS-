/**
 * Lógica de la página info-importante: modal de pagos, cuotas, notificaciones.
 * Sin historial ni autolegajo.
 */
import { initDashboardLayout } from '../core/dashboardLayout.js';
import { initNotificationsPanel } from '../core/notificationsPanel.js';
import { initPaymentModal } from '../ui/paymentModal.js';

export function initInfoImportantePage(data) {
  const alumnos            = data.alumnos || [];
  const saldoTotalFamiliar = Number(data.saldoTotalFamiliar) || 0;
  const nroFamilia         = data.nroFamilia ?? '';
  const csrfToken          = data.csrfToken ?? '';

  initDashboardLayout();
  initNotificationsPanel(undefined, csrfToken);
  initPaymentModal({ alumnos, saldoTotalFamiliar, nroFamilia, csrfToken });
}
