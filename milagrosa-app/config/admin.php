<?php
// config/admin.php
if (!defined('_ACCESS')) {
    die('Acceso prohibido');
}

// Genera tu hash con: password_hash('tu_contraseña', PASSWORD_DEFAULT)
define('ADMIN_PASSWORD_HASH', '$2y$10$RrbUxhDh2v9r62vNZ8TVkOih1/EB2R.dXwYmFp.Luvwg5bVoE3HLO');