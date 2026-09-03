/**
 * @deprecated Las vistas deben usar:
 *   <script type="module" src="./frontend/js/index.js"></script>
 * Este archivo mantiene compatibilidad cargando el bundle modular.
 */
(function () {
  var s = document.createElement('script');
  s.type = 'module';
  s.src = './frontend/js/index.js';
  document.head.appendChild(s);
})();
