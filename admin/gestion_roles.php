<?php
// gestion_roles.php - PANEL DE ADMINISTRACIÓN DE ACCESOS
require '../auth.php';
require '../db.php';
verificarPermiso(['admin']);

$mensaje = '';

// Procesar Guardado de Permisos
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_permisos'])) {
    try {
        $pdo->beginTransaction();
        $rol_id = $_POST['rol_id'];
        
        // Limpiar permisos actuales para este rol
        $pdo->prepare("DELETE FROM permisos_modulos WHERE id_rol = ?")->execute([$rol_id]);
        
        // Insertar nuevos permisos
        if (isset($_POST['modulos'])) {
            foreach ($_POST['modulos'] as $modulo => $perms) {
                $ver = isset($perms['ver']) ? 1 : 0;
                $edit = isset($perms['editar']) ? 1 : 0;
                
                $stmt = $pdo->prepare("INSERT INTO permisos_modulos (id_rol, modulo, puede_ver, puede_editar) VALUES (?, ?, ?, ?)");
                $stmt->execute([$rol_id, $modulo, $ver, $edit]);
            }
        }
        
        $pdo->commit();
        $mensaje = "✅ Permisos actualizados con éxito.";
    } catch (Exception $e) {
        $pdo->rollBack();
        $mensaje = "❌ Error: " . $e->getMessage();
    }
}

$roles = $pdo->query("SELECT * FROM roles_sistema")->fetchAll();
$selected_rol = $_GET['rol_id'] ?? ($roles[0]['id'] ?? 0);

// Módulos del sistema
$modulos_lista = [
    'Dashboard' => 'index.php',
    'Expediente de Empleados' => 'usuarios/editar_empleado.php',
    'Listado de Perfiles' => 'usuarios/perfil_empleado.php',
    'Historial de Asistencia' => 'asistencia/historial.php',
    'Constuctor de Carnets' => 'carnets/carnets_config.php',
    'Carga de Archivos' => 'datos/cargar_datos.php',
    'Gestión de Ausencias' => 'asistencia/ausencias_prolongadas.php',
    'Configuración de Evaluación' => 'admin/config_evaluacion.php'
];

// Obtener permisos actuales del rol seleccionado
$permisos_actuales = [];
if ($selected_rol) {
    $stmt = $pdo->prepare("SELECT * FROM permisos_modulos WHERE id_rol = ?");
    $stmt->execute([$selected_rol]);
    while ($row = $stmt->fetch()) {
        $permisos_actuales[$row['modulo']] = $row;
    }
}

require '../layout/layout_head.php';
?>

<div class="max-w-4xl mx-auto pb-20">
    <div class="mb-8 flex justify-between items-center">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">Control de Acceso</h1>
            <p class="text-sm text-gray-500">Define qué puede ver y modificar cada nivel de usuario.</p>
        </div>
        <i class="fas fa-user-shield text-4xl text-blue-100"></i>
    </div>

    <?php if($mensaje): ?>
        <div class="mb-6 p-4 rounded-xl border border-green-200 bg-green-50 text-green-700 font-bold"><?php echo $mensaje; ?></div>
    <?php endif; ?>

    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
        <!-- Sidebar de Roles -->
        <div class="md:col-span-1 space-y-2">
            <h3 class="text-xs font-bold text-gray-400 uppercase mb-3">Roles Disponibles</h3>
            <?php foreach($roles as $rol): ?>
                <a href="?rol_id=<?php echo $rol['id']; ?>" class="block px-4 py-3 rounded-xl border transition font-bold text-sm <?php echo $selected_rol == $rol['id'] ? 'bg-blue-600 text-white border-blue-600 shadow-md' : 'bg-white text-gray-600 hover:bg-gray-50'; ?>">
                    <i class="fas fa-user-tag mr-2 opacity-50"></i> <?php echo strtoupper($rol['nombre_rol']); ?>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Tabla de Permisos -->
        <div class="md:col-span-3">
            <form method="POST" class="bg-white rounded-2xl shadow-lg border border-gray-200 overflow-hidden">
                <input type="hidden" name="rol_id" value="<?php echo $selected_rol; ?>">
                
                <div class="p-6 border-b bg-gray-50">
                    <h2 class="font-bold text-gray-700">Configurando: <?php 
                        foreach($roles as $r) if($r['id'] == $selected_rol) echo strtoupper($r['nombre_rol']);
                    ?></h2>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-gray-400 uppercase text-[10px]">
                            <tr>
                                <th class="px-6 py-4 text-left">Módulo / Archivo</th>
                                <th class="px-6 py-4 text-center">Ver</th>
                                <th class="px-6 py-4 text-center">Editar</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php foreach($modulos_lista as $nombre => $archivo): 
                                $p_ver = $permisos_actuales[$archivo]['puede_ver'] ?? 0;
                                $p_edit = $permisos_actuales[$archivo]['puede_editar'] ?? 0;
                            ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4">
                                    <div class="font-bold text-gray-700"><?php echo $nombre; ?></div>
                                    <div class="text-[10px] font-mono text-gray-400"><?php echo $archivo; ?></div>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <input type="checkbox" name="modulos[<?php echo $archivo; ?>][ver]" value="1" <?php echo $p_ver ? 'checked' : ''; ?> class="w-5 h-5 rounded text-blue-600">
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <input type="checkbox" name="modulos[<?php echo $archivo; ?>][editar]" value="1" <?php echo $p_edit ? 'checked' : ''; ?> class="w-5 h-5 rounded text-red-500">
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="p-6 bg-gray-50 text-right">
                    <button type="submit" name="actualizar_permisos" class="bg-slate-800 hover:bg-black text-white px-8 py-3 rounded-xl font-bold shadow-lg transition">
                        <i class="fas fa-save mr-2"></i> Guardar Cambios
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require '../layout/layout_footer.php'; ?>