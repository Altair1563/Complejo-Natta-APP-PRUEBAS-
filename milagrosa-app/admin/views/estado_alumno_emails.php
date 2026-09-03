<?php
/**
 * Solapa Emails — contactos de familia por alumno (secretaría).
 *
 * Variables esperadas: $escuelaActiva, $escuelas, $cursosEscuelaActiva, $filtrosEmails,
 * $emailsData
 */
$h = 'estado_alumno_h';
$buscar = (string)$filtrosEmails['buscar'];
$alumnosEmails = $emailsData['alumnos'] ?? [];
$totalEmails = (int)($emailsData['total'] ?? 0);
$errorEmails = (string)($emailsData['error'] ?? '');

$renderMail = static function (string $mail) use ($h): string {
    if ($mail === '') {
        return '<span class="text-muted">—</span>';
    }

    return '<a class="bolsa-email" href="mailto:' . $h($mail) . '">' . $h($mail) . '</a>';
};

$exportEmailsQuery = http_build_query(array_filter([
    'escuela' => $escuelaActiva,
    'buscar' => $buscar !== '' ? $buscar : null,
], static function ($v) {
    return $v !== null && $v !== '';
}), '', '&', PHP_QUERY_RFC3986);
?>
<form method="get" class="filtros emails-form" id="emailsForm">
    <input type="hidden" name="vista" value="emails">
    <input type="hidden" name="escuela" value="<?php echo $h($escuelaActiva); ?>">

    <div class="campo campo-buscar">
        <label for="buscar_emails" class="form-label">Buscar (apellido, nombre, legajo, email)</label>
        <input
            type="text"
            class="form-control"
            name="buscar"
            id="buscar_emails"
            value="<?php echo $h($buscar); ?>"
            placeholder="Ej: GARCÍA, 10144/01, ejemplo@mail.com"
        >
    </div>

    <div class="campo">
        <button type="submit" class="btn btn-primary btn-consultar">
            <span class="spinner btn-spinner" aria-hidden="true"></span>
            <span class="btn-consultar-label">Consultar</span>
        </button>
    </div>
</form>

<?php if ($errorEmails !== ''): ?>
    <div class="alert alert-danger"><?php echo $h($errorEmails); ?></div>
<?php elseif ($totalEmails === 0): ?>
    <div class="no-data">
        No se encontraron alumnos para los filtros seleccionados. Probá ajustar la búsqueda.
    </div>
<?php else: ?>
    <div class="tab-content-mails">
    <div class="resumen-wrapper">
        <div class="resumen">
            <div class="resumen-text">
                Alumnos en <span class="resumen-highlight"><?php echo $h($escuelas[$escuelaActiva] ?? $escuelaActiva); ?></span>:
                <span class="resumen-highlight"><?php echo $totalEmails; ?></span>.
            </div>
        </div>
    </div>

    <div class="alumnos-card emails-card">
        <div class="alumnos-card-header">
            <h2 class="alumnos-card-title">Emails de familia por alumno</h2>
            <div class="alumnos-card-actions">
                <span class="estado-contrato-pill">Mail1–Mail4: padre, padre trabajo, madre, madre trabajo</span>
                <a
                    class="btn btn-success btn-sm"
                    href="exportar_estado_alumno_emails.php?<?php echo $h($exportEmailsQuery); ?>"
                >Descargar Excel</a>
            </div>
        </div>

        <div class="table-responsive">
        <table class="table table-striped table-sm mb-0 js-sortable-table">
            <thead>
                <tr>
                    <th>Curso</th>
                    <th>Apellido y nombre</th>
                    <th>Mail1</th>
                    <th>Mail2</th>
                    <th>Mail3</th>
                    <th>Mail4</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($alumnosEmails as $alumno): ?>
                    <tr>
                        <td><?php echo $h($alumno['curso'] ?? ''); ?></td>
                        <td><?php echo $h(trim(($alumno['apellido_alumno'] ?? '') . ', ' . ($alumno['nombre_alumno'] ?? ''), ', ')); ?></td>
                        <td data-sort-value="<?php echo $h((string)($alumno['mail_padre'] ?? '')); ?>"><?php echo $renderMail((string)($alumno['mail_padre'] ?? '')); ?></td>
                        <td data-sort-value="<?php echo $h((string)($alumno['mail_padre_trabajo'] ?? '')); ?>"><?php echo $renderMail((string)($alumno['mail_padre_trabajo'] ?? '')); ?></td>
                        <td data-sort-value="<?php echo $h((string)($alumno['mail_madre'] ?? '')); ?>"><?php echo $renderMail((string)($alumno['mail_madre'] ?? '')); ?></td>
                        <td data-sort-value="<?php echo $h((string)($alumno['mail_madre_trabajo'] ?? '')); ?>"><?php echo $renderMail((string)($alumno['mail_madre_trabajo'] ?? '')); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    </div>
<?php endif; ?>
