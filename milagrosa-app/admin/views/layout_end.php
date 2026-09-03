        </div>
    </div>

    <?php require __DIR__ . '/modals.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script id="page-data" type="application/json"><?= json_encode([
        'csrfToken'        => $csrf_token,
        'initialActiveTab' => $active_tab,
        'initialActiveGrupo' => $active_grupo,
        'msgFromUrl'       => $msg_from_url,
        'msgTypeFromUrl'   => $msg_type_from_url,
        'ajaxUrl'          => 'admin_ajax.php',
        'appPublicUrl'     => APP_PUBLIC_URL,
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
    <script type="module" src="../frontend/js/index.js"></script>
</body>
</html>
