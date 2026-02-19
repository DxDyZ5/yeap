<?php
// paths.php - Helper para rutas relativas desde cualquier ubicación
if (!function_exists('base_path')) {
    function base_path($path = '') {
        $base = dirname(dirname(__FILE__));
        return $base . ($path ? '/' . ltrim($path, '/') : '');
    }
}

if (!function_exists('core_path')) {
    function core_path($path = '') {
        return base_path('core' . ($path ? '/' . ltrim($path, '/') : ''));
    }
}

if (!function_exists('templates_path')) {
    function templates_path($path = '') {
        return base_path('templates' . ($path ? '/' . ltrim($path, '/') : ''));
    }
}

if (!function_exists('modulos_path')) {
    function modulos_path($path = '') {
        return base_path('modulos' . ($path ? '/' . ltrim($path, '/') : ''));
    }
}

// Función para obtener ruta relativa desde el archivo actual hasta la raíz
if (!function_exists('rel_path')) {
    function rel_path($target_path) {
        $current_dir = dirname($_SERVER['PHP_SELF']);
        $current_depth = substr_count($current_dir, '/');
        
        // Si estamos en la raíz
        if ($current_depth <= 1) {
            return $target_path;
        }
        
        // Construir ruta relativa
        $relative = str_repeat('../', $current_depth - 1) . $target_path;
        return $relative;
    }
}
