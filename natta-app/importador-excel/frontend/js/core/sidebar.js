/**
 * Sidebar: estado inicial según viewport y toggle con .btn-menu-dashboard y .dashboard-sideBar-bg.
 * Compatible con breakpoints usados en las vistas (315, 768, 991).
 */
function setInitialSidebar() {
  const sidebar = document.querySelector('.dashboard-sideBar');
  const bodyContent = document.querySelector('.dashboard-contentPage');
  if (!sidebar) return;

  const w = window.innerWidth;
  if (w <= 768) {
    sidebar.classList.add('hide-sidebar');
    sidebar.classList.remove('show-sidebar');
    if (bodyContent) bodyContent.classList.add('no-paddin-left');
  } else {
    sidebar.classList.remove('hide-sidebar');
    sidebar.classList.add('show-sidebar');
    if (bodyContent) bodyContent.classList.remove('no-paddin-left');
  }
}

function toggleSidebar() {
  const sidebar = document.querySelector('.dashboard-sideBar');
  const bodyContent = document.querySelector('.dashboard-contentPage');
  if (!sidebar) return;

  const w = window.innerWidth;
  if (w < 315) {
    sidebar.classList.toggle('show-sidebar');
  } else if (w <= 991) {
    sidebar.classList.toggle('hide-sidebar');
    if (bodyContent) bodyContent.classList.toggle('no-paddin-left');
  } else {
    sidebar.classList.toggle('hide-sidebar');
    if (bodyContent) bodyContent.classList.toggle('no-paddin-left');
  }
}

export function initSidebar() {
  setInitialSidebar();
  window.addEventListener('resize', setInitialSidebar);

  document.querySelectorAll('.btn-menu-dashboard').forEach(btn => {
    btn.addEventListener('click', (e) => {
      e.preventDefault();
      toggleSidebar();
    });
  });

  const sideBarBg = document.querySelector('.dashboard-sideBar-bg');
  if (sideBarBg) {
    sideBarBg.addEventListener('click', (e) => {
      e.preventDefault();
      const sidebar = document.querySelector('.dashboard-sideBar');
      const bodyContent = document.querySelector('.dashboard-contentPage');
      const w = window.innerWidth;
      if (w < 315 && sidebar) sidebar.classList.remove('show-sidebar');
      else if (w <= 991 && sidebar) {
        sidebar.classList.add('hide-sidebar');
        if (bodyContent) bodyContent.classList.add('no-paddin-left');
      }
    });
  }
}

export function initSubmenus() {
  if (document.body.dataset.submenuDelegationBound === '1') return;
  document.body.dataset.submenuDelegationBound = '1';

  document.addEventListener(
    'click',
    (e) => {
      const btn = e.target.closest('.btn-sideBar-SubMenu');
      if (!btn || !document.body.contains(btn)) return;
      if (!e.target.closest('.dashboard-sideBar')) return;

      e.preventDefault();
      const li = btn.closest('li');
      let subMenu = null;
      if (li) {
        for (const child of li.children) {
          if (child.tagName === 'UL') {
            subMenu = child;
            break;
          }
        }
      }
      if (!subMenu) {
        subMenu = btn.nextElementSibling;
      }
      const iconBtn = btn.querySelector('.zmdi-caret-down');
      if (subMenu) {
        subMenu.classList.toggle('show-sideBar-SubMenu');
        if (iconBtn) iconBtn.classList.toggle('zmdi-hc-rotate-180', subMenu.classList.contains('show-sideBar-SubMenu'));
      }
    },
    false
  );
}
