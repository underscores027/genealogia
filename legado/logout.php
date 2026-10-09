<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_SESSION['user_id'])) {
    include 'includes/db.php';
    registrarLog($pdo, "Saiu do sistema (Logout).");
}

session_destroy();
header("Location: arvore.php");
exit();
