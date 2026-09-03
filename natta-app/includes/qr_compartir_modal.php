<?php
/** Modal para compartir la app vía QR. Requiere APP_PUBLIC_URL (config/app.php). */
$url = htmlspecialchars(APP_PUBLIC_URL, ENT_QUOTES, 'UTF-8');
?>
<div id="modalQrShare" class="qr-share-modal" role="dialog" aria-labelledby="qrShareTitle" aria-hidden="true" style="display:none;">
    <div class="qr-share-modal__panel">
        <button type="button" class="qr-share-modal__close" id="cerrarModalQr" aria-label="Cerrar">&times;</button>
        <h3 id="qrShareTitle" class="qr-share-modal__title">Compartir la APP Natta</h3>
        <p class="qr-share-modal__hint">Escaneá el código con la cámara del celular para abrir el acceso a familias.</p>
        <div id="qrcode" class="qr-share-modal__code" aria-hidden="true"></div>
        <p class="qr-share-modal__url"><a href="<?= $url ?>" target="_blank" rel="noopener noreferrer"><?= $url ?></a></p>
        <button type="button" class="qr-share-modal__copy" id="btnCopiarUrlApp">Copiar enlace</button>
    </div>
</div>
