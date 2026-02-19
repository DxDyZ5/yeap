<?php
// reporte_departamentos.php - REPORTE DE DEPARTAMENTOS CON ANÁLISIS
require_once __DIR__ . '/../../core/auth.php'; 
require_once __DIR__ . '/../../core/db.php'; 

verificarPermiso(['admin', 'rrhh']);

$fecha_inicio = $_GET['fecha_inicio'] ?? date('Y-m-01');
$fecha_fin = $_GET['fecha_fin'] ?? date('Y-m-d');
$departamento_sel = $_GET['departamento'] ?? '';

// Obtener URL base usando función centralizada
$base_url = base_url();
require __DIR__ . '/../../templates/layout_head.php'; 
?>

<div class="container mx-auto px-4 py-6">
    <h1 class="text-2xl font-black text-slate-800 mb-6">Reporte por Departamentos</h1>
    
    <div class="bg-white rounded-xl shadow-lg border border-gray-200 p-6 mb-6">
        <form method="GET" class="flex flex-wrap items-end gap-4">
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-1">Fecha Inicio</label>
                <input type="date" name="fecha_inicio" value="<?php echo $fecha_inicio; ?>" class="border rounded-lg px-3 py-2">
            </div>
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-1">Fecha Fin</label>
                <input type="date" name="fecha_fin" value="<?php echo $fecha_fin; ?>" class="border rounded-lg px-3 py-2">
            </div>
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-1">Departamento</label>
                <select name="departamento" class="border rounded-lg px-3 py-2">
                    <option value="">Todos</option>
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
            <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded-lg font-bold">Generar Reporte</button>
        </form>
    </div>

    <?php
    $where = ["a.fecha BETWEEN ? AND ?"];
    $params = [$fecha_inicio, $fecha_fin];
    
    if ($departamento_sel) {
        $where[] = "e.departamento = ?";
        $params[] = $departamento_sel;
    }
    
    $sql = "SELECT e.departamento, 
                   COUNT(DISTINCT e.id_reloj) as total_empleados,
                   COUNT(DISTINCT CASE WHEN a.hora_entrada IS NOT NULL THEN e.id_reloj END) as presentes,
                   COUNT(DISTINCT CASE WHEN a.hora_entrada IS NULL THEN e.id_reloj END) as ausentes
            FROM empleados e
            LEFT JOIN asistencia a ON e.id_reloj = a.id_empleado_reloj AND a.fecha BETWEEN ? AND ?
            WHERE e.estatus_nomina = 'En Nomina' " . 
            ($departamento_sel ? "AND e.departamento = ?" : "") . "
            GROUP BY e.departamento
            ORDER BY e.departamento";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$fecha_inicio, $fecha_fin], $departamento_sel ? [$departamento_sel] : []));
    $reportes = $stmt->fetchAll();
    ?>
    
    <div class="bg-white rounded-xl shadow-lg border border-gray-200 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-gray-600 font-bold text-xs uppercase">
                <tr>
                    <th class="px-6 py-4 text-left">Departamento</th>
                    <th class="px-6 py-4 text-center">Total Empleados</th>
                    <th class="px-6 py-4 text-center">Presentes</th>
                    <th class="px-6 py-4 text-center">Ausentes</th>
                    <th class="px-6 py-4 text-center">% Asistencia</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php foreach($reportes as $r): 
                    $porcentaje = $r['total_empleados'] > 0 ? round(($r['presentes'] / $r['total_empleados']) * 100, 1) : 0;
                ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4 font-bold text-slate-700"><?php echo htmlspecialchars($r['departamento']); ?></td>
                    <td class="px-6 py-4 text-center"><?php echo $r['total_empleados']; ?></td>
                    <td class="px-6 py-4 text-center text-green-600 font-bold"><?php echo $r['presentes']; ?></td>
                    <td class="px-6 py-4 text-center text-red-600 font-bold"><?php echo $r['ausentes']; ?></td>
                    <td class="px-6 py-4 text-center">
                        <span class="px-3 py-1 rounded-full text-xs font-bold <?php echo $porcentaje >= 80 ? 'bg-green-100 text-green-700' : ($porcentaje >= 60 ? 'bg-yellow-100 text-yellow-700' : 'bg-red-100 text-red-700'); ?>">
                            <?php echo $porcentaje; ?>%
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../../templates/layout_footer.php'; ?>
