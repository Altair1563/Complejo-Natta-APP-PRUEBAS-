import {
  AJAX_CONTRACT_ACCEPT,
  AJAX_CONTRACT_GET,
  AJAX_CONTRACT_PREVIEW,
  AJAX_CONTRACT_STATUS,
  CONTRATO_DOCUMENTO_FIRMADO_URL,
  CONTRATO_DOCUMENTO_URL,
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

/** Nombre legible del documento vigente (ej. Contrato_2027_v1 → Contrato de Servicio Educativo 2027). */
function formatDocumentoVigente(contractVersion) {
  const v = String(contractVersion ?? '');
  const m = v.match(/20\d{2}/);
  return m ? `Contrato de Servicio Educativo ${m[0]}` : (v || '-');
}

function htmlContractModalHeader() {
  return `
<div class="contract-modal-header">
  <div class="contract-modal-header__logos">
    <img src="./assets/img/LogoMilagrosa.png" alt="Instituto Jardin de Infantes La Milagrosa" class="contract-modal-header__logo contract-modal-header__logo--natta" />
  </div>
  <h3 class="contract-modal-header__title">Firma de contrato</h3>
</div>`;
}

/** Bloque superior del modal de firma con email, curso y documento vigente. */
function htmlContractModalInfoSection({
  responsableEmail,
  studentName,
  studentDni,
  curso,
  contractVersion,
  hash,
}) {
  const documentoVigente = formatDocumentoVigente(contractVersion);
  return `
  <p><strong>Email del Responsable:</strong> ${escapeHtml(responsableEmail || '-')}</p>
  <p><strong>Alumno:</strong> ${escapeHtml(studentName)}</p>
  <p class="contract-modal-info-dni-curso"><strong>DNI del alumno:</strong> ${escapeHtml(studentDni)} <span class="contract-modal-info-curso"><strong>CURSO:</strong> ${escapeHtml(curso || '-')}</span></p>
  <p><strong>Documento Vigente:</strong> ${escapeHtml(documentoVigente)}</p>
  <p><strong>Versión del documento:</strong> ${escapeHtml(contractVersion || '-')}</p>
  <p><strong>Huella SHA-256:</strong> ${escapeHtml(hash)}</p>
  <p style="font-size:13px;color:#555;line-height:1.45;">Al firmar, el sistema generará el PDF y calculará la huella SHA-256 que permite verificar posteriormente que el archivo no fue modificado.</p>`;
}

/** URL sin parámetros; los datos van en sesión vía POST previo. */
export function buildContractDocumentUrl() {
  return CONTRATO_DOCUMENTO_URL;
}

/** PDF firmado depositado en storage (Dompdf), sin PII en query string. */
export function buildSignedContractPdfUrl() {
  return CONTRATO_DOCUMENTO_FIRMADO_URL;
}

/**
 * Guarda vista previa en sesión y abre el contrato sin PII en la URL.
 * @param {string} csrfToken
 * @param {string} studentDni
 * @param {{ declarantName?: string, declarantDni?: string, declarantDomicilio?: string, declarantLocalidad?: string }} [values]
 * @param {{ signed?: boolean, autoPdf?: boolean }} [opts]
 */
async function storeContractPreviewSession(csrfToken, studentDni, values = {}, opts = {}) {
  const form = new FormData();
  form.append('csrf_token', csrfToken || '');
  form.append('student_dni', String(studentDni ?? ''));
  if (opts.signed) form.append('modo', 'firmado');
  if (values.declarantName) form.append('declarant_name', values.declarantName);
  if (values.declarantDni) form.append('declarant_dni', values.declarantDni);
  if (values.declarantDomicilio) form.append('declarant_domicilio', values.declarantDomicilio);
  if (values.declarantLocalidad) form.append('declarant_localidad', values.declarantLocalidad);
  if (opts.autoPdf) form.append('pdf', '1');

  const resp = await fetch(AJAX_CONTRACT_PREVIEW, { method: 'POST', body: form });
  const data = await resp.json();
  if (!resp.ok || !data.ok) {
    throw new Error(data.msg || 'No se pudo preparar el contrato.');
  }
  return data.url || CONTRATO_DOCUMENTO_URL;
}

export async function openContractDocumentPreview(csrfToken, studentDni, values = {}, opts = {}) {
  const url = await storeContractPreviewSession(csrfToken, studentDni, values, opts);
  window.open(url, '_blank', 'noopener');
}

async function openSignedContractPdf(csrfToken, studentDni) {
  await storeContractPreviewSession(csrfToken, studentDni, {}, { signed: true });
  window.open(buildSignedContractPdfUrl(), '_blank', 'noopener');
}

let contractCsrfToken = '';

function htmlContractModalLinksSection(
  contractPreviewUrl,
  reglamentoUrl,
  { contratoLinkId, reglamentoLinkId, reglamentoLinkLabel = 'Leer REGLAMENTO INSTITUCIONAL 2027' },
) {
  return `
  <p class="contract-modal-links" style="margin:0 0 12px 0;font-size:14px;line-height:1.5;text-align:center;">
    <span class="contract-modal-links__item">
      <a id="${contratoLinkId}" data-contract-doc-read="contrato" href="${escapeHtml(contractPreviewUrl)}" target="_blank" rel="noopener">Leer CONTRATO 2027</a><span id="${contratoLinkId}ReadMark" style="color:#1b5e20;font-weight:600;margin-left:4px;"></span>
    </span>
    <span class="contract-modal-links__sep" aria-hidden="true"> - </span>
    <span class="contract-modal-links__item">
      <a id="${reglamentoLinkId}" data-contract-doc-read="reglamento" href="${escapeHtml(reglamentoUrl)}" target="_blank" rel="noopener">${escapeHtml(reglamentoLinkLabel)}</a><span id="${reglamentoLinkId}ReadMark" style="color:#1b5e20;font-weight:600;margin-left:4px;"></span>
    </span>
  </p>`;
}

function htmlContractModalErrorBox(errorId) {
  return `<div id="${errorId}" role="alert" style="display:none;margin:10px 0 0 0;padding:10px 12px;border-radius:8px;background:#fdecea;border:1px solid #f5c2c7;color:#842029;font-size:13px;line-height:1.45;"></div>`;
}

const CONTRACT_PREAMBLE_INPUT_STYLE =
  'display:inline-block;vertical-align:middle;width:min(100%,240px);max-width:100%;margin:2px 4px;padding:8px 10px;border:1px solid #ccc;border-radius:6px;font-size:14px;box-sizing:border-box;';

/** Lee los campos del formulario de firma dentro del modal. */
function getContractSignFormValues(root, fieldIds) {
  const declarantDniRaw = root.querySelector(`#${fieldIds.declarantDni}`)?.value.trim() || '';
  return {
    declarantName: root.querySelector(`#${fieldIds.declarantName}`)?.value.trim() || '',
    declarantDni: declarantDniRaw.replace(/\D+/g, ''),
    declarantDomicilio: root.querySelector(`#${fieldIds.declarantDomicilio}`)?.value.trim() || '',
    declarantLocalidad: root.querySelector(`#${fieldIds.declarantLocalidad}`)?.value.trim() || '',
    password: root.querySelector(`#${fieldIds.password}`)?.value.trim() || '',
    checkboxChecked: !!root.querySelector(`#${fieldIds.checkbox}`)?.checked,
  };
}

/** Validación unificada antes de enviar la firma. */
function validateContractSignForm(values, readGate) {
  if (!values.declarantName) {
    return { ok: false, msg: 'Debe completar nombre y apellido del firmante.' };
  }
  if (!/^\d{6,12}$/.test(values.declarantDni)) {
    return { ok: false, msg: 'Debe ingresar un DNI válido del firmante.' };
  }
  if (!values.declarantDomicilio) {
    return { ok: false, msg: 'Debe completar el domicilio real del firmante.' };
  }
  if (!values.declarantLocalidad) {
    return { ok: false, msg: 'Debe completar la localidad del firmante.' };
  }
  if (!readGate.bothRead()) {
    return {
      ok: false,
      msg: 'Debe hacer clic en ambos enlaces (contrato y reglamento) antes de continuar.',
    };
  }
  if (!values.checkboxChecked) {
    return {
      ok: false,
      msg: 'Debe marcar la declaración de lectura y aceptación para continuar.',
    };
  }
  if (!values.password) {
    return { ok: false, msg: 'Debe reconfirmar su contraseña.' };
  }
  return { ok: true, msg: '' };
}

function showContractInlineError(errorId, message) {
  const el = document.getElementById(errorId);
  if (!el) return;
  const msg = String(message || '').trim();
  el.textContent = msg;
  el.style.display = msg ? 'block' : 'none';
}

/** Swal v6: los errores de validación deben rechazar la Promise, no devolver false. */
function rejectContractValidation(message) {
  return Promise.reject(String(message || 'No se pudo completar la firma.'));
}

function findSwalConfirmButton() {
  return document.querySelector('.swal2-confirm');
}

function syncSignConfirmButton(readGate, fieldIds, root, confirmBtn) {
  const values = getContractSignFormValues(root, fieldIds);
  const validation = validateContractSignForm(values, readGate);
  if (confirmBtn) {
    confirmBtn.disabled = !validation.ok;
    confirmBtn.style.opacity = validation.ok ? '1' : '0.55';
    confirmBtn.style.cursor = validation.ok ? 'pointer' : 'not-allowed';
    confirmBtn.title = validation.ok ? '' : validation.msg;
  }
  return validation;
}

function syncContractSignConfirmButton(readGate, fieldIds, root) {
  return syncSignConfirmButton(readGate, fieldIds, root || findSwalModalRoot() || document, findSwalConfirmButton());
}

function bindContractSignFormWatchers(root, fieldIds, getReadGate, errorId, confirmBtn) {
  const refresh = () => {
    showContractInlineError(errorId, '');
    const readGate = typeof getReadGate === 'function' ? getReadGate() : getReadGate;
    if (confirmBtn) {
      syncSignConfirmButton(readGate, fieldIds, root, confirmBtn);
    } else {
      syncSignConfirmButton(readGate, fieldIds, root || findSwalModalRoot() || document, findSwalConfirmButton());
    }
  };
  root.addEventListener('input', refresh);
  root.addEventListener('change', refresh);
  refresh();
  return refresh;
}

function htmlContractModalCheckboxSection(checkboxId, hintId) {
  return `
  <p id="${hintId}" style="margin:0 0 8px 0;font-size:13px;color:#666;line-height:1.45;">
    Paso 1: haga clic en cada enlace para abrir el contrato y el reglamento.
  </p>
  <div class="contract-modal-checkbox-box" style="border:1px solid #e0e0e0;border-radius:8px;padding:14px 16px;margin:12px 0;background:#fafbfd;">
    <label id="${checkboxId}Label" style="display:flex;gap:12px;align-items:flex-start;margin:0;cursor:not-allowed;font-size:14px;line-height:1.55;color:#888;opacity:0.65;">
      <input id="${checkboxId}" type="checkbox" disabled
        style="margin-top:4px;flex-shrink:0;width:18px;height:18px;cursor:not-allowed;" />
      <span>Declaro haber leído y aceptado íntegramente el Contrato de servicio educativo 2027 y el reglamento institucional vigente.</span>
    </label>
  </div>`;
}

/**
 * Habilita la casilla de aceptación solo después de abrir contrato y reglamento.
 * @param {ParentNode} root
 * @param {{ contratoLinkId: string, reglamentoLinkId: string, checkboxId: string, hintId: string }} ids
 */
function setupContractDocumentReadGate(root, ids, { onStateChange, studentDni, preambleFieldIds, csrfToken = '' } = {}) {
  const checkbox = root.querySelector(`#${ids.checkboxId}`);
  const hintEl = root.querySelector(`#${ids.hintId}`);
  const labelEl = root.querySelector(`#${ids.checkboxId}Label`);
  if (!checkbox) {
    return { bothRead: () => false };
  }

  let contratoRead = false;
  let reglamentoRead = false;

  const updateState = () => {
    const ready = contratoRead && reglamentoRead;
    checkbox.disabled = !ready;
    if (!ready) {
      checkbox.checked = false;
      if (labelEl) {
        labelEl.style.cursor = 'not-allowed';
        labelEl.style.opacity = '0.65';
        labelEl.style.color = '#888';
      }
      checkbox.style.cursor = 'not-allowed';
      if (hintEl) {
        hintEl.textContent =
          'Paso 1: haga clic en cada enlace para abrir el contrato y el reglamento.';
        hintEl.style.display = '';
        hintEl.style.color = '#666';
      }
    } else {
      if (labelEl) {
        labelEl.style.cursor = 'pointer';
        labelEl.style.opacity = '1';
        labelEl.style.color = '#222';
      }
      checkbox.style.cursor = 'pointer';
      if (hintEl) {
        hintEl.textContent = 'Paso 2: marque la casilla de aceptación y complete sus datos para firmar.';
        hintEl.style.display = '';
        hintEl.style.color = '#1b5e20';
      }
    }
    onStateChange?.(ready, { contratoRead, reglamentoRead });
  };

  const markFromLink = (linkEl) => {
    if (!linkEl) return;
    const kind = linkEl.getAttribute('data-contract-doc-read');
    if (kind === 'contrato') {
      contratoRead = true;
      const mark = root.querySelector(`#${ids.contratoLinkId}ReadMark`);
      if (mark) mark.textContent = ' ✓';
      linkEl.style.fontWeight = '600';
    }
    if (kind === 'reglamento') {
      reglamentoRead = true;
      const mark = root.querySelector(`#${ids.reglamentoLinkId}ReadMark`);
      if (mark) mark.textContent = ' ✓';
      linkEl.style.fontWeight = '600';
    }
    updateState();
  };

  const onDocLinkActivate = (event) => {
    const linkEl = event.target.closest('[data-contract-doc-read]');
    if (!linkEl || !root.contains(linkEl)) return;
    const kind = linkEl.getAttribute('data-contract-doc-read');
    if (kind === 'contrato' && studentDni) {
      event.preventDefault();
      const values = preambleFieldIds ? getContractSignFormValues(root, preambleFieldIds) : {};
      openContractDocumentPreview(csrfToken, studentDni, {
        declarantName: values.declarantName,
        declarantDni: values.declarantDni,
        declarantDomicilio: values.declarantDomicilio,
        declarantLocalidad: values.declarantLocalidad,
      }).catch(() => { /* el enlace no abre si falla la sesión */ });
    }
    markFromLink(linkEl);
  };

  root.addEventListener('click', onDocLinkActivate);
  root.addEventListener('auxclick', onDocLinkActivate);

  updateState();

  return {
    bothRead: () => contratoRead && reglamentoRead,
    destroy: () => {
      root.removeEventListener('click', onDocLinkActivate);
      root.removeEventListener('auxclick', onDocLinkActivate);
    },
  };
}

/** Contenedor del modal SweetAlert2 (v6 usa .swal2-modal; versiones nuevas .swal2-popup). */
function findSwalModalRoot() {
  return (
    document.querySelector('.swal2-modal') ||
    document.querySelector('.swal2-popup') ||
    document.querySelector('.swal2-container')
  );
}

/**
 * Registra el bloqueo de lectura cuando el modal ya está en pantalla (compatible Swal v6/v7+).
 * @param {(gate: ReturnType<typeof setupContractDocumentReadGate>) => void} setGate
 * @param {{ contratoLinkId: string, reglamentoLinkId: string, checkboxId: string, hintId: string }} ids
 */
function initContractReadGateOnModalOpen(setGate, ids, { onReady, studentDni, preambleFieldIds, csrfToken = '' } = {}) {
  const readIds = {
    contratoLinkId: ids.contratoLinkId,
    reglamentoLinkId: ids.reglamentoLinkId,
    checkboxId: ids.checkboxId,
    hintId: ids.hintId,
  };
  const attach = () => {
    const root = findSwalModalRoot();
    if (!root) return false;
    const gate = setupContractDocumentReadGate(root, readIds, {
      onStateChange: () => onReady?.(root),
      studentDni,
      preambleFieldIds,
      csrfToken,
    });
    setGate(gate);
    onReady?.(root);
    return true;
  };
  if (attach()) return;
  requestAnimationFrame(() => {
    if (!attach()) setTimeout(attach, 50);
  });
}

const CONTRACT_DOMICILIO_ELECTRONICO_INSTITUCION = 'nattadomicilioelectronico@gmail.com';

/** Texto del representante del establecimiento (coincide con lo que arma el backend al guardar). */
function htmlFragmentoInstitucionContrato(ic) {
  const resp = String(ic?.responsable_institucion ?? '').trim();
  const inst = String(ic?.institucion ?? '').trim();
  const sede = String(ic?.domicilio_institucion ?? '').trim();
  const email =
    String(ic?.email_domicilio_electronico ?? '').trim() || CONTRACT_DOMICILIO_ELECTRONICO_INSTITUCION;
  return `${escapeHtml(resp)}, en representación ${escapeHtml(inst)}, con domicilio electrónico en ${escapeHtml(email)} y sede en ${escapeHtml(sede)}`;
}

function contractPreambleInputStyle(widthPx, swalInputs) {
  const w = widthPx || 240;
  const base = CONTRACT_PREAMBLE_INPUT_STYLE.replace('240px', `${w}px`);
  if (!swalInputs) return base;
  return `${base.replace(/margin:2px 4px/g, 'margin:2px 4px!important').replace(/padding:8px 10px/g, 'padding:8px 10px!important')}`;
}

/** Bloque del preámbulo contractual con datos del firmante y del establecimiento. */
function htmlContractPreambleSection(institucionContrato, fieldIds, { swalInputs = false } = {}) {
  const fragInst = htmlFragmentoInstitucionContrato(institucionContrato || {});
  const inputClass = swalInputs ? ' class="swal2-input"' : '';
  const styleName = contractPreambleInputStyle(280, swalInputs);
  const styleDni = contractPreambleInputStyle(140, swalInputs);
  const styleDom = contractPreambleInputStyle(240, swalInputs);
  const styleLoc = contractPreambleInputStyle(180, swalInputs);

  return `
  <div class="contract-preamble" style="border:1px solid #e0e0e0;border-radius:8px;padding:12px 14px;margin:12px 0;background:#fafbfd;">
    <p style="margin:0;text-align:justify;line-height:1.65;font-size:14px;color:#222;">
      Entre el Sr.
      <input id="${fieldIds.declarantName}" type="text"${inputClass} aria-label="Nombre y apellido completos del firmante"
        style="${styleName}" placeholder="Nombre y apellido completos" />
      con DNI N°
      <input id="${fieldIds.declarantDni}" type="text"${inputClass} inputmode="numeric" autocomplete="on" aria-label="DNI del firmante"
        style="${styleDni}" placeholder="DNI" />,
      con domicilio real en
      <input id="${fieldIds.declarantDomicilio}" type="text"${inputClass} aria-label="Domicilio real del firmante"
        style="${styleDom}" placeholder="calle y número" />
      localidad de
      <input id="${fieldIds.declarantLocalidad}" type="text"${inputClass} aria-label="Localidad del firmante"
        style="${styleLoc}" placeholder="Localidad" />, en adelante &laquo;LA FAMILIA&raquo; y ${fragInst},
      en adelante &laquo;ESTABLECIMIENTO EDUCATIVO&raquo; celebran el presente contrato de servicios
      educativos sujeto a las siguientes cláusulas:
    </p>
  </div>`;
}

/** Textos de la línea de estado (contratos.php y sincronización visual). */
const TEXTO_ESTADO_LINEA = {
  pendiente_firma: 'Estado del Contrato 2027: Pendiente de Firmar',
  firma_bloqueada_noviembre: 'Estará disponible una vez abonada la cuota de noviembre',
  pendiente_aprobacion: 'Contrato firmado – Requisitos pendientes',
  aprobado: 'Contrato firmado – Aprobado',
  inactivo_sin_firma: 'No aplica (alumno inactivo)',
  error: 'No se pudo obtener el estado del contrato.',
};

const REQUISITOS_ITEMS = [
  {
    key: 'firma',
    label: 'Firma y aceptación del Contrato de Servicios Educativos y del Reglamento Institucional vigente',
  },
  {
    key: 'documentacion',
    label: 'Presentación de la documentación requerida por la institución correspondiente',
  },
  {
    key: 'sin_deudas_familia',
    label: 'No registrar deudas pendientes correspondientes al ciclo lectivo 2026',
    note: 'se habilitará una vez abonado NOVIEMBRE',
    nested: true,
  },
  {
    key: 'reserva_vacante',
    label: 'Pago de la Reserva de Vacante 2027',
    note: 'Se habilitará una vez definidos los aranceles correspondientes. En el caso de los alumnos ingresantes, la Reserva de Vacante se abonará en dos etapas: Adelanto de Reserva de Vacante y Resto de Reserva de Vacante.',
    noteBlock: true,
    rv: true,
  },
];

function findContratoCardElements(studentDni) {
  const dni = String(studentDni ?? '');
  const estadoEl = document.querySelector(`[data-contrato-estado-dni="${dni}"]`);
  const card = estadoEl?.closest('.contrato-alumno-card') ?? null;
  const panelEl = card?.querySelector(`[data-contrato-requisitos-dni="${dni}"]`) ?? null;
  return { card, estadoEl, panelEl };
}

function isContratosPage() {
  return document.body?.dataset?.page === 'contratos';
}

function requisitoNestedData(requisitos, key) {
  const val = requisitos?.[key];
  return val && typeof val === 'object' ? val : null;
}

function requisitoRvPartes(requisitos) {
  return {
    adelanto: requisitoNestedData(requisitos, 'adelanto_rv'),
    resto: requisitoNestedData(requisitos, 'resto_rv'),
  };
}

function requisitoReservaVacanteVisible(requisitos) {
  const { adelanto, resto } = requisitoRvPartes(requisitos);
  const adelantoAplica = adelanto && adelanto.aplica !== false;
  const restoAplica = resto && resto.aplica !== false;
  return adelantoAplica || restoAplica;
}

function requisitoReservaVacanteCumplido(requisitos) {
  const { adelanto, resto } = requisitoRvPartes(requisitos);
  const adelantoOk = !adelanto || adelanto.aplica === false || !!adelanto.cumplido;
  const restoOk = !resto || resto.aplica === false || !!resto.cumplido;
  return adelantoOk && restoOk;
}

function requisitoCumplido(requisitos, key) {
  if (!requisitos || typeof requisitos !== 'object') return false;
  if (key === 'reserva_vacante') {
    return requisitoReservaVacanteCumplido(requisitos);
  }
  const nested = requisitoNestedData(requisitos, key);
  if (nested) {
    if (nested.aplica === false) return true;
    return !!nested.cumplido;
  }
  return !!requisitos[key];
}

function requisitoVisible(requisitos, key) {
  if (key === 'reserva_vacante') {
    return requisitoReservaVacanteVisible(requisitos);
  }
  const nested = requisitoNestedData(requisitos, key);
  if (!nested) return true;
  return nested.aplica !== false;
}

function buildContratoRequisitosHtml(flow, status) {
  const requisitos = status?.requisitos;
  const todosCumplidos = !!requisitos?.todos_cumplidos;
  const cardMod = todosCumplidos ? 'aprobado' : 'pendiente';
  const estadoSuffix = todosCumplidos
    ? TEXTO_ESTADO_LINEA.aprobado
    : TEXTO_ESTADO_LINEA.pendiente_aprobacion;
  const tituloHtml = `<strong>Estado del contrato 2027:</strong> ${escapeHtml(estadoSuffix)}`;

  let introHtml = '';
  if (!todosCumplidos) {
    introHtml =
      '<p class="contrato-requisitos-card-lead">El contrato fue firmado correctamente, pero '
      + '<strong>entrará en vigencia una vez que se hayan cumplido todos los requisitos indicados a continuación</strong>.</p>'
      + '<div class="contrato-requisitos-card-aviso" role="note">'
      + '<p><strong>Importante:</strong> algunos requisitos pueden permanecer con estado '
      + '<strong>Pendiente</strong> hasta fin de año debido a procesos administrativos o a la disponibilidad de información, '
      + 'como la definición de aranceles. Esto no implica necesariamente que exista un inconveniente con el contrato.</p>'
      + '</div>';
  } else {
    introHtml =
      '<p class="contrato-requisitos-card-lead">Todos los requisitos fueron cumplidos. '
      + '<strong>El contrato tiene validez.</strong></p>';
  }

  const items = REQUISITOS_ITEMS.filter((item) => requisitoVisible(requisitos, item.key))
    .map((item) => {
      const ok = requisitoCumplido(requisitos, item.key);
      const estadoTxt = ok ? 'Cumplido' : 'Pendiente';
      const icon = ok ? '✓' : '○';
      const labelHtml = item.note
        ? item.noteBlock
          ? `<strong>${escapeHtml(item.label)}</strong>`
            + `<em class="contrato-req-nota contrato-req-nota--block">(${escapeHtml(item.note)})</em>`
          : `<strong>${escapeHtml(item.label)}</strong> `
            + `<em class="contrato-req-nota">(${escapeHtml(item.note)})</em>`
        : `<strong>${escapeHtml(item.label)}</strong>`;
      return `<li class="contrato-req contrato-req--${ok ? 'ok' : 'pending'}">`
        + `<span class="contrato-req-icon" aria-hidden="true">${icon}</span>`
        + `<div class="contrato-req-body">`
        + `<span class="contrato-req-label">${labelHtml}</span>`
        + `<strong class="contrato-req-estado">${escapeHtml(estadoTxt)}</strong>`
        + `</div>`
        + `</li>`;
    })
    .join('');

  return (
    `<div class="contrato-requisitos-card-inner contrato-requisitos-card-inner--${cardMod}">`
    + `<div class="contrato-requisitos-card-header">`
    + `<p class="contrato-requisitos-card-title">${tituloHtml}</p>`
    + introHtml
    + `</div>`
    + `<ul class="contrato-requisitos" aria-label="Requisitos para validez del contrato">${items}</ul>`
    + `<div class="contrato-requisitos-card-footer"></div>`
    + `</div>`
  );
}

function hideContratoRequisitosPanel(studentDni) {
  const { card, panelEl } = findContratoCardElements(studentDni);
  if (panelEl) {
    panelEl.hidden = true;
    panelEl.setAttribute('aria-hidden', 'true');
    panelEl.innerHTML = '';
  }
  card?.classList.remove('contrato-alumno-card--con-requisitos');
}

function showContratoRequisitosPanel(studentDni, flow, status) {
  const { card, estadoEl, panelEl } = findContratoCardElements(studentDni);
  if (!panelEl) return false;

  panelEl.innerHTML = buildContratoRequisitosHtml(flow, status);
  panelEl.hidden = false;
  panelEl.removeAttribute('aria-hidden');
  card?.classList.add('contrato-alumno-card--con-requisitos');

  if (estadoEl) {
    estadoEl.hidden = true;
    estadoEl.setAttribute('aria-hidden', 'true');
  }

  return true;
}

function inferEstadoFlujo(s) {
  if (!s || typeof s !== 'object') return 'pendiente_firma';
  if (s.estado_flujo) return s.estado_flujo;
  if (s.es_inactivo && !s.signed) return 'inactivo_sin_firma';
  if (!s.signed && s.noviembre_abonado === false) return 'firma_bloqueada_noviembre';
  if (!s.signed) return 'pendiente_firma';
  if (s.requisitos?.todos_cumplidos) return 'aprobado';
  if (!s.admin_aprobado) return 'pendiente_aprobacion';
  return 'aprobado';
}

/**
 * @param {Element} el
 * @param {{ phase: 'loading' } | { phase: 'ready', flow: string, detailMessage?: string | null, status?: object | null }} opts
 */
function updateContratoEstadoElement(el, opts) {
  el.classList.remove(
    'contrato-estado--loading',
    'contrato-estado--pendiente_firma',
    'contrato-estado--firma_bloqueada_noviembre',
    'contrato-estado--pendiente_aprobacion',
    'contrato-estado--aprobado',
    'contrato-estado--inactivo_sin_firma',
    'contrato-estado--error',
    'contrato-estado--con-requisitos'
  );
  if (opts.phase === 'loading') {
    el.classList.add('contrato-estado--loading');
    el.innerHTML =
      '<span class="contrato-estado-spinner" aria-hidden="true"></span><span class="contrato-estado-msg">Consultando estado…</span>';
    return;
  }
  const flow = opts.flow || 'error';
  el.classList.add(`contrato-estado--${flow}`);

  const dni = el.getAttribute('data-contrato-estado-dni') ?? '';
  if (
    isContratosPage()
    && (flow === 'pendiente_aprobacion' || flow === 'aprobado')
    && opts.status?.requisitos
    && showContratoRequisitosPanel(dni, flow, opts.status)
  ) {
    return;
  }

  hideContratoRequisitosPanel(dni);
  el.hidden = false;
  el.removeAttribute('aria-hidden');

  let txt = TEXTO_ESTADO_LINEA[flow] || TEXTO_ESTADO_LINEA.error;
  if (flow === 'error' && opts.detailMessage) {
    txt = String(opts.detailMessage);
  }
  el.innerHTML = `<span class="contrato-estado-msg">${escapeHtml(txt)}</span>`;
}

/** Pone “Consultando…” en todos los bloques de estado (evita quedar colgado si el DNI del botón no coincide con el span). */
function applyContratoEstadoVisualAllLoading() {
  document.querySelectorAll('[data-contrato-estado-dni]').forEach((el) => {
    const dni = el.getAttribute('data-contrato-estado-dni') ?? '';
    hideContratoRequisitosPanel(dni);
    el.hidden = false;
    el.removeAttribute('aria-hidden');
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
 * @param {{ phase: 'loading' } | { phase: 'ready', flow: string, status?: object | null }} opts
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
function ensureContractDownloadLink(el, status) {
  if (!el) return;
  const card = el.closest('.contrato-alumno-card');
  if (!card) return;
  const studentDni = String(el.dataset.studentDni ?? '');
  const flow = inferEstadoFlujo(status);
  const showDownload = flow === 'pendiente_aprobacion' || flow === 'aprobado';
  const useRequisitosCard = isContratosPage() && showDownload && !!status?.requisitos;
  const panelEl = card.querySelector(`[data-contrato-requisitos-dni="${studentDni}"]`);
  const footerEl = panelEl?.querySelector('.contrato-requisitos-card-footer');
  const actions = card.querySelector('.contrato-alumno-actions');
  const host = useRequisitosCard && footerEl ? footerEl : actions;
  if (!host) return;

  let downloadLink = host.querySelector('.btn-contrato-download');
  if (actions && actions !== host) {
    actions.querySelector('.btn-contrato-download')?.remove();
  }

  if (!showDownload || !studentDni) {
    downloadLink?.remove();
    return;
  }

  if (!downloadLink) {
    downloadLink = document.createElement('a');
    downloadLink.className = 'btn-contrato-download';
    downloadLink.setAttribute('rel', 'noopener');
    downloadLink.setAttribute('target', '_blank');
    host.appendChild(downloadLink);
  } else if (downloadLink.parentElement !== host) {
    host.appendChild(downloadLink);
  }
  downloadLink.href = buildSignedContractPdfUrl();
  if (downloadLink.dataset.sessionBound !== '1') {
    downloadLink.dataset.sessionBound = '1';
    downloadLink.addEventListener('click', (ev) => {
      ev.preventDefault();
      openSignedContractPdf(contractCsrfToken, studentDni).catch(() => { /* ignore */ });
    });
  }
  downloadLink.textContent = 'Descargar contrato (PDF)';
  downloadLink.title = 'Descargar el PDF firmado depositado por el sistema';
  if (useRequisitosCard) {
    downloadLink.classList.add('btn-contrato-download--footer');
  } else {
    downloadLink.classList.remove('btn-contrato-download--footer');
  }
}

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
  el.classList.remove('btn-contract-pending', 'btn-contract-signed', 'btn-contract-review', 'btn-contract-blocked');

  if (flow === 'pendiente_firma') {
    el.dataset.signed = '0';
    el.textContent = el.classList.contains('contrato-link-home') ? 'Contrato: Pendiente' : 'Contrato Pendiente';
    el.classList.add('btn-contract-pending');
    el.title = 'Ir a firmar el contrato de servicios educativos';
  } else if (flow === 'firma_bloqueada_noviembre') {
    el.dataset.signed = '0';
    el.textContent = 'Contrato 2027';
    el.classList.add('btn-contract-blocked');
    el.title = 'La firma del contrato se habilita una vez abonada la cuota de NOVIEMBRE.';
    if (isButton) {
      el.disabled = true;
      el.dataset.disabledByRules = '1';
      el.style.cursor = 'not-allowed';
    }
  } else if (flow === 'pendiente_aprobacion') {
    el.dataset.signed = '1';
    if (isContratosPage()) {
      el.style.display = 'none';
      el.dataset.hiddenByRules = '1';
    } else {
      el.textContent = 'Contrato: Firmado';
      el.classList.add('btn-contract-review');
      el.title = 'Firma registrada. Requisitos pendientes para validez del contrato.';
      if (isButton) {
        el.disabled = true;
        el.dataset.disabledByRules = '1';
        el.style.cursor = 'default';
      }
    }
  } else if (flow === 'aprobado') {
    el.dataset.signed = '1';
    if (isContratosPage()) {
      el.style.display = 'none';
      el.dataset.hiddenByRules = '1';
    } else {
      el.textContent = 'Contrato: Aprobado';
      el.classList.add('btn-contract-signed');
      el.title = 'Contrato firmado y aprobado — todos los requisitos cumplidos';
      if (isButton) {
        el.disabled = true;
        el.dataset.disabledByRules = '1';
        el.style.cursor = 'default';
      }
    }
  }

  const dni = el.dataset.studentDni || '';
  if (dni) applyContratoEstadoVisual(dni, { phase: 'ready', flow, status });
  ensureContractDownloadLink(el, status);
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
const normalizeOpts = (opts) => {
  const next = { ...opts };
  if (typeof next.didOpen === 'function' && typeof next.onOpen !== 'function') {
    next.onOpen = next.didOpen;
  }
  return next;
};
if (typeof instance.fire === 'function') {
return {
  run: (opts) => instance.fire(normalizeOpts(opts)),
  showValidationMessage: (msg) => instance.showValidationMessage?.(msg) ?? instance.showValidationError?.(msg),
};
}
if (typeof instance === 'function') {
return {
  run: (opts) => instance(normalizeOpts(opts)),
  showValidationMessage: (msg) => instance.showValidationError?.(msg),
};
}
return null;
}

function openContractFallbackModal({
  studentName,
  studentDni,
  curso,
  responsableEmail,
  version,
  hash,
  contractPreviewUrl,
  reglamentoUrl,
  reglamentoLinkLabel,
  institucionContrato,
  csrfToken = '',
}) {
return new Promise((resolve) => {
const regLabel = reglamentoLinkLabel || 'Leer REGLAMENTO INSTITUCIONAL 2027';
const fallbackPreambleIds = {
  declarantName: 'fallbackDeclarantName',
  declarantDni: 'fallbackDeclarantDni',
  declarantDomicilio: 'fallbackDeclarantDomicilio',
  declarantLocalidad: 'fallbackDeclarantLocalidad',
};
const overlay = document.createElement('div');
overlay.style.position = 'fixed';
overlay.style.inset = '0';
overlay.style.background = 'rgba(0,0,0,.5)';
overlay.style.zIndex = '1000';

const modal = document.createElement('div');
modal.className = 'contract-fallback-modal';
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
modal.style.boxSizing = 'border-box';
modal.innerHTML = `
${htmlContractModalHeader({ codigoEscuela: institucionContrato?.codigo, curso })}
<div class="contract-modal-body">
${htmlContractModalInfoSection({
  responsableEmail,
  studentName,
  studentDni,
  curso,
  contractVersion: version,
  hash,
})}
${htmlContractPreambleSection(institucionContrato, fallbackPreambleIds)}
${htmlContractModalLinksSection(contractPreviewUrl, reglamentoUrl, {
  contratoLinkId: 'fallbackContractPdfLink',
  reglamentoLinkId: 'fallbackReglamentoPdfLink',
  reglamentoLinkLabel: regLabel,
})}
${htmlContractModalCheckboxSection('fallbackContractCheckbox', 'fallbackContractReadHint')}

${htmlContractModalErrorBox('fallbackContractError')}

<label for="fallbackContractPassword"><strong>Reconfirmar contraseña</strong></label>
<input id="fallbackContractPassword" type="password"
  style="width:100%;margin:8px 0 12px 0;padding:10px;border:1px solid #ddd;border-radius:8px;box-sizing:border-box;"
  placeholder="Ingrese nuevamente su contraseña" />
</div>
<div class="contract-fallback-actions" style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px;">
  <button id="fallbackContractCancel" type="button"
    style="padding:10px 14px;border:1px solid #ccc;background:#fff;border-radius:8px;cursor:pointer;">Cancelar</button>
  <button id="fallbackContractConfirm" type="button" disabled
    style="padding:10px 14px;border:none;background:#0c3484;color:#fff;border-radius:8px;cursor:not-allowed;opacity:0.55;">FIRMAR CONTRATO</button>
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
const errorEl = modal.querySelector('#fallbackContractError');
const fallbackFieldIds = {
  ...fallbackPreambleIds,
  password: 'fallbackContractPassword',
  checkbox: 'fallbackContractCheckbox',
};
let refreshFallbackForm = () => {};
const readGate = setupContractDocumentReadGate(modal, {
  contratoLinkId: 'fallbackContractPdfLink',
  reglamentoLinkId: 'fallbackReglamentoPdfLink',
  checkboxId: 'fallbackContractCheckbox',
  hintId: 'fallbackContractReadHint',
}, {
  onStateChange: () => refreshFallbackForm(),
  studentDni,
  preambleFieldIds: fallbackPreambleIds,
  csrfToken,
});
refreshFallbackForm = bindContractSignFormWatchers(
  modal,
  fallbackFieldIds,
  () => readGate,
  'fallbackContractError',
  confirmBtn
);

overlay.addEventListener('click', () => {
close();
resolve({ isConfirmed: false });
});

cancelBtn?.addEventListener('click', () => {
close();
resolve({ isConfirmed: false });
});

confirmBtn?.addEventListener('click', () => {
const values = getContractSignFormValues(modal, fallbackFieldIds);
const validation = validateContractSignForm(values, readGate);
if (!validation.ok) {
  showContractInlineError('fallbackContractError', validation.msg);
  return;
}
showContractInlineError('fallbackContractError', '');
close();
resolve({
  isConfirmed: true,
  value: {
    acceptedCheckbox: '1',
    password: values.password,
    declarantName: values.declarantName,
    declarantDni: values.declarantDni,
    declarantDomicilio: values.declarantDomicilio,
    declarantLocalidad: values.declarantLocalidad,
  },
});
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
 * Enlaces del home hacia contratos.php: visible solo con noviembre abonado
 * (o si ya hay firma). Mismo texto Pendiente/Firmado/Aprobado que en Contratos.
 */
export async function initContratoEnlacesHome({ csrfToken }) {
  const links = Array.from(document.querySelectorAll('a.contrato-link-home[href*="contratos.php"]'));
  if (!links.length) return;

  const hideLink = (a) => {
    a.style.display = 'none';
    a.setAttribute('aria-hidden', 'true');
  };

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
        hideLink(a);
        return;
      }
      // Sin noviembre abonado y sin firma: el acceso a firmar sigue bloqueado.
      if (flow === 'firma_bloqueada_noviembre') {
        hideLink(a);
        return;
      }

      a.removeAttribute('aria-hidden');
      a.style.textDecoration = 'none';
      a.style.display = 'inline-block';
      a.classList.remove(
        'btn-contract-pending',
        'btn-contract-signed',
        'btn-contract-review',
        'btn-contract-blocked'
      );
      a.dataset.signed = signed ? '1' : '0';
      if (flow === 'pendiente_firma') {
        a.textContent = 'Contrato: Pendiente';
        a.classList.add('btn-contract-pending');
        a.title = 'Ir a Contratos para firmar';
      } else if (flow === 'pendiente_aprobacion') {
        a.textContent = 'Contrato: Firmado';
        a.classList.add('btn-contract-review');
        a.title = 'Firma registrada. Requisitos pendientes — ver en Contratos';
      } else if (flow === 'aprobado') {
        a.textContent = 'Contrato: Aprobado';
        a.classList.add('btn-contract-signed');
        a.title = 'Contrato aprobado — ver en Contratos';
      } else {
        hideLink(a);
      }
    });
  } catch (_err) {
    /* deja oculto el HTML por defecto */
  }
}

export async function initHomeContracts({ csrfToken }) {
  contractCsrfToken = csrfToken || '';
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
      applyContratoEstadoVisual(dni, { phase: 'ready', flow, status });
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
const hash = 'Se generará automáticamente sobre el PDF final';
const contractPreviewUrl = buildContractDocumentUrl();
const reglamentoUrl = String(contract.reglamento_pdf_url || '').trim() || REGLEMENTO_INSTITUCIONAL_2027_PDF_URL;
const reglamentoLinkLabel = String(contract.reglamento_link_label || '').trim() || 'Leer REGLAMENTO INSTITUCIONAL 2027';
const institucionContrato = getResult.institucion_contrato || {};
const responsableEmail = String(getResult.responsable_email || '');
const curso = String(getResult.curso || institucionContrato.curso || '');
let contractReadGate = { bothRead: () => false };
const swalPreambleIds = {
  declarantName: 'contractDeclarantName',
  declarantDni: 'contractDeclarantDni',
  declarantDomicilio: 'contractDeclarantDomicilio',
  declarantLocalidad: 'contractDeclarantLocalidad',
};
const swalFieldIds = {
  ...swalPreambleIds,
  password: 'contractPassword',
  checkbox: 'contractCheckbox',
};
let refreshSwalForm = () => {};

const swalApi = getSwalApi();
let modalResult;
if (swalApi) {
modalResult = await swalApi.run({
title: '',
width: 700,
showCancelButton: true,
confirmButtonText: 'FIRMAR CONTRATO',
cancelButtonText: 'Cancelar',
focusConfirm: false,
showLoaderOnConfirm: true,
html: `
${htmlContractModalHeader({ codigoEscuela: institucionContrato.codigo, curso })}
<div class="contract-modal-body" style="text-align:left">
  ${htmlContractModalInfoSection({
    responsableEmail,
    studentName,
    studentDni,
    curso,
    contractVersion: version,
    hash,
  })}
  ${htmlContractPreambleSection(institucionContrato, swalPreambleIds, { swalInputs: true })}
  ${htmlContractModalLinksSection(contractPreviewUrl, reglamentoUrl, {
    contratoLinkId: 'contractPdfLink',
    reglamentoLinkId: 'contractReglamentoPdfLink',
    reglamentoLinkLabel,
  })}
  ${htmlContractModalCheckboxSection('contractCheckbox', 'contractReadHint')}
  <label for="contractPassword"><strong>Reconfirmar contraseña</strong></label>
  <input id="contractPassword" type="password" class="swal2-input" style="margin:8px 0 0 0;"
    placeholder="Ingrese nuevamente su contraseña" />
  ${htmlContractModalErrorBox('contractSignError')}
</div>
`,
didOpen: () => {
  initContractReadGateOnModalOpen((gate) => {
    contractReadGate = gate;
  }, {
    contratoLinkId: 'contractPdfLink',
    reglamentoLinkId: 'contractReglamentoPdfLink',
    checkboxId: 'contractCheckbox',
    hintId: 'contractReadHint',
  }, {
    onReady: () => refreshSwalForm(),
    studentDni,
    preambleFieldIds: swalPreambleIds,
    csrfToken,
  });
  const root = findSwalModalRoot();
  if (root) {
    refreshSwalForm = bindContractSignFormWatchers(root, swalFieldIds, () => contractReadGate, 'contractSignError');
  }
},
preConfirm: () => {
  const root = findSwalModalRoot() || document;
  const values = getContractSignFormValues(root, swalFieldIds);
  const validation = validateContractSignForm(values, contractReadGate);
  if (!validation.ok) {
    showContractInlineError('contractSignError', validation.msg);
    return rejectContractValidation(validation.msg);
  }
  showContractInlineError('contractSignError', '');

  const acceptForm = new FormData();
  acceptForm.append('csrf_token', csrfToken || '');
  acceptForm.append('student_dni', studentDni);
  acceptForm.append('nro_legajo', nroLegajo);
  acceptForm.append('accepted_checkbox', '1');
  acceptForm.append('password', values.password);
  acceptForm.append('declarant_name', values.declarantName);
  acceptForm.append('declarant_dni', values.declarantDni);
  acceptForm.append('declarant_domicilio', values.declarantDomicilio);
  acceptForm.append('declarant_localidad', values.declarantLocalidad);

  return fetch(AJAX_CONTRACT_ACCEPT, { method: 'POST', body: acceptForm })
    .then((acceptResp) => acceptResp.json())
    .then((acceptResult) => {
      if (!acceptResult.ok) {
        showContractInlineError('contractSignError', acceptResult.msg || 'No se pudo registrar la aceptación.');
        return rejectContractValidation(acceptResult.msg || 'No se pudo registrar la aceptación.');
      }
      return acceptResult;
    });
}
});
} else {
const fallbackResult = await openContractFallbackModal({
studentName,
studentDni,
curso,
responsableEmail,
version,
hash,
contractPreviewUrl,
reglamentoUrl,
  reglamentoLinkLabel,
  institucionContrato,
  csrfToken,
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
acceptForm.append('declarant_domicilio', fallbackResult.value.declarantDomicilio);
acceptForm.append('declarant_localidad', fallbackResult.value.declarantLocalidad);

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

const acceptedPayload = (modalResult && modalResult.value && typeof modalResult.value === 'object')
  ? modalResult.value
  : modalResult;
const acceptedSuccessfully = !!(acceptedPayload && acceptedPayload.ok === true);

if (acceptedSuccessfully) {
applyStatusToContractControl(button, {
signed: true,
admin_aprobado: false,
es_inactivo: false,
estado_flujo: 'pendiente_aprobacion',
requisitos: {
  firma: true,
  documentacion: false,
  adelanto_rv: { aplica: true, cumplido: false, disponible: false },
  resto_rv: { aplica: true, cumplido: false, disponible: false },
  sin_deudas_familia: { aplica: true, cumplido: false, disponible: false },
  todos_cumplidos: false,
},
});
showSuccess(String(acceptedPayload.msg || 'La firma del contrato quedó registrada correctamente.'));
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