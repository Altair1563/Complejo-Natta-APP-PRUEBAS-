<?php
require_once __DIR__ . '/config/session.php';
secure_session_start();

require_once __DIR__ . '/config/app.php';
if (!app_is_maintenance_mode()) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema en Mantenimiento</title>
    <link rel="stylesheet" href="./css/pages/mantenimiento.css">
</head>
<body>
    <div class="container">
        <div class="icon">🛠️</div>
        <h1>Estimadas familias</h1>
        <p>
            El sistema se encuentra momentáneamente fuera de servicio debido a la <strong>aplicación de intereses</strong> correspondientes al período actual.
        </p>
        <p>
            Les solicitamos aguardar a que finalice este proceso para poder abonar el monto exacto que corresponde.
        </p>
        <p>
            Disculpen las molestias. En breve estaremos nuevamente operativos.
        </p>
        <div class="footer">
            Instituto Jardin de Infantes La Milagrosa
        </div>
    </div>
</body>
</html>