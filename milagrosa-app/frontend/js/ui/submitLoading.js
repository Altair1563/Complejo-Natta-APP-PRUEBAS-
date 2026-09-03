/**
 * Indicador de carga en botones de consulta / búsqueda / envío de formularios GET.
 */

export function getSubmitButton(form) {
  if (!form) return null;
  return form.querySelector('.btn-consultar[type="submit"]');
}

export function setButtonLoading(button, loading) {
  if (!button) return;
  if (loading) {
    button.classList.add('is-loading');
    button.setAttribute('aria-busy', 'true');
    setTimeout(() => {
      button.disabled = true;
    }, 0);
    return;
  }
  button.classList.remove('is-loading');
  button.removeAttribute('aria-busy');
  button.disabled = false;
}

export function restoreAllSubmitButtons() {
  document.querySelectorAll('.btn-consultar.is-loading').forEach((button) => {
    setButtonLoading(button, false);
  });
}

/**
 * @param {HTMLFormElement | null} form
 * @param {{ validate?: (event: SubmitEvent) => boolean }} [options]
 */
export function initSubmitLoading(form, options = {}) {
  const button = getSubmitButton(form);
  if (!form || !button) return;

  form.addEventListener('submit', (event) => {
    if (typeof options.validate === 'function' && !options.validate(event)) {
      event.preventDefault();
      return;
    }
    setButtonLoading(button, true);
  });
}

/**
 * Muestra el spinner cuando un select dispara el envío del formulario.
 * @param {HTMLFormElement | null} form
 * @param {HTMLSelectElement | null} select
 * @param {{ shouldSubmit?: () => boolean }} [options]
 */
export function initSelectSubmitLoading(form, select, options = {}) {
  if (!form || !select) return;

  select.addEventListener('change', () => {
    const shouldSubmit = typeof options.shouldSubmit === 'function'
      ? options.shouldSubmit()
      : true;
    if (!shouldSubmit) return;

    setButtonLoading(getSubmitButton(form), true);
    form.submit();
  });
}

export function initPageshowSubmitRestore() {
  window.addEventListener('pageshow', restoreAllSubmitButtons);
}
