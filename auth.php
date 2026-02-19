<?php
// auth.php - CONTROL DE ACCESO ESTRICTO
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Cargar configuración de base de datos
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/core/resources.php';

/**
 * PROTECCIÓN DE RUTA: 
 * Si no hay un ID de usuario válido en la sesión, redirigir al login inmediatamente.
 * Excluimos la propia página de login para evitar bucles de redirección.
 */
$pagina_actual = basename($_SERVER['PHP_SELF']);

if (!isset($_SESSION['user_id']) && $pagina_actual !== 'login.php') {
    $login_url = base_url() . '/login.php';
    header("Location: $login_url");
    exit;
}

/**
 * RE-VALIDACIÓN DE SESIÓN:
 * Si el usuario está logueado, verificamos que su rol y datos sigan activos.
 */
$rol_logueado = $_SESSION['rol'] ?? null;
$nombre_usuario = $_SESSION['nombre_usuario'] ?? 'Usuario';

/**
 * Verifica si el usuario actual es Super Admin.
 * @return bool
 */
function isSuperAdmin() {
    return isset($_SESSION['rol']) && $_SESSION['rol'] === 'superadmin';
}

/**
 * Función para verificar acceso dinámico por base de datos
 */
function verificarAcceso($accion = 'ver', $archivo = null) {
    global $pdo, $rol_logueado;
    
    // El Super Admin tiene "Dios-Mode" (acceso total)
    if (isSuperAdmin()) return true;

    if ($archivo === null) {
        $archivo = basename($_SERVER['PHP_SELF']);
    }

    try {
        // Consultar la matriz de permisos definida en gestion_roles.php
        $stmt = $pdo->prepare("
            SELECT pm.puede_ver, pm.puede_editar 
            FROM permisos_modulos pm 
            JOIN roles_sistema rs ON pm.id_rol = rs.id 
            WHERE rs.nombre_rol = ? AND pm.modulo = ?
        ");
        $stmt->execute([$rol_logueado, $archivo]);
        $permisos = $stmt->fetch();

        if (!$permisos) {
            // Si no hay registro de permiso, denegar por defecto
            $index_url = base_url() . '/index.php?error=acceso_denegado';
            header("Location: $index_url");
            exit;
        }
        
        if ($accion === 'ver' && !$permisos['puede_ver']) {
            $index_url = base_url() . '/index.php?error=acceso_denegado';
            header("Location: $index_url");
            exit;
        }
        
        if ($accion === 'editar' && !$permisos['puede_editar']) {
            return false;
        }
        
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Función heredada para compatibilidad
 */
function verificarPermiso($roles_permitidos) {
    global $rol_logueado;
    if (isSuperAdmin()) return;
    if (!in_array($rol_logueado, $roles_permitidos)) {
        $index_url = base_url() . '/index.php?error=no_autorizado';
        header("Location: $index_url");
        exit;
    }
}