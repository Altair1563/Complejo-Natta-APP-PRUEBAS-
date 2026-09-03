<?php
/**
 * Tras login correcto: pantalla intermedia para aceptar la Política de Privacidad antes de home.php.
 */
require_once __DIR__ . '/../config/session.php';
secure_session_start();

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

if (isset($_SESSION['dni_alumno'])) {
    header('Location: ../home.php');
    exit;
}

$gate = $_SESSION['privacy_gate'] ?? null;
if (!is_array($gate) || empty($gate['dni_alumno']) || empty($gate['email']) || empty($gate['nro_familia'])) {
    header('Location: ../index.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$policy_version = htmlspecialchars((string)($gate['policy_version'] ?? ''), ENT_QUOTES, 'UTF-8');
$error_msg = isset($_SESSION['error_privacy_gate']) ? (string)$_SESSION['error_privacy_gate'] : '';
unset($_SESSION['error_privacy_gate']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Política de privacidad — Milagrosa App</title>
    <link rel="stylesheet" href="../css/main.css">
    <style>
        .pg-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.82);
            z-index: 100000;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
            overflow-y: auto;
        }
        .pg-dialog {
            background: #fff;
            border-radius: 8px;
            max-width: 520px;
            width: 100%;
            padding: 24px 22px 20px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.35);
        }
        .pg-dialog h1 {
            font-size: 1.35rem;
            margin: 0 0 12px;
            color: #1a1a1a;
        }
        .pg-dialog p {
            color: #444;
            line-height: 1.5;
            font-size: 0.95rem;
            margin: 0 0 12px;
        }
        .pg-actions { margin-top: 18px; }
        .pg-actions .btn-continuar {
            width: 100%;
            margin-top: 8px;
        }
        .pg-cancel { text-align: center; margin-top: 14px; font-size: 0.9rem; }
        .pg-cancel a { color: #666; }
        .pg-checkbox-row {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            margin-top: 16px;
            padding: 16px 18px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            background: #fafafa;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .pg-checkbox-row:focus-within {
            border-color: #c62828;
            box-shadow: 0 0 0 3px rgba(198, 40, 40, 0.12);
        }
        .pg-checkbox-input {
            width: 22px;
            height: 22px;
            min-width: 22px;
            margin-top: 3px;
            cursor: pointer;
            accent-color: #c62828;
            flex-shrink: 0;
        }
        .pg-checkbox-label {
            cursor: pointer;
            font-weight: normal;
            color: #333;
            line-height: 1.45;
            font-size: 0.98rem;
            user-select: none;
        }
    </style>
</head>
<body style="margin:0;background:#111;">
    <div class="pg-overlay" role="dialog" aria-modal="true" aria-labelledby="pg-title">
        <div class="pg-dialog">
            <h1 id="pg-title">Aceptación de Política de Privacidad</h1>
            <p>
                Su usuario y contraseña son correctos. Para continuar debe aceptar la
                <strong>Política de Privacidad</strong> vigente (Ley 25.326).
                <?php if ($policy_version !== ''): ?>
                    <br><span style="font-size:0.88rem;color:#666;">Versión registrada: <?php echo $policy_version; ?></span>
                <?php endif; ?>
            </p>
            <p>
                <a href="/milagrosa-app/politica-privacidad.php" target="_blank" rel="noopener noreferrer">Leer Políticas de Privacidad</a>
            </p>
            <?php if ($error_msg !== ''): ?>
                <div class="alert alert-danger" style="margin-bottom:12px;"><?php echo htmlspecialchars($error_msg, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            <form method="post" action="privacy_gate_submit.php" id="pg-form">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                <div class="pg-checkbox-row">
                    <input type="checkbox" name="privacy_accept" id="privacy_accept" value="1" required class="pg-checkbox-input" autocomplete="off">
                    <label for="privacy_accept" class="pg-checkbox-label">
                        He leído y acepto las Políticas de Privacidad.
                    </label>
                </div>
                <div class="pg-actions">
                    <button type="submit" class="btn btn-raised btn-danger btn-continuar">Continuar a mi cuenta</button>
                </div>
            </form>
            <div class="pg-cancel">
                <a href="privacy_gate_cancel.php">Cancelar y volver al inicio de sesión</a>
            </div>
        </div>
    </div>
    <script src="../js/jquery-3.1.1.min.js"></script>
    <script src="../js/bootstrap.min.js"></script>
</body>
</html>
