<?php
/**
 * Catálogo de escuelas (sin dependencias pesadas).
 */

function admin_escuelas_catalog(): array
{
    return [
        'CJ' => 'Casita de Jesus',
        'HV' => 'La hormiguita Viajera',
        'JA' => 'Jardin de la Alegria',
        'SC' => 'Instituto Santa Cruz',
        'JN' => 'Instituto Jesus Niño',
        'MB' => 'Instituto Manuel Belgrano',
        'ET' => 'Instituto de Educacion Tecnica',
        'SU' => 'Instituto de Educacion Superior',
    ];
}

function admin_validar_escuela_codigo(string $codigo, array $escuelas): ?string
{
    $codigo = strtoupper(trim($codigo));

    return isset($escuelas[$codigo]) ? $codigo : null;
}
