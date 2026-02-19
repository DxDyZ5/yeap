<?php
// base_path.php - Define rutas base del sistema
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(dirname(__FILE__)));
}
if (!defined('CORE_PATH')) {
    define('CORE_PATH', BASE_PATH . '/core');
}
if (!defined('TEMPLATES_PATH')) {
    define('TEMPLATES_PATH', BASE_PATH . '/templates');
}
if (!defined('MODULOS_PATH')) {
    define('MODULOS_PATH', BASE_PATH . '/modulos');
}
