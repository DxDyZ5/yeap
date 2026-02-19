<?php
// usuarios_sistema.php - ADMINISTRACIÓN DE PERSONAL DE OFICINA Y ACCESOS
require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/../../core/db.php';

// Solo el rol 'admin' puede gestionar otros usuarios
verificarPermiso(['admin']);

$mensaje = '';
$tipo_msg = '';

// --- LÓGICA DE PROCESAMIENTO (CRUD) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Agregar o Editar Usuario
    if (isset($_POST['guardar_usuario'])) {
        $id = $_POST['id_usuario'] ?? null;
        $username = trim($_POST['usuario']);
        $nombre = trim($_POST['nombre_real']);
        $id_rol = $_POST['id_rol'];
        $activo = isset($_POST['activo']) ? 1 : 0;
        $pass = $_POST['password'];

        try {
            if ($id) {
                // Editar
                if (!empty($pass)) {
                    $passHash = password_hash($pass, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE usuarios_sistema SET usuario=?, password=?, nombre_real=?, id_rol=?, activo=? WHERE id=?");
                    $stmt->execute([$username, $passHash, $nombre, $id_rol, $activo, $id]);
                } else {
                    $stmt = $pdo->prepare("UPDATE usuarios_sistema SET usuario=?, nombre_real=?, id_rol=?, activo=? WHERE id=?");
                    $stmt->execute([$username, $nombre, $id_rol, $activo, $id]);
                }
                $mensaje = "✅ Usuario actualizado correctamente.";
            } else {
                // Nuevo
                $passHash = password_hash($pass, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO usuarios_sistema (usuario, password, nombre_real, id_rol, activo) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$username, $passHash, $nombre, $id_rol, $activo]);
                $mensaje = "✅ Nuevo usuario creado con éxito.";
            }
            $tipo_msg = 'success';
        } catch (Exception $e) {
            $mensaje = "❌ Error: " . $e->getMessage();
            $tipo_msg = 'error';
        }
    }

    // 2. Eliminar Usuario
    if (isset($_POST['eliminar_id'])) {
        $id_borrar = $_POST['eliminar_id'];
        if ($id_borrar == $_SESSION['user_id']) {
            $mensaje = "❌ No puedes eliminarte a ti mismo.";
            $tipo_msg = 'error';
        } else {
            $pdo->prepare("DELETE FROM usuarios_sistema WHERE id = ?")->execute([$id_borrar]);
            $mensaje = "🗑️ Usuario eliminado.";
            $tipo_msg = 'success';
        }
    }
}

