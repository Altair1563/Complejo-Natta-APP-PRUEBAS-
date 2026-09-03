<?php
/**
 * Collation unificada para comparaciones entre tablas legacy y nuevas.
 */

const ADMIN_DB_COLLATE = 'utf8mb4_unicode_ci';

function admin_sql_collate(string $expr): string
{
    return $expr . ' COLLATE ' . ADMIN_DB_COLLATE;
}

function admin_mysqli_apply_collation(mysqli $conn): void
{
    $conn->set_charset('utf8mb4');
    @$conn->query("SET NAMES utf8mb4 COLLATE '" . ADMIN_DB_COLLATE . "'");
}
