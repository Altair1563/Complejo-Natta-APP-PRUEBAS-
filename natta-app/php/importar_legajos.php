<?php
// ======================
// importar_legajos.php - Importación de legajos.csv y legajos-inactivos.csv
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
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    error_log("Error de conexión: " . $conn->connect_error);
    die("Error interno del servidor. Intente más tarde.");
}
$conn->set_charset('utf8mb4');

// ============================================
//  TRUNCAR TABLAS ANTES DE IMPORTAR
// ============================================
echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Importación de Legajos</title>";
echo "<link rel='stylesheet' href='../css/pages/import-reports.css'>";
echo "</head><body>";
echo "<div class='container'>";
echo "<h3>📊 Importación de Archivos de Legajos</h3>";

echo "<div class='stats'>";
echo "<strong>🗑️ Limpiando tablas existentes...</strong><br>";
if ($conn->query("TRUNCATE TABLE legajos")) {
    echo "✔️ Tabla <code>legajos</code> vaciada correctamente.<br>";
} else {
    echo "❌ Error al vaciar <code>legajos</code>: " . $conn->error . "<br>";
}
if ($conn->query("TRUNCATE TABLE legajos_inactivos")) {
    echo "✔️ Tabla <code>legajos_inactivos</code> vaciada correctamente.<br>";
} else {
    echo "❌ Error al vaciar <code>legajos_inactivos</code>: " . $conn->error . "<br>";
}
echo "</div>";

// ============================================
//  FUNCIÓN AUXILIAR: convertir a UTF-8
// ============================================
function to_utf8($s) {
    if ($s === null || $s === '') return '';
    $s = (string)$s;
    if (mb_check_encoding($s, 'UTF-8')) return $s;
    return mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
}

/** Parte de nro_legajo antes de la primera barra (hermanos: 3714/01 → 3714). */
function prefijo_numero_legajo($nro_legajo) {
    $nro_legajo = trim((string)$nro_legajo);
    $p = strpos($nro_legajo, '/');
    if ($p === false) {
        return $nro_legajo;
    }
    return trim(substr($nro_legajo, 0, $p));
}

/** El número de familia debe coincidir con el prefijo del legajo (antes de /01, /02, etc.). */
function familia_coincide_con_legajo($nro_legajo, $nro_familia) {
    $pref = prefijo_numero_legajo($nro_legajo);
    $fam = trim((string)$nro_familia);
    return $pref !== '' && $fam !== '' && $pref === $fam;
}

