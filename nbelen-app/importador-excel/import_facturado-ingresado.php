<?php
// ============================================
// import_facturado-ingresado.php - Importación de cuotas desde Excel
// ============================================

require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

require_once __DIR__ . '/../config/session.php';
secure_session_start();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Desactivar mostrar errores en producción (solo log)
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

date_default_timezone_set('America/Argentina/Buenos_Aires');

// ============================================
//  CONFIGURACIÓN DE ADMIN (contraseña hasheada)
// ============================================
define('_ACCESS', true);
require_once __DIR__ . '/../config/admin.php';
require_once __DIR__ . '/../config/db.php';

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
    error_log("Error de conexión en import_facturado-ingresado.php: " . $e->getMessage());
    die("Error interno del servidor. Intente más tarde.");
}

// ============================================
//  ARCHIVOS A IMPORTAR
// ============================================
$files = [
    'rptfacing.xls',
    'rptfacing.xlsx',
    'rptfacing1.xls',
    'rptfacing1.xlsx',
    'rptfacing2.xls',
    'rptfacing2.xlsx',
    'rptfacing3.xls',
    'rptfacing3.xlsx',
    'rptfacing4.xls',
    'rptfacing4.xlsx',
    'rptfacing5.xls',
    'rptfacing5.xlsx',
];

// ============================================
//  HELPERS
// ============================================

// Convierte montos a float "seguro"
function parseMontoToFloat($raw): float {
    if ($raw === null) return 0.0;

    if (is_numeric($raw)) {
        return (float)$raw;
    }

    $s = trim((string)$raw);
    if ($s === '') return 0.0;

    // Saca espacios y símbolos comunes
    $s = str_replace(["\xc2\xa0", " ", "$", "AR$", "USD"], "", $s);

    $hasComma = strpos($s, ',') !== false;
    $hasDot   = strpos($s, '.') !== false;

    if ($hasComma && $hasDot) {
        $lastComma = strrpos($s, ',');
        $lastDot   = strrpos($s, '.');
        if ($lastComma > $lastDot) {
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        } else {
            $s = str_replace(',', '', $s);
        }
    } elseif ($hasComma && !$hasDot) {
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
    } else {
        if ($hasDot) {
            $parts = explode('.', $s);
            if (count($parts) === 2 && strlen($parts[1]) === 3) {
                $s = str_replace('.', '', $s);
            }
        }
    }

    return is_numeric($s) ? (float)$s : 0.0;
}

// Devuelve string con 2 decimales
function fmtMonto($raw): string {
    return number_format(parseMontoToFloat($raw), 2, '.', '');
}

