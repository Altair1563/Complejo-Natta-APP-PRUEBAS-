/**
 * Ejecuta callback cuando el DOM está listo (equivalente a DOMContentLoaded).
 */
export function onDomReady(fn) {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', fn);
  } else {
    fn();
  }
}