// Cargar Datos
$usuarios = $pdo->query("
    SELECT u.*, r.nombre_rol 
    FROM usuarios_sistema u 
    JOIN roles_sistema r ON u.id_rol = r.id 
    ORDER BY u.id ASC
")->fetchAll();

$roles = $pdo->query("SELECT * FROM roles_sistema")->fetchAll();

require __DIR__ . '/../../templates/layout_head.php';
?>

<div class="max-w-6xl mx-auto pb-20">
    <div class="flex justify-between items-center mb-8">
        <div>
            <h1 class="text-2xl font-black text-slate-800 tracking-tight">Gestión de Usuarios</h1>
            <p class="text-sm text-gray-500">Control de acceso para el personal administrativo.</p>
        </div>
        <button onclick="abrirModal()" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-2xl font-bold shadow-lg shadow-blue-100 transition flex items-center gap-2">
            <i class="fas fa-user-plus"></i> Nuevo Usuario
        </button>
    </div>

    <?php if($mensaje): ?>
        <div class="mb-6 p-4 rounded-xl border <?php echo $tipo_msg == 'success' ? 'bg-green-50 border-green-200 text-green-700' : 'bg-red-50 border-red-200 text-red-700'; ?> font-bold animate-fade-in">
            <?php echo $mensaje; ?>
        </div>
    <?php endif; ?>

    <div class="bg-white rounded-3xl shadow-xl border border-gray-100 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-gray-400 font-bold text-[10px] uppercase tracking-widest border-b">
                <tr>
                    <th class="px-6 py-4 text-left">Usuario / Nombre</th>
                    <th class="px-6 py-4 text-center">Nivel de Acceso</th>
                    <th class="px-6 py-4 text-center">Estado</th>
                    <th class="px-6 py-4 text-center">Último Acceso</th>
                    <th class="px-6 py-4 text-right">Acciones</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                <?php foreach($usuarios as $u): 
                    $is_me = ($u['id'] == $_SESSION['user_id']);
                ?>
                <tr class="hover:bg-blue-50/30 transition">
                    <td class="px-6 py-4">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full bg-slate-100 flex items-center justify-center text-slate-400 font-bold border border-slate-200">
                                <?php echo strtoupper(substr($u['usuario'], 0, 2)); ?>
                            </div>
                            <div>
                                <div class="font-black text-slate-800"><?php echo htmlspecialchars($u['usuario']); ?> <?php if($is_me) echo '<span class="text-[9px] bg-blue-100 text-blue-600 px-1.5 rounded ml-1">TÚ</span>'; ?></div>
                                <div class="text-[11px] text-gray-400 font-medium"><?php echo htmlspecialchars($u['nombre_real']); ?></div>
                            </div>
                        </div>
                    </td>
                    <td class="px-6 py-4 text-center">
                        <span class="px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-tighter <?php echo $u['nombre_rol'] == 'admin' ? 'bg-purple-100 text-purple-600' : 'bg-blue-50 text-blue-600'; ?>">
                            <?php echo $u['nombre_rol']; ?>
                        </span>
                    </td>
                    <td class="px-6 py-4 text-center">
                        <?php if($u['activo']): ?>
                            <span class="inline-flex items-center gap-1 text-green-600 font-bold text-xs"><div class="w-1.5 h-1.5 rounded-full bg-green-500"></div> Activo</span>
                        <?php else: ?>
                            <span class="inline-flex items-center gap-1 text-red-400 font-bold text-xs"><div class="w-1.5 h-1.5 rounded-full bg-red-400"></div> Inactivo</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 text-center text-gray-400 font-mono text-xs">
                        <?php echo $u['ultimo_acceso'] ? date('d/m/Y H:i', strtotime($u['ultimo_acceso'])) : 'Nunca'; ?>
                    </td>
                    <td class="px-6 py-4 text-right space-x-1">
                        <button onclick='editarUsuario(<?php echo json_encode($u); ?>)' class="w-8 h-8 rounded-lg bg-gray-50 text-gray-400 hover:bg-blue-600 hover:text-white transition flex items-center justify-center inline-flex shadow-sm"><i class="fas fa-pen text-xs"></i></button>
                        <?php if(!$is_me): ?>
                        <form method="POST" class="inline" onsubmit="return confirm('¿Eliminar acceso a este usuario?')">
                            <input type="hidden" name="eliminar_id" value="<?php echo $u['id']; ?>">
                            <button class="w-8 h-8 rounded-lg bg-gray-50 text-gray-400 hover:bg-red-500 hover:text-white transition flex items-center justify-center inline-flex shadow-sm"><i class="fas fa-trash text-xs"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal de Usuario -->
<div id="modalUsuario" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white w-full max-w-md rounded-3xl shadow-2xl overflow-hidden animate-pop-in">
        <div class="px-8 py-6 border-b bg-gray-50/50 flex justify-between items-center">
            <h2 id="modalTitle" class="font-black text-slate-800 text-lg uppercase">Nuevo Usuario</h2>
            <button onclick="cerrarModal()" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" class="p-8 space-y-4">
            <input type="hidden" name="id_usuario" id="form_id">
            <input type="hidden" name="guardar_usuario" value="1">
            
            <div>
                <label class="text-[10px] font-bold text-gray-400 uppercase ml-1">Nombre Real</label>
                <input type="text" name="nombre_real" id="form_nombre" required class="w-full border-none bg-gray-50 rounded-xl px-4 py-3 focus:ring-2 focus:ring-blue-500">
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="text-[10px] font-bold text-gray-400 uppercase ml-1">Usuario (Login)</label>
                    <input type="text" name="usuario" id="form_usuario" required class="w-full border-none bg-gray-50 rounded-xl px-4 py-3 focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="text-[10px] font-bold text-gray-400 uppercase ml-1">Rol / Nivel</label>
                    <select name="id_rol" id="form_rol" class="w-full border-none bg-gray-50 rounded-xl px-4 py-3 focus:ring-2 focus:ring-blue-500">
                        <?php foreach($roles as $r): ?>
                            <option value="<?php echo $r['id']; ?>"><?php echo strtoupper($r['nombre_rol']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div>
                <label class="text-[10px] font-bold text-gray-400 uppercase ml-1">Contraseña</label>
                <input type="password" name="password" id="form_pass" placeholder="Dejar vacío para no cambiar" class="w-full border-none bg-gray-50 rounded-xl px-4 py-3 focus:ring-2 focus:ring-blue-500">
            </div>

            <div class="flex items-center gap-2 p-2 bg-blue-50 rounded-xl">
                <input type="checkbox" name="activo" id="form_activo" checked class="w-5 h-5 rounded text-blue-600 border-none">
                <label for="form_activo" class="text-sm font-bold text-blue-700">Cuenta activa para ingreso</label>
            </div>

            <div class="pt-4">
                <button type="submit" class="w-full bg-slate-800 hover:bg-black text-white font-black py-4 rounded-2xl shadow-lg transition transform active:scale-95 uppercase tracking-widest text-xs">
                    Guardar Usuario
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    function abrirModal() {
        document.getElementById('modalTitle').innerText = 'Nuevo Usuario';
        document.getElementById('form_id').value = '';
        document.getElementById('form_usuario').value = '';
        document.getElementById('form_nombre').value = '';
        document.getElementById('form_pass').required = true;
        document.getElementById('modalUsuario').classList.remove('hidden');
    }

    function editarUsuario(u) {
        document.getElementById('modalTitle').innerText = 'Editar Usuario';
        document.getElementById('form_id').value = u.id;
        document.getElementById('form_usuario').value = u.usuario;
        document.getElementById('form_nombre').value = u.nombre_real;
        document.getElementById('form_rol').value = u.id_rol;
        document.getElementById('form_activo').checked = (u.activo == 1);
        document.getElementById('form_pass').required = false;
        document.getElementById('modalUsuario').classList.remove('hidden');
    }

    function cerrarModal() {
        document.getElementById('modalUsuario').classList.add('hidden');
    }
</script>

<?php require __DIR__ . '/../../templates/layout_footer.php'; ?>