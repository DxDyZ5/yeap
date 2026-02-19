<?php
// logout.php - LIMPIEZA TOTAL DE SESIÓN
session_start();

// Limpiar todas las variables de sesión
$_SESSION = array();

// Si se desea destruir la cookie de sesión también
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destruir la sesión físicamente
session_destroy();

// Redirigir al login usando función base_url
require_once __DIR__ . '/core/resources.php';
$login_url = base_url() . '/login.php';
header("Location: $login_url");
exit;