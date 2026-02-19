<?php
// perfil_departamento.php - DETALLE SEGMENTADO CON BUSCADOR MANUAL DE EMPLEADOS
require '../core/auth.php';
verificarPermiso(['admin', 'rrhh']);

$nombre_depto = $_GET['name'] ?? '';
$empresa = $_GET['empresa'] ?? ''; 
$view_mode = $_GET['view'] ?? 'list'; 
$sort_mode = $_GET['sort'] ?? 'score_desc'; 

if (empty($nombre_depto)) { header("Location: departamento_list.php"); exit; }

// --- FUNCIÓN DE CALIFICACIÓN INDIVIDUAL ---
function obtenerCalificacionIndividual($id_reloj, $pdo) {
    $fecha_ini = date('Y-m-d', strtotime('-30 days'));
    $fecha_fin = date('Y-m-d');
    $stmt = $pdo->prepare("SELECT fecha, hora_entrada, hora_salida FROM asistencia WHERE id_empleado_reloj = ? AND fecha BETWEEN ? AND ?");
    $stmt->execute([$id_reloj, $fecha_ini, $fecha_fin]);
    $asistencias = $stmt->fetchAll();
    $map = []; foreach($asistencias as $a) $map[$a['fecha']] = $a;
    $suma = 0; $dias = 0;
    $curr = strtotime($fecha_fin); $stop = strtotime($fecha_ini);
    while($curr >= $stop) {
        if (date('w', $curr) != 0 && date('w', $curr) != 6) {
            $p = 100;
            $f = date('Y-m-d', $curr);
            if (!isset($map[$f]) || empty($map[$f]['hora_entrada'])) $p = 0;
            elseif (empty($map[$f]['hora_salida']) || $map[$f]['hora_salida'] == '00:00:00') $p = 60;
            $suma += $p; $dias++;
        }
        $curr = strtotime('-1 day', $curr);
    }
    return ($dias > 0) ? round($suma / $dias) : 100;
}

