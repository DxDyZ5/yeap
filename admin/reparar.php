<?php
// reparar.php - RESTABLECIMIENTO MAESTRO DE CREDENCIALES (EMERGENCIA)
session_start();
require_once 'db.php'; // Usamos la conexión centralizada

try {
    // 1. Asegurar que el rol 'admin' existe en la tabla de roles
    $pdo->exec("INSERT IGNORE INTO roles_sistema (nombre_rol, descripcion) VALUES ('admin', 'Acceso Total al Sistema')");
    
    // Obtener el ID del rol admin
    $stmtRol = $pdo->prepare("SELECT id FROM roles_sistema WHERE nombre_rol = 'admin' LIMIT 1");
    $stmtRol->execute();
    $id_rol_admin = $stmtRol->fetchColumn();

    if (!$id_rol_admin) {
        die("Error crítico: No se pudo crear o encontrar el rol 'admin'.");
    }

    // 2. Limpiar usuario admin previo si existe para evitar conflictos de integridad
    $pdo->prepare("DELETE FROM usuarios_sistema WHERE usuario = 'admin'")->execute();

    // 3. Crear el nuevo usuario de emergencia
    // Contraseña: admin123
    $password_hash = password_hash('admin123', PASSWORD_DEFAULT);
    $sql = "INSERT INTO usuarios_sistema (usuario, password, nombre_real, id_rol, activo) 
            VALUES ('admin', ?, 'Administrador Maestro', ?, 1)";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$password_hash, $id_rol_admin]);

    // 4. Destruir sesiones activas
    session_unset();
    session_destroy();

    echo "<div style='font-family: sans-serif; max-width: 500px; margin: 50px auto; text-align: center; border: 2px solid #e2e8f0; padding: 40px; border-radius: 30px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1);'>";
    echo "<div style='background: #dcfce7; color: #166534; width: 60px; height: 60px; border-radius: 20px; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; font-size: 30px;'>✓</div>";
    echo "<h1 style='color:#1e293b; font-size: 24px; margin-bottom: 10px; font-weight: 900;'>SISTEMA RESTABLECIDO</h1>";
    echo "<p style='color:#64748b; font-size: 14px;'>Se ha vinculado el usuario a la tabla <b>usuarios_sistema</b> con privilegios de administrador.</p>";
    echo "<div style='background: #f8fafc; padding: 20px; border-radius: 20px; margin: 25px 0; text-align: left; border: 1px solid #f1f5f9;'>";
    echo "<span style='display:block; font-size: 10px; font-weight: 900; color: #94a3b8; text-transform: uppercase;'>Credenciales de Acceso</span>";
    echo "<p style='margin: 5px 0; font-size: 15px; color: #1e293b;'>Usuario: <b>admin</b></p>";
    echo "<p style='margin: 5px 0; font-size: 15px; color: #1e293b;'>Password: <b>admin123</b></p>";
    echo "</div>";
    echo "<a href='login.php' style='display: inline-block; background: #2563eb; color: white; padding: 15px 35px; text-decoration: none; border-radius: 15px; font-weight: bold; font-size: 13px; text-transform: uppercase; letter-spacing: 1px; box-shadow: 0 10px 15px -3px rgba(37,99,235,0.3);'>Ir al Login</a>";
    echo "</div>";

} catch (Exception $e) {
    echo "<div style='color:red; font-family: sans-serif; text-align:center; padding: 50px;'>";
    echo "<h2>❌ ERROR DE REPARACIÓN</h2>";
    echo "<p>" . $e->getMessage() . "</p>";
    echo "</div>";
}
?>