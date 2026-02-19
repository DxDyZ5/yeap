<?php
// perfil_empleado.php - DIRECTORIO CON SCORE CENTRALIZADO, ORDENAMIENTO Y LAZY LOADING
require_once __DIR__ . '/../core/auth.php'; 
require_once __DIR__ . '/../core/db.php'; 

// Seguridad: Verifica si el rol tiene permiso 'ver'
verificarAcceso('ver');

// --- CONFIGURACIÓN DE LÍMITE Y PAGINACIÓN ---
$f_limit = isset($_GET['f_limit']) ? ($_GET['f_limit'] === 'all' ? 999999 : (int)$_GET['f_limit']) : 100;
$pagina_actual = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($pagina_actual < 1) $pagina_actual = 1;

// --- FILTROS Y PARÁMETROS ---
$termino = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$f_empresa = $_GET['f_empresa'] ?? '';
$f_depto = $_GET['f_depto'] ?? '';
$f_estatus = $_GET['f_estatus'] ?? 'En Nomina'; 
$f_color = $_GET['f_color'] ?? ''; 
$sort = $_GET['sort'] ?? 'id_desc'; // id_desc, score_asc, score_desc
$hoy = date('Y-m-d');

// Puntos que definen que el usuario está "fuera"
$puntos_salida = ['Torniquete 2-2', 'Torniquete 1-2', 'Torniquete 3-2'];

$lista_empresas = $pdo->query("SELECT DISTINCT empresa FROM empleados WHERE empresa IS NOT NULL AND empresa != '' ORDER BY empresa ASC")->fetchAll(PDO::FETCH_COLUMN);
$lista_deptos = $pdo->query("SELECT DISTINCT departamento FROM empleados WHERE departamento IS NOT NULL AND departamento != '' ORDER BY departamento ASC")->fetchAll(PDO::FETCH_COLUMN);

// --- CONSTRUCCIÓN DE SQL ---
$where = ["(e.nombre_completo LIKE :t1 OR e.cedula LIKE :t2 OR e.id_reloj LIKE :t3)"];
$params = [':t1' => "%$termino%", ':t2' => "%$termino%", ':t3' => "%$termino%", ':hoy' => $hoy];

if ($f_empresa) { $where[] = "e.empresa = :emp"; $params[':emp'] = $f_empresa; }
if ($f_depto) { $where[] = "e.departamento = :dep"; $params[':dep'] = $f_depto; }
if ($f_estatus !== 'todos') { $where[] = "e.estatus_nomina = :est"; $params[':est'] = $f_estatus; }

$sql = "SELECT e.*, a.hora_entrada as registro_hoy, a.ultimo_punto 
        FROM empleados e 
        LEFT JOIN asistencia a ON e.id_reloj = a.id_empleado_reloj AND a.fecha = :hoy
        WHERE " . implode(" AND ", $where);

// Orden inicial por ID si no es por score
if ($sort === 'id_asc') { $sql .= " ORDER BY e.id_reloj ASC"; } 
else if ($sort === 'id_desc') { $sql .= " ORDER BY e.id_reloj DESC"; }

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$data_pool = $stmt->fetchAll();

// --- PROCESAMIENTO DE SCORE CENTRALIZADO ---
foreach ($data_pool as $key => &$r) { 
    // Invocación al Motor Centralizado en db.php
    $r['score_data'] = obtenerScoreGlobal($r['id_reloj'], $pdo); 
    $r['score_val'] = $r['score_data']['val'];

    // Filtro por color (Alerta)
    if ($f_color !== '') {
        $pass = false;
        if ($f_color === 'verde' && $r['score_val'] >= 80) $pass = true;
        elseif ($f_color === 'amarillo' && $r['score_val'] >= 50 && $r['score_val'] < 80) $pass = true;
        elseif ($f_color === 'rojo' && $r['score_val'] < 50) $pass = true;
        if (!$pass) unset($data_pool[$key]);
    }
}

// --- ORDENAMIENTO POR SCORE (PHP side) ---
if ($sort === 'score_asc') {
    usort($data_pool, fn($a, $b) => $a['score_val'] <=> $b['score_val']);
} elseif ($sort === 'score_desc') {
    usort($data_pool, fn($a, $b) => $b['score_val'] <=> $a['score_val']);
}