// 1. Obtener Info del Encargado
$stmt = $pdo->prepare("SELECT e.nombre_completo, e.cargo, e.cedula, e.id_reloj 
                       FROM departamentos_meta m 
                       JOIN empleados e ON m.encargado_id_reloj = e.id_reloj 
                       WHERE m.nombre_departamento = ? AND (m.empresa = ? OR m.empresa = '') 
                       ORDER BY m.empresa DESC LIMIT 1");
$stmt->execute([$nombre_depto, $empresa]);
$meta = $stmt->fetch();

$foto_leader = "https://ui-avatars.com/api/?name=".urlencode($meta['nombre_completo'] ?? 'S/A')."&background=random";
if ($meta && !empty($meta['cedula'])) {
    $ced_l = preg_replace('/[^0-9]/', '', $meta['cedula']);
    if (file_exists("../fotos/$ced_l.jpg")) $foto_leader = "../fotos/$ced_l.jpg";
}

// 2. Obtener Integrantes
$sqlInt = "SELECT * FROM empleados WHERE departamento = ? AND estatus_nomina = 'En Nomina'";
$params = [$nombre_depto];
if (!empty($empresa)) { $sqlInt .= " AND empresa = ?"; $params[] = $empresa; }

$stmtI = $pdo->prepare($sqlInt);
$stmtI->execute($params);
$integrantes = $stmtI->fetchAll();

foreach($integrantes as &$i) { $i['score'] = obtenerCalificacionIndividual($i['id_reloj'], $pdo); }
unset($i);

if ($sort_mode === 'score_desc') usort($integrantes, function($a, $b) { return $b['score'] <=> $a['score']; });
elseif ($sort_mode === 'score_asc') usort($integrantes, function($a, $b) { return $a['score'] <=> $b['score']; });
elseif ($sort_mode === 'name') usort($integrantes, function($a, $b) { return strcmp($a['nombre_completo'], $b['nombre_completo']); });

$promedio = count($integrantes) > 0 ? round(array_sum(array_column($integrantes, 'score')) / count($integrantes)) : 0;

require '../layout/layout_head.php';
?>

<div class="max-w-7xl mx-auto space-y-8 pb-20">
    
    <!-- CABECERA PRINCIPAL -->
    <div class="bg-gradient-to-br from-slate-800 to-indigo-900 rounded-3xl p-8 text-white shadow-2xl relative overflow-hidden">
        <div class="relative z-10 flex flex-col md:flex-row justify-between items-center gap-8">
            <div class="text-center md:text-left">
                <span class="bg-blue-500/30 text-blue-200 px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-widest border border-white/10 mb-2 inline-block">
                    <?php echo $empresa ?: 'Corporativo'; ?>
                </span>
                <h1 class="text-4xl md:text-5xl font-black tracking-tight mb-4"><?php echo htmlspecialchars($nombre_depto); ?></h1>
                <div class="flex flex-wrap justify-center md:justify-start gap-4">
                    <div class="bg-white/10 backdrop-blur-sm px-4 py-2 rounded-2xl border border-white/10">
                        <p class="text-[10px] uppercase font-bold opacity-60">Personal</p>
                        <p class="text-xl font-black"><?php echo count($integrantes); ?></p>
                    </div>
                    <div class="bg-white/10 backdrop-blur-sm px-4 py-2 rounded-2xl border border-white/10">
                        <p class="text-[10px] uppercase font-bold opacity-60">Eficacia Grupal</p>
                        <p class="text-xl font-black"><?php echo $promedio; ?>%</p>
                    </div>
                </div>
            </div>

            <?php if($meta): ?>
            <a href="editar_empleado.php?id=<?php echo $meta['id_reloj']; ?>" class="bg-white/10 backdrop-blur-md p-5 rounded-3xl border border-white/20 flex items-center gap-5 hover:bg-white/20 transition group">
                <img src="<?php echo $foto_leader; ?>" class="w-20 h-20 rounded-2xl object-cover border-2 border-white shadow-xl group-hover:scale-105 transition">
                <div>
                    <p class="text-[10px] font-black uppercase text-blue-300 tracking-tighter mb-1">Encargado de Unidad</p>
                    <p class="font-black text-xl leading-tight"><?php echo $meta['nombre_completo']; ?></p>
                    <p class="text-xs opacity-70 uppercase font-bold"><?php echo $meta['cargo']; ?></p>
                </div>
            </a>
            <?php endif; ?>
        </div>
        <i class="fas fa-sitemap absolute -right-20 -bottom-20 text-[350px] opacity-5"></i>
    </div>

    <!-- BARRA DE HERRAMIENTAS -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 bg-white p-5 rounded-2xl shadow-sm border border-slate-100">
        <div class="flex items-center gap-3">
            <h2 class="font-black text-slate-700 uppercase text-xs tracking-widest">Colaboradores</h2>
            <!-- BUSCADOR MANUAL -->
            <div class="relative">
                <input type="text" id="member_search" placeholder="Buscar por nombre, ID o cargo..." class="pl-10 pr-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold focus:ring-2 focus:ring-blue-500 outline-none w-[300px]">
                <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
            </div>
        </div>
        
        <div class="flex flex-wrap items-center gap-3">
            <div class="flex items-center bg-slate-100 p-1 rounded-xl">
                <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'score_desc'])); ?>" class="px-3 py-1.5 rounded-lg text-[10px] font-bold uppercase transition <?php echo $sort_mode == 'score_desc' ? 'bg-white shadow-sm text-blue-600' : 'text-slate-500 hover:text-slate-700'; ?>">Mejor</a>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'score_asc'])); ?>" class="px-3 py-1.5 rounded-lg text-[10px] font-bold uppercase transition <?php echo $sort_mode == 'score_asc' ? 'bg-white shadow-sm text-red-600' : 'text-slate-500 hover:text-slate-700'; ?>">Menor</a>
            </div>
            <div class="flex items-center bg-slate-100 p-1 rounded-xl">
                <a href="?<?php echo http_build_query(array_merge($_GET, ['view' => 'grid'])); ?>" class="w-9 h-9 flex items-center justify-center rounded-lg transition <?php echo $view_mode == 'grid' ? 'bg-white shadow-sm text-blue-600' : 'text-slate-400'; ?>">
                    <i class="fas fa-th-large"></i>
                </a>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['view' => 'list'])); ?>" class="w-9 h-9 flex items-center justify-center rounded-lg transition <?php echo $view_mode == 'list' ? 'bg-white shadow-sm text-blue-600' : 'text-slate-400'; ?>">
                    <i class="fas fa-list"></i>
                </a>
            </div>
        </div>
    </div>

    <?php if ($view_mode === 'grid'): ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6" id="member_grid">
            <?php foreach ($integrantes as $emp): 
                $ced_l = preg_replace('/[^0-9]/', '', $emp['cedula']);
                $foto_emp = file_exists("../fotos/$ced_l.jpg") ? "../fotos/$ced_l.jpg" : "https://ui-avatars.com/api/?name=".urlencode($emp['nombre_completo']);
                $color = ($emp['score'] < 50) ? 'text-red-500' : (($emp['score'] < 80) ? 'text-yellow-600' : 'text-green-500');
            ?>
            <a href="editar_empleado.php?id=<?php echo $emp['id_reloj']; ?>" 
               class="member-item bg-white rounded-3xl p-5 border border-slate-200 shadow-sm hover:shadow-xl transition-all group text-center"
               data-search="<?php echo strtolower($emp['nombre_completo'] . ' ' . $emp['id_reloj'] . ' ' . $emp['cargo']); ?>">
                <img src="<?php echo $foto_emp; ?>" class="w-24 h-24 rounded-full mx-auto object-cover border-4 border-white shadow-lg group-hover:scale-110 transition mb-4">
                <h3 class="font-black text-slate-800 leading-tight mb-1"><?php echo $emp['nombre_completo']; ?></h3>
                <p class="text-[10px] text-slate-400 font-bold uppercase mb-4"><?php echo $emp['cargo']; ?></p>
                <div class="text-xs font-black <?php echo $color; ?> mb-2"><?php echo $emp['score']; ?>%</div>
                <div class="flex justify-center gap-1 text-[8px] <?php echo $color; ?> pt-4 border-t border-slate-50">
                    <?php $stars = round($emp['score']/20); for($k=1; $k<=5; $k++): ?><i class="<?php echo ($k<=$stars)?'fas':'far'; ?> fa-star"></i><?php endfor; ?>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="bg-white rounded-3xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left" id="member_table">
                    <thead class="bg-slate-50 border-b border-slate-100">
                        <tr>
                            <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase">Empleado</th>
                            <th class="px-6 py-4 text-center text-[10px] font-black text-slate-400 uppercase">ID Reloj</th>
                            <th class="px-6 py-4 text-center text-[10px] font-black text-slate-400 uppercase">Desempeño</th>
                            <th class="px-6 py-4 text-right text-[10px] font-black text-slate-400 uppercase">Eficacia</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        <?php foreach($integrantes as $emp): 
                            $ced_l = preg_replace('/[^0-9]/', '', $emp['cedula']);
                            $foto_emp = file_exists("../fotos/$ced_l.jpg") ? "../fotos/$ced_l.jpg" : "https://ui-avatars.com/api/?name=".urlencode($emp['nombre_completo']);
                            $stars = round($emp['score'] / 20);
                            $color = ($emp['score'] < 50) ? 'text-red-500' : (($emp['score'] < 80) ? 'text-yellow-600' : 'text-green-500');
                        ?>
                        <tr class="member-row hover:bg-blue-50/30 transition cursor-pointer" 
                            onclick="window.location='editar_empleado.php?id=<?php echo $emp['id_reloj']; ?>'"
                            data-search="<?php echo strtolower($emp['nombre_completo'] . ' ' . $emp['id_reloj'] . ' ' . $emp['cargo']); ?>">
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-4">
                                    <img src="<?php echo $foto_emp; ?>" class="w-10 h-10 rounded-xl object-cover shadow-sm">
                                    <div>
                                        <p class="font-black text-slate-800 text-sm"><?php echo $emp['nombre_completo']; ?></p>
                                        <p class="text-[9px] text-slate-400 font-bold uppercase"><?php echo $emp['cargo']; ?></p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-center"><span class="font-mono text-xs text-slate-400"><?php echo $emp['id_reloj']; ?></span></td>
                            <td class="px-6 py-4 text-center">
                                <div class="flex gap-0.5 <?php echo $color; ?> justify-center text-[10px]">
                                    <?php for($i=1; $i<=5; $i++): ?><i class="<?php echo ($i <= $stars) ? 'fas' : 'far'; ?> fa-star"></i><?php endfor; ?>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-right"><span class="font-black text-sm <?php echo $color; ?>"><?php echo $emp['score']; ?>%</span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
    const memSearch = document.getElementById('member_search');
    memSearch.addEventListener('input', function() {
        const val = this.value.toLowerCase().trim();
        document.querySelectorAll('.member-item').forEach(item => {
            item.style.display = item.getAttribute('data-search').includes(val) ? 'block' : 'none';
        });
        document.querySelectorAll('.member-row').forEach(row => {
            row.style.display = row.getAttribute('data-search').includes(val) ? 'table-row' : 'none';
        });
    });
</script>

<?php require '../layout/layout_footer.php'; ?>