// ============================================
//  FUNCIÓN DE IMPORTACIÓN (SIN MODIFICAR NINGÚN VALOR)
// ============================================
function importar_csv($conn, $archivo_csv, $tabla, $skip_dni_map = null, $build_dni_map = false) {

    $result = [
        'importados' => 0,
        'actualizados' => 0,
        'errores' => 0,
        'lineas_procesadas' => 0,
        'dni_map' => null,
        'skipped_due_to_active' => [],
        'hubo_error_grave' => false,
        'familia_legajo_errores' => [],
        'dni_duplicados_en_archivo' => [],
    ];

    $ruta_completa = __DIR__ . '/../config/imports/' . $archivo_csv;

    if (!file_exists($ruta_completa)) {
        echo "⚠️ No se encontró el archivo $archivo_csv<br>";
        return $result;
    }

    $archivo = fopen($ruta_completa, 'r');
    if (!$archivo) {
        echo "❌ No se pudo abrir $archivo_csv<br>";
        $result['errores']++;
        return $result;
    }

    $encabezado = fgetcsv($archivo, 0, ";");
    if ($encabezado === false) {
        echo "❌ Archivo sin encabezado válido<br>";
        fclose($archivo);
        return $result;
    }

    $linea = 1;
    $dni_map_local = [];
    $primera_fila_por_dni = [];

    $sql = "INSERT INTO $tabla (
                nro_legajo, nro_familia, apellido_alumno, nombre_alumno, curso,
                dni_alumno, codigo_descuento, porcentaje_descuento, saldo_total
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                nro_familia = VALUES(nro_familia),
                apellido_alumno = VALUES(apellido_alumno),
                nombre_alumno = VALUES(nombre_alumno),
                curso = VALUES(curso),
                dni_alumno = VALUES(dni_alumno),
                codigo_descuento = VALUES(codigo_descuento),
                porcentaje_descuento = VALUES(porcentaje_descuento),
                saldo_total = VALUES(saldo_total)";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("Error prepare para tabla $tabla: " . $conn->error);
        echo "❌ Error interno al preparar la consulta.<br>";
        fclose($archivo);
        $result['errores']++;
        return $result;
    }

    $conn->begin_transaction();

    try {
        while (($datos = fgetcsv($archivo, 0, ";")) !== false) {
            $linea++;
            $result['lineas_procesadas']++;

            // Normalizar encoding
            foreach ($datos as $k => $v) {
                $datos[$k] = to_utf8($v);
            }

            // Eliminar BOM del primer campo
            if (strpos($datos[0], "\xEF\xBB\xBF") === 0) {
                $datos[0] = substr($datos[0], 3);
            }

            $num_cols = count($datos);
            if ($num_cols !== 9 && $num_cols !== 10) {
                echo "<span class='error'>⚠️ Línea $linea: número de columnas inválido ($num_cols)</span><br>";
                $result['errores']++;
                continue;
            }

            // Parsear según 9 o 10 columnas
            if ($num_cols === 10) {
                list($nro_legajo, $nro_familia, $apellido, $nombre, $curso,
                     $dni, $cod_desc, $porc_desc, $entero, $centavos) = $datos;
                $saldo = floatval($entero) + floatval($centavos) / 100;
            } else {
                list($nro_legajo, $nro_familia, $apellido, $nombre, $curso,
                     $dni, $cod_desc, $porc_desc, $saldo) = $datos;
            }

            // ===== LIMPIEZA MÍNIMA =====
            $dni = trim($dni);
            if (!$dni || !is_numeric($dni)) {
                echo "<span class='error'>⚠️ Línea $linea: DNI inválido o vacío</span><br>";
                $result['errores']++;
                continue;
            }

            // 🔥 PRIORIDAD ACTIVOS POR DNI (saltar inactivos con DNI existente en activos)
            if (is_array($skip_dni_map) && isset($skip_dni_map[$dni])) {
                $result['skipped_due_to_active'][] = [
                    'dni' => $dni,
                    'activo_ref' => $skip_dni_map[$dni],
                    'inactivo' => [
                        'nro_legajo' => $nro_legajo,
                        'apellido' => $apellido,
                        'nombre' => $nombre,
                        'saldo_total' => $saldo
                    ]
                ];
                continue;
            }

            // 🔥 SE USAN LOS VALORES ORIGINALES SIN MODIFICAR
            $nro_legajo = trim($nro_legajo);
            $nro_familia = trim($nro_familia);
            $apellido = trim($apellido);
            $nombre = trim($nombre);
            $curso = trim($curso);
            $cod_desc = (int)$cod_desc;
            $porc_desc = (int)$porc_desc;
            $saldo = is_numeric($saldo) ? (float)$saldo : 0;

            if (!familia_coincide_con_legajo($nro_legajo, $nro_familia)) {
                $pref = prefijo_numero_legajo($nro_legajo);
                $result['familia_legajo_errores'][] = [
                    'linea' => $linea,
                    'nro_legajo' => $nro_legajo,
                    'nro_familia' => $nro_familia,
                    'prefijo_legajo' => $pref,
                    'apellido' => $apellido,
                    'nombre' => $nombre,
                ];
                echo "<span class='error'>⚠️ Línea $linea: nro. familia (<strong>" . htmlspecialchars($nro_familia) . "</strong>) no coincide con el prefijo del legajo (<strong>" . htmlspecialchars($pref) . "</strong> de " . htmlspecialchars($nro_legajo) . ") — " . htmlspecialchars($apellido . ', ' . $nombre) . "</span><br>";
                $result['errores']++;
                continue;
            }

            if (isset($primera_fila_por_dni[$dni])) {
                $dup_row = [
                    'linea' => $linea,
                    'nro_legajo' => $nro_legajo,
                    'nro_familia' => $nro_familia,
                    'apellido' => $apellido,
                    'nombre' => $nombre,
                    'curso' => $curso,
                ];
                if (!isset($result['dni_duplicados_en_archivo'][$dni])) {
                    $result['dni_duplicados_en_archivo'][$dni] = [$primera_fila_por_dni[$dni]];
                }
                $result['dni_duplicados_en_archivo'][$dni][] = $dup_row;
                echo "<span class='error'>⚠️ Línea $linea: DNI duplicado en el archivo (<strong>" . htmlspecialchars($dni) . "</strong>) — se omite esta fila. " . htmlspecialchars($apellido . ', ' . $nombre) . " (legajo " . htmlspecialchars($nro_legajo) . ")</span><br>";
                $result['errores']++;
                continue;
            }
            $primera_fila_por_dni[$dni] = [
                'linea' => $linea,
                'nro_legajo' => $nro_legajo,
                'nro_familia' => $nro_familia,
                'apellido' => $apellido,
                'nombre' => $nombre,
                'curso' => $curso,
            ];

            $stmt->bind_param(
                "ssssssiid",
                $nro_legajo,
                $nro_familia,
                $apellido,
                $nombre,
                $curso,
                $dni,
                $cod_desc,
                $porc_desc,
                $saldo
            );

            try {
                $stmt->execute();
                if ($build_dni_map) {
                    $dni_map_local[$dni] = [
                        'nro_legajo' => $nro_legajo,
                        'apellido' => $apellido,
                        'nombre' => $nombre,
                        'saldo_total' => $saldo
                    ];
                }
                if ($stmt->affected_rows === 1) {
                    $result['importados']++;
                } else {
                    $result['actualizados']++;
                }
            } catch (mysqli_sql_exception $e) {
                if ($e->getCode() == 1062) {
                    echo "<span class='error'>❌ Línea $linea: Duplicado en campo único (legajo: " . htmlspecialchars($nro_legajo ?? 'NULL') . ") - " . $e->getMessage() . "</span><br>";
                    $result['errores']++;
                } else {
                    throw $e;
                }
            }
        }

        $conn->commit();

    } catch (Throwable $e) {
        $conn->rollback();
        echo "<span class='error'>❌ ERROR GRAVE: {$e->getMessage()}</span><br>";
        $result['hubo_error_grave'] = true;
    } finally {
        if (isset($stmt)) $stmt->close();
        if (isset($archivo)) fclose($archivo);
    }

    if ($build_dni_map) {
        $result['dni_map'] = $dni_map_local;
    }

    return $result;
}

