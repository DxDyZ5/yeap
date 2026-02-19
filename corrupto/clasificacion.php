<?php
// clasificacion.php - CLASIFICACIÓN DE DEPARTAMENTOS (Se perdió, recreando estructura básica)
require_once __DIR__ . '/../../core/auth.php'; 
require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../core/resources.php'; 

verificarPermiso(['admin', 'rrhh']);

$departamento_sel = $_GET['departamento'] ?? '';
$busqueda_empleado = $_GET['q'] ?? '';

// Obtener URL base usando función centralizada
$base_url = base_url();
require __DIR__ . '/../../templates/layout_head.php'; 
?>

<div class="container mx-auto px-4 py-6">
    <h1 class="text-2xl font-black text-slate-800 mb-6">Clasificación de Departamentos</h1>
    
    <div class="bg-white rounded-xl shadow-lg border border-gray-200 p-6 mb-6">
        <form method="GET" class="flex flex-wrap items-end gap-4">
            <div class="flex-1">
                <label class="block text-sm font-bold text-gray-700 mb-1">Seleccionar Departamento</label>
                <select name="departamento" onchange="this.form.submit()" class="w-full border rounded-lg px-3 py-2">
                    <option value="">Todos los Departamentos</option>
                    <?php
                    $deptos = $pdo->query("SELECT DISTINCT departamento FROM empleados WHERE departamento IS NOT NULL ORDER BY departamento")->fetchAll(PDO::FETCH_COLUMN);
                    foreach($deptos as $depto):
                    ?>
                    <option value="<?php echo htmlspecialchars($depto); ?>" <?php echo $departamento_sel == $depto ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($depto); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex-1">
                <label class="block text-sm font-bold text-gray-700 mb-1">Buscar Empleado</label>
                <input type="text" name="q" value="<?php echo htmlspecialchars($busqueda_empleado); ?>" placeholder="Nombre, cédula o ID..." class="w-full border rounded-lg px-3 py-2">
            </div>
            <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded-lg font-bold">Buscar</button>
        </form>
    </div>

    <?php
    $where = ["e.estatus_nomina = 'En Nomina'"];
    $params = [];
    
    if ($departamento_sel) {
        $where[] = "e.departamento = ?";
        $params[] = $departamento_sel;
    }
    
    if ($busqueda_empleado) {
        $where[] = "(e.nombre_completo LIKE ? OR e.cedula LIKE ? OR e.id_reloj LIKE ?)";
        $busq = "%$busqueda_empleado%";
        $params[] = $busq;
        $params[] = $busq;
        $params[] = $busq;
    }
    
    $sql = "SELECT e.*, 
                   COUNT(DISTINCT a.fecha) as dias_asistidos,
                   AVG(CASE WHEN a.hora_entrada IS NOT NULL THEN 1 ELSE 0 END) * 100 as porcentaje_asistencia
            FROM empleados e
            LEFT JOIN asistencia a ON e.id_reloj = a.id_empleado_reloj AND a.fecha >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            WHERE " . implode(" AND ", $where) . "
            GROUP BY e.id_reloj
            ORDER BY e.departamento, e.nombre_completo";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $empleados = $stmt->fetchAll();
    ?>
    
    <div class="bg-white rounded-xl shadow-lg border border-gray-200 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-gray-600 font-bold text-xs uppercase">
                <tr>
                    <th class="px-6 py-4 text-left">Empleado</th>
                    <th class="px-6 py-4 text-left">Departamento</th>
                    <th class="px-6 py-4 text-center">Días Asistidos (30d)</th>
                    <th class="px-6 py-4 text-center">% Asistencia</th>
                    <th class="px-6 py-4 text-center">Acciones</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php foreach($empleados as $emp): 
                    $c_l = preg_replace('/[^0-9]/', '', $emp['cedula'] ?? '');
                    $foto = foto_path($emp['cedula'] ?? '');
                ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4">
                        <div class="flex items-center gap-3">
                            <img src="<?php echo $foto; ?>" class="w-10 h-10 rounded-full object-cover">
                            <div>
                                <p class="font-bold text-slate-700"><?php echo htmlspecialchars($emp['nombre_completo']); ?></p>
                                <p class="text-xs text-gray-500">ID: <?php echo $emp['id_reloj']; ?></p>
                            </div>
                        </div>
                    </td>
                    <td class="px-6 py-4 font-bold text-slate-600"><?php echo htmlspecialchars($emp['departamento']); ?></td>
                    <td class="px-6 py-4 text-center font-bold"><?php echo $emp['dias_asistidos']; ?></td>
                    <td class="px-6 py-4 text-center">
                        <span class="px-3 py-1 rounded-full text-xs font-bold <?php echo $emp['porcentaje_asistencia'] >= 80 ? 'bg-green-100 text-green-700' : ($emp['porcentaje_asistencia'] >= 60 ? 'bg-yellow-100 text-yellow-700' : 'bg-red-100 text-red-700'); ?>">
                            <?php echo round($emp['porcentaje_asistencia'], 1); ?>%
                        </span>
                    </td>
                    <td class="px-6 py-4 text-center">
                        <a href="<?php echo $base_url; ?>/modulos/personal/editar.php?id=<?php echo $emp['id_reloj']; ?>" class="text-blue-600 hover:text-blue-800 font-bold text-xs">Ver Detalle</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../../templates/layout_footer.php'; ?>
