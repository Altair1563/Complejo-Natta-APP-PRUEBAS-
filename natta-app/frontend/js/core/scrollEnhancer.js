/**
 * Inicializa mCustomScrollbar en sidebar, content y notifications.
 * En dispositivos táctiles destruye el plugin y usa scroll nativo con -webkit-overflow-scrolling: touch.
 * Depende de jQuery y jquery.mCustomScrollbar cargados en la página.
 *
 * @param {() => void} [onReady] Se ejecuta después de aplicar scroll (p. ej. submenús del sidebar: el plugin puede recrear nodos).
 */
export function initScrollEnhancer(onReady) {
  const $ = window.jQuery || window.$;

  function notifyReady() {
    if (typeof onReady === 'function') {
      try {
        onReady();
      } catch (_e) { /* ignore */ }
    }
  }

  if (!$) {
    notifyReady();
    return;
  }

  function run() {
    try {
      const isTouch = 'ontouchstart' in window || navigator.maxTouchPoints > 0 || navigator.msMaxTouchPoints > 0;
      const $sidebarCt = $('.dashboard-sideBar-ct');
      const $content = $('.dashboard-contentPage');
      const $notifications = $('.Notifications-body');

      if (isTouch) {
        try {
          $sidebarCt.add($content).add($notifications).mCustomScrollbar('destroy');
        } catch (e) { /* ignore */ }
        $sidebarCt.add($content).add($notifications).css({
          'overflow-y': 'auto',
          '-webkit-overflow-scrolling': 'touch'
        });
        return;
      }

      $sidebarCt.mCustomScrollbar({
        theme: 'light-thin',
        scrollbarPosition: 'inside',
        autoHideScrollbar: true,
        scrollInertia: 180,
        mouseWheel: { scrollAmount: 120, normalizeDelta: true },
        scrollButtons: { enable: false }
      });
      $content.add($notifications).mCustomScrollbar({
        theme: 'dark-thin',
        scrollbarPosition: 'inside',
        autoHideScrollbar: true,
        scrollInertia: 180,
        mouseWheel: { scrollAmount: 120, normalizeDelta: true },
        scrollButtons: { enable: false }
      });
    } finally {
      notifyReady();
    }
  }

  if (document.readyState === 'complete') run();
  else window.addEventListener('load', run);
}