function imprimir_errores_familia_legajo($titulo_archivo, $lista) {
    if (empty($lista)) {
        return;
    }
    echo "<div class='details'>";
    echo "<h3 class='error'>Número de familia distinto al prefijo del legajo — {$titulo_archivo}</h3>";
    echo "<p>La parte del <strong>nro_legajo</strong> antes de la barra (<code>/</code>) debe coincidir exactamente con <strong>nro_familia</strong> (p. ej. legajo <code>3714/01</code> → familia <code>3714</code>). Estas filas <strong>no se guardaron</strong>.</p>";
    echo "<table><thead><tr><th>Línea</th><th>nro_legajo</th><th>nro_familia</th><th>Prefijo legajo</th><th>Alumno</th></tr></thead><tbody>";
    foreach ($lista as $e) {
        $al = htmlspecialchars(trim(($e['apellido'] ?? '') . ', ' . ($e['nombre'] ?? '')));
        echo '<tr><td>' . (int)$e['linea'] . '</td><td>' . htmlspecialchars((string)($e['nro_legajo'] ?? '')) . '</td><td>' . htmlspecialchars((string)($e['nro_familia'] ?? '')) . '</td><td>' . htmlspecialchars((string)($e['prefijo_legajo'] ?? '')) . "</td><td>{$al}</td></tr>";
    }
    echo '</tbody></table><p><strong>Total filas rechazadas por este motivo: ' . count($lista) . '</strong></p></div>';
}

function imprimir_dnis_duplicados_en_archivo($titulo_archivo, $map, $nombre_tabla) {
    if (empty($map)) {
        return;
    }
    echo "<div class='details'>";
    echo "<h3 class='error'>DNI repetidos en el CSV — {$titulo_archivo}</h3>";
    echo "<p>En <code>" . htmlspecialchars($nombre_tabla) . "</code> el DNI es único. Por cada DNI repetido en el archivo solo quedó cargada la <strong>primera</strong> fila; las demás se omitieron para evitar pisar datos (antes MySQL podía mezclar registros con <code>ON DUPLICATE KEY UPDATE</code>).</p>";
    ksort($map, SORT_STRING);
    foreach ($map as $dni => $filas) {
        if (!is_array($filas) || $filas === []) {
            continue;
        }
        $nd = htmlspecialchars((string)$dni);
        $n = count($filas);
        echo "<h4>DNI {$nd} <span class='warning'>({$n} filas en el archivo)</span></h4>";
        echo "<table><thead><tr><th>Línea</th><th>nro_legajo</th><th>nro_familia</th><th>Apellido</th><th>Nombre</th><th>Curso</th></tr></thead><tbody>";
        foreach ($filas as $f) {
            echo '<tr><td>' . (int)($f['linea'] ?? 0) . '</td><td>' . htmlspecialchars((string)($f['nro_legajo'] ?? '')) . '</td><td>' . htmlspecialchars((string)($f['nro_familia'] ?? '')) . '</td><td>' . htmlspecialchars((string)($f['apellido'] ?? '')) . '</td><td>' . htmlspecialchars((string)($f['nombre'] ?? '')) . '</td><td>' . htmlspecialchars((string)($f['curso'] ?? '')) . '</td></tr>';
        }
        echo '</tbody></table>';
    }
    $total_dnis = count($map);
    echo "<p><strong>DNIs distintos con repetición en el archivo: {$total_dnis}</strong></p></div>";
}

