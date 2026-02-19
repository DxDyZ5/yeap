<?php
// ausencias_prolongadas.php - MONITOR DE INACTIVIDAD CON FILTROS DE TIPO Y ESTATUS
require '../auth.php';
require_once '../db.php';

verificarAcceso('ver');

$hoy = date('Y-m-d');

// --- PARÁMETROS DE FILTRO Y ORDEN ---
$termino = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$f_estatus = $_GET['f_estatus'] ?? 'En Nomina'; 
$f_tipo = $_GET['f_tipo'] ?? 'planta'; // Valor predeterminado: EN PLANTA
$sort = $_GET['sort'] ?? 'dias_desc'; // dias_desc, score_asc, score_desc

// 1. Obtener feriados para el cálculo preciso
$feriados = $pdo->query("SELECT fecha FROM feriados")->fetchAll(PDO::FETCH_COLUMN) ?: [];

// 2. Construcción de Consulta SQL con Filtros
$where = [];
$params = [];

if ($termino !== '') {
    $where[] = "(e.nombre_completo LIKE :q OR e.id_reloj LIKE :q OR e.cedula LIKE :q)";
    $params[':q'] = "%$termino%";
}

if ($f_estatus !== 'todos') {
    $where[] = "e.estatus_nomina = :est";
    $params[':est'] = $f_estatus;
}

// Filtro de Tipo de Personal (EN PLANTA / EXTERNO)
if ($f_tipo !== 'todos') {
    $where[] = "e.tipo_personal = :tipo";
    $params[':tipo'] = $f_tipo;
}

$sql = "SELECT e.id_reloj, e.nombre_completo, e.cargo, e.cedula, e.departamento, e.tipo_personal,
               MAX(a.fecha) as ultima_actividad
        FROM empleados e
        LEFT JOIN asistencia a ON e.id_reloj = a.id_empleado_reloj " . 
        (!empty($where) ? " WHERE " . implode(" AND ", $where) : "") . "
        GROUP BY e.id_reloj";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$pool = $stmt->fetchAll();

$ausencias_detectadas = [];

foreach ($pool as $e) {
    $ultima = $e['ultima_actividad'];
    $dias_ausente = 0;
    
    if (!$ultima) {
        $dias_ausente = 999; // Representa "NUNCA" para ordenamiento
        $fecha_format = "S/R";
    } else {
        $start = strtotime($ultima . ' +1 day');
        $end = strtotime($hoy . ' -1 day'); 

        for ($i = $start; $i <= $end; $i = strtotime('+1 day', $i)) {
            $fecha_it = date('Y-m-d', $i);
            $dia_sem = date('w', $i);
            if ($dia_sem != 0 && $dia_sem != 6 && !in_array($fecha_it, $feriados)) {
                $dias_ausente++;
            }
        }
        $fecha_format = date('d/m/Y', strtotime($ultima));
    }

    // Filtro de umbral: Solo reportar si inactividad >= 3 días o Nunca
    if ($dias_ausente >= 3) {
        // Invocación al Motor Centralizado en db.php
        $scoreData = obtenerScoreGlobal($e['id_reloj'], $pdo);
        
        $ausencias_detectadas[] = [
            'id' => $e['id_reloj'],
            'nombre' => $e['nombre_completo'],
            'cargo' => $e['cargo'],
            'cedula' => $e['cedula'],
            'ultima_fecha' => $fecha_format,
            'dias' => $dias_ausente,
            'score' => $scoreData['val'],
            'stars' => $scoreData['stars'],
            'tipo' => $e['tipo_personal']
        ];
    }
}

// --- LÓGICA DE ORDENAMIENTO ---
if ($sort === 'score_asc') {
    usort($ausencias_detectadas, fn($a, $b) => $a['score'] <=> $b['score']);
} elseif ($sort === 'score_desc') {
    usort($ausencias_detectadas, fn($a, $b) => $b['score'] <=> $a['score']);
} elseif ($sort === 'dias_asc') {
    usort($ausencias_detectadas, fn($a, $b) => $a['dias'] <=> $b['dias']);
} else {
    usort($ausencias_detectadas, fn($a, $b) => $b['dias'] <=> $a['dias']);
}

require '../layout/layout_head.php';
?>

