<?php
session_start();
session_unset();
session_destroy();
header("Location: /natta-app/index.php");
exit;