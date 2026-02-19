<?php
// redirects.php - Helper para redirecciones consistentes entre módulos
require_once __DIR__ . '/resources.php';

if (!function_exists('redirect_to_dashboard')) {
    function redirect_to_dashboard($error = '') {
        $url = base_url() . '/modulos/dashboard/index.php';
        
        if ($error) {
            $url .= '?error=' . urlencode($error);
        }
        header("Location: $url");
        exit;
    }
}

if (!function_exists('redirect_to_module')) {
    function redirect_to_module($modulo, $archivo = 'index.php', $params = []) {
        $url = modulo_url($modulo, $archivo, $params);
        header("Location: $url");
        exit;
    }
}
