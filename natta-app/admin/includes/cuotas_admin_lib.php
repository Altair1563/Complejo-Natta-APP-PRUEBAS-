<?php
/**
 * Lógica compartida de cuotas y ventana de mes lógico (admin).
 * Usada por listado de familias e información general.
 */

require_once dirname(__DIR__, 2) . '/backend/lib/ingresantes_externos_2027.php';

if (!function_exists('admin_cuota_umbral_al_dia')) {
    function admin_cuota_umbral_al_dia(): float
    {
        return 0.01;
    }
}

if (!function_exists('admin_cuota_nombre_mes')) {
    /** 1 = MARZO … 9 = NOVIEMBRE, 10–12 = RV */
    function admin_cuota_nombre_mes($n)
    {
        $map = [
            1  => 'MARZO',
            2  => 'ABRIL',
            3  => 'MAYO',
            4  => 'JUNIO',
            5  => 'JULIO',
            6  => 'AGOSTO',
            7  => 'SEPTIEMBRE',
            8  => 'OCTUBRE',
            9  => 'NOVIEMBRE',
            10 => 'ADELANTO RV 2027',
            11 => 'RESTO RV',
            12 => 'RV COMPLETA',
        ];
        return isset($map[$n]) ? $map[$n] : '';
    }
}

if (!function_exists('admin_cuota_mes_vigente_config')) {
    /**
     * Mes de corte "vigente" configurado por el admin (clave configuracion.cuota_vigente).
     * 1 = MARZO … 9 = NOVIEMBRE, 10–12 = RV. Devuelve valor entre 1 y 12.
     */
    function admin_cuota_mes_vigente_config(mysqli $conn): int
    {
        $mes = 0;
        $res = @$conn->query("SELECT valor FROM configuracion WHERE clave = 'cuota_vigente' LIMIT 1");
        if ($res instanceof mysqli_result) {
            $row = $res->fetch_assoc();
            $res->free();
            if ($row !== null) {
                $mes = (int)($row['valor'] ?? 0);
            }
        }
        if ($mes < 1 || $mes > 12) {
            $mes = 9;
        }
        return $mes;
    }
}

if (!function_exists('obtenerEscuelaDesdeCurso')) {
    function obtenerEscuelaDesdeCurso($curso)
    {
        $curso = mb_strtoupper(trim($curso ?? ''), 'UTF-8');
        if ($curso !== '' && mb_strlen($curso, 'UTF-8') >= 2) {
            return mb_substr($curso, -2, 2, 'UTF-8');
        }
        return '';
    }
}

if (!function_exists('cuotaANumeroMesLogico')) {
    /**
     * Mapea numero_cuota físico → mes lógico (1–12) según escuela.
     * SU: 2..10 => 1..9 (sin RV). Resto: 1..9 y 10..12 (RV).
     */
    function cuotaANumeroMesLogico($numero_cuota, $escuela)
    {
        $numero_cuota = (int)$numero_cuota;

        if ($escuela === 'SU') {
            if ($numero_cuota >= 2 && $numero_cuota <= 10) {
                return $numero_cuota - 1;
            }
            return null;
        }
        if ($numero_cuota >= 1 && $numero_cuota <= 9) {
            return $numero_cuota;
        }
        if ($numero_cuota >= 10 && $numero_cuota <= 12) {
            return $numero_cuota;
        }
        return null;
    }
}

if (!function_exists('admin_cuota_es_en_ventana')) {
    function admin_cuota_es_en_ventana(int $numeroCuota, string $escuela, int $mesSeleccionado, string $curso = ''): bool
    {
        if (curso_es_ingresante_externo_2027($curso)) {
            return $numeroCuota === ingresante_externo_2027_numero_cuota();
        }
        if ($mesSeleccionado < 1 || $mesSeleccionado > 12) {
            return false;
        }
        $mesLogico = cuotaANumeroMesLogico($numeroCuota, $escuela);
        return $mesLogico !== null && $mesLogico <= $mesSeleccionado;
    }
}

if (!function_exists('admin_cuota_acumular_ventana_legajo')) {
    /**
     * Acumula deuda y montos de un legajo dentro de la ventana [1..mesSeleccionado].
     *
     * @param array<int, array{numero_cuota:int, diferencia:float, monto_facturado?:float, monto_ingresado?:float}> $cuotas
     * @return array{
     *   deuda_neta: float,
     *   deuda_reportada: float,
     *   moroso: bool,
     *   slots_total: int,
     *   slots_abonadas: int,
     *   monto_facturado: float,
     *   monto_ingresado: float,
     *   primer_mes_impago: int|null,
     *   ultimo_mes_impago: int|null
     * }
     */
    function admin_cuota_acumular_ventana_legajo(array $cuotas, string $escuela, int $mesSeleccionado, bool $incluirMontos = false, string $curso = ''): array
    {
        $umbral = admin_cuota_umbral_al_dia();
        $deudaNeta = 0.0;
        $montoFacturado = 0.0;
        $montoIngresado = 0.0;
        $slotsTotal = 0;
        $slotsAbonadas = 0;
        $primerMesImpago = null;
        $ultimoMesImpago = null;

        foreach ($cuotas as $cuota) {
            $numeroCuota = (int)($cuota['numero_cuota'] ?? 0);
            if (!admin_cuota_es_en_ventana($numeroCuota, $escuela, $mesSeleccionado, $curso)) {
                continue;
            }

            $dif = (float)($cuota['diferencia'] ?? 0);
            $deudaNeta += $dif;

            if ($incluirMontos) {
                $montoFacturado += (float)($cuota['monto_facturado'] ?? 0);
                $montoIngresado += (float)($cuota['monto_ingresado'] ?? 0);
            }

            $slotsTotal++;
            if ($dif <= $umbral) {
                $slotsAbonadas++;
            }

            $mesLogico = cuotaANumeroMesLogico($numeroCuota, $escuela);
            if ($mesLogico !== null && $dif > $umbral) {
                if ($primerMesImpago === null || $mesLogico < $primerMesImpago) {
                    $primerMesImpago = $mesLogico;
                }
                if ($ultimoMesImpago === null || $mesLogico > $ultimoMesImpago) {
                    $ultimoMesImpago = $mesLogico;
                }
            }
        }

        $deudaReportada = $deudaNeta > $umbral ? $deudaNeta : 0.0;

        return [
            'deuda_neta' => $deudaNeta,
            'deuda_reportada' => $deudaReportada,
            'moroso' => $deudaNeta > $umbral,
            'slots_total' => $slotsTotal,
            'slots_abonadas' => $slotsAbonadas,
            'monto_facturado' => $montoFacturado,
            'monto_ingresado' => $montoIngresado,
            'primer_mes_impago' => $primerMesImpago,
            'ultimo_mes_impago' => $ultimoMesImpago,
        ];
    }
}
