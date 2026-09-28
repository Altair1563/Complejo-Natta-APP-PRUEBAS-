<?php
/**
 * Ingresantes externos 2027 (cursos EX de Nuestra Señora de Belén).
 * EXJ Externos Jardín, EXP Externos Primaria, EXS Externos Secundaria.
 * Solo tienen liquidada la cuota 10: Adelanto de Reserva de vacante (2027).
 * La cuota 11 (Resto de Reserva de Vacante) habilita la evaluación de deudas del contrato.
 */

function ingresante_externo_2027_cursos(): array
{
    return ['EXJ', 'EXP', 'EXS'];
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
 * Último año de jardín y de primaria: además de sus cuotas del ciclo,
 * tienen cuota 15 (Adelanto) y cuota 16 (Resto) de Reserva de Vacante 2027.
 */
function adelanto_rv_nivel_2027_cursos(): array
{
    return ['3AJ', '3BJ', '6AP', '6BP'];
}

function curso_es_adelanto_rv_nivel_2027(?string $curso): bool
{
    $c = mb_strtoupper(trim((string)$curso), 'UTF-8');

    return $c !== '' && in_array($c, adelanto_rv_nivel_2027_cursos(), true);
}

function adelanto_rv_nivel_2027_numero_cuota(): int
{
    return 15;
}

function resto_rv_nivel_2027_numero_cuota(): int
{
    return 16;
}

function adelanto_rv_nivel_2027_nombre_cuota(): string
{
    return 'Adelanto de Reserva de Vacante 2027';
}

function resto_rv_nivel_2027_nombre_cuota(): string
{
    return 'Resto de Reserva de Vacante 2027';
}

/** Firma habilitada por adelanto de RV (EX cuota 10, o 3AJ/3BJ/6AP/6BP cuota 15). */
function curso_firma_por_adelanto_rv(?string $curso): bool
{
    return curso_es_ingresante_externo_2027($curso) || curso_es_adelanto_rv_nivel_2027($curso);
}

function numero_cuota_adelanto_rv_curso(string $curso): int
{
    return curso_es_adelanto_rv_nivel_2027($curso)
        ? adelanto_rv_nivel_2027_numero_cuota()
        : ingresante_externo_2027_numero_cuota();
}

function numero_cuota_resto_rv_curso(string $curso): int
{
    return curso_es_adelanto_rv_nivel_2027($curso)
        ? resto_rv_nivel_2027_numero_cuota()
        : 11;
}

function nota_requisito_adelanto_rv_curso(string $curso): string
{
    if (curso_es_adelanto_rv_nivel_2027($curso)) {
        return 'Ingresantes externos 2027: únicamente CUOTA-15 Adelanto de Reserva de Vacante 2027 abonada.';
    }

    return 'Ingresantes externos 2027: únicamente CUOTA-10 Adelanto de Reserva de vacante (2027) abonada.';
}

function nota_requisito_sin_deudas_adelanto_curso(string $curso): string
{
    if (curso_es_adelanto_rv_nivel_2027($curso)) {
        return 'se habilitará una vez abonada la CUOTA-16 Resto de Reserva de Vacante 2027 y que el grupo familiar no registre deuda pendiente';
    }

    return 'se habilitará una vez abonada la CUOTA-11 RESTO DE RV 2027 y que el grupo familiar no registre deuda pendiente';
}

function texto_estado_firma_bloqueada_adelanto(string $curso): string
{
    if (curso_es_adelanto_rv_nivel_2027($curso)) {
        return 'Estará disponible una vez abonada la CUOTA-15 Adelanto de Reserva de Vacante 2027';
    }

    return 'Estará disponible una vez abonada la CUOTA-10 Adelanto de Reserva de vacante (2027)';
}

/**
 * Cuota futura / no liquidada.
 * EX: solo la cuota 10. Cursos 3AJ/3BJ/6AP/6BP: ciclo normal más cuotas 15 y 16.
 */
function cuota_es_futura_para_curso(int $numCuota, int $cuotaVigente, int $mesActual, string $curso = ''): bool
{
    if (curso_es_ingresante_externo_2027($curso)) {
        return $numCuota !== ingresante_externo_2027_numero_cuota();
    }

    if (curso_es_adelanto_rv_nivel_2027($curso)
        && ($numCuota === adelanto_rv_nivel_2027_numero_cuota() || $numCuota === resto_rv_nivel_2027_numero_cuota())
    ) {
        return false;
    }

    if ($numCuota <= 9) {
        return $numCuota > $cuotaVigente;
    }

    if ($numCuota >= 13) {
        return true;
    }

    return $mesActual < 3;
}

function cuota_nombre_para_curso(int $numCuota, string $curso = '', ?string $fallback = null): string
{
    if (curso_es_ingresante_externo_2027($curso) && $numCuota === ingresante_externo_2027_numero_cuota()) {
        return ingresante_externo_2027_nombre_cuota();
    }

    if (curso_es_adelanto_rv_nivel_2027($curso) && $numCuota === adelanto_rv_nivel_2027_numero_cuota()) {
        return adelanto_rv_nivel_2027_nombre_cuota();
    }

    if (curso_es_adelanto_rv_nivel_2027($curso) && $numCuota === resto_rv_nivel_2027_numero_cuota()) {
        return resto_rv_nivel_2027_nombre_cuota();
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
        15 => 'Adelanto de Reserva de Vacante 2027',
        16 => 'Resto de Reserva de Vacante 2027',
    ];

    return $meses[$numCuota] ?? ('Cuota ' . $numCuota);
}