<style>
    body { opacity: 0; transition: opacity 0.4s ease-in-out; background: #f8fafc; }
    body.ready { opacity: 1; }
    .card-exp { background: white; border-radius: 2.2rem; border: 1px solid #f1f5f9; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.02); overflow: hidden; }
    .label-black { color: #000 !important; font-weight: 950 !important; font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.05em; }
</style>

<div class="container mx-auto px-4 py-8 max-w-7xl">
    
    <!-- Encabezado -->
    <div class="flex flex-col md:flex-row justify-between items-center mb-8 mt-4 gap-6">
        <div class="flex items-center gap-5">
            <div class="w-16 h-16 bg-red-600 rounded-3xl flex items-center justify-center text-white shadow-xl shadow-red-200">
                <i class="fas fa-calendar-times text-2xl"></i>
            </div>
            <div>
                <h1 class="text-3xl font-black text-slate-800 tracking-tighter uppercase leading-none">Ausencias Prolongadas</h1>
                <p class="text-[10px] text-gray-400 font-black uppercase tracking-widest mt-2">Detección de Inactividad Crítica (+3 Días Laborables)</p>
            </div>
        </div>
        <div class="text-right bg-white px-6 py-3 rounded-2xl border border-slate-100 shadow-sm">
            <p class="text-[9px] font-black text-gray-400 uppercase tracking-widest mb-1">Casos Identificados</p>
            <p class="text-xl font-black text-red-600"><?php echo count($ausencias_detectadas); ?></p>
        </div>
    </div>

    <!-- Filtros Superiores -->
    <div class="bg-white p-6 rounded-[2rem] shadow-sm border border-slate-100 mb-8">
        <form method="GET" class="grid grid-cols-1 md:grid-cols-12 gap-4 items-end">
            <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort); ?>">
            
            <div class="md:col-span-4 relative">
                <label class="label-black mb-2 block ml-1">Buscador de Personal</label>
                <div class="relative">
                    <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-slate-300"></i>
                    <input type="text" name="q" value="<?php echo htmlspecialchars($termino); ?>" placeholder="Nombre, ID o Cédula..." class="input-premium pl-12 shadow-inner bg-slate-50/50 uppercase">
                </div>
            </div>

            <div class="md:col-span-2">
                <label class="label-black mb-2 block ml-1">Tipo de Personal</label>
                <select name="f_tipo" onchange="this.form.submit()" class="input-premium bg-white font-bold">
                    <option value="planta" <?php echo $f_tipo == 'planta' ? 'selected' : ''; ?>>EN PLANTA</option>
                    <option value="externo" <?php echo $f_tipo == 'externo' ? 'selected' : ''; ?>>EXTERNO</option>
                    <option value="todos" <?php echo $f_tipo == 'todos' ? 'selected' : ''; ?>>VER TODOS</option>
                </select>
            </div>

            <div class="md:col-span-2">
                <label class="label-black mb-2 block ml-1">Estatus Nómina</label>
                <select name="f_estatus" onchange="this.form.submit()" class="input-premium bg-white">
                    <option value="En Nomina" <?php echo $f_estatus == 'En Nomina' ? 'selected' : ''; ?>>SOLO EN NÓMINA</option>
                    <option value="Fuera de Nomina" <?php echo $f_estatus == 'Fuera de Nomina' ? 'selected' : ''; ?>>FUERA DE NÓMINA</option>
                    <option value="todos" <?php echo $f_estatus == 'todos' ? 'selected' : ''; ?>>VER TODOS</option>
                </select>
            </div>

            <div class="md:col-span-4 flex gap-2">
                <button type="submit" class="flex-grow bg-slate-900 hover:bg-black text-white py-3.5 rounded-2xl font-black text-[10px] uppercase tracking-widest transition shadow-lg active:scale-95">
                    Filtrar
                </button>
                <a href="ausencias_prolongadas.php" class="bg-slate-100 hover:bg-slate-200 text-slate-500 p-3.5 rounded-2xl flex items-center justify-center transition" title="Limpiar Filtros">
                    <i class="fas fa-undo-alt"></i>
                </a>
            </div>
        </form>
    </div>

    <!-- Tabla de Resultados -->
    <div class="card-exp shadow-2xl border-t-8 border-red-600">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-slate-400 font-bold text-[10px] uppercase tracking-widest border-b">
                    <tr>
                        <th class="px-8 py-5 text-left">Colaborador / Posición</th>
                        <th class="px-6 py-5 text-center">Tipo</th>
                        <th class="px-6 py-5 text-center">Última Marca</th>
                        <th class="px-6 py-5 text-center">Inactividad</th>
                        <th class="px-6 py-5 text-center">
                            <div class="flex items-center justify-center gap-2">
                                Impacto Score
                                <div class="flex flex-col text-[7px] gap-0.5">
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'score_desc'])); ?>" class="<?php echo $sort == 'score_desc' ? 'text-blue-600':'text-slate-300'; ?>"><i class="fas fa-chevron-up"></i></a>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'score_asc'])); ?>" class="<?php echo $sort == 'score_asc' ? 'text-blue-600':'text-slate-300'; ?>"><i class="fas fa-chevron-down"></i></a>
                                </div>
                            </div>
                        </th>
                        <th class="px-8 py-5 text-right">Auditoría</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    <?php if(empty($ausencias_detectadas)): ?>
                        <tr>
                            <td colspan="6" class="py-20 text-center">
                                <i class="fas fa-check-circle text-green-400 text-5xl mb-4"></i>
                                <p class="text-slate-400 font-bold uppercase text-xs tracking-widest">No se detectan ausencias críticas bajo este filtro</p>
                            </td>
                        </tr>
                    <?php else: foreach ($ausencias_detectadas as $aus): 
                        $c_l = preg_replace('/[^0-9]/', '', $aus['cedula']);
                        $foto = file_exists("../fotos/$c_l.jpg") ? "../fotos/$c_l.jpg" : "https://ui-avatars.com/api/?name=".urlencode($aus['nombre'])."&background=random";
                        
                        $es_critico = ($aus['dias'] >= 5 || $aus['dias'] === 999);
                        $clase_alerta = $es_critico ? 'bg-red-600 text-white animate-pulse' : 'bg-orange-100 text-orange-700';
                        $texto_dias = ($aus['dias'] === 999) ? "NUNCA" : $aus['dias'] . " DÍAS";
                    ?>
                    <tr class="hover:bg-red-50/30 transition group">
                        <td class="px-8 py-5">
                            <div class="flex items-center gap-4">
                                <img src="<?php echo $foto; ?>" class="w-12 h-12 rounded-full object-cover border-2 border-white shadow-sm ring-2 ring-slate-100">
                                <div>
                                    <a href="../usuarios/editar_empleado.php?id=<?php echo $aus['id']; ?>" class="font-black text-slate-800 uppercase leading-tight hover:text-blue-600 transition-colors">
                                        <?php echo htmlspecialchars($aus['nombre']); ?>
                                    </a>
                                    <p class="text-[9px] text-gray-400 font-bold uppercase mt-0.5"><?php echo htmlspecialchars($aus['cargo']); ?></p>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-5 text-center">
                            <span class="px-2 py-1 rounded bg-slate-100 text-slate-500 font-black text-[8px] uppercase">
                                <?php echo htmlspecialchars($aus['tipo']); ?>
                            </span>
                        </td>
                        <td class="px-6 py-5 text-center">
                            <span class="text-xs font-bold text-slate-500 font-mono"><?php echo $aus['ultima_fecha']; ?></span>
                        </td>
                        <td class="px-6 py-5 text-center">
                            <span class="px-4 py-1.5 rounded-full text-[10px] font-black uppercase tracking-tighter <?php echo $clase_alerta; ?>">
                                <?php echo $texto_dias; ?>
                            </span>
                        </td>
                        <td class="px-6 py-5 text-center">
                            <div class="inline-flex flex-col items-center">
                                <span class="text-sm font-black text-red-600"><?php echo $aus['score']; ?>%</span>
                                <div class="flex gap-0.5 text-[7px] text-amber-400 mt-1">
                                    <?php for($i=1;$i<=5;$i++) echo '<i class="fas fa-star '.($i<=$aus['stars']?'':'text-slate-100').'"></i>'; ?>
                                </div>
                            </div>
                        </td>
                        <td class="px-8 py-5 text-right">
                            <a href="historial.php?id=<?php echo $aus['id']; ?>" class="w-10 h-10 inline-flex items-center justify-center bg-slate-800 hover:bg-black text-white rounded-xl shadow-lg transition transform active:scale-90" title="Ver Historial Detallado">
                                <i class="fas fa-clock-rotate-left text-xs"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Info Panel -->
    <div class="mt-8 grid grid-cols-1 md:grid-cols-2 gap-6 no-print">
        <div class="bg-indigo-900 rounded-3xl p-8 text-white relative overflow-hidden shadow-xl border border-white/5">
            <i class="fas fa-info-circle absolute -right-4 -bottom-4 text-8xl opacity-10"></i>
            <h3 class="text-xs font-black uppercase tracking-[0.2em] text-indigo-300 mb-4">Criterio de Inactividad</h3>
            <p class="text-sm leading-relaxed text-indigo-100">Este monitor calcula exclusivamente **Días Laborables** (Lun-Vie), excluyendo fines de semana y feriados oficiales. El score mostrado es el resultado global del algoritmo centralizado de evaluación definido en la configuración.</p>
        </div>
        <div class="bg-slate-100 rounded-3xl p-8 text-slate-600 border border-slate-200 shadow-inner">
            <h3 class="text-xs font-black uppercase tracking-[0.2em] text-slate-400 mb-4">Acción Recomendada</h3>
            <p class="text-sm leading-relaxed italic">"Cualquier ausencia superior a 3 días sin justificación registrada (vacaciones o licencias) debe ser reportada a la dirección para auditoría de nómina inmediata."</p>
        </div>
    </div>
</div>

<script>
    document.addEventListener("DOMContentLoaded", () => {
        setTimeout(() => document.body.classList.add('ready'), 100);
    });
</script>

<?php require '../layout/layout_footer.php'; ?>