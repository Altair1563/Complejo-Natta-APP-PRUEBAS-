/**
 * Modal para compartir la app: genera el QR en el cliente con qrcodejs (sin APIs externas).
 */
export function initQrShareModal(appUrl) {
  const openBtn = document.querySelector('.btn-compartir-qr');
  const modal = document.getElementById('modalQrShare');
  const closeBtn = document.getElementById('cerrarModalQr');
  const copyBtn = document.getElementById('btnCopiarUrlApp');
  const qrContainer = document.getElementById('qrcode');

  if (!modal || !qrContainer || !appUrl) return;

  let qrRendered = false;

  function renderQr() {
    if (qrRendered || typeof window.QRCode === 'undefined') return;
    qrContainer.innerHTML = '';
    // eslint-disable-next-line no-new
    new window.QRCode(qrContainer, {
      text: appUrl,
      width: 256,
      height: 256,
      colorDark: '#0c3484',
      colorLight: '#ffffff',
    });
    qrRendered = true;
  }

  function openModal(e) {
    if (e) e.preventDefault();
    renderQr();
    modal.style.display = 'flex';
    modal.setAttribute('aria-hidden', 'false');
  }

  function closeModal() {
    modal.style.display = 'none';
    modal.setAttribute('aria-hidden', 'true');
  }

  if (openBtn) {
    openBtn.addEventListener('click', openModal);
  }

  if (closeBtn) {
    closeBtn.addEventListener('click', closeModal);
  }

  modal.addEventListener('click', (e) => {
    if (e.target === modal) closeModal();
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && modal.style.display !== 'none') {
      closeModal();
    }
  });

  if (copyBtn) {
    copyBtn.addEventListener('click', async () => {
      try {
        await navigator.clipboard.writeText(appUrl);
        copyBtn.textContent = '¡Enlace copiado!';
        setTimeout(() => {
          copyBtn.textContent = 'Copiar enlace';
        }, 2000);
      } catch {
        window.prompt('Copiá este enlace:', appUrl);
      }
    });
  }
}
