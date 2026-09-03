<?php
/**
 * Extrae y analiza streams FlateDecode de un PDF para medir márgenes de contenido.
 */
$path = $argv[1] ?? (dirname(__DIR__, 2) . '/storage/contratos_firmados/2026/Contrato_JB_2027_v1_00000000_20260101000000.pdf');
$raw = (string) file_get_contents($path);

echo 'Archivo: ' . $path . PHP_EOL;
echo 'Tamaño: ' . number_format(strlen($raw)) . ' bytes' . PHP_EOL;

if (preg_match('/\/MediaBox\s*\[\s*([^\]]+)\]/', $raw, $m)) {
    $p = preg_split('/\s+/', trim($m[1]));
    $w = (float) $p[2] - (float) $p[0];
    $h = (float) $p[3] - (float) $p[1];
    echo "MediaBox: {$w} × {$h} pt (" . round($w * 0.3528, 1) . ' × ' . round($h * 0.3528, 1) . " mm)\n\n";
}

$pageW = 595.28;
$pageH = 841.89;

$allX = [];
$allY = [];
$streamCount = 0;

if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $raw, $streams)) {
    foreach ($streams[1] as $idx => $compressed) {
        $decoded = @gzuncompress($compressed);
        if ($decoded === false) {
            $decoded = @gzinflate($compressed);
        }
        if ($decoded === false) {
            // stream sin comprimir
            if (strpos($compressed, 'Tm') !== false || strpos($compressed, ' cm') !== false) {
                $decoded = $compressed;
            } else {
                continue;
            }
        }
        $streamCount++;
        extractPositions($decoded, $allX, $allY);
    }
}

echo "Streams analizados: {$streamCount}\n\n";

if (empty($allX)) {
    echo "No se detectaron posiciones de contenido (streams comprimidos u otro formato).\n";
    exit(0);
}

$minX = min($allX);
$maxX = max($allX);
$minY = min($allY);
$maxY = max($allY);

echo "Contenido detectado (coordenadas PDF, origen abajo-izquierda):\n";
echo '  X mínimo (margen izquierdo): ' . round($minX, 2) . ' pt ≈ ' . round($minX * 0.3528, 1) . " mm\n";
echo '  X máximo: ' . round($maxX, 2) . ' pt → margen derecho ≈ ' . round($pageW - $maxX, 2) . ' pt ≈ ' . round(($pageW - $maxX) * 0.3528, 1) . " mm\n";
echo '  Y máximo (cerca del borde superior): ' . round($maxY, 2) . ' pt → margen superior ≈ ' . round($pageH - $maxY, 2) . ' pt ≈ ' . round(($pageH - $maxY) * 0.3528, 1) . " mm\n";
echo '  Y mínimo (cerca del borde inferior): ' . round($minY, 2) . ' pt → margen inferior ≈ ' . round($minY, 2) . ' pt ≈ ' . round($minY * 0.3528, 1) . " mm\n\n";

echo "Referencias:\n";
echo '  Dompdf default @page margin = 1.2 cm ≈ 34 pt por lado' . PHP_EOL;
echo '  Margen 6 mm ≈ 17 pt' . PHP_EOL;
echo '  Margen 0 mm = 0 pt' . PHP_EOL;

function extractPositions(string $stream, array &$allX, array &$allY): void
{
    // cm: a b c d e f  → e=x, f=y
    if (preg_match_all('/(-?\d+\.?\d*)\s+(-?\d+\.?\d*)\s+(-?\d+\.?\d*)\s+(-?\d+\.?\d*)\s+(-?\d+\.?\d*)\s+(-?\d+\.?\d*)\s+cm\b/', $stream, $m, PREG_SET_ORDER)) {
        foreach ($m as $cm) {
            $x = (float) $cm[5];
            $y = (float) $cm[6];
            if ($x >= 0 && $x < 600) {
                $allX[] = $x;
            }
            if ($y >= 0 && $y < 900) {
                $allY[] = $y;
            }
        }
    }
    // Tm: a b c d e f → e=x, f=y
    if (preg_match_all('/(-?\d+\.?\d*)\s+(-?\d+\.?\d*)\s+(-?\d+\.?\d*)\s+(-?\d+\.?\d*)\s+(-?\d+\.?\d*)\s+(-?\d+\.?\d*)\s+Tm\b/', $stream, $m, PREG_SET_ORDER)) {
        foreach ($m as $tm) {
            $x = (float) $tm[5];
            $y = (float) $tm[6];
            if ($x >= 0 && $x < 600) {
                $allX[] = $x;
            }
            if ($y >= 0 && $y < 900) {
                $allY[] = $y;
            }
        }
    }
    // re (rectángulos): x y w h
    if (preg_match_all('/(-?\d+\.?\d*)\s+(-?\d+\.?\d*)\s+(-?\d+\.?\d*)\s+(-?\d+\.?\d*)\s+re\b/', $stream, $m, PREG_SET_ORDER)) {
        foreach ($m as $re) {
            $x = (float) $re[1];
            $y = (float) $re[2];
            if ($x >= 0 && $x < 600) {
                $allX[] = $x;
                $allX[] = $x + (float) $re[3];
            }
            if ($y >= 0 && $y < 900) {
                $allY[] = $y;
                $allY[] = $y + (float) $re[4];
            }
        }
    }
}
