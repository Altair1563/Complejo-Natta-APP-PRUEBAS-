<?php
// ======================
// publicar_comunicado.php - Endpoint AJAX para publicar comunicados (admin)
// ======================

require_once __DIR__ . '/../config/session.php';
secure_session_start();

// Limpiar búferes de salida
while (ob_get_level()) ob_end_clean();

// Desactivar errores visibles (solo log)
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Argentina/Buenos_Aires');

function normalize_plain_text($value, $maxLen = 0, $preserveNewLines = false)
{
    $text = is_string($value) ? $value : '';
    $text = strip_tags($text);
    if ($preserveNewLines) {
        $text = preg_replace("/\r\n?/", "\n", $text);
        $text = preg_replace("/[ \t]+/", ' ', $text);
    } else {
        $text = preg_replace("/[\r\n\t]+/", ' ', $text);
    }
    $text = trim($text);
    if ($maxLen > 0 && function_exists('mb_substr')) {
        $text = mb_substr($text, 0, $maxLen);
    }
    return $text;
}

// ============================================
//  VERIFICAR AUTENTICACIÓN DE ADMINISTRADOR
// ============================================
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'No autorizado']);
    exit;
}

// ============================================
//  VALIDAR MÉTODO POST
// ============================================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    exit;
}

// ============================================
//  VALIDAR TOKEN CSRF
// ============================================
$csrf_token = $_POST['csrf_token'] ?? '';
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Token CSRF inválido']);
    exit;
}

// ============================================
//  CONEXIÓN A LA BASE DE DATOS (SEGURA)
// ============================================
define('_ACCESS', true);
require_once __DIR__ . '/../config/db.php';

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";port=3306;dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (Throwable $e) {
    error_log("Error de conexión en publicar_comunicado.php: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Error interno del servidor']);
    exit;
}

// ============================================
//  OBTENER Y VALIDAR DATOS
// ============================================
$titulo = normalize_plain_text($_POST['titulo'] ?? '', 255);
$contenido = normalize_plain_text($_POST['contenido'] ?? '', 0, true);

if ($titulo === '' || $contenido === '') {
    echo json_encode(['ok' => false, 'error' => 'Título y contenido son obligatorios']);
    exit;
}

// ============================================
//  INSERTAR COMUNICADO
// ============================================
require_once __DIR__ . '/../includes/comunicados_pdf.php';

$newId = 0;
try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("INSERT INTO comunicados (titulo, contenido, fecha) VALUES (?, ?, NOW())");
    $stmt->execute([$titulo, $contenido]);
    $newId = (int)$pdo->lastInsertId();

    $pdfFile = $_FILES['pdf_adjunto'] ?? null;
    if (is_array($pdfFile) && ($pdfFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $pdfResult = comunicado_save_pdf_upload($pdfFile, $newId);
        if (!$pdfResult['ok']) {
            $pdo->rollBack();
            comunicado_delete_pdf($newId);
            echo json_encode(['ok' => false, 'error' => $pdfResult['msg']]);
            exit;
        }
        if ($pdfResult['saved']) {
            $stmtPdf = $pdo->prepare('UPDATE comunicados SET archivo_pdf = ? WHERE id = ?');
            $stmtPdf->execute([$pdfResult['path'], $newId]);
        }
    }

    // Crear mensaje de notificación
    $mensaje = "📢 Nuevo comunicado: " . (strlen($titulo) > 40 ? substr($titulo, 0, 40) . "..." : $titulo) . " (Ver en Información Importante)";

    // Insertar notificaciones para todas las familias
    $stmtNotif = $pdo->prepare("INSERT INTO notificaciones (nro_familia, mensaje) SELECT DISTINCT nro_familia, ? FROM legajos WHERE nro_familia IS NOT NULL");
    $stmtNotif->execute([$mensaje]);

    $pdo->commit();

    echo json_encode(['ok' => true, 'message' => 'Comunicado publicado correctamente']);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($newId > 0) {
        comunicado_delete_pdf($newId);
    }
    error_log("Error al publicar comunicado: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Error al publicar el comunicado']);
    exit;
}