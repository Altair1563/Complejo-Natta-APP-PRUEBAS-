/**
 * Helpers para incluir token CSRF en peticiones.
 * El token debe inyectarse desde PHP o leerse de un input con id csrf_token.
 */
export function getCsrfToken() {
  const input = document.getElementById('csrf_token');
  return input ? input.value : '';
}

export function appendCsrfToFormData(formData) {
  const token = getCsrfToken();
  if (token) formData.append('csrf_token', token);
  return formData;
}
