/**
 * Modal de pagos reutilizable: familia, alumno total y cuota específica.
 *
 * Usado por homePage e infoImportantePage que comparten la misma lógica de
 * selección de opciones de pago, carga de cuotas y detalle de transferencia.
 */
import { showSuccess } from './dialogs.js';
import { AJAX_CUOTAS } from '../config/apiEndpoints.js';

export function initPaymentModal({ alumnos = [], saldoTotalFamiliar = 0, nroFamilia = '', csrfToken = '' } = {}) {
  const overlay      = document.getElementById('overlay');
  const modal        = document.getElementById('modalPago');
  const cerrarBtn    = document.getElementById('cerrarModal');
  const detalle      = document.getElementById('detalleSeleccion');
  const textoDetalle = document.getElementById('textoDetalle');
  const textoArrastre = document.getElementById('textoArrastre');
  const montoPago    = document.getElementById('montoPago');
  const modalTipo    = document.getElementById('modalTipo');
  const modalId      = document.getElementById('modalId');
  const modalMonto   = document.getElementById('modalMonto');
  const modalCuotaId = document.getElementById('modalCuotaId');
  const btnCopiarCBU = document.getElementById('btnCopiarCBU');

  window.cuotasAlumnoActual = [];
  window.legajoCuotasActual = '';

  function limpiarNodo(node) {
    if (node) node.textContent = '';
  }

  function estadoSeguro(estado) {
    const val = String(estado || '').toLowerCase();
    return val === 'pagada' || val === 'parcial' || val === 'pendiente' ? val : 'pendiente';
  }

  function abrirModal({ id = '', monto = '', tipo = '' } = {}) {
    if (modal) modal.style.display = 'block';
    if (overlay) overlay.style.display = 'block';
    inicializarSelectores();
    const montoNum = monto !== '' ? Number(monto) : null;
    if (montoNum !== null && modalMonto) modalMonto.value = montoNum;
    if (montoNum !== null && montoPago) montoPago.textContent = Number(montoNum).toFixed(2);
    if (id && modalId) modalId.value = id;
    if (tipo && modalTipo) modalTipo.value = tipo;
    document.querySelectorAll('.modal-section').forEach(sec => { sec.style.display = 'none'; });
    if (detalle) detalle.style.display = 'none';
    if (textoArrastre) textoArrastre.textContent = '';
  }

  function cerrarModal() {
    if (modal) modal.style.display = 'none';
    if (overlay) overlay.style.display = 'none';
    if (detalle) detalle.style.display = 'none';
    if (textoArrastre) textoArrastre.textContent = '';
    document.querySelectorAll('.modal-section').forEach(sec => { sec.style.display = 'none'; });
  }

  function inicializarSelectores() {
    const selectorTotal = document.getElementById('selectorAlumnoTotal');
    const selectorCuota = document.getElementById('selectorAlumnoCuota');
    if (!selectorTotal || !selectorCuota) return;
    limpiarNodo(selectorTotal);
    limpiarNodo(selectorCuota);
    selectorTotal.appendChild(new Option('Seleccione un alumno', ''));
    selectorCuota.appendChild(new Option('Seleccione un alumno', ''));
    alumnos.forEach(alumno => {
      selectorTotal.appendChild(new Option(alumno.nombre_completo + ' - $' + (Number(alumno.saldo) || 0).toFixed(2), alumno.legajo));
      selectorCuota.appendChild(new Option(alumno.nombre_completo, alumno.legajo));
    });
  }

  function actualizarReferenciaTransferencia() {
    const tipoVal = modalTipo ? modalTipo.value : '';
    const idVal   = modalId ? modalId.value : '';
    const montoVal = montoPago ? montoPago.textContent : '0.00';
    const legajoRef = document.getElementById('legajoReferencia');
    if (legajoRef) legajoRef.textContent = idVal || '—';
    document.querySelectorAll('input[name="id_relacionado"]').forEach(input => { try { input.value = idVal; } catch (_) {} });
    document.querySelectorAll('input[name="monto"]').forEach(input => { try { input.value = montoVal; } catch (_) {} });
    document.querySelectorAll('input[name="tipo_pago"]').forEach(input => { try { input.value = tipoVal; } catch (_) {} });
  }

  function mostrarResumenFamiliar() {
    const contenedor = document.getElementById('resumenFamiliar');
    if (!contenedor) return;
    limpiarNodo(contenedor);
    const titulo = document.createElement('h5');
    titulo.textContent = 'Detalle del Grupo Familiar:';
    contenedor.appendChild(titulo);
    if (alumnos.length) {
      let tieneSaldo = false;
      alumnos.forEach(alumno => {
        const s = Number(alumno.saldo) || 0;
        if (s > 0) {
          tieneSaldo = true;
          const p = document.createElement('p');
          p.textContent = `${alumno.nombre_completo} - Curso ${alumno.curso}: `;
          const strong = document.createElement('strong');
          strong.textContent = `$${s.toFixed(2)}`;
          p.appendChild(strong);
          contenedor.appendChild(p);
        }
      });
      if (!tieneSaldo) {
        const p = document.createElement('p');
        p.textContent = 'No hay saldos pendientes en el grupo familiar.';
        contenedor.appendChild(p);
      } else {
        const total = document.createElement('div');
        total.className = 'total-final';
        total.textContent = `Total Familiar: $${Number(saldoTotalFamiliar).toFixed(2)}`;
        contenedor.appendChild(total);
      }
    } else {
      const p = document.createElement('p');
      p.textContent = 'No hay alumnos en el grupo familiar.';
      contenedor.appendChild(p);
    }
    if (textoDetalle) textoDetalle.textContent = 'Pago de TODO el total familiar';
    if (textoArrastre) textoArrastre.textContent = '';
    if (montoPago) montoPago.textContent = Number(saldoTotalFamiliar).toFixed(2);
    if (modalTipo) modalTipo.value = 'familia';
    if (modalId) modalId.value = nroFamilia;
    if (modalMonto) modalMonto.value = Number(saldoTotalFamiliar);
    if (detalle) detalle.style.display = 'block';
    actualizarReferenciaTransferencia();
  }

  function configurarSelectorAlumnoTotal() {
    const selector = document.getElementById('selectorAlumnoTotal');
    if (!selector) return;
    selector.onchange = function () {
      const legajo = this.value;
      const alumno = alumnos.find(a => a.legajo == legajo);
      const contenedor = document.getElementById('resumenAlumnoTotal');
      if (alumno && contenedor) {
        const saldo = Number(alumno.saldo) || 0;
        limpiarNodo(contenedor);
        const h5 = document.createElement('h5');
        h5.textContent = alumno.nombre_completo;
        const pCurso = document.createElement('p');
        pCurso.textContent = `Curso: ${alumno.curso}`;
        const pTotal = document.createElement('p');
        const strong = document.createElement('strong');
        strong.textContent = `Total a pagar: $${saldo.toFixed(2)}`;
        pTotal.appendChild(strong);
        contenedor.appendChild(h5);
        contenedor.appendChild(pCurso);
        contenedor.appendChild(pTotal);
        if (textoDetalle) textoDetalle.textContent = `Pago total del alumno: ${alumno.nombre_completo}`;
        if (textoArrastre) textoArrastre.textContent = '';
        if (montoPago) montoPago.textContent = saldo.toFixed(2);
        if (modalTipo) modalTipo.value = 'alumno_total';
        if (modalId) modalId.value = legajo;
        if (modalMonto) modalMonto.value = saldo;
        if (detalle) detalle.style.display = 'block';
        actualizarReferenciaTransferencia();
      }
    };
  }

  function configurarSelectorAlumnoCuota() {
    const selector = document.getElementById('selectorAlumnoCuota');
    if (!selector) return;
    selector.onchange = function () {
      const legajo = this.value;
      if (legajo) {
        cargarCuotasAlumno(legajo);
        if (detalle) detalle.style.display = 'none';
        if (textoArrastre) textoArrastre.textContent = '';
      } else {
        const lista = document.getElementById('listaCuotas');
        if (lista) lista.textContent = '';
      }
    };
  }

  async function cargarCuotasAlumno(legajo) {
    const contenedor = document.getElementById('listaCuotas');
    if (!contenedor) return;
    limpiarNodo(contenedor);
    const cargando = document.createElement('p');
    cargando.textContent = 'Cargando cuotas...';
    contenedor.appendChild(cargando);
    try {
      const form = new FormData();
      form.append('legajo', legajo);
      if (csrfToken) form.append('csrf_token', csrfToken);
      const resp = await fetch(AJAX_CUOTAS, { method: 'POST', body: form });
      if (!resp.ok) throw new Error('Error servidor: ' + resp.status);
      const cuotas = await resp.json();
      window.cuotasAlumnoActual = Array.isArray(cuotas) ? cuotas : [];
      window.legajoCuotasActual = legajo;
      limpiarNodo(contenedor);
      const h5 = document.createElement('h5');
      h5.textContent = 'Cuotas del Alumno:';
      contenedor.appendChild(h5);
      if (cuotas && cuotas.length) {
        cuotas.forEach(cuota => {
          const saldoPendiente = Number(cuota.pendiente || cuota.diferencia || 0);
          const descripcion = String(cuota.descripcion || '');
          const estado = estadoSeguro(cuota.estado);

          const item = document.createElement('div');
          item.className = `cuota-item cuota-${estado} pm-cuota-item`;
          item.setAttribute('data-cuota-id', String(Number(cuota.id) || 0));
          item.setAttribute('data-monto', String(saldoPendiente));
          item.setAttribute('data-descripcion', descripcion);

          const strong = document.createElement('strong');
          strong.textContent = descripcion;
          item.appendChild(strong);
          item.appendChild(document.createElement('br'));

          const detalleMontos = document.createTextNode(
            `Monto: $${Number(cuota.monto).toFixed(2)} | Pagado: $${Number(cuota.pagado).toFixed(2)} | Pendiente: $${saldoPendiente.toFixed(2)}`
          );
          item.appendChild(detalleMontos);
          item.appendChild(document.createElement('br'));

          const small = document.createElement('small');
          small.textContent = `Estado: ${estado === 'pagada' ? 'Pagada' : estado === 'parcial' ? 'Parcial' : 'Pendiente'}`;
          item.appendChild(small);

          contenedor.appendChild(item);
        });
      } else {
        const p = document.createElement('p');
        p.textContent = 'No hay cuotas pendientes.';
        contenedor.appendChild(p);
      }
    } catch (_error) {
      limpiarNodo(contenedor);
      const p = document.createElement('p');
      p.textContent = 'Error al cargar las cuotas. Revisa la consola para más información.';
      contenedor.appendChild(p);
    }
  }

  function seleccionarCuotaModal(idCuota, monto, descripcion) {
    try {
      document.querySelectorAll('#listaCuotas .pm-cuota-item').forEach(item => item.classList.remove('cuota-seleccionada'));
      const elemento = document.querySelector(`#listaCuotas .pm-cuota-item[data-cuota-id="${idCuota}"]`);
      if (elemento) elemento.classList.add('cuota-seleccionada');
      const selectorAlumnoCuota = document.getElementById('selectorAlumnoCuota');
      const alumnoNombre = selectorAlumnoCuota ? selectorAlumnoCuota.options[selectorAlumnoCuota.selectedIndex].text : 'Alumno';
      const legajoAlumno = selectorAlumnoCuota ? selectorAlumnoCuota.value : window.legajoCuotasActual || '';
      const cuotas = Array.isArray(window.cuotasAlumnoActual) ? window.cuotasAlumnoActual : [];
      let arrastre = 0;
      const detallesArrastre = [];
      const idxSel = cuotas.findIndex(c => Number(c.id) === Number(idCuota));
      if (idxSel > -1) {
        for (let i = 0; i < idxSel; i++) {
          const c = cuotas[i];
          const pend = Number(c.pendiente || c.diferencia || 0);
          if (pend > 0.0001) {
            arrastre += pend;
            detallesArrastre.push(`${c.descripcion}: $${pend.toFixed(2)}`);
          }
        }
      }
      const total = (Number(monto) || 0) + arrastre;
      if (textoDetalle) textoDetalle.textContent = `Pago de cuota: ${descripcion} - ${alumnoNombre}`;
      if (montoPago) montoPago.textContent = total.toFixed(2);
      if (modalTipo) modalTipo.value = 'cuota';
      if (modalId) modalId.value = legajoAlumno;
      if (modalCuotaId) modalCuotaId.value = idCuota;
      if (modalMonto) modalMonto.value = total;
      if (detalle) detalle.style.display = 'block';
      if (textoArrastre) {
        textoArrastre.textContent = arrastre > 0
          ? `Esta cuota incluye arrastre de saldos anteriores (${detallesArrastre.join(' | ')}). Si desea abonar un importe menor, deberá comenzar pagando la cuota más atrasada.`
          : '';
      }
      actualizarReferenciaTransferencia();
    } catch (err) {
      console.error('Error seleccionando cuota:', err);
    }
  }

  const listaCuotas = document.getElementById('listaCuotas');
  if (listaCuotas) {
    listaCuotas.addEventListener('click', (e) => {
      const item = e.target.closest('.pm-cuota-item');
      if (!item) return;
      const idCuota = Number(item.getAttribute('data-cuota-id'));
      const monto = Number(item.getAttribute('data-monto') || 0);
      const descripcion = item.getAttribute('data-descripcion') || '';
      seleccionarCuotaModal(idCuota, monto, descripcion);
    });
  }

  function manejarOpcionPago(tipo) {
    document.querySelectorAll('.modal-section').forEach(sec => { sec.style.display = 'none'; });
    if (detalle) detalle.style.display = 'none';
    if (textoArrastre) textoArrastre.textContent = '';
    if (tipo === 'familia') {
      const sec = document.getElementById('seccionFamilia');
      if (sec) { sec.style.display = 'block'; mostrarResumenFamiliar(); }
    } else if (tipo === 'alumno') {
      const sec = document.getElementById('seccionAlumno');
      if (sec) { sec.style.display = 'block'; configurarSelectorAlumnoTotal(); }
    } else if (tipo === 'cuota') {
      const sec = document.getElementById('seccionCuota');
      if (sec) { sec.style.display = 'block'; configurarSelectorAlumnoCuota(); }
    }
  }

  function copiarCBU() {
    const cbuEl = document.getElementById('cbuText');
    const CBU = cbuEl ? cbuEl.textContent.trim() : '0140090801518401415855';
    if (!navigator.clipboard) {
      const tempInput = document.createElement('input');
      tempInput.value = CBU;
      document.body.appendChild(tempInput);
      tempInput.select();
      try { document.execCommand('copy'); alert('CBU copiado al portapapeles'); }
      catch (_) { alert('No se pudo copiar automáticamente. Copialo manualmente.'); }
      document.body.removeChild(tempInput);
      return;
    }
    navigator.clipboard.writeText(CBU)
      .then(() => showSuccess('CBU copiado', 'CBU copiado'))
      .catch(() => alert('No se pudo copiar el CBU. Copialo manualmente.'));
  }

  // Bind events
  document.querySelectorAll('.btn-opcion').forEach(btn => {
    btn.addEventListener('click', function () { manejarOpcionPago(this.getAttribute('data-tipo')); });
  });

  const botonesAbrir = document.querySelectorAll('#btnPagarGeneral1, #btnPagarGeneral2');
  botonesAbrir.forEach(btn => {
    btn.addEventListener('click', function () {
      abrirModal({
        id: btn.getAttribute('data-id') || '',
        monto: btn.getAttribute('data-monto') || '',
        tipo: btn.getAttribute('data-tipo') || ''
      });
    });
  });

  if (cerrarBtn) cerrarBtn.addEventListener('click', cerrarModal);
  if (overlay) overlay.addEventListener('click', (e) => { if (e.target === overlay) cerrarModal(); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') cerrarModal(); });
  if (btnCopiarCBU) btnCopiarCBU.addEventListener('click', copiarCBU);

  return { abrirModal, cerrarModal };
}
