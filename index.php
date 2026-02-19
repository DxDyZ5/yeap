<?php
// index.php - PUNTO DE ENTRADA: Verifica sesión y redirige apropiadamente
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/core/resources.php';

// Si no hay sesión activa, redirigir al login
if (!isset($_SESSION['user_id'])) {
    $login_url = base_url() . '/login.php';
    header("Location: $login_url");
    exit;
}

// Si hay sesión activa, redirigir al dashboard
$dashboard_url = base_url() . '/dashboard/index.php';
header("Location: $dashboard_url");
exit;
