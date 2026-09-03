/**
 * Helpers para fetch: cabeceras comunes y parsing JSON.
 * Respuestas típicas: { success, message }, { ok, msg }, { error }, o array.
 */

export async function getJson(url, options = {}) {
  const res = await fetch(url, {
    ...options,
    headers: { 'X-Requested-With': 'XMLHttpRequest', ...options.headers }
  });
  if (!res.ok) throw new Error(`HTTP ${res.status}`);
  return res.json();
}

export async function postForm(url, formData, options = {}) {
  const res = await fetch(url, {
    method: 'POST',
    body: formData,
    ...options,
    headers: { 'X-Requested-With': 'XMLHttpRequest', ...options.headers }
  });
  if (!res.ok) throw new Error(`HTTP ${res.status}`);
  return res.json();
}

export async function postJson(url, body, options = {}) {
  const res = await fetch(url, {
    method: 'POST',
    body: JSON.stringify(body),
    ...options,
    headers: {
      'Content-Type': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      ...options.headers
    }
  });
  if (!res.ok) throw new Error(`HTTP ${res.status}`);
  return res.json();
}