// Convierte celda a "Y-m-d" o null
function toDate($cell): ?string {
    try {
        $txt = trim((string)($cell->getFormattedValue() ?? ''));
    } catch (\Throwable $e) {
        $txt = '';
    }

    if ($txt !== '') {
        $formats = ['d/m/Y', 'Y-m-d', 'd-m-Y', 'd/m/y', 'd-m-y'];
        foreach ($formats as $fmt) {
            $d = \DateTime::createFromFormat($fmt, $txt);
            if ($d instanceof \DateTime) {
                return $d->format('Y-m-d');
            }
        }

        $ts = strtotime($txt);
        if ($ts !== false) {
            return date('Y-m-d', $ts);
        }
    }

    $raw = $cell->getValue();
    if (is_numeric($raw)) {
        try {
            return Date::excelToDateTimeObject($raw)->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }

    return null;
}

// Cargar spreadsheet según extensión
function loadSpreadsheet(string $inputFileName) {
    $ext = strtolower(pathinfo($inputFileName, PATHINFO_EXTENSION));
    $readerType = ($ext === 'xlsx') ? 'Xlsx' : 'Xls';
    $reader = IOFactory::createReader($readerType);
    return $reader->load($inputFileName);
}

// ============================================
//  CONTADORES TOTALES
// ============================================
$totalNuevos = 0;
$totalActualizados = 0;
$totalErrores = 0;
$totalSaltados = 0;
$archivosProcesados = 0;

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Importación cuotas</title>";
echo "<link rel='stylesheet' href='../css/pages/import-reports.css'>";
echo "</head><body>";
echo "<h3>Importación múltiple de cuotas</h3>";

// ============================================
//  PROCESAR CADA ARCHIVO
// ============================================
foreach ($files as $inputFileName) {
    $fullPath = __DIR__ . '/../config/imports/' . $inputFileName;

    echo "<hr>";
    echo "<strong>📄 Archivo:</strong> " . htmlspecialchars($inputFileName, ENT_QUOTES, 'UTF-8') . "<br>";

    if (!file_exists($fullPath)) {
        echo "<span class='warning'>⚠️ No existe, se saltea.</span><br>";
        $totalSaltados++;
        continue;
    }

    try {
        $spreadsheet = loadSpreadsheet($fullPath);
    } catch (\Throwable $e) {
        error_log("Error abriendo $inputFileName: " . $e->getMessage());
        echo "<span class='error'>❌ Error abriendo " . htmlspecialchars($inputFileName, ENT_QUOTES, 'UTF-8') . ": " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "</span><br>";
        $totalErrores++;
        continue;
    }

    $sheet = $spreadsheet->getActiveSheet();

    $nuevos = 0;
    $actualizados = 0;
    $errores = 0;

    $highestRow = $sheet->getHighestDataRow();

    // Iniciar transacción para este archivo
    $pdo->beginTransaction();

    try {
        for ($row = 2; $row <= $highestRow; $row++) {

            $nro_legajo = trim((string)($sheet->getCell('A' . $row)->getValue() ?? ''));
            $cuotaVal   = $sheet->getCell('E' . $row)->getValue();
            $cuota      = (int)$cuotaVal;

            if ($nro_legajo === '' || $cuota === 0) {
                continue;
            }

            $recibo = trim((string)($sheet->getCell('G' . $row)->getValue() ?? ''));

            $montoFacturado = fmtMonto($sheet->getCell('I' . $row)->getValue());
            $montoIngresado = fmtMonto($sheet->getCell('K' . $row)->getValue());
            $diferencia     = fmtMonto($sheet->getCell('M' . $row)->getValue());

            $estado = ($diferencia === '0.00') ? 'pagado' : 'pendiente';

            $fechaLiquidacion = toDate($sheet->getCell('N' . $row));
            $fechaPago        = toDate($sheet->getCell('O' . $row));

            // Asegurar que el legajo exista en legajos
            $stmt = $pdo->prepare(
                "INSERT INTO legajos (nro_legajo)
                 VALUES (?)
                 ON DUPLICATE KEY UPDATE nro_legajo = VALUES(nro_legajo)"
            );
            
            if (!$stmt->execute([$nro_legajo])) {
                throw new Exception("Error insert/dup legajos fila $row");
            }

            // Verificar existencia de cuota
            $check = $pdo->prepare("SELECT id FROM cuotas WHERE nro_legajo = ? AND numero_cuota = ?");
            $check->execute([$nro_legajo, $cuota]);
            $existe = ($check->fetch() !== false);

            $mf = (float)$montoFacturado;
            $mi = (float)$montoIngresado;
            $df = (float)$diferencia;

            if ($existe) {
                $update = $pdo->prepare(
                    "UPDATE cuotas SET
                        recibo_nro = ?,
                        monto_facturado = ?,
                        monto_ingresado = ?,
                        diferencia = ?,
                        fecha_liquidacion = ?,
                        fecha_pago = ?,
                        estado = ?
                     WHERE nro_legajo = ? AND numero_cuota = ?"
                );
                
                if (!$update->execute([
                    $recibo,
                    $mf,
                    $mi,
                    $df,
                    $fechaLiquidacion,
                    $fechaPago,
                    $estado,
                    $nro_legajo,
                    $cuota
                ])) {
                    throw new Exception("Error actualizando fila $row");
                }
                $actualizados++;
            } else {
                $insert = $pdo->prepare(
                    "INSERT INTO cuotas
                        (nro_legajo, numero_cuota, recibo_nro, monto_facturado, monto_ingresado, diferencia, fecha_liquidacion, fecha_pago, estado)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                
                if (!$insert->execute([
                    $nro_legajo,
                    $cuota,
                    $recibo,
                    $mf,
                    $mi,
                    $df,
                    $fechaLiquidacion,
                    $fechaPago,
                    $estado
                ])) {
                    throw new Exception("Error insertando fila $row");
                }
                $nuevos++;
            }
        }

        $pdo->commit();
        $archivosProcesados++;

        echo "<span class='success'>✅ Procesado {$inputFileName}</span><br>";
        echo "📌 Nuevos: {$nuevos} | ♻️ Actualizados: {$actualizados}<br>";

        $totalNuevos += $nuevos;
        $totalActualizados += $actualizados;

    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log("Error en archivo $inputFileName: " . $e->getMessage());
        echo "<span class='error'>❌ Error en archivo " . htmlspecialchars($inputFileName, ENT_QUOTES, 'UTF-8') . ": " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "</span><br>";
        $errores++;
        $totalErrores++;
    }

    // Liberar memoria
    $spreadsheet->disconnectWorksheets();
    unset($spreadsheet);
}

if ($archivosProcesados > 0) {
    try {
        $stmtUltimaActualizacion = $pdo->prepare(
            "INSERT INTO configuracion (clave, valor, descripcion)
             VALUES ('ultima_actualizacion_cuotas', ?, 'Última importación exitosa de cuotas')
             ON DUPLICATE KEY UPDATE valor = VALUES(valor), descripcion = VALUES(descripcion)"
        );
        $stmtUltimaActualizacion->execute([date('Y-m-d H:i:s')]);
    } catch (Throwable $e) {
        error_log('No se pudo registrar la última actualización de cuotas: ' . $e->getMessage());
    }
}

echo "<hr>";
echo "<h3>📊 Resumen total</h3>";
echo "📌 Nuevos registros: {$totalNuevos}<br>";
echo "♻️ Actualizados: {$totalActualizados}<br>";
echo "<span class='error'>❌ Errores: {$totalErrores}</span><br>";
echo "<span class='warning'>⚠️ Archivos inexistentes saltados: {$totalSaltados}</span><br>";
echo "</body></html>";
?>