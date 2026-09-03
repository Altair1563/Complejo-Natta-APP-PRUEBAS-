/**
 * Sección admin: generar, descargar y compartir el QR de la app (qrcodejs en el cliente).
 */
export function initAdminQrApp(appUrl, showToast) {
  const container = document.getElementById('admin-qrcode');
  if (!container || !appUrl) return;

  if (typeof window.QRCode === 'undefined') {
    container.textContent = 'No se pudo cargar la librería de QR. Recargá la página.';
    return;
  }

  container.innerHTML = '';
  // eslint-disable-next-line no-new
  new window.QRCode(container, {
    text: appUrl,
    width: 280,
    height: 280,
    colorDark: '#0c3484',
    colorLight: '#ffffff',
  });

  const getQrImg = () => container.querySelector('img');

  document.getElementById('btnDescargarQr')?.addEventListener('click', () => {
    const img = getQrImg();
    if (!img?.src) return;
    const link = document.createElement('a');
    link.download = 'nbelen-app-qr.png';
    link.href = img.src;
    link.click();
    showToast?.('QR descargado', 'success');
  });

  document.getElementById('btnCopiarUrlQr')?.addEventListener('click', async () => {
    const btn = document.getElementById('btnCopiarUrlQr');
    try {
      await navigator.clipboard.writeText(appUrl);
      if (btn) {
        const prev = btn.textContent;
        btn.textContent = '¡Enlace copiado!';
        setTimeout(() => { btn.textContent = prev; }, 2000);
      }
      showToast?.('Enlace copiado al portapapeles', 'success');
    } catch {
      window.prompt('Copiá este enlace:', appUrl);
    }
  });

  document.getElementById('btnCompartirQr')?.addEventListener('click', async () => {
    const img = getQrImg();

    if (navigator.share && img?.src) {
      try {
        const blob = await fetch(img.src).then((r) => r.blob());
        const file = new File([blob], 'nbelen-app-qr.png', { type: blob.type || 'image/png' });
        const shareData = {
          title: 'App Nuestra Señora de Itati',
          text: `Accedé a la APP de Nuestra Señora de Itati: ${appUrl}`,
          url: appUrl,
        };
        if (navigator.canShare?.({ files: [file] })) {
          await navigator.share({ ...shareData, files: [file] });
        } else {
          await navigator.share(shareData);
        }
        return;
      } catch (err) {
        if (err?.name === 'AbortError') return;
      }
    }

    try {
      await navigator.clipboard.writeText(appUrl);
      showToast?.('Enlace copiado. Pegalo donde quieras compartir.', 'info');
    } catch {
      window.prompt('Copiá este enlace para compartir:', appUrl);
    }
  });
}

/**
 * Generador de QR a partir de una URL/texto que ingresa el admin.
 * Muestra el QR y permite descargarlo o compartirlo.
 */
export function initAdminQrGenerator(showToast) {
  const input = document.getElementById('qrGenUrl');
  const container = document.getElementById('admin-qrcode-gen');
  const result = document.getElementById('qrGenResult');
  const errorEl = document.getElementById('qrGenError');
  const btnGenerar = document.getElementById('btnGenerarQr');
  const linkEl = document.getElementById('qrGenLink');
  if (!input || !container || !result || !btnGenerar) return;

  let currentText = '';

  const showError = (msg) => {
    if (!errorEl) return;
    errorEl.textContent = msg;
    errorEl.hidden = false;
  };

  const clearError = () => {
    if (errorEl) errorEl.hidden = true;
  };

  const normalizeUrl = (value) => {
    const trimmed = value.trim();
    if (!trimmed) return '';
    // Si parece un dominio sin esquema, le anteponemos https://
    if (/^[\w-]+(\.[\w-]+)+([/?#].*)?$/i.test(trimmed) && !/^https?:\/\//i.test(trimmed)) {
      return `https://${trimmed}`;
    }
    return trimmed;
  };

  const getQrImg = () => container.querySelector('img');

  const generar = () => {
    clearError();
    const value = normalizeUrl(input.value);
    if (!value) {
      showError('Ingresá un enlace o texto para generar el código QR.');
      result.hidden = true;
      return;
    }
    if (typeof window.QRCode === 'undefined') {
      showError('No se pudo cargar la librería de QR. Recargá la página.');
      return;
    }

    currentText = value;
    container.innerHTML = '';
    // eslint-disable-next-line no-new
    new window.QRCode(container, {
      text: value,
      width: 280,
      height: 280,
      colorDark: '#0c3484',
      colorLight: '#ffffff',
    });

    if (linkEl) {
      linkEl.href = value;
      linkEl.textContent = value;
    }
    result.hidden = false;
  };

  btnGenerar.addEventListener('click', generar);
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      generar();
    }
  });

  document.getElementById('btnDescargarQrGen')?.addEventListener('click', () => {
    const img = getQrImg();
    if (!img?.src) return;
    const link = document.createElement('a');
    link.download = 'qr-natta.png';
    link.href = img.src;
    link.click();
    showToast?.('QR descargado', 'success');
  });

  document.getElementById('btnCopiarUrlQrGen')?.addEventListener('click', async () => {
    if (!currentText) return;
    const btn = document.getElementById('btnCopiarUrlQrGen');
    try {
      await navigator.clipboard.writeText(currentText);
      if (btn) {
        const prev = btn.textContent;
        btn.textContent = '¡Enlace copiado!';
        setTimeout(() => { btn.textContent = prev; }, 2000);
      }
      showToast?.('Enlace copiado al portapapeles', 'success');
    } catch {
      window.prompt('Copiá este enlace:', currentText);
    }
  });

  document.getElementById('btnCompartirQrGen')?.addEventListener('click', async () => {
    if (!currentText) return;
    const img = getQrImg();

    if (navigator.share && img?.src) {
      try {
        const blob = await fetch(img.src).then((r) => r.blob());
        const file = new File([blob], 'qr-natta.png', { type: blob.type || 'image/png' });
        const shareData = {
          title: 'Código QR',
          text: currentText,
          url: currentText,
        };
        if (navigator.canShare?.({ files: [file] })) {
          await navigator.share({ ...shareData, files: [file] });
        } else {
          await navigator.share(shareData);
        }
        return;
      } catch (err) {
        if (err?.name === 'AbortError') return;
      }
    }

    try {
      await navigator.clipboard.writeText(currentText);
      showToast?.('Enlace copiado. Pegalo donde quieras compartir.', 'info');
    } catch {
      window.prompt('Copiá este enlace para compartir:', currentText);
    }
  });
}
