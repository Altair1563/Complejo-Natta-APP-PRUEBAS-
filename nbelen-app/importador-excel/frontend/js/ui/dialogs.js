/**
 * Helpers para SweetAlert2 (swal / Swal). Asume que SweetAlert2 está cargado globalmente.
 */
function getSwal() {
  return window.Swal || window.swal;
}

export function showSuccess(message, title = 'Éxito') {
  const Swal = getSwal();
  if (Swal && typeof Swal.fire === 'function') {
    Swal.fire({ icon: 'success', title, text: message, timer: 2000, showConfirmButton: false });
  } else {
    alert(message);
  }
}

export function showError(message, title = 'Error') {
  const Swal = getSwal();
  if (Swal && typeof Swal.fire === 'function') {
    Swal.fire({ icon: 'error', title, text: message });
  } else {
    alert(message);
  }
}

export function confirmAction(options) {
  const Swal = getSwal();
  const defaults = {
    title: '¿Estás seguro?',
    showCancelButton: true,
    confirmButtonColor: '#03A9F4',
    cancelButtonColor: '#F44336',
    confirmButtonText: 'Sí',
    cancelButtonText: 'Cancelar'
  };
  if (Swal && typeof Swal.fire === 'function') {
    return Swal.fire({ ...defaults, ...options });
  }
  return Promise.resolve({ isConfirmed: confirm(options.text || defaults.title) });
}

export function confirmLogout(logoutUrl) {
  return confirmAction({
    title: 'Estas seguro?',
    text: 'La sesión actual se cerrará',
    icon: 'warning',
    confirmButtonText: '<i class="zmdi zmdi-run"></i> Sí, Salir!',
    cancelButtonText: '<i class="zmdi zmdi-close-circle"></i> Cancelar!'
  }).then((result) => {
    if (result && result.isConfirmed) window.location.href = logoutUrl;
  });
}

export function openSearchDialog() {
  const Swal = getSwal();
  if (!Swal || typeof Swal.fire !== 'function') return;
  Swal.fire({
    title: 'What are you looking for?',
    confirmButtonText: '<i class="zmdi zmdi-search"></i> Search',
    confirmButtonColor: '#03A9F4',
    showCancelButton: true,
    cancelButtonColor: '#F44336',
    cancelButtonText: '<i class="zmdi zmdi-close-circle"></i> Cancel',
    html: '<div class="form-group label-floating">' +
      '<label class="control-label" for="InputSearch">write here</label>' +
      '<input class="form-control" id="InputSearch" type="text">' +
      '</div>'
  }).then(() => {
    const val = document.getElementById('InputSearch')?.value || '';
    Swal.fire('You wrote', val, 'success');
  });
}
