<?php
/**
 * Registro de auditoría de acciones del panel admin.
 */

function admin_audit_log(
    PDO $pdo,
    string $accion,
    ?string $entidad = null,
    ?int $entidadId = null,
    array $detalle = []
): void {
    try {
        $userId = !empty($_SESSION['admin_user_id']) ? (int)$_SESSION['admin_user_id'] : null;
        $json = $detalle !== [] ? json_encode($detalle, JSON_UNESCAPED_UNICODE) : null;
        $stmt = $pdo->prepare(
            'INSERT INTO admin_audit_log
                (admin_user_id, accion, entidad, entidad_id, detalle, ip, user_agent, session_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            $accion,
            $entidad,
            $entidadId,
            $json,
            $_SERVER['REMOTE_ADDR'] ?? null,
            isset($_SERVER['HTTP_USER_AGENT'])
                ? substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 255)
                : null,
            session_id(),
        ]);
    } catch (Throwable $e) {
        error_log('admin_audit_log: ' . $e->getMessage());
    }
}
