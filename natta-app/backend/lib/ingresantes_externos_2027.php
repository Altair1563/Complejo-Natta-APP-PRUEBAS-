<?php
/**
 * Ingresantes externos 2027 (cursos EX*).
 * Solo tienen liquidada la cuota 10: Adelanto de Reserva de vacante (2027).
 */

function ingresante_externo_2027_cursos(): array
{
    return ['EXCJ', 'EXHV', 'EXJA', 'EXJN', 'EXSC', 'EXMB', 'EXET'];
}

function curso_es_ingresante_externo_2027(?string $curso): bool
{
    $c = mb_strtoupper(trim((string)$curso), 'UTF-8');

    return $c !== '' && in_array($c, ingresante_externo_2027_cursos(), true);
}

function ingresante_externo_2027_numero_cuota(): int
{
    return 10;
}

function ingresante_externo_2027_nombre_cuota(): string
{
    return 'Adelanto de Reserva de vacante (2027)';
}

/**
 * Cuota futura / no liquidada. En ingresantes externos 2027 solo la cuota 10 está liquidada.
 */
function cuota_es_futura_para_curso(int $numCuota, int $cuotaVigente, int $mesActual, string $curso = ''): bool
{
    if (curso_es_ingresante_externo_2027($curso)) {
        return $numCuota !== ingresante_externo_2027_numero_cuota();
    }

    if ($numCuota <= 9) {
        return $numCuota > $cuotaVigente;
    }

    return $mesActual < 3;
}

function cuota_nombre_para_curso(int $numCuota, string $curso = '', ?string $fallback = null): string
{
    if (curso_es_ingresante_externo_2027($curso) && $numCuota === ingresante_externo_2027_numero_cuota()) {
        return ingresante_externo_2027_nombre_cuota();
    }

    if ($fallback !== null && $fallback !== '') {
        return $fallback;
    }

    $meses = [
        1 => 'Marzo',
        2 => 'Abril',
        3 => 'Mayo',
        4 => 'Junio',
        5 => 'Julio',
        6 => 'Agosto',
        7 => 'Septiembre',
        8 => 'Octubre',
        9 => 'Noviembre',
        10 => 'Adelanto de Reserva de vacante (2027)',
        11 => 'Resto Reserva de Vacante',
        12 => 'Reserva de Vacante 2026',
    ];

    return $meses[$numCuota] ?? ('Cuota ' . $numCuota);
}
