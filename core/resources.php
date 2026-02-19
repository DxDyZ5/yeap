<?php
// resources.php - Helper para rutas de recursos estáticos (fotos, uploads, etc.)
if (!function_exists('asset_path')) {
    /**
     * Obtiene la ruta de un recurso estático relativo a la raíz del proyecto
     * @param string $path Ruta del recurso (ej: 'fotos/123.jpg')
     * @return string Ruta completa relativa desde la raíz
     */
    function asset_path($path) {
        $base = dirname(dirname(__FILE__));
        $relative = str_replace($base, '', __DIR__);
        // Calcular niveles desde el módulo actual hasta la raíz
        $current_dir = dirname($_SERVER['PHP_SELF']);
        $depth = substr_count($current_dir, '/');
        
        if ($depth <= 1) {
            return $path;
        }
        
        $relative_path = str_repeat('../', $depth - 1) . $path;
        return $relative_path;
    }
}

if (!function_exists('foto_path')) {
    /**
     * Obtiene la ruta de una foto de empleado
     * @param string $cedula Cédula del empleado (solo números)
     * @return string URL de la foto o avatar por defecto
     */
    function foto_path($cedula) {
        $c_l = preg_replace('/[^0-9]/', '', $cedula);
        $base = dirname(dirname(__FILE__));
        $foto_file = $base . '/fotos/' . $c_l . '.jpg';
        
        if (file_exists($foto_file)) {
            // Calcular ruta relativa desde el módulo actual hasta la raíz
            // Desde modulos/[modulo]/[archivo].php a fotos/ en la raíz
            $script_path = $_SERVER['PHP_SELF'];
            if (strpos($script_path, '/modulos/') !== false) {
                // Estamos en un módulo, subir 2 niveles
                return "../../fotos/$c_l.jpg?v=" . time();
            } else {
                // Estamos en la raíz
                return "fotos/$c_l.jpg?v=" . time();
            }
        }
        
        return "https://ui-avatars.com/api/?name=" . urlencode($cedula) . "&background=random";
    }
}

if (!function_exists('base_url')) {
    /**
     * Obtiene la URL base del proyecto
     * @return string URL base
     */
    function base_url() {
        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
        $host = $_SERVER['HTTP_HOST'];
        
        // Calcular la ruta desde core/resources.php hasta la raíz del proyecto
        // core/resources.php está en: adesarrollo/core/resources.php
        // Necesitamos: adesarrollo/
        $core_dir = dirname(__FILE__); // adesarrollo/core
        $project_root = dirname($core_dir); // adesarrollo
        
        // Obtener la ruta del documento raíz del servidor
        $document_root = $_SERVER['DOCUMENT_ROOT'];
        
        // Calcular la ruta relativa desde DOCUMENT_ROOT hasta project_root
        $relative_path = str_replace($document_root, '', $project_root);
        $relative_path = str_replace('\\', '/', $relative_path); // Normalizar para Windows
        
        // Si la ruta relativa está vacía, estamos en la raíz
        $base_dir = $relative_path === '' ? '' : $relative_path;
        
        return "$protocol://$host$base_dir";
    }
}

if (!function_exists('modulo_url')) {
    /**
     * Genera URL para un módulo específico
     * @param string $modulo Nombre del módulo (ej: 'personal', 'asistencia')
     * @param string $archivo Nombre del archivo (ej: 'index.php', 'editar.php')
     * @param array $params Parámetros GET (ej: ['id' => 123])
     * @return string URL completa
     */
    function modulo_url($modulo, $archivo = 'index.php', $params = []) {
        $base = base_url();
        $url = "$base/modulos/$modulo/$archivo";
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }
        return $url;
    }
}
