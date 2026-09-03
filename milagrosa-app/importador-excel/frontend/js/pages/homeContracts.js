import {
  AJAX_CONTRACT_ACCEPT,
  AJAX_CONTRACT_GET,
  AJAX_CONTRACT_STATUS,
  REGLEMENTO_INSTITUCIONAL_2027_PDF_URL,
} from '../config/apiEndpoints.js';
import { showError, showSuccess } from '../ui/dialogs.js';

function escapeHtml(str) {
  return String(str ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

/** Texto del representante del establecimiento (coincide con lo que arma el backend al guardar). */
function htmlFragmentoInstitucionContrato(ic) {
  const resp = String(ic?.responsable_institucion ?? '');
  const inst = String(ic?.institucion ?? '');
  const dom = String(ic?.domicilio_institucion ?? '');
  return `${escapeHtml(resp)}, en representación ${escapeHtml(inst)}, con domicilio en ${escapeHtml(dom)}`;
}

/** Textos de la línea de estado (contratos.php y sincronización visual). */
const TEXTO_ESTADO_LINEA = {
  pendiente_firma: 'Estado del Contrato 2027: Pendiente de Firmar',
  pendiente_aprobacion:
    'Estado del contrato 2027: Contrato Firmado - Pendiente de aprobación administrativa',
  aprobado: 'Estado del contrato 2027: Contrato firmado - Aprobado',
  inactivo_sin_firma: 'No aplica (alumno inactivo)',
  error: 'No se pudo obtener el estado del contrato.',
};

function inferEstadoFlujo(s) {
  if (!s || typeof s !== 'object') return 'pendiente_firma';
  if (s.estado_flujo) return s.estado_flujo;
  if (s.es_inactivo && !s.signed) return 'inactivo_sin_firma';
  if (!s.signed) return 'pendiente_firma';
  if (!s.admin_aprobado) return 'pendiente_aprobacion';
  return 'aprobado';
}

/**
 * @param {Element} el
 * @param {{ phase: 'loading' } | { phase: 'ready', flow: string, detailMessage?: string | null }} opts
 */
function updateContratoEstadoElement(el, opts) {
  el.classList.remove(
    'contrato-estado--loading',
    'contrato-estado--pendiente_firma',
    'contrato-estado--pendiente_aprobacion',
    'contrato-estado--aprobado',
    'contrato-estado--inactivo_sin_firma',
    'contrato-estado--error'
  );
  if (opts.phase === 'loading') {
    el.classList.add('contrato-estado--loading');
    el.innerHTML =
      '<span class="contrato-estado-spinner" aria-hidden="true"></span><span class="contrato-estado-msg">Consultando estado…</span>';
    return;
  }
  const flow = opts.flow || 'error';
  el.classList.add(`contrato-estado--${flow}`);
  let txt = TEXTO_ESTADO_LINEA[flow] || TEXTO_ESTADO_LINEA.error;
  if (flow === 'error' && opts.detailMessage) {
    txt = String(opts.detailMessage);
  }
  el.innerHTML = `<span class="contrato-estado-msg">${escapeHtml(txt)}</span>`;
}

/** Pone “Consultando…” en todos los bloques de estado (evita quedar colgado si el DNI del botón no coincide con el span). */
function applyContratoEstadoVisualAllLoading() {
  document.querySelectorAll('[data-contrato-estado-dni]').forEach((el) => {
    updateContratoEstadoElement(el, { phase: 'loading' });
  });
}

/** Mismo mensaje de error en todos los bloques (p. ej. sin contrato vigente en contratos_instituciones). */
function applyContratoEstadoVisualAllError(detailMessage) {
  document.querySelectorAll('[data-contrato-estado-dni]').forEach((el) => {
    updateContratoEstadoElement(el, {
      phase: 'ready',
      flow: 'error',
      detailMessage: detailMessage || null,
    });
  });
}

/**
 * Actualiza el bloque de estado junto al botón (si existe span con data-contrato-estado-dni).
 * @param {string} studentDni
 * @param {{ phase: 'loading' } | { phase: 'ready', flow: string }} opts
 */
function applyContratoEstadoVisual(studentDni, opts) {
  const dni = String(studentDni ?? '');
  document.querySelectorAll('[data-contrato-estado-dni]').forEach((el) => {
    if (el.getAttribute('data-contrato-estado-dni') !== dni) return;
    updateContratoEstadoElement(el, opts);
  });
}

/**
 * Aplica texto/clases del contrato a un botón o enlace `.btn-contrato`.
 * @param {HTMLElement} el
 * @param {object} status fila de ajax_contract_status (signed, admin_aprobado, es_inactivo, estado_flujo)
 */
function applyStatusToContractControl(el, status) {
  if (!el) return;
  const flow = inferEstadoFlujo(status);
  const isButton = el.tagName === 'BUTTON';

  el.style.display = '';
  if (isButton) {
    el.dataset.hiddenByRules = '0';
    el.dataset.disabledByRules = '0';
    el.disabled = false;
    el.style.opacity = '';
    el.style.cursor = '';
  } else {
    el.removeAttribute('aria-disabled');
    el.style.pointerEvents = '';
  }
  el.title = '';
  el.classList.remove('btn-contract-pending', 'btn-contract-signed', 'btn-contract-review');

  if (flow === 'pendiente_firma') {
    el.dataset.signed = '0';
    el.textContent = 'Contrato: Pendiente';
    el.classList.add('btn-contract-pending');
    el.title = 'Ir a firmar el contrato de servicios educativos';
  } else if (flow === 'pendiente_aprobacion') {
    el.dataset.signed = '1';
    el.textContent = 'Contrato: Firmado';
    el.classList.add('btn-contract-review');
    el.title = 'Firma registrada. Pendiente de aprobación administrativa.';
    if (isButton) {
      el.disabled = true;
      el.dataset.disabledByRules = '1';
      el.style.cursor = 'default';
    }
  } else if (flow === 'aprobado') {
    el.dataset.signed = '1';
    el.textContent = 'Contrato: Aprobado';
    el.classList.add('btn-contract-signed');
    el.title = 'Contrato firmado y aprobado por administración';
    if (isButton) {
      el.disabled = true;
      el.dataset.disabledByRules = '1';
      el.style.cursor = 'default';
    }
  }

  const dni = el.dataset.studentDni || '';
  if (dni) applyContratoEstadoVisual(dni, { phase: 'ready', flow });
}

/** Quita “Consultando…” si quedó colgado tras un error o excepción. */
function clearStuckContratoEstadoLoading() {
  document.querySelectorAll('[data-contrato-estado-dni].contrato-estado--loading').forEach((el) => {
    const dni = el.getAttribute('data-contrato-estado-dni') ?? '';
    applyContratoEstadoVisual(dni, { phase: 'ready', flow: 'pendiente_firma' });
  });
}

function getSwalApi() {
const instance = window.Swal || window.swal || window.Sweetalert2;
if (!instance) return null;
if (typeof instance.fire === 'function') {
return { run: (opts) => instance.fire(opts), showValidationMessage: (msg) => instance.showValidationMessage?.(msg) };
}
if (typeof instance === 'function') {
return { run: (opts) => instance(opts), showValidationMessage: (msg) => instance.showValidationError?.(msg) };
}
return null;
}

function openContractFallbackModal({
  studentName,
  studentDni,
  version,
  hash,
  pdfUrl,
  reglamentoUrl,
  reglamentoLinkLabel,
  institucionContrato,
}) {
return new Promise((resolve) => {
const fragInst = htmlFragmentoInstitucionContrato(institucionContrato || {});
const regLabel = reglamentoLinkLabel || 'Leer REGLAMENTO INSTITUCIONAL 2027';
const overlay = document.createElement('div');
overlay.style.position = 'fixed';
overlay.style.inset = '0';
overlay.style.background = 'rgba(0,0,0,.5)';
overlay.style.zIndex = '1000';

const modal = document.createElement('div');
modal.style.position = 'fixed';
modal.style.top = '50%';
modal.style.left = '50%';
modal.style.transform = 'translate(-50%, -50%)';
modal.style.width = '95%';
modal.style.maxWidth = '700px';
modal.style.maxHeight = '90vh';
modal.style.overflowY = 'auto';
modal.style.background = '#fff';
modal.style.borderRadius = '12px';
modal.style.padding = '16px';
modal.style.zIndex = '1001';
modal.innerHTML = `
<h3 style="margin:0 0 12px 0;color:#0c3484;">Firma de contrato</h3>
<p><strong>Alumno:</strong> ${escapeHtml(studentName)}</p>
<p><strong>DNI del alumno:</strong> ${escapeHtml(studentDni)}</p>
<p><strong>Versión:</strong> ${escapeHtml(version)}</p>
<p><strong>Código único de verificación:</strong> ${escapeHtml(hash)}</p>
<div style="border:1px solid #e0e0e0;border-radius:8px;padding:12px 14px;margin:12px 0;background:#fafbfd;">
  <p style="margin:0;text-align:justify;line-height:1.65;font-size:14px;color:#222;">
    Entre el Sr./Sra.
    <input id="fallbackDeclarantName" type="text" aria-label="Nombre y apellido completos del firmante"
      style="display:inline-block;vertical-align:middle;width:min(100%,280px);max-width:100%;margin:2px 4px;padding:8px 10px;border:1px solid #ccc;border-radius:6px;font-size:14px;box-sizing:border-box;"
      placeholder="Nombre y apellido completos" />
    con DNI Nº
    <input id="fallbackDeclarantDni" type="text" inputmode="numeric" autocomplete="on" aria-label="DNI del firmante"
      style="display:inline-block;vertical-align:middle;width:min(100%,140px);max-width:100%;margin:2px 4px;padding:8px 10px;border:1px solid #ccc;border-radius:6px;font-size:14px;box-sizing:border-box;"
      placeholder="DNI" />
    responsable del alumno <strong>${escapeHtml(studentName)}</strong>, en adelante &laquo;LA FAMILIA&raquo; y ${fragInst},
    en adelante &laquo;ESTABLECIMIENTO EDUCATIVO&raquo; celebran el presente contrato de servicios
    educativos sujeto a las siguientes cláusulas:
  </p>
</div>
<p style="margin:0 0 12px 0;font-size:14px;line-height:1.5;text-align:center;">
  <a href="${escapeHtml(pdfUrl)}" target="_blank" rel="noopener">Leer CONTRATO 2027</a>
  <span aria-hidden="true"> - </span>
  <a href="${escapeHtml(reglamentoUrl)}" target="_blank" rel="noopener">${escapeHtml(regLabel)}</a>
</p>
<div style="border:1px solid #e0e0e0;border-radius:8px;padding:14px 16px;margin:12px 0;background:#fafbfd;">
  <label style="display:flex;gap:12px;align-items:flex-start;margin:0;cursor:pointer;font-size:14px;line-height:1.55;color:#222;">
    <input id="fallbackContractCheckbox" type="checkbox" style="margin-top:4px;flex-shrink:0;width:18px;height:18px;cursor:pointer;" />
    <span>Declaro haber leído y aceptado íntegramente el Contrato de servicio educativo 2027 y el reglamento institucional vigente.</span>
  </label>
</div>

<label for="fallbackContractPassword"><strong>Reconfirmar contraseña</strong></label>
<input id="fallbackContractPassword" type="password"
  style="width:100%;margin:8px 0 12px 0;padding:10px;border:1px solid #ddd;border-radius:8px;"
  placeholder="Ingrese nuevamente su contraseña" />
<div id="fallbackContractError" style="min-height:20px;color:#b30000;font-size:13px;"></div>
<div style="display:flex;gap:8px;justify-content:flex-end;">
  <button id="fallbackContractCancel" type="button"
    style="padding:10px 14px;border:1px solid #ccc;background:#fff;border-radius:8px;cursor:pointer;">Cancelar</button>
  <button id="fallbackContractConfirm" type="button"
    style="padding:10px 14px;border:none;background:#0c3484;color:#fff;border-radius:8px;cursor:pointer;">ACEPTO EL
    CONTRATO</button>
</div>
`;

document.body.appendChild(overlay);
document.body.appendChild(modal);

const close = () => {
modal.remove();
overlay.remove();
};

const cancelBtn = modal.querySelector('#fallbackContractCancel');
const confirmBtn = modal.querySelector('#fallbackContractConfirm');
const checkbox = modal.querySelector('#fallbackContractCheckbox');
const passwordInput = modal.querySelector('#fallbackContractPassword');
const declarantNameInput = modal.querySelector('#fallbackDeclarantName');
const declarantDniInput = modal.querySelector('#fallbackDeclarantDni');
const errorEl = modal.querySelector('#fallbackContractError');

overlay.addEventListener('click', () => {
close();
resolve({ isConfirmed: false });
});

cancelBtn?.addEventListener('click', () => {
close();
resolve({ isConfirmed: false });
});

confirmBtn?.addEventListener('click', () => {
const acceptedCheckbox = checkbox && checkbox.checked ? '1' : '0';
const password = passwordInput ? passwordInput.value.trim() : '';
const declarantName = declarantNameInput ? declarantNameInput.value.trim() : '';
const declarantDni = declarantDniInput ? declarantDniInput.value.trim() : '';
const dniOnly = declarantDni.replace(/\D+/g, '');
if (!declarantName) {
if (errorEl) errorEl.textContent = 'Debe completar nombre y apellido.';
return;
}
if (!/^\d{6,12}$/.test(dniOnly)) {
if (errorEl) errorEl.textContent = 'Debe ingresar un DNI válido.';
return;
}
if (!checkbox || !checkbox.checked) {
if (errorEl) errorEl.textContent = 'Debe marcar la declaración de lectura y aceptación para continuar.';
return;
}
if (!password) {
if (errorEl) errorEl.textContent = 'Debe reconfirmar su contraseña.';
return;
}
close();
resolve({ isConfirmed: true, value: { acceptedCheckbox, password, declarantName, declarantDni: dniOnly } });
});
});
}

function setInactiveNoSignState(el) {
  if (!el) return;
  el.style.display = 'none';
  if (el.tagName === 'BUTTON') {
    el.dataset.hiddenByRules = '1';
    el.dataset.disabledByRules = '1';
  }
  if (el.tagName === 'A') {
    el.setAttribute('aria-hidden', 'true');
  }
  const dni = el.dataset.studentDni || '';
  if (dni) applyContratoEstadoVisual(dni, { phase: 'ready', flow: 'inactivo_sin_firma' });
}

/**
 * Enlaces del home hacia contratos.php: mismo texto Pendiente/Firmado que en Contratos.
 */
export async function initContratoEnlacesHome({ csrfToken }) {
const links = Array.from(document.querySelectorAll('a.contrato-link-home[href*="contratos.php"]'));
if (!links.length) return;

try {
const statusForm = new FormData();
statusForm.append('csrf_token', csrfToken || '');
const statusResp = await fetch(AJAX_CONTRACT_STATUS, { method: 'POST', body: statusForm });
const statusResult = await statusResp.json();
if (!statusResult.ok) return;

const map = new Map((statusResult.status || []).map((s) => [String(s.student_dni), s]));
links.forEach((a) => {
const status = map.get(String(a.dataset.studentDni));
const flow = inferEstadoFlujo(status || {});
const esInactivo = status ? !!status.es_inactivo : (a.dataset.esInactivo === '1');
const signed = status ? !!status.signed : false;
if (esInactivo && !signed) {
a.style.display = 'none';
a.setAttribute('aria-hidden', 'true');
return;
}
a.removeAttribute('aria-hidden');
a.style.textDecoration = 'none';
a.style.display = 'inline-block';
a.classList.remove('btn-contract-pending', 'btn-contract-signed', 'btn-contract-review');
a.dataset.signed = signed ? '1' : '0';
if (flow === 'pendiente_firma') {
a.textContent = 'Contrato: Pendiente de firmar';
a.classList.add('btn-contract-pending');
a.title = 'Ir a Contratos para firmar';
} else if (flow === 'pendiente_aprobacion') {
a.textContent = 'Contrato: Firmado';
a.classList.add('btn-contract-review');
a.title = 'Firma registrada. Pendiente de aprobación — ver en Contratos';
} else if (flow === 'aprobado') {
a.textContent = 'Contrato: Aprobado';
a.classList.add('btn-contract-signed');
a.title = 'Contrato aprobado — ver en Contratos';
}
});
} catch (_err) {
/* deja el texto por defecto del HTML */
}
}

export async function initHomeContracts({ csrfToken }) {
  const estadoSpans = document.querySelectorAll('[data-contrato-estado-dni]');
  const contractControls = Array.from(document.querySelectorAll('button.btn-contrato, a.btn-contrato'));
  const signButtons = Array.from(document.querySelectorAll('button.btn-contrato'));

  if (!estadoSpans.length && !contractControls.length) return;

  if (estadoSpans.length) applyContratoEstadoVisualAllLoading();

  const t0 = Date.now();
  let statusResult = { ok: false };
  try {
    const statusForm = new FormData();
    statusForm.append('csrf_token', csrfToken || '');
    const statusResp = await fetch(AJAX_CONTRACT_STATUS, { method: 'POST', body: statusForm });
    statusResult = await statusResp.json();
  } catch (_err) {
    statusResult = { ok: false };
  }

  const elapsed = Date.now() - t0;
  await new Promise((r) => setTimeout(r, Math.max(0, 5000 - elapsed)));

  try {
    if (!statusResult.ok) {
      applyContratoEstadoVisualAllError(statusResult.msg || '');
      return;
    }

    const map = new Map((statusResult.status || []).map((s) => [String(s.student_dni), s]));

    estadoSpans.forEach((spanEl) => {
      const dni = spanEl.getAttribute('data-contrato-estado-dni') ?? '';
      const status = map.get(String(dni)) || {
        signed: false,
        admin_aprobado: false,
        es_inactivo: false,
      };
      const flow = inferEstadoFlujo(status);
      applyContratoEstadoVisual(dni, { phase: 'ready', flow });
    });

    contractControls.forEach((ctrl) => {
      const status = map.get(String(ctrl.dataset.studentDni)) || {
        signed: false,
        admin_aprobado: false,
        es_inactivo: ctrl.dataset.esInactivo === '1',
      };
      const esInactivo = !!status.es_inactivo || ctrl.dataset.esInactivo === '1';
      const signed = !!status.signed;
      if (esInactivo && !signed) {
        setInactiveNoSignState(ctrl);
        return;
      }
      if (ctrl.tagName === 'A') {
        ctrl.removeAttribute('aria-hidden');
        ctrl.style.textDecoration = 'none';
        ctrl.style.display = 'inline-block';
      }
      applyStatusToContractControl(ctrl, status);
    });
  } finally {
    clearStuckContratoEstadoLoading();
  }

  if (!statusResult.ok) return;

  signButtons.forEach((button) => {
    button.addEventListener('click', async () => {
if (button.dataset.hiddenByRules === '1' || button.dataset.disabledByRules === '1' || button.dataset.signed === '1') {
return;
}
const studentDni = button.dataset.studentDni || '';
const nroLegajo = button.dataset.legajo || '';
const studentName = button.dataset.nombre || '';

if (!studentDni || !nroLegajo) {
showError('No se pudo identificar el alumno para firmar el contrato.');
return;
}

try {
const getForm = new FormData();
getForm.append('csrf_token', csrfToken || '');
getForm.append('student_dni', studentDni);
const getResp = await fetch(AJAX_CONTRACT_GET, { method: 'POST', body: getForm });
const getResult = await getResp.json();
if (!getResult.ok) {
showError(getResult.msg || 'No se pudo cargar el contrato.');
return;
}

const contract = getResult.contract || {};
const version = String(contract.contract_version || '-');
const hash = String(contract.contract_hash || '-');
const pdfUrl = String(contract.pdf_url || '#');
const reglamentoUrl = String(contract.reglamento_pdf_url || '').trim() || REGLEMENTO_INSTITUCIONAL_2027_PDF_URL;
const reglamentoLinkLabel = String(contract.reglamento_link_label || '').trim() || 'Leer REGLAMENTO INSTITUCIONAL 2027';
const institucionContrato = getResult.institucion_contrato || {};
const fragInstSwal = htmlFragmentoInstitucionContrato(institucionContrato);

const swalApi = getSwalApi();
let modalResult;
if (swalApi) {
modalResult = await swalApi.run({
title: 'Firma de contrato',
width: 700,
showCancelButton: true,
confirmButtonText: 'ACEPTO EL CONTRATO',
cancelButtonText: 'Cancelar',
focusConfirm: false,
html: `
<div style="text-align:left">
  <p><strong>Alumno:</strong> ${escapeHtml(studentName)}</p>
  <p><strong>DNI del alumno:</strong> ${escapeHtml(studentDni)}</p>
  <p><strong>Versión:</strong> ${escapeHtml(version)}</p>
  <p><strong>Código único de verificación:</strong> ${escapeHtml(hash)}</p>
  <div style="border:1px solid #e0e0e0;border-radius:8px;padding:12px 14px;margin:10px 0;background:#fafbfd;">
    <p style="margin:0;text-align:justify;line-height:1.65;font-size:14px;color:#222;">
      Entre el Sr./Sra.
      <input id="contractDeclarantName" type="text" class="swal2-input"
        aria-label="Nombre y apellido completos del firmante"
        style="display:inline-block;vertical-align:middle;width:min(100%,280px)!important;max-width:100%;margin:2px 4px!important;padding:8px 10px!important;box-sizing:border-box;"
        placeholder="Nombre y apellido completos" />
      con DNI Nº
      <input id="contractDeclarantDni" type="text" class="swal2-input" inputmode="numeric" autocomplete="on"
        aria-label="DNI del firmante"
        style="display:inline-block;vertical-align:middle;width:min(100%,140px)!important;max-width:100%;margin:2px 4px!important;padding:8px 10px!important;box-sizing:border-box;"
        placeholder="DNI" />
      responsable del alumno <strong>${escapeHtml(studentName)}</strong>, en adelante &laquo;LA FAMILIA&raquo; y ${fragInstSwal},
      en adelante &laquo;ESTABLECIMIENTO EDUCATIVO&raquo; celebran el presente contrato de servicios
      educativos sujeto a las siguientes cláusulas:
    </p>
  </div>
  <p style="margin:0 0 12px 0;font-size:14px;line-height:1.5;text-align:center;">
    <a href="${escapeHtml(pdfUrl)}" target="_blank" rel="noopener">Leer CONTRATO 2027</a>
    <span aria-hidden="true"> - </span>
    <a href="${escapeHtml(reglamentoUrl)}" target="_blank" rel="noopener">${escapeHtml(reglamentoLinkLabel)}</a>
  </p>
  <div style="border:1px solid #e0e0e0;border-radius:8px;padding:14px 16px;margin:12px 0;background:#fafbfd;">
    <label style="display:flex;gap:12px;align-items:flex-start;margin:0;cursor:pointer;font-size:14px;line-height:1.55;color:#222;">
      <input id="contractCheckbox" type="checkbox" style="margin-top:4px;flex-shrink:0;width:18px;height:18px;cursor:pointer;" />
      <span>Declaro haber leído y aceptado íntegramente el Contrato de servicio educativo 2027 y el reglamento institucional vigente.</span>
    </label>
  </div>
  <label for="contractPassword"><strong>Reconfirmar contraseña</strong></label>
  <input id="contractPassword" type="password" class="swal2-input" style="margin:8px 0 0 0;"
    placeholder="Ingrese nuevamente su contraseña" />
</div>
`,
preConfirm: async () => {
const checkbox = document.getElementById('contractCheckbox');
const passwordInput = document.getElementById('contractPassword');
const declarantNameInput = document.getElementById('contractDeclarantName');
const declarantDniInput = document.getElementById('contractDeclarantDni');
const acceptedCheckbox = checkbox && checkbox.checked ? '1' : '0';
const password = passwordInput ? passwordInput.value.trim() : '';
const declarantName = declarantNameInput ? declarantNameInput.value.trim() : '';
const declarantDniRaw = declarantDniInput ? declarantDniInput.value.trim() : '';
const declarantDni = declarantDniRaw.replace(/\D+/g, '');

if (!declarantName) {
if (swalApi.showValidationMessage) {
swalApi.showValidationMessage('Debe completar nombre y apellido.');
}
return false;
}
if (!/^\d{6,12}$/.test(declarantDni)) {
if (swalApi.showValidationMessage) {
swalApi.showValidationMessage('Debe ingresar un DNI válido.');
}
return false;
}

if (!checkbox || !checkbox.checked) {
if (swalApi.showValidationMessage) {
swalApi.showValidationMessage('Debe marcar la declaración de lectura y aceptación para continuar.');
}
return false;
}
if (!password) {
if (swalApi.showValidationMessage) {
swalApi.showValidationMessage('Debe reconfirmar su contraseña.');
}
return false;
}

const acceptForm = new FormData();
acceptForm.append('csrf_token', csrfToken || '');
acceptForm.append('student_dni', studentDni);
acceptForm.append('nro_legajo', nroLegajo);
acceptForm.append('accepted_checkbox', acceptedCheckbox);
acceptForm.append('password', password);
acceptForm.append('declarant_name', declarantName);
acceptForm.append('declarant_dni', declarantDni);

const acceptResp = await fetch(AJAX_CONTRACT_ACCEPT, { method: 'POST', body: acceptForm });
const acceptResult = await acceptResp.json();
if (!acceptResult.ok) {
if (swalApi.showValidationMessage) {
swalApi.showValidationMessage(acceptResult.msg || 'No se pudo registrar la aceptación.');
}
return false;
}
return acceptResult;
}
});
} else {
const fallbackResult = await openContractFallbackModal({
studentName,
studentDni,
version,
hash,
pdfUrl,
reglamentoUrl,
reglamentoLinkLabel,
institucionContrato,
});
if (fallbackResult.isConfirmed && fallbackResult.value) {
const acceptForm = new FormData();
acceptForm.append('csrf_token', csrfToken || '');
acceptForm.append('student_dni', studentDni);
acceptForm.append('nro_legajo', nroLegajo);
acceptForm.append('accepted_checkbox', fallbackResult.value.acceptedCheckbox);
acceptForm.append('password', fallbackResult.value.password);
acceptForm.append('declarant_name', fallbackResult.value.declarantName);
acceptForm.append('declarant_dni', fallbackResult.value.declarantDni);

const acceptResp = await fetch(AJAX_CONTRACT_ACCEPT, { method: 'POST', body: acceptForm });
const acceptResult = await acceptResp.json();
if (!acceptResult.ok) {
showError(acceptResult.msg || 'No se pudo registrar la aceptación.');
return;
}
modalResult = { isConfirmed: true, value: acceptResult };
} else {
modalResult = { isConfirmed: false };
}
}

const acceptedSuccessfully =
!!(modalResult && (
(modalResult.isConfirmed && modalResult.value) ||
// SweetAlert2 v6 devuelve directamente el valor confirmado
(typeof modalResult === 'object' && modalResult.ok === true)
));

if (acceptedSuccessfully) {
applyStatusToContractControl(button, {
signed: true,
admin_aprobado: false,
es_inactivo: false
});
showSuccess('La firma del contrato quedó registrada correctamente.');
setTimeout(() => {
const next = document.body?.dataset?.page === 'contratos' ? 'contratos.php' : 'home.php';
window.location.href = next;
}, 300);
}
} catch (_err) {
showError('Error de conexión al intentar registrar la firma.');
}
});
});
}