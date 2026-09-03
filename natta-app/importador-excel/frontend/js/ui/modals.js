/**
 * Modal de ayuda (#Dialog-Help). Si existe overlay, lo muestra/oculta junto con el modal.
 */
export function initHelpModal() {
  const helpBtns = document.querySelectorAll('.btn-modal-help');
  const helpModal = document.getElementById('Dialog-Help');
  const overlay = document.getElementById('overlay');

  if (!helpBtns.length || !helpModal) return;

  helpBtns.forEach((helpBtn) => {
    helpBtn.addEventListener('click', (e) => {
      e.preventDefault();
      helpModal.style.display = 'block';
      if (overlay) overlay.style.display = 'block';
    });
  });

  helpModal.querySelectorAll('[data-dismiss="modal"], .close').forEach(btn => {
    btn.addEventListener('click', (e) => {
      e.preventDefault();
      helpModal.style.display = 'none';
      if (overlay) overlay.style.display = 'none';
    });
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && helpModal.style.display === 'block') {
      helpModal.style.display = 'none';
      if (overlay) overlay.style.display = 'none';
    }
  });
}
