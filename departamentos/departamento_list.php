<?php
// departamento_list.php - VISTA AVANZADA CON BUSCADOR MANUAL EN TIEMPO REAL
require '../core/auth.php';
verificarPermiso(['admin', 'rrhh']);

// --- PARÁMETROS DE VISTA Y ORDEN ---
$view_mode = $_GET['view'] ?? 'grid'; // grid o list
$sort_mode = $_GET['sort'] ?? 'score_desc'; // score_desc, score_asc, name

// --- FUNCIÓN DE CALIFICACIÓN BASE ---
function obtenerCalificacionBase($id_reloj, $pdo) {
    $fecha_ini = date('Y-m-d', strtotime('-30 days'));
    $fecha_fin = date('Y-m-d');
    $stmt = $pdo->prepare("SELECT fecha, hora_entrada, hora_salida FROM asistencia WHERE id_empleado_reloj = ? AND fecha BETWEEN ? AND ?");
    $stmt->execute([$id_reloj, $fecha_ini, $fecha_fin]);
    $asistencias = $stmt->fetchAll();
    $map = []; foreach($asistencias as $a) $map[$a['fecha']] = $a;
    $suma = 0; $dias = 0;
    $curr = strtotime($fecha_fin); $stop = strtotime($fecha_ini);
    while($curr >= $stop) {
        $f = date('Y-m-d', $curr);
        if (date('w', $curr) != 0 && date('w', $curr) != 6) {
            $p = 100;
            if (!isset($map[$f]) || empty($map[$f]['hora_entrada'])) $p = 0;
            elseif (empty($map[$f]['hora_salida']) || $map[$f]['hora_salida'] == '00:00:00') $p = 60;
            $suma += $p; $dias++;
        }
        $curr = strtotime('-1 day', $curr);
    }
    return ($dias > 0) ? round($suma / $dias) : 100;
}

// 1. Obtener combinaciones ÚNICAS de Depto y Empresa
$sql = "SELECT DISTINCT departamento, empresa 
        FROM empleados 
        WHERE departamento != '' AND estatus_nomina = 'En Nomina'
        ORDER BY empresa ASC, departamento ASC";
$combos = $pdo->query($sql)->fetchAll();

