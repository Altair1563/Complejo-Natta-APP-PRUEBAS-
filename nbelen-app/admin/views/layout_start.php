<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Administrativo</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/pages/admin.css">
    <link rel="stylesheet" href="../css/pages/informacion-general.css">
    <link rel="stylesheet" href="../css/pages/estados-listado-familias.css">
    <link rel="stylesheet" href="../css/pages/form-alta-alumno.css">
    <link rel="stylesheet" href="../css/pages/form-lista-alumnos.css">
    <link rel="stylesheet" href="../css/components/qr-share.css">
</head>
<body data-page="admin-dashboard">

    <div class="container-fluid admin-page-shell mt-4">

        <?php
        $adminUser = admin_current_user();
        $activeGrupoDef = $admin_nav_groups[$active_grupo] ?? null;
        $subnavActiveTab = admin_nav_subnav_active_tab($active_tab);
        $showSubnav = $activeGrupoDef && count($activeGrupoDef['tabs']) > 1;
        $admin_nav_badges = $admin_nav_badges ?? [];
        $headerLogoFile = function_exists('tenant_logo_file') ? tenant_logo_file() : 'LogoNbelen.jpg';
        $headerLogoAlt = function_exists('tenant_name') ? tenant_name() : 'Nuestra Señora de Itati';
        ?>
        <div class="admin-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="admin-header__brand d-flex align-items-center gap-3">
                <img src="../assets/img/<?= htmlspecialchars($headerLogoFile, ENT_QUOTES, 'UTF-8') ?>"
                     alt="<?= htmlspecialchars($headerLogoAlt, ENT_QUOTES, 'UTF-8') ?>"
                     class="admin-header-logo">
                <div>
                    <h1 class="mb-0">
                        Panel de Administración
                    </h1>
                    <p class="text-muted mb-0 small mt-1">
                        👤 <?= htmlspecialchars($adminUser['nombre'], ENT_QUOTES, 'UTF-8') ?>
                        (<?= htmlspecialchars($adminUser['username'], ENT_QUOTES, 'UTF-8') ?>)
                        · <span class="badge <?= admin_is_superadmin() ? 'bg-dark' : 'bg-primary' ?>"><?= htmlspecialchars($adminUser['rol'], ENT_QUOTES, 'UTF-8') ?></span>
                    </p>
                </div>
            </div>
            <a href="admin_logout.php" class="btn btn-danger admin-header__logout">🚪 Cerrar sesión</a>
        </div>

        <div id="toastContainer"></div>

        <?= $mensaje ?>

        <nav class="admin-nav-main" aria-label="Secciones del panel">
            <ul class="nav nav-pills admin-nav-groups">
                <?php foreach ($admin_nav_groups as $grupoKey => $grupoDef): ?>
                    <?php
                    $isActiveGrupo = ($active_grupo === $grupoKey);
                    $grupoUrl = admin_page_url($grupoDef['default']);
                    $grupoBadgeCount = 0;
                    if ($grupoKey === 'solicitudes') {
                        $grupoBadgeCount = (int)($admin_nav_badges['solicitudes'] ?? 0);
                    } elseif ($grupoKey === 'revision-contratos') {
                        $grupoBadgeCount = (int)($admin_nav_badges['revision-contratos'] ?? 0);
                    }
                    $grupoBadgeHtml = admin_nav_badge_html(
                        $grupoBadgeCount,
                        $grupoKey === 'revision-contratos'
                            ? ($grupoBadgeCount === 1 ? '1 contrato con info errónea' : $grupoBadgeCount . ' contratos con info errónea')
                            : ($grupoBadgeCount === 1 ? '1 solicitud pendiente' : $grupoBadgeCount . ' solicitudes pendientes')
                    );
                    ?>
                    <li class="nav-item">
                        <a class="nav-link <?= $isActiveGrupo ? 'active' : '' ?>"
                           href="<?= htmlspecialchars($grupoUrl, ENT_QUOTES, 'UTF-8') ?>"
                           data-nav-group="<?= htmlspecialchars($grupoKey, ENT_QUOTES, 'UTF-8') ?>">
                            <span class="nav-icon" aria-hidden="true"><?= $grupoDef['icon'] ?? '' ?></span>
                            <?= htmlspecialchars($grupoDef['label'], ENT_QUOTES, 'UTF-8') ?>
                            <?= $grupoBadgeHtml ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </nav>

        <?php if ($showSubnav): ?>
        <nav class="admin-nav-sub mt-2" aria-label="Subsección">
            <ul class="nav nav-pills admin-nav-subtabs">
                <?php foreach ($activeGrupoDef['tabs'] as $tabKey => $tabDef): ?>
                    <?php
                    $tabBadgeCount = (int)($admin_nav_badges[$tabKey] ?? 0);
                    $tabBadgeHtml = admin_nav_badge_html($tabBadgeCount);
                    ?>
                    <li class="nav-item">
                        <a class="nav-link <?= $subnavActiveTab === $tabKey ? 'active' : '' ?>"
                           href="<?= htmlspecialchars(admin_page_url($tabKey), ENT_QUOTES, 'UTF-8') ?>">
                            <span class="nav-icon" aria-hidden="true"><?= $tabDef['icon'] ?? '' ?></span>
                            <?= htmlspecialchars($tabDef['label'], ENT_QUOTES, 'UTF-8') ?>
                            <?= $tabBadgeHtml ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </nav>
        <?php endif; ?>

        <div class="tab-content admin-tab-content" id="adminTabsContent">
