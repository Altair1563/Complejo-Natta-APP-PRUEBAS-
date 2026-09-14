/**
 * URLs de endpoints AJAX/API. Ajustar base si la app corre en un subdirectorio.
 */
const API_BASE = '';
const AJAX_BASE = API_BASE + 'backend/ajax/';

export const AJAX_CUOTAS = AJAX_BASE + 'ajax_cuotas.php';
export const AJAX_NOTIFICACIONES = AJAX_BASE + 'ajax_notificaciones.php';
export const AJAX_NOTIFICACIONES_LEER = AJAX_BASE + 'ajax_notificaciones_leer.php';
export const AJAX_HISTORIAL = AJAX_BASE + 'ajax_historial.php';
export const AJAX_SOLICITAR_TALON = AJAX_BASE + 'ajax_solicitar_talon.php';
export const AJAX_CANCELAR_SOLICITUDES = AJAX_BASE + 'ajax_cancelar_solicitudes.php';
export const AJAX_AGREGAR_EMAIL = AJAX_BASE + 'ajax_agregar_email.php';
export const AJAX_CANCELAR_SOLICITUD_EMAIL = AJAX_BASE + 'ajax_cancelar_solicitud_email.php';
export const AJAX_CONTRACT_STATUS = AJAX_BASE + 'ajax_contract_status.php';
export const AJAX_CONTRACT_GET = AJAX_BASE + 'ajax_contract_get.php';
export const AJAX_CONTRACT_ACCEPT = AJAX_BASE + 'ajax_contract_accept.php';
export const AJAX_CONTRACT_PREVIEW = AJAX_BASE + 'ajax_contract_preview.php';
export const AJAX_FAMILIA_CONTEXT = AJAX_BASE + 'ajax_familia_context.php';
export const AJAX_TALON_CUOTAS = AJAX_BASE + 'ajax_talon_cuotas.php';

/** Vista HTML del contrato con datos del responsable (lectura / vista previa). */
export const CONTRATO_DOCUMENTO_URL = 'contrato_documento.php';

/** PDF firmado depositado en storage (generado con Dompdf al aceptar el contrato). */
export const CONTRATO_DOCUMENTO_FIRMADO_URL = 'contrato_documento_firmado.php';

/** PDF del reglamento institucional 2027 (fallback si el backend no envía URL). */
export const REGLEMENTO_INSTITUCIONAL_2027_PDF_URL =
  './docs/reglamento-institucional/' + encodeURIComponent('REGLAMENTO CPEEN 2027 - La Milagrosa.pdf');

export const PHP_LOGOUT = 'php/logout.php';
