<?php
/**
 * Navegación agrupada del panel admin (grupo + subpestaña).
 */

/**
 * Pestañas accesibles por URL pero no listadas en la subnavegación.
 *
 * @return array<string, string> tab oculta => pestaña padre resaltada en subnav
 */
function admin_nav_hidden_tabs(): array
{
    return [
        'lista-alumnos-nuevos' => 'alta-alumnos-nuevos',
    ];
}

/**
 * Alias de pestañas legacy (URLs antiguas).
 *
 * @return array<string, string>
 */
function admin_nav_tab_aliases(): array
{
    return [
        'comunicados' => 'comunicados-generales',
        'informacion-general' => 'informacion-matriculas',
    ];
}

/**
 * @return array<string, array{label: string, icon: string, default: string, tabs: array<string, array{label: string, icon: string, hidden?: bool}>}>
 */
function admin_nav_groups_definition(): array
{
    return [
        'sistema' => [
            'label'   => 'Sistema',
            'icon'    => '⚙️',
            'default' => 'actualizaciones',
            'tabs'    => [
                'actualizaciones'      => ['label' => 'Actualizaciones', 'icon' => '🔄'],
                'configuracion'        => ['label' => 'Configuración', 'icon' => '🔧'],
                'usuarios'             => ['label' => 'Agregar Usuario', 'icon' => '👥'],
                'alta-alumnos-nuevos'  => ['label' => 'Alta de Alumnos', 'icon' => '➕'],
                'qr-app'               => ['label' => 'QR App familias', 'icon' => '📱'],
                'auditoria'            => ['label' => 'Auditoría Admin.', 'icon' => '📝'],
                'auditoria-app'        => ['label' => 'Auditoría App', 'icon' => '🔒'],
            ],
        ],
        'solicitudes' => [
            'label'   => 'Solicitudes',
            'icon'    => '📋',
            'default' => 'talones',
            'tabs'    => [
                'talones'     => ['label' => 'Solicitudes de talón', 'icon' => '🎫'],
                'informes'    => ['label' => 'Informes de error', 'icon' => '🔧'],
                'emails'      => ['label' => 'Cambios de email', 'icon' => '✉️'],
                'sugerencias' => ['label' => 'Sugerencias', 'icon' => '💡'],
            ],
        ],
        'comunicacion' => [
            'label'   => 'Comunicados App',
            'icon'    => '📢',
            'default' => 'comunicados-generales',
            'tabs'    => [
                'comunicados-generales'   => ['label' => 'Comunicados Generales', 'icon' => '📣'],
                'comunicados-individuales' => ['label' => 'Comunicados Individuales', 'icon' => '👤'],
            ],
        ],
        'revision-contratos' => [
            'label'   => 'Revisión Contratos',
            'icon'    => '📋',
            'default' => 'revision-contratos',
            'tabs'    => [
                'revision-contratos' => ['label' => 'Revisión Contratos', 'icon' => '📋'],
            ],
        ],
        'informacion' => [
            'label'   => 'Información General',
            'icon'    => '📊',
            'default' => 'informacion-matriculas',
            'tabs'    => [
                'informacion-matriculas'          => ['label' => 'Matrículas', 'icon' => '🎓'],
                'informacion-ingresado-facturado' => ['label' => 'Ingresado-Facturado', 'icon' => '💰'],
                'informacion-app'                 => ['label' => 'Información APP', 'icon' => '📱'],
                'listado-familias'                => ['label' => 'Listado por Familias', 'icon' => '👨‍👩‍👧‍👦'],
                'estado-alumno'                   => ['label' => 'Estado Alumnos', 'icon' => '📁'],
            ],
        ],
    ];
}

/**
 * Permiso requerido para una pestaña (alias de capacidades).
 */
function admin_nav_capability(string $tab): string
{
    static $map = [
        'comunicados-generales'   => 'comunicados',
        'comunicados-individuales' => 'comunicados',
        'lista-alumnos-nuevos'    => 'alta-alumnos-nuevos',
        'informacion-matriculas'          => 'informacion-general',
        'informacion-ingresado-facturado' => 'informacion-general',
        'informacion-app'                 => 'informacion-general',
    ];

    return $map[$tab] ?? $tab;
}

/**
 * @return array<string, string>
 */
function admin_nav_tab_to_grupo_map(): array
{
    $map = [];
    foreach (admin_nav_groups_definition() as $grupo => $def) {
        foreach (array_keys($def['tabs']) as $tab) {
            $map[$tab] = $grupo;
        }
    }
    foreach (admin_nav_hidden_tabs() as $tab => $parentTab) {
        if (isset($map[$parentTab])) {
            $map[$tab] = $map[$parentTab];
        }
    }
    return $map;
}

/**
 * Pestaña resaltada en la subnavegación (pestañas ocultas muestran su padre).
 */
function admin_nav_subnav_active_tab(string $tab): string
{
    return admin_nav_hidden_tabs()[$tab] ?? $tab;
}

/**
 * Grupos y pestañas visibles según permisos del usuario actual.
 *
 * @return array<string, array{label: string, icon: string, default: string, tabs: array<string, array{label: string, icon: string, hidden?: bool}>}>
 */
function admin_nav_visible_groups(): array
{
    $visible = [];
    foreach (admin_nav_groups_definition() as $grupo => $def) {
        $tabs = [];
        foreach ($def['tabs'] as $tab => $tabDef) {
            if (!empty($tabDef['hidden'])) {
                continue;
            }
            if (admin_can($tab)) {
                $tabs[$tab] = $tabDef;
            }
        }
        if ($tabs === []) {
            continue;
        }
        $default = $def['default'];
        if (!isset($tabs[$default])) {
            $default = (string)array_key_first($tabs);
        }
        $visible[$grupo] = [
            'label'   => $def['label'],
            'icon'    => $def['icon'],
            'default' => $default,
            'tabs'    => $tabs,
        ];
    }
    return $visible;
}

