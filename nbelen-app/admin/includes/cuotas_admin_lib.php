<?php
/**
 * Lógica compartida de cuotas y ventana de mes lógico (admin).
 * Usada por listado de familias e información general.
 */

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
            10 => 'ADELANTO RV',
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
        if (!function_exists('tenant_escuela_desde_curso')) {
            require_once dirname(__DIR__, 2) . '/config/tenant_helpers.php';
        }

        return tenant_escuela_desde_curso($curso);
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
    function admin_cuota_es_en_ventana(int $numeroCuota, string $escuela, int $mesSeleccionado): bool
    {
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
     * La mora se basa en cuotas con diferencia > umbral (igual que el portal familia),
     * sin que créditos/negativos anulen meses impagos.
     *
     * @param array<int, array{numero_cuota:int, diferencia:float, monto_facturado?:float, monto_ingresado?:float}> $cuotas
     * @return array{
     *   deuda_neta: float,
     *   deuda_impaga: float,
     *   deuda_reportada: float,
     *   moroso: bool,
     *   slots_total: int,
     *   slots_abonadas: int,
     *   monto_facturado: float,
     *   monto_ingresado: float,
     *   primer_mes_impago: int|null,
     *   ultimo_mes_impago: int|null,
     *   meses_impagos: list<int>
     * }
     */
    function admin_cuota_acumular_ventana_legajo(array $cuotas, string $escuela, int $mesSeleccionado, bool $incluirMontos = false): array
    {
        $umbral = admin_cuota_umbral_al_dia();
        $deudaNeta = 0.0;
        $deudaImpaga = 0.0;
        $montoFacturado = 0.0;
        $montoIngresado = 0.0;
        $slotsTotal = 0;
        $slotsAbonadas = 0;
        $primerMesImpago = null;
        $ultimoMesImpago = null;
        $mesesImpagos = [];

        foreach ($cuotas as $cuota) {
            $numeroCuota = (int)($cuota['numero_cuota'] ?? 0);
            if (!admin_cuota_es_en_ventana($numeroCuota, $escuela, $mesSeleccionado)) {
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
                $deudaImpaga += $dif;
                $mesesImpagos[] = (int)$mesLogico;
                if ($primerMesImpago === null || $mesLogico < $primerMesImpago) {
                    $primerMesImpago = $mesLogico;
                }
                if ($ultimoMesImpago === null || $mesLogico > $ultimoMesImpago) {
                    $ultimoMesImpago = $mesLogico;
                }
            }
        }

        $mesesImpagos = array_values(array_unique($mesesImpagos));
        sort($mesesImpagos, SORT_NUMERIC);

        $deudaReportada = $deudaImpaga > $umbral ? $deudaImpaga : 0.0;

        return [
            'deuda_neta' => $deudaNeta,
            'deuda_impaga' => $deudaImpaga,
            'deuda_reportada' => $deudaReportada,
            'moroso' => $deudaImpaga > $umbral,
            'slots_total' => $slotsTotal,
            'slots_abonadas' => $slotsAbonadas,
            'monto_facturado' => $montoFacturado,
            'monto_ingresado' => $montoIngresado,
            'primer_mes_impago' => $primerMesImpago,
            'ultimo_mes_impago' => $ultimoMesImpago,
            'meses_impagos' => $mesesImpagos,
        ];
    }
}

if (!function_exists('admin_cuota_formato_meses_impagos')) {
    /**
     * Formato de rango: "ABRIL – JUNIO" (solo primer y último mes adeudado).
     *
     * @param list<int>|null $mesesImpagos
     */
    function admin_cuota_formato_meses_impagos($mesesImpagos, $primerMes = null, $ultimoMes = null): string
    {
        $desde = null;
        $hasta = null;

        if (is_array($mesesImpagos) && $mesesImpagos !== []) {
            $meses = [];
            foreach ($mesesImpagos as $m) {
                $m = (int)$m;
                if ($m >= 1 && $m <= 12) {
                    $meses[] = $m;
                }
            }
            if ($meses !== []) {
                $desde = min($meses);
                $hasta = max($meses);
            }
        }

        if ($desde === null && $primerMes !== null) {
            $desde = (int)$primerMes;
            $hasta = $ultimoMes !== null ? (int)$ultimoMes : $desde;
        }

        if ($desde === null || $desde < 1 || $desde > 12) {
            return '';
        }
        if ($hasta === null || $hasta < 1 || $hasta > 12) {
            $hasta = $desde;
        }

        $desdeTxt = admin_cuota_nombre_mes($desde);
        if ($desdeTxt === '') {
            return '';
        }
        if ($hasta === $desde) {
            return $desdeTxt;
        }

        $hastaTxt = admin_cuota_nombre_mes($hasta);
        return $hastaTxt !== '' ? ($desdeTxt . ' – ' . $hastaTxt) : $desdeTxt;
    }
}
