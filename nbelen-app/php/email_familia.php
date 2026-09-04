<?php
// ======================
// admin.php - Dashboard administrativo con login simple
// ======================

require_once __DIR__ . '/../config/session.php';
secure_session_start();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Desactivar mostrar errores en producción (solo log)
/*ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

date_default_timezone_set('America/Argentina/Buenos_Aires');*/

// ============================================
//  CONFIGURACIÓN DE ADMIN (contraseña hasheada)
// ============================================
define('_ACCESS', true);
require_once __DIR__ . '/../config/admin.php';
require_once __DIR__ . '/../config/db.php'; // Contiene ADMIN_PASSWORD_HASH

// ============================================
//  SESIÓN ADMIN (dashboard)
// ============================================
require_once __DIR__ . '/../admin/includes/admin_user.php';
if (!admin_is_logged_in()) {
    header('Location: ../admin/admin_dashboard.php');
    exit;
}
if (!admin_is_superadmin()) {
    http_response_code(403);
    exit('Solo superadmin puede ejecutar importaciones.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método no permitido. Use el panel de administración.');
}
if (!isset($_POST['csrf_token'], $_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
    http_response_code(403);
    exit('Token CSRF inválido');
}

// ============================================
//  CONEXIÓN A LA BASE DE DATOS (SEGURA)
// ============================================

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    error_log("Error de conexión en email_familia.php: " . $conn->connect_error);
    die("Error interno del servidor. Intente más tarde.");
}

// Establecer charset
$conn->set_charset('utf8mb4');

// Ruta al archivo CSV (centralizado en config/imports para proteger archivos sensibles)
$archivo_csv = __DIR__ . '/../config/imports/email-padres.csv';
if (!file_exists($archivo_csv)) {
    die("❌ No se encontró el archivo CSV en config/imports.");
}

// Abrir el archivo
$archivo = fopen($archivo_csv, 'r');
if (!$archivo) {
    die("❌ No se pudo abrir el archivo CSV.");
}

// Leer encabezado (se espera: email_padre, email_madre, email_trabajo_padre, email_trabajo_madre, email_afip, nro_familia)
$encabezado = fgetcsv($archivo, 0, ";");
if ($encabezado === false || count($encabezado) !== 6) {
    die("❌ El archivo CSV no tiene el formato esperado (6 columnas separadas por ;).");
}

// Preparar consulta con placeholders
$sql = "INSERT INTO email_familia (
            nro_familia,
            mail_padre,
            mail_padre_trabajo,
            mail_madre,
            mail_madre_trabajo,
            mail_resp_afip
        ) VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            mail_padre = VALUES(mail_padre),
            mail_padre_trabajo = VALUES(mail_padre_trabajo),
            mail_madre = VALUES(mail_madre),
            mail_madre_trabajo = VALUES(mail_madre_trabajo),
            mail_resp_afip = VALUES(mail_resp_afip)";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    error_log("Error prepare: " . $conn->error);
    die("Error interno del servidor.");
}

// Variables para estadísticas
$importados = 0;
$actualizados = 0;
$errores = 0;
$linea = 1; // ya se leyó el encabezado

// Array para almacenar detalles de errores
$errores_detalle = [];

// Iniciar transacción
$conn->begin_transaction();

