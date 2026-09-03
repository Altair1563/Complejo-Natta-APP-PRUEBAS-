/**
 * Página Contratos: firma y estado (reutiliza la lógica de homeContracts).
 */
import { initDashboardLayout } from '../core/dashboardLayout.js';
import { initNotificationsPanel } from '../core/notificationsPanel.js';
import { initPaymentModal } from '../ui/paymentModal.js';
import { initHomeContracts } from './homeContracts.js';

export function initContratosPage(data) {
  const alumnos = data.alumnos || [];
  const saldoTotalFamiliar = Number(data.saldoTotalFamiliar) || 0;
  const nroFamilia = data.nroFamilia ?? '';
  const csrfToken = data.csrfToken ?? '';

  initDashboardLayout();
  initNotificationsPanel(undefined, csrfToken);
  initPaymentModal({ alumnos, saldoTotalFamiliar, nroFamilia, csrfToken });
  initHomeContracts({ csrfToken });
}