// --- PAGINACIÓN ---
$total_registros = count($data_pool);
$total_paginas = ceil($total_registros / $f_limit);
$offset = ($pagina_actual - 1) * $f_limit;
$empleados = array_slice($data_pool, $offset, $f_limit);

require 'layout_head.php';
?>

<style>
    body { opacity: 0; transition: opacity 0.4s ease-in-out; background: #f8fafc; }
    body.ready { opacity: 1; }
    .label-black { color: #000 !important; font-weight: 950 !important; font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.05em; }
    .text-dark-gray { color: #1e293b !important; font-weight: 700; }
    .filter-card { background: white; border-radius: 1.5rem; padding: 1.5rem; border: 1px solid #f1f5f9; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.02); }
    .sort-link { display: flex; align-items: center; justify-content: center; gap: 4px; transition: color 0.2s; }
    .sort-link:hover { color: #3b82f6; }
    .sort-active { color: #2563eb !important; }
</style>

<div class="container mx-auto px-4 py-6">
    
    <!-- Panel de Filtros -->
    <div class="filter-card mb-8">
        <form method="GET" id="filterForm" class="space-y-6">
            <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort); ?>">
            <div class="grid grid-cols-1 md:grid-cols-12 gap-4 items-end">
                <div class="md:col-span-10 relative">
                    <label class="label-black mb-2 ml-1 block">Buscador Inteligente</label>
                    <div class="relative">
                        <input type="text" name="q" value="<?php echo htmlspecialchars($termino); ?>" placeholder="Nombre, Cédula o ID..." class="w-full pl-12 pr-4 py-4 border-2 border-slate-50 rounded-2xl focus:border-blue-500 focus:ring-4 focus:ring-blue-100 outline-none text-dark-gray text-lg transition-all shadow-inner bg-slate-50/50">
                        <i class="fas fa-search absolute left-4 top-1/2 transform -translate-y-1/2 text-blue-400 text-xl"></i>
                    </div>
                </div>
                <div class="md:col-span-2">
                    <a href="<?php echo modulo_url('personal', 'nuevo.php'); ?>" class="w-full bg-blue-600 hover:bg-blue-700 text-white py-4 rounded-2xl font-black shadow-lg shadow-blue-200 transition text-xs flex items-center justify-center gap-2 uppercase tracking-widest active:scale-95">
                        <i class="fas fa-plus-circle"></i> Nuevo Ingreso
                    </a>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-10 gap-3 items-end pt-2 border-t border-slate-50 mt-4">
                <div class="md:col-span-2">
                    <label class="label-black mb-1 block">Empresa</label>
                    <select name="f_empresa" onchange="this.form.submit()" class="w-full h-[45px] px-3 border border-slate-200 rounded-xl text-xs font-bold text-dark-gray bg-white">
                        <option value="">Todas las Empresas</option>
                        <?php foreach($lista_empresas as $empresa): ?>
                            <option value="<?php echo htmlspecialchars($empresa); ?>" <?php echo $f_empresa == $empresa ? 'selected' : ''; ?>><?php echo htmlspecialchars($empresa); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="md:col-span-2">
                    <label class="label-black mb-1 block">Departamento</label>
                    <select name="f_depto" onchange="this.form.submit()" class="w-full h-[45px] px-3 border border-slate-200 rounded-xl text-xs font-bold text-dark-gray bg-white">
                        <option value="">Todos los Deptos</option>
                        <?php foreach($lista_deptos as $depto): ?>
                            <option value="<?php echo htmlspecialchars($depto); ?>" <?php echo $f_depto == $depto ? 'selected' : ''; ?>><?php echo htmlspecialchars($depto); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="md:col-span-2">
                    <label class="label-black mb-1 block">Estatus Nómina</label>
                    <select name="f_estatus" onchange="this.form.submit()" class="w-full h-[45px] px-3 border border-blue-100 rounded-xl text-xs font-black text-blue-700 bg-blue-50">
                        <option value="En Nomina" <?php echo $f_estatus == 'En Nomina' ? 'selected' : ''; ?>>En Nómina</option>
                        <option value="todos" <?php echo $f_estatus == 'todos' ? 'selected' : ''; ?>>Ver Históricos</option>
                    </select>
                </div>
                <div class="md:col-span-2">
                    <label class="label-black mb-1 block">Alerta de Asistencia</label>
                    <select name="f_color" onchange="this.form.submit()" class="w-full h-[45px] px-3 border border-slate-200 rounded-xl text-xs font-bold text-dark-gray bg-white">
                        <option value="">Sin Filtro</option>
                        <option value="verde" <?php echo $f_color == 'verde' ? 'selected' : ''; ?>>🟢 EXCELENTE</option>
                        <option value="amarillo" <?php echo $f_color == 'amarillo' ? 'selected' : ''; ?>>🟡 REGULAR</option>
                        <option value="rojo" <?php echo $f_color == 'rojo' ? 'selected' : ''; ?>>🔴 CRÍTICO</option>
                    </select>
                </div>
                <div class="md:col-span-2">
                    <div class="bg-slate-900 text-white h-[45px] rounded-xl flex flex-col items-center justify-center font-black text-[9px] uppercase tracking-tighter shadow-lg">
                        <span class="opacity-50">Total Encontrados</span>
                        <span class="text-xs text-blue-400"><?php echo $total_registros; ?></span>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- Listado de Empleados -->
    <div class="bg-white rounded-[2.5rem] shadow-2xl border border-gray-100 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-slate-400 font-bold text-[10px] uppercase tracking-widest border-b">
                <tr>
                    <th class="px-8 py-5 text-left">Empleado / Cargo</th>
                    <th class="px-6 py-5 text-left">Organización</th>
                    <th class="px-6 py-5 text-center">
                        <div class="flex items-center justify-center gap-2">
                            Score 30d
                            <div class="flex flex-col text-[8px] gap-0.5">
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'score_desc'])); ?>" class="<?php echo $sort == 'score_desc' ? 'text-blue-600':'text-slate-300'; ?>"><i class="fas fa-chevron-up"></i></a>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'score_asc'])); ?>" class="<?php echo $sort == 'score_asc' ? 'text-blue-600':'text-slate-300'; ?>"><i class="fas fa-chevron-down"></i></a>
                            </div>
                        </div>
                    </th>
                    <th class="px-8 py-5 text-right">Gestión</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
                <?php foreach ($empleados as $e): 
                    $c_l = preg_replace('/[^0-9]/', '', (string)$e['cedula']);
                    $foto = foto_path($e['cedula'] ?? '');
                    
                    $tiene_entrada = !empty($e['registro_hoy']);
                    $esta_fuera = in_array($e['ultimo_punto'] ?? '', $puntos_salida);
                    
                    $borde_class = (!$tiene_entrada) ? 'border-red-500 ring-4 ring-red-50' : 'border-green-500 ring-4 ring-green-50';
                    $dot_color = ($tiene_entrada && !$esta_fuera) ? 'bg-green-500' : 'bg-red-500';
                    $dot_icon = ($tiene_entrada && !$esta_fuera) ? 'fa-check' : 'fa-times';

                    $score = $e['score_val'];
                    $stars = $e['score_data']['stars'];
                ?>
                <tr class="hover:bg-blue-50/30 transition group">
                    <td class="px-8 py-5">
                        <div class="flex items-center gap-5">
                            <div class="relative w-16 h-16 flex-shrink-0">
                                <img src="<?php echo $foto; ?>" loading="lazy" class="w-full h-full rounded-full object-cover border-2 shadow-sm <?php echo $borde_class; ?>">
                                <div class="absolute bottom-0 right-0 w-5 h-5 rounded-full border-2 border-white <?php echo $dot_color; ?> shadow-md flex items-center justify-center">
                                    <i class="fas <?php echo $dot_icon; ?> text-white text-[8px]"></i>
                                </div>
                            </div>
                            <div>
                                <a href="<?php echo modulo_url('personal', 'editar.php', ['id' => $e['id_reloj']]); ?>" class="font-black text-slate-800 text-base hover:text-blue-600 transition-colors uppercase"><?php echo htmlspecialchars($e['nombre_completo']); ?></a>
                                <p class="text-[10px] text-blue-600 font-black uppercase tracking-tighter mt-0.5"><?php echo htmlspecialchars($e['cargo']); ?></p>
                                <div class="flex items-center gap-2 mt-1">
                                    <span class="text-[9px] font-mono text-slate-400 bg-slate-100 px-1.5 rounded">ID: <?php echo $e['id_reloj']; ?></span>
                                    <?php if($esta_fuera): ?>
                                        <span class="text-[8px] font-black text-red-500 uppercase bg-red-50 px-1.5 rounded border border-red-100">En Exterior</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </td>
                    <td class="px-6 py-5">
                        <div class="flex flex-col">
                            <span class="text-[10px] font-black text-slate-700 uppercase tracking-tight"><?php echo htmlspecialchars($e['empresa']); ?></span>
                            <span class="text-[9px] text-slate-400 font-bold uppercase mt-1"><?php echo htmlspecialchars($e['departamento']); ?></span>
                        </div>
                    </td>
                    <td class="px-6 py-5 text-center">
                        <div class="inline-flex flex-col items-center">
                            <div class="text-lg font-black <?php echo $score < 70 ? 'text-red-500' : 'text-blue-700'; ?>"><?php echo $score; ?>%</div>
                            <div class="flex gap-0.5 text-[7px] text-amber-400 mb-1">
                                <?php for($i=1;$i<=5;$i++) echo '<i class="fas fa-star '.($i<=$stars?'':'text-slate-200').'"></i>'; ?>
                            </div>
                            <div class="w-16 h-1 bg-slate-100 rounded-full overflow-hidden">
                                <div class="h-full <?php echo $score < 70 ? 'bg-red-500' : 'bg-blue-600'; ?>" style="width: <?php echo $score; ?>%"></div>
                            </div>
                        </div>
                    </td>
                    <td class="px-8 py-5 text-right">
                        <div class="flex items-center justify-end gap-2">
                            <a href="<?php echo modulo_url('asistencia', 'historial.php', ['id' => $e['id_reloj']]); ?>" class="bg-slate-800 hover:bg-black text-white px-5 py-2.5 rounded-xl text-[9px] font-black uppercase tracking-widest shadow-lg transition active:scale-95">Auditoría</a>
                            <a href="<?php echo modulo_url('personal', 'editar.php', ['id' => $e['id_reloj']]); ?>" class="w-10 h-10 flex items-center justify-center bg-blue-50 text-blue-500 rounded-xl hover:bg-blue-600 hover:text-white transition shadow-sm border border-blue-100" title="Editar Expediente"><i class="fas fa-fingerprint"></i></a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Paginación -->
    <?php if ($total_paginas > 1): ?>
    <div class="mt-10 flex justify-center items-center gap-2">
        <?php if($pagina_actual > 1): ?>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $pagina_actual-1])); ?>" class="w-10 h-10 flex items-center justify-center rounded-xl bg-white border text-slate-400 hover:bg-slate-50 transition"><i class="fas fa-chevron-left"></i></a>
        <?php endif; ?>

        <?php 
        $rango = 2;
        for($i=1; $i<=$total_paginas; $i++): 
            if($i == 1 || $i == $total_paginas || ($i >= $pagina_actual - $rango && $i <= $pagina_actual + $rango)):
        ?>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" class="w-10 h-10 flex items-center justify-center rounded-xl font-black text-xs transition <?php echo $pagina_actual == $i ? 'bg-blue-600 text-white shadow-xl shadow-blue-200' : 'bg-white text-slate-400 border border-slate-100 hover:bg-blue-50'; ?>"><?php echo $i; ?></a>
        <?php endif; endfor; ?>

        <?php if($pagina_actual < $total_paginas): ?>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $pagina_actual+1])); ?>" class="w-10 h-10 flex items-center justify-center rounded-xl bg-white border text-slate-400 hover:bg-slate-50 transition"><i class="fas fa-chevron-right"></i></a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<script>
    document.addEventListener("DOMContentLoaded", () => {
        setTimeout(() => document.body.classList.add('ready'), 120);
    });
</script>

<?php require 'layout_footer.php'; ?>