try {
    while (($datos = fgetcsv($archivo, 0, ";")) !== false) {
        $linea++;

        // Validar número de columnas
        if (count($datos) !== 6) {
            $error_msg = "Línea $linea: número de columnas incorrecto (tiene " . count($datos) . ", se esperaban 6)";
            error_log($error_msg);
            $errores_detalle[] = $error_msg;
            $errores++;
            continue;
        }

        list(
            $email_padre,
            $email_madre,
            $email_trabajo_padre,
            $email_trabajo_madre,
            $email_afip,
            $nro_familia
        ) = $datos;

        // Limpieza básica
        $nro_familia = trim($nro_familia);
        $email_padre = trim($email_padre);
        $email_madre = trim($email_madre);
        $email_trabajo_padre = trim($email_trabajo_padre);
        $email_trabajo_madre = trim($email_trabajo_madre);
        $email_afip = trim($email_afip);

        // Validar que nro_familia no esté vacío
        if ($nro_familia === '') {
            $error_msg = "Línea $linea: nro_familia vacío, se omite.";
            error_log($error_msg);
            $errores_detalle[] = $error_msg;
            $errores++;
            continue;
        }

        // Validar emails (opcional, pero recomendable)
        if ($email_padre !== '' && !filter_var($email_padre, FILTER_VALIDATE_EMAIL)) {
            $error_msg = "Línea $linea (Familia: $nro_familia): email_padre inválido ($email_padre)";
            error_log($error_msg);
            $errores_detalle[] = $error_msg;
            $errores++;
            continue;
        }
        if ($email_madre !== '' && !filter_var($email_madre, FILTER_VALIDATE_EMAIL)) {
            $error_msg = "Línea $linea (Familia: $nro_familia): email_madre inválido ($email_madre)";
            error_log($error_msg);
            $errores_detalle[] = $error_msg;
            $errores++;
            continue;
        }
        if ($email_trabajo_padre !== '' && !filter_var($email_trabajo_padre, FILTER_VALIDATE_EMAIL)) {
            $error_msg = "Línea $linea (Familia: $nro_familia): email_trabajo_padre inválido ($email_trabajo_padre)";
            error_log($error_msg);
            $errores_detalle[] = $error_msg;
            $errores++;
            continue;
        }
        if ($email_trabajo_madre !== '' && !filter_var($email_trabajo_madre, FILTER_VALIDATE_EMAIL)) {
            $error_msg = "Línea $linea (Familia: $nro_familia): email_trabajo_madre inválido ($email_trabajo_madre)";
            error_log($error_msg);
            $errores_detalle[] = $error_msg;
            $errores++;
            continue;
        }
        if ($email_afip !== '' && !filter_var($email_afip, FILTER_VALIDATE_EMAIL)) {
            $error_msg = "Línea $linea (Familia: $nro_familia): email_afip inválido ($email_afip)";
            error_log($error_msg);
            $errores_detalle[] = $error_msg;
            $errores++;
            continue;
        }

        // Ejecutar inserción
        $stmt->bind_param(
            "ssssss",
            $nro_familia,
            $email_padre,
            $email_trabajo_padre,
            $email_madre,
            $email_trabajo_madre,
            $email_afip
        );

        if ($stmt->execute()) {
            if ($stmt->affected_rows === 1) {
                $importados++;
            } elseif ($stmt->affected_rows === 2) {
                // En MySQL, ON DUPLICATE KEY UPDATE afecta 2 filas si se actualiza (1 borrado + 1 insertado)
                $actualizados++;
            }
        } else {
            $error_msg = "Línea $linea (Familia: $nro_familia): Error en BD - " . $stmt->error;
            error_log($error_msg);
            $errores_detalle[] = $error_msg;
            $errores++;
        }
    }

    $conn->commit();
    
    // Mostrar resultado con detalles de errores
    echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Importación</title>";
    echo "<link rel='stylesheet' href='../css/pages/import-reports.css'>";
    echo "</head><body>";
    
    echo "<h3>📊 Resultado de Importación</h3>";
    echo "<p class='success'>✔️ Importación finalizada correctamente.</p>";
    echo "<ul>";
    echo "<li>📥 Nuevos registros: <strong>$importados</strong></li>";
    echo "<li>♻️ Registros actualizados: <strong>$actualizados</strong></li>";
    echo "<li class='error'>❌ Líneas con error: <strong>$errores</strong></li>";
    echo "</ul>";
    
    if (!empty($errores_detalle)) {
        echo "<div class='details'>";
        echo "<h4>🔍 Detalle de errores encontrados:</h4>";
        echo "<table>";
        echo "<thead><tr><th>#</th><th>Descripción del error</th></tr></thead>";
        echo "<tbody>";
        $contador = 1;
        foreach ($errores_detalle as $error) {
            echo "<tr>";
            echo "<td>" . $contador++ . "</td>";
            echo "<td class='error'>" . htmlspecialchars($error) . "</td>";
            echo "</tr>";
        }
        echo "</tbody>";
        echo "</table>";
        echo "</div>";
    } else {
        echo "<p class='success'>✅ No se encontraron errores durante la importación.</p>";
    }

} catch (Throwable $e) {
    $conn->rollback();
    error_log("Excepción en importación: " . $e->getMessage());
    echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Importación</title>";
    echo "<link rel='stylesheet' href='../css/pages/import-reports.css'>";
    echo "</head><body>";
    echo "<h3>❌ Error durante la importación</h3>";
    echo "<p class='error'>Se ha revertido la operación.</p>";
    echo "<p><strong>Detalle:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    if (!empty($errores_detalle)) {
        echo "<h4>🔍 Errores registrados antes del fallo:</h4>";
        echo "<ul>";
        foreach ($errores_detalle as $error) {
            echo "<li class='error'>" . htmlspecialchars($error) . "</li>";
        }
        echo "</ul>";
    }
}

// Cerrar recursos
if (isset($stmt) && $stmt) $stmt->close();
if (isset($archivo) && $archivo) fclose($archivo);
if (isset($conn) && $conn) $conn->close();

echo "</body></html>";
?>