/**
 * @param array<string, mixed> $query
 * @return array{grupo: string, tab: string}
 */
function admin_nav_resolve(array $query): array
{
    $aliases = admin_nav_tab_aliases();
    $map = admin_nav_tab_to_grupo_map();
    $visible = admin_nav_visible_groups();

    $tab = isset($query['tab']) ? (string)$query['tab'] : '';
    $grupo = isset($query['grupo']) ? (string)$query['grupo'] : '';

    if ($tab !== '' && isset($aliases[$tab])) {
        $tab = $aliases[$tab];
    }

    if ($tab !== '' && isset($map[$tab])) {
        $grupo = $map[$tab];
    }

    if ($grupo !== '' && isset($visible[$grupo])) {
        $def = $visible[$grupo];
        $subnavTab = admin_nav_subnav_active_tab($tab);
        if ($tab === '' || (!isset($def['tabs'][$subnavTab]) && !isset($map[$tab]))) {
            $tab = $def['default'];
        }
        return ['grupo' => $grupo, 'tab' => $tab];
    }

    foreach ($visible as $g => $def) {
        return ['grupo' => $g, 'tab' => $def['default']];
    }

    return ['grupo' => 'sistema', 'tab' => 'actualizaciones'];
}

/**
 * @param array<string, scalar|null> $extra
 */
function admin_page_url(string $tab, array $extra = []): string
{
    $map = admin_nav_tab_to_grupo_map();
    $grupo = $map[$tab] ?? 'sistema';
    $params = array_merge(['grupo' => $grupo, 'tab' => $tab], $extra);
    return 'admin_dashboard.php?' . http_build_query($params);
}

/**
 * @param array<string, scalar|null> $extra
 */
function admin_redirect_tab(string $tab, string $msg, string $msgType = 'info', array $extra = []): void
{
    if (!in_array($msgType, ['success', 'danger', 'warning', 'info', 'primary', 'secondary', 'dark'], true)) {
        $msgType = 'info';
    }

    // Flash en sesión para evitar URLs enormes o con caracteres conflictivos en Location.
    $_SESSION['admin_flash_msg'] = $msg;
    $_SESSION['admin_flash_type'] = $msgType;

    $location = admin_page_url($tab, $extra);
    if (!headers_sent()) {
        header('Location: ' . $location);
    } else {
        echo '<!doctype html><html><head><meta charset="utf-8">';
        echo '<meta http-equiv="refresh" content="0;url=' . htmlspecialchars($location, ENT_QUOTES, 'UTF-8') . '">';
        echo '</head><body>';
        echo '<a href="' . htmlspecialchars($location, ENT_QUOTES, 'UTF-8') . '">Continuar</a>';
        echo '</body></html>';
    }
    exit;
}

/**
 * Conteos de pendientes para badges de notificación en la navegación.
 *
 * @return array{
 *   talones:int,
 *   informes:int,
 *   emails:int,
 *   sugerencias:int,
 *   solicitudes:int,
 *   revision-contratos:int
 * }
 */
function admin_nav_notification_counts(PDO $pdo): array
{
    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }

    $counts = [
        'talones' => 0,
        'informes' => 0,
        'emails' => 0,
        'sugerencias' => 0,
        'solicitudes' => 0,
        'revision-contratos' => 0,
    ];

    $safeCount = static function (PDO $pdo, string $sql): int {
        try {
            $n = $pdo->query($sql)->fetchColumn();
            return max(0, (int)$n);
        } catch (Throwable $e) {
            error_log('admin_nav_notification_counts: ' . $e->getMessage());
            return 0;
        }
    };

    $counts['talones'] = $safeCount($pdo, "SELECT COUNT(*) FROM solicitudes_talon WHERE estado = 'pendiente'");
    $counts['informes'] = $safeCount(
        $pdo,
        "SELECT COUNT(*) FROM informes_error WHERE estado IN ('pendiente', 'en_proceso')"
    );
    $counts['emails'] = $safeCount($pdo, "SELECT COUNT(*) FROM solicitudes_email WHERE estado = 'pendiente'");
    $counts['sugerencias'] = $safeCount($pdo, 'SELECT COUNT(*) FROM sugerencias WHERE leido = 0');
    $counts['solicitudes'] = $counts['talones'] + $counts['informes'] + $counts['emails'] + $counts['sugerencias'];

    try {
        $hasCol = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'contratos_aceptados'
               AND COLUMN_NAME = 'info_erronea'"
        )->fetchColumn();
        if ((int)$hasCol > 0) {
            $counts['revision-contratos'] = $safeCount(
                $pdo,
                "SELECT COUNT(*) FROM contratos_aceptados
                 WHERE status = 'activo' AND COALESCE(info_erronea, 0) = 1"
            );
        }
    } catch (Throwable $e) {
        error_log('admin_nav_notification_counts revision: ' . $e->getMessage());
    }

    $cached = $counts;
    return $cached;
}

/**
 * HTML del badge rojo de notificación (vacío si count = 0).
 */
function admin_nav_badge_html(int $count, string $ariaLabel = ''): string
{
    if ($count < 1) {
        return '';
    }

    $display = $count > 99 ? '99+' : (string)$count;
    $label = $ariaLabel !== ''
        ? $ariaLabel
        : ($count === 1 ? '1 pendiente' : $count . ' pendientes');

    return '<span class="admin-nav-badge" title="'
        . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
        . '" aria-label="'
        . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
        . '">'
        . htmlspecialchars($display, ENT_QUOTES, 'UTF-8')
        . '</span>';
}
