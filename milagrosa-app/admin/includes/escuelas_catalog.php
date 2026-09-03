<?php
/**
 * Catálogo de escuelas/salas del tenant (sin dependencias pesadas).
 */

require_once dirname(__DIR__, 2) . '/config/tenant_helpers.php';

function admin_escuelas_catalog(): array
{
    return tenant_escuelas_catalog();
}

function admin_validar_escuela_codigo(string $codigo, array $escuelas): ?string
{
    $codigo = strtoupper(trim($codigo));

    return isset($escuelas[$codigo]) ? $codigo : null;
}
