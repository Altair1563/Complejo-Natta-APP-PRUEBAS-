<?php
/**
 * Bootstrap centralizado del backend.
 *
 * Carga configuración, autoloader de Composer y helpers comunes.
 * Incluir desde cualquier script PHP de la raíz:
 *   require_once __DIR__ . '/backend/bootstrap.php';
 */

require_once __DIR__ . '/../config/session.php';
secure_session_start();

define('BASE_PATH', dirname(__DIR__));

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

date_default_timezone_set('America/Argentina/Buenos_Aires');

// Composer autoloader (PHPMailer, etc.)
$autoload = BASE_PATH . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

// Constante de acceso para config/db.php
if (!defined('_ACCESS')) {
    define('_ACCESS', true);
}

require_once BASE_PATH . '/config/db.php';
require_once BASE_PATH . '/config/app_audit.php';

/**
 * Abre y devuelve una conexión mysqli con charset utf8mb4.
 */
function getDbConnection(): mysqli
{
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        error_log('Error de conexión: ' . $conn->connect_error);
        die('Lo sentimos, no se pudo conectar con la base de datos. Intente más tarde.');
    }
    $conn->set_charset('utf8mb4');
    // Alinea CURRENT_TIMESTAMP y triggers de MySQL al horario de Argentina.
    try {
        $conn->query("SET time_zone = '-03:00'");
    } catch (mysqli_sql_exception $e) {
        error_log('getDbConnection SET time_zone: ' . $e->getMessage());
    }
    return $conn;
}

/**
 * fetch compatible con servidores sin mysqlnd.
 */
function fetchAllFromStmt(mysqli_stmt $stmt): array
{
    $rows = [];
    if (method_exists($stmt, 'get_result')) {
        $res = $stmt->get_result();
        if ($res instanceof mysqli_result) {
            while ($r = $res->fetch_assoc()) {
                $rows[] = $r;
            }
        }
    } else {
        $stmt->store_result();
        $meta = $stmt->result_metadata();
        if (!$meta) {
            return $rows;
        }
        $fields = [];
        $row    = [];
        while ($field = $meta->fetch_field()) {
            $fields[] = &$row[$field->name];
        }
        call_user_func_array([$stmt, 'bind_result'], $fields);
        while ($stmt->fetch()) {
            $r = [];
            foreach ($row as $k => $v) {
                $r[$k] = $v;
            }
            $rows[] = $r;
        }
        unset($row);
    }
    return $rows;
}

/**
 * Verifica que el usuario tenga sesión activa; redirige al login si no.
 */
function requireAuth(string $loginUrl = '/natta-app/index.php'): void
{
    if (!isset($_SESSION['dni_alumno']) || !isset($_SESSION['nro_familia'])) {
        header('Location: ' . $loginUrl);
        exit;
    }
}