// Preparar consulta para buscar el mejor encargado
$stmtMeta = $pdo->prepare("SELECT e.nombre_completo, e.cedula 
                           FROM departamentos_meta m 
                           JOIN empleados e ON m.encargado_id_reloj = e.id_reloj 
                           WHERE m.nombre_departamento = ? AND (m.empresa = ? OR m.empresa = '') 
                           ORDER BY m.empresa DESC LIMIT 1");

$lista_final = [];
foreach ($combos as $c) {
    $nombre = $c['departamento'];
    $empresa = $c['empresa'] ?: 'SIN EMPRESA';
    
    $stmtMeta->execute([$nombre, $c['empresa']]);
    $meta = $stmtMeta->fetch();

    $stmtI = $pdo->prepare("SELECT id_reloj FROM empleados WHERE departamento = ? AND empresa = ? AND estatus_nomina = 'En Nomina'");
    $stmtI->execute([$nombre, $c['empresa']]);
    $integrantes = $stmtI->fetchAll(PDO::FETCH_COLUMN);
    
    $suma = 0;
    foreach ($integrantes as $id) { $suma += obtenerCalificacionBase($id, $pdo); }
    $promedio = count($integrantes) > 0 ? round($suma / count($integrantes)) : 0;

    $nombre_encargado = $meta['nombre_completo'] ?? 'No asignado';
    $foto_encargado = "https://ui-avatars.com/api/?name=".urlencode($nombre_encargado)."&background=random";
    if ($meta && !empty($meta['cedula'])) {
        $ced_l = preg_replace('/[^0-9]/', '', $meta['cedula']);
        if (file_exists("../fotos/$ced_l.jpg")) $foto_encargado = "../fotos/$ced_l.jpg";
    }

    $lista_final[] = [
        'nombre' => $nombre,
        'empresa' => $empresa,
        'encargado' => $nombre_encargado,
        'foto_encargado' => $foto_encargado,
        'cantidad' => count($integrantes),
        'calificacion' => $promedio
    ];
}

if ($sort_mode === 'score_desc') usort($lista_final, function($a, $b) { return $b['calificacion'] <=> $a['calificacion']; });
elseif ($sort_mode === 'score_asc') usort($lista_final, function($a, $b) { return $a['calificacion'] <=> $b['calificacion']; });
elseif ($sort_mode === 'name') usort($lista_final, function($a, $b) { return strcmp($a['nombre'], $b['nombre']); });

require '../layout/layout_head.php';
?>

<div class="max-w-7xl mx-auto space-y-6 pb-20">
    
    <!-- CABECERA Y HERRAMIENTAS -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 bg-white p-6 rounded-3xl shadow-sm border border-slate-100">
        <div>
            <h1 class="text-3xl font-black text-slate-800 tracking-tight">Departamentos</h1>
            <p class="text-slate-400 text-sm font-medium">Análisis de desempeño por unidades de negocio.</p>
        </div>
        
        <div class="flex flex-wrap items-center gap-3">
            <!-- BUSCADOR MANUAL -->
            <div class="relative min-w-[250px]">
                <input type="text" id="manual_search" placeholder="Buscar depto, empresa o encargado..." class="w-full pl-10 pr-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold focus:ring-2 focus:ring-blue-500 outline-none transition">
                <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i>
            </div>

            <!-- Selector de Orden -->
            <div class="flex items-center bg-slate-100 p-1 rounded-xl">
                <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'score_desc'])); ?>" class="px-3 py-1.5 rounded-lg text-[10px] font-bold uppercase transition <?php echo $sort_mode == 'score_desc' ? 'bg-white shadow-sm text-blue-600' : 'text-slate-500 hover:text-slate-700'; ?>">Mejor</a>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'score_asc'])); ?>" class="px-3 py-1.5 rounded-lg text-[10px] font-bold uppercase transition <?php echo $sort_mode == 'score_asc' ? 'bg-white shadow-sm text-red-600' : 'text-slate-500 hover:text-slate-700'; ?>">Menor</a>
            </div>

            <!-- Selector de Vista -->
            <div class="flex items-center bg-slate-100 p-1 rounded-xl">
                <a href="?<?php echo http_build_query(array_merge($_GET, ['view' => 'grid'])); ?>" class="w-9 h-9 flex items-center justify-center rounded-lg transition <?php echo $view_mode == 'grid' ? 'bg-white shadow-sm text-blue-600' : 'text-slate-400'; ?>">
                    <i class="fas fa-th-large"></i>
                </a>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['view' => 'list'])); ?>" class="w-9 h-9 flex items-center justify-center rounded-lg transition <?php echo $view_mode == 'list' ? 'bg-white shadow-sm text-blue-600' : 'text-slate-400'; ?>">
                    <i class="fas fa-list"></i>
                </a>
            </div>

            <a href="departamentos_config.php" class="bg-slate-800 hover:bg-slate-900 text-white w-10 h-10 flex items-center justify-center rounded-xl transition shadow-lg">
                <i class="fas fa-cog"></i>
            </a>
        </div>
    </div>

    <?php if ($view_mode === 'grid'): ?>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6" id="depto_container">
            <?php foreach ($lista_final as $depto): 
                $color = ($depto['calificacion'] < 50) ? 'text-red-600' : (($depto['calificacion'] < 80) ? 'text-yellow-600' : 'text-green-600');
                $bg = ($depto['calificacion'] < 50) ? 'bg-red-50' : (($depto['calificacion'] < 80) ? 'bg-yellow-50' : 'bg-green-50');
            ?>
            <a href="perfil_departamento.php?name=<?php echo urlencode($depto['nombre']); ?>&empresa=<?php echo urlencode($depto['empresa'] == 'SIN EMPRESA' ? '' : $depto['empresa']); ?>" 
               class="depto-item bg-white rounded-3xl p-6 border border-slate-200 shadow-sm hover:shadow-xl transition-all duration-300 group relative overflow-hidden"
               data-search="<?php echo strtolower($depto['nombre'] . ' ' . $depto['empresa'] . ' ' . $depto['encargado']); ?>">
                <div class="flex justify-between items-start mb-4">
                    <div class="flex items-center gap-3">
                        <img src="<?php echo $depto['foto_encargado']; ?>" class="w-12 h-12 rounded-2xl object-cover border-2 border-white shadow-md group-hover:scale-110 transition">
                        <div class="flex flex-col">
                            <span class="text-[9px] font-black text-blue-600 uppercase tracking-widest"><?php echo $depto['empresa']; ?></span>
                            <h3 class="text-lg font-black text-slate-800 leading-tight truncate max-w-[150px]"><?php echo $depto['nombre']; ?></h3>
                        </div>
                    </div>
                    <div class="<?php echo $bg . ' ' . $color; ?> px-2 py-1 rounded-lg text-xs font-black"><?php echo $depto['calificacion']; ?>%</div>
                </div>
                <div class="space-y-3">
                    <p class="text-[10px] text-slate-400 font-bold uppercase">Líder Responsable</p>
                    <p class="text-sm font-bold text-slate-700 truncate"><?php echo $depto['encargado']; ?></p>
                    <div class="flex items-center justify-between pt-4 border-t border-slate-50">
                        <span class="text-slate-400 text-[10px] font-bold uppercase"><i class="fas fa-users mr-1"></i> <?php echo $depto['cantidad']; ?> empleados</span>
                        <i class="fas fa-chevron-right text-slate-200 group-hover:text-blue-500 transition"></i>
                    </div>
                </div>
                <div class="absolute bottom-0 left-0 h-1 bg-blue-500 transition-all duration-500" style="width: <?php echo $depto['calificacion']; ?>%; opacity: 0.3;"></div>
            </a>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="bg-white rounded-3xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left" id="depto_table">
                    <thead class="bg-slate-50 border-b border-slate-100">
                        <tr>
                            <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase">Departamento / Empresa</th>
                            <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase">Encargado</th>
                            <th class="px-6 py-4 text-center text-[10px] font-black text-slate-400 uppercase">Personal</th>
                            <th class="px-6 py-4 text-center text-[10px] font-black text-slate-400 uppercase">Desempeño</th>
                            <th class="px-6 py-4 text-right text-[10px] font-black text-slate-400 uppercase">Eficacia</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        <?php foreach ($lista_final as $depto): 
                            $stars = round($depto['calificacion'] / 20);
                            $color = ($depto['calificacion'] < 50) ? 'text-red-500' : (($depto['calificacion'] < 80) ? 'text-yellow-600' : 'text-green-600');
                        ?>
                        <tr class="depto-row hover:bg-blue-50/30 transition cursor-pointer" 
                            onclick="window.location='perfil_departamento.php?name=<?php echo urlencode($depto['nombre']); ?>&empresa=<?php echo urlencode($depto['empresa'] == 'SIN EMPRESA' ? '' : $depto['empresa']); ?>'"
                            data-search="<?php echo strtolower($depto['nombre'] . ' ' . $depto['empresa'] . ' ' . $depto['encargado']); ?>">
                            <td class="px-6 py-4">
                                <div class="font-black text-slate-800 text-sm"><?php echo $depto['nombre']; ?></div>
                                <div class="text-[9px] font-bold text-blue-500 uppercase"><?php echo $depto['empresa']; ?></div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-3">
                                    <img src="<?php echo $depto['foto_encargado']; ?>" class="w-8 h-8 rounded-lg object-cover shadow-sm">
                                    <span class="text-xs font-bold text-slate-600"><?php echo $depto['encargado']; ?></span>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <span class="bg-slate-100 px-2 py-1 rounded text-[10px] font-black text-slate-500"><?php echo $depto['cantidad']; ?></span>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <div class="flex gap-0.5 <?php echo $color; ?> justify-center text-[10px]">
                                    <?php for($i=1; $i<=5; $i++): ?><i class="<?php echo ($i <= $stars) ? 'fas' : 'far'; ?> fa-star"></i><?php endfor; ?>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <span class="font-black text-sm <?php echo $color; ?>"><?php echo $depto['calificacion']; ?>%</span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
    const searchInput = document.getElementById('manual_search');
    searchInput.addEventListener('input', function() {
        const val = this.value.toLowerCase().trim();
        
        // Filtrar vista grid
        document.querySelectorAll('.depto-item').forEach(item => {
            const data = item.getAttribute('data-search');
            item.style.display = data.includes(val) ? 'block' : 'none';
        });

        // Filtrar vista tabla
        document.querySelectorAll('.depto-row').forEach(row => {
            const data = row.getAttribute('data-search');
            row.style.display = data.includes(val) ? 'table-row' : 'none';
        });
    });
</script>

<?php require '../layout/layout_footer.php'; ?>