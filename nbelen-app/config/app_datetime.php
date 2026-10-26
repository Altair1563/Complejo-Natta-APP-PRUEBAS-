<?php
/**
 * Reloj institucional: Argentina (UTC-3).
 *
 * Convención:
 * - Los campos *_utc (ej. accepted_at_utc) se guardan con gmdate() en UTC.
 * - Al mostrarlos se convierten a America/Argentina/Buenos_Aires.
 * - El resto de DATETIME/TIMESTAMP se guarda y lee en hora Argentina
 *   (PHP date_default_timezone_set + SET time_zone = '-03:00' en MySQL).
 */

if (!defined('APP_TIMEZONE')) {
    define('APP_TIMEZONE', 'America/Argentina/Buenos_Aires');
}
if (!defined('APP_MYSQL_TIME_ZONE')) {
    define('APP_MYSQL_TIME_ZONE', '-03:00');
}

if (!function_exists('app_datetime_tz_ar')) {
    function app_datetime_tz_ar(): DateTimeZone
    {
        $tz = @timezone_open(APP_TIMEZONE);

        return $tz instanceof DateTimeZone ? $tz : new DateTimeZone(APP_MYSQL_TIME_ZONE);
    }
}

if (!function_exists('app_now_utc')) {
    function app_now_utc(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}

if (!function_exists('app_now_ar')) {
    function app_now_ar(): string
    {
        return (new DateTime('now', app_datetime_tz_ar()))->format('Y-m-d H:i:s');
    }
}

if (!function_exists('app_datetime_format_from_utc')) {
    /**
     * Interpreta un DATETIME naive como UTC y lo muestra en hora Argentina.
     */
    function app_datetime_format_from_utc(?string $raw, string $format = 'd/m/Y H:i', bool $withHs = false): string
    {
        $raw = trim((string)$raw);
        try {
            if ($raw === '') {
                $dt = new DateTime('now', app_datetime_tz_ar());
            } else {
                $dt = new DateTime($raw, new DateTimeZone('UTC'));
                $dt->setTimezone(app_datetime_tz_ar());
            }
            $out = $dt->format($format);

            return $withHs ? ($out . ' hs') : $out;
        } catch (Exception $e) {
            return $raw !== '' ? $raw : '—';
        }
    }
}

if (!function_exists('app_datetime_utc_to_ar_sql')) {
    function app_datetime_utc_to_ar_sql(?string $utc): string
    {
        return app_datetime_format_from_utc($utc, 'Y-m-d H:i:s', false);
    }
}

if (!function_exists('app_datetime_format_ar')) {
    /**
     * Formatea un DATETIME que ya está en hora Argentina (NOW() con TZ -03, date() PHP).
     */
    function app_datetime_format_ar(?string $raw, string $format = 'd/m/Y H:i'): string
    {
        $raw = trim((string)$raw);
        if ($raw === '' || $raw === '—' || strtolower($raw) === 'null') {
            return '—';
        }
        try {
            $dt = new DateTime($raw, app_datetime_tz_ar());

            return $dt->format($format);
        } catch (Exception $e) {
            $ts = strtotime($raw);

            return $ts !== false ? date($format, $ts) : $raw;
        }
    }
}

if (!function_exists('app_db_apply_timezone')) {
    /**
     * @param mysqli|PDO $db
     */
    function app_db_apply_timezone($db): void
    {
        $sql = "SET time_zone = '" . APP_MYSQL_TIME_ZONE . "'";
        try {
            if ($db instanceof mysqli) {
                $db->query($sql);
            } elseif ($db instanceof PDO) {
                if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
                    $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
                }
                $tzStmt = $db->query($sql);
                if ($tzStmt !== false) {
                    $tzStmt->closeCursor();
                }
            }
        } catch (Throwable $e) {
            error_log('app_db_apply_timezone: ' . $e->getMessage());
        }
    }
}

if (!function_exists('app_mysqli')) {
    function app_mysqli(): mysqli
    {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if (!$conn->connect_error) {
            $conn->set_charset('utf8mb4');
            app_db_apply_timezone($conn);
        }

        return $conn;
    }
}
