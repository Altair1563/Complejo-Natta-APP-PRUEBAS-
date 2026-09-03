/**
 * Toggle del panel de notificaciones (.Notifications-area).
 * El contenido del panel lo cargan los módulos de cada página.
 */
export function initNotificationsToggle() {
  const btn = document.querySelector('.btn-Notifications-area');
  const area = document.querySelector('.Notifications-area');
  if (!btn || !area) return;

  btn.addEventListener('click', function (e) {
    e.preventDefault();
    if (area.style.opacity === '0' || getComputedStyle(area).opacity === '0') {
      area.classList.add('show-Notification-area');
    } else {
      area.classList.remove('show-Notification-area');
    }
  });
}