// =====================================================
// PROCESO PRINCIPAL
// =====================================================

// 1) Activos
echo "<div class='stats'>";
echo "<strong>📁 Importando activos (legajos.csv)...</strong><br>";
$res_activos = importar_csv($conn, 'legajos.csv', 'legajos', null, true);

// Construir mapa de DNIs activos (aunque tras truncar estará vacío, lo dejamos por si se reutiliza)
$active_dni_map = [];
if (!empty($res_activos['dni_map']) && is_array($res_activos['dni_map'])) {
    foreach ($res_activos['dni_map'] as $dni => $info) {
        $active_dni_map[$dni] = $info;
    }
}

echo "<br>";
if ($res_activos['hubo_error_grave']) {
    echo "<span class='error'>❌ La importación de activos tuvo errores graves y se revirtió.</span><br>";
} else {
    echo "<span class='success'>✔️ Importación activos finalizada correctamente.</span><br>";
}
echo "📥 Nuevos registros (activos): <strong>" . (int)$res_activos['importados'] . "</strong><br>";
echo "♻️ Registros actualizados (activos): <strong>" . (int)$res_activos['actualizados'] . "</strong><br>";
echo "❗ Líneas con error (activos): <strong class='error'>" . (int)$res_activos['errores'] . "</strong><br>";
echo "ℹ️ Líneas procesadas (activos): <strong>" . (int)$res_activos['lineas_procesadas'] . "</strong><br>";
echo "</div>";

imprimir_errores_familia_legajo('legajos.csv', $res_activos['familia_legajo_errores'] ?? []);
imprimir_dnis_duplicados_en_archivo('legajos.csv', $res_activos['dni_duplicados_en_archivo'] ?? [], 'legajos');

// 2) Inactivos
echo "<div class='stats'>";
echo "<strong>📁 Importando inactivos (legajos-inactivos.csv) — se saltarán los DNI que ya existan en activos...</strong><br>";
$res_inactivos = importar_csv($conn, 'legajos-inactivos.csv', 'legajos_inactivos', $active_dni_map, false);

echo "<br>";
if ($res_inactivos['hubo_error_grave']) {
    echo "<span class='error'>❌ La importación de inactivos tuvo errores graves y se revirtió.</span><br>";
} else {
    echo "<span class='success'>✔️ Importación inactivos finalizada correctamente.</span><br>";
}
echo "📥 Nuevos registros (inactivos): <strong>" . (int)$res_inactivos['importados'] . "</strong><br>";
echo "♻️ Registros actualizados (inactivos): <strong>" . (int)$res_inactivos['actualizados'] . "</strong><br>";
echo "❗ Líneas con error (inactivos): <strong class='error'>" . (int)$res_inactivos['errores'] . "</strong><br>";
echo "ℹ️ Líneas procesadas (inactivos): <strong>" . (int)$res_inactivos['lineas_procesadas'] . "</strong><br>";
echo "</div>";

imprimir_errores_familia_legajo('legajos-inactivos.csv', $res_inactivos['familia_legajo_errores'] ?? []);
imprimir_dnis_duplicados_en_archivo('legajos-inactivos.csv', $res_inactivos['dni_duplicados_en_archivo'] ?? [], 'legajos_inactivos');

// 3) Reporte de conflictos por DNI
$conflictos = $res_inactivos['skipped_due_to_active'] ?? [];
if (!empty($conflictos)) {
    echo "<h3>🔍 Conflictos detectados (mismo DNI en activo e inactivo)</h3>";
    echo "<p>Se priorizó el registro activo. Los registros inactivos que coinciden por DNI fueron <strong>saltados</strong> y no se insertaron en <code>legajos_inactivos</code>.</p>";
    echo "<table class='table table-bordered'>";
    echo "<thead><tr><th>DNI</th><th>Activo</th><th>Inactivo (saltado)</th></tr></thead>";
    echo "<tbody>";
    foreach ($conflictos as $c) {
        $dni = htmlspecialchars($c['dni']);
        $activo = $c['activo_ref'];
        $inactivo = $c['inactivo'];
        $activo_nombre = htmlspecialchars(trim($activo['apellido'] . ' ' . $activo['nombre']));
        $inactivo_nombre = htmlspecialchars(trim($inactivo['apellido'] . ' ' . $inactivo['nombre']));
        echo "<tr><td>$dni</td><td>$activo_nombre</td><td>$inactivo_nombre</td></tr>";
    }
    echo "</tbody></table>";
    echo "<p><strong>Total de casos detectados y saltados: " . count($conflictos) . "</strong></p>";
} else {
    echo "<h3 class='success'>✅ No hubo filas de inactivos omitidas por tener el mismo DNI que un alumno activo.</h3>";
}

$conn->close();
echo "</div>";
echo "</body></html>";
?>