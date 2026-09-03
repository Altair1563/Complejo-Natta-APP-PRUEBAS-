<?php
/**
 * Layout compartido para pestañas de Información general.
 * Requiere: $igActiveTab, $igHeroTitle, $igHeroLead, y el partial de secciones vía $igSectionsPartial.
 */
$igH = static function ($str) {
    return htmlspecialchars((string)($str ?? ''), ENT_QUOTES, 'UTF-8');
};
$igFormAction = admin_page_url($igActiveTab);
$igLoadError = null;
$igData = [];

require_once __DIR__ . '/../../../includes/informacion_general_data.php';
try {
    $igData = admin_load_informacion_general_data();
} catch (Throwable $e) {
    $igLoadError = $e->getMessage();
}
?>
            <div class="tab-pane fade show active" id="<?= $igH($igActiveTab) ?>" role="tabpanel">
                <div class="informacion-general-admin">
                    <div class="ig-shell">
<?php if ($igLoadError !== null) { ?>
                    <div class="alert alert-danger mt-3 mb-0"><?= $igH($igLoadError) ?></div>
<?php } else {
    extract($igData, EXTR_SKIP);
?>
<header class="ig-hero">
        <h1 class="ig-hero-title"><?= $igH($igHeroTitle) ?></h1>
        <p class="ig-hero-lead"><?= $igHeroLead ?></p>
    </header>

<?php
    require __DIR__ . '/' . $igSectionsPartial;
?>
      </div>
    </div>
  </div>
<?php } ?>
