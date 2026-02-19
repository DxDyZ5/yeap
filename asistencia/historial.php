<?php
// historial.php - REPORTE DE ASISTENCIA: FOTO REDONDA + FILA DE HORARIO + MÉTRICAS AJUSTADAS
require 'auth.php'; 
require_once 'db.php'; 

verificarPermiso(['admin', 'rrhh', 'carnet']);

// --- CONFIGURACIÓN ---
$hoy = date('Y-m-d');
$apiKey = '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'; 

// Cargar Diccionarios
try {
    $feriados_db = $pdo->query("SELECT fecha, descripcion FROM feriados")->fetchAll(PDO::FETCH_KEY_PAIR);
    $fallos_db   = $pdo->query("SELECT fecha, motivo FROM dias_sin_sistema")->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) { $feriados_db = []; $fallos_db = []; }

// Variables UI
$resultados_busqueda = []; $empleado = null; $asistencia = []; $termino = ''; 
$avg_entrada = '--'; $avg_salida = '--'; $avg_jornada = '--'; $puntuacion_promedio = 0;
$analisis_ia_texto = '';

// Filtros de Fecha
$filtro_fin = isset($_GET['fecha_fin']) ? $_GET['fecha_fin'] : date('Y-m-d');
$filtro_inicio = isset($_GET['fecha_inicio']) ? $_GET['fecha_inicio'] : date('Y-m-d', strtotime('-30 days'));

// 1. BUSCADOR
if (isset($_GET['q'])) {
    $termino = trim($_GET['q']);
    if (strlen($termino) > 0) {
        $stmt = $pdo->prepare("SELECT * FROM empleados WHERE nombre_completo LIKE :term OR cedula LIKE :term OR id_reloj LIKE :term LIMIT 20");
        $stmt->execute([':term' => "%$termino%"]);
        $resultados_busqueda = $stmt->fetchAll();
    }
}

// 2. PROCESAMIENTO DE HISTORIAL
if (isset($_GET['id'])) {
    $id_reloj = $_GET['id'];
    $stmtEmp = $pdo->prepare("SELECT * FROM empleados WHERE id_reloj = ?");
    $stmtEmp->execute([$id_reloj]);
    $empleado = $stmtEmp->fetch();

    if ($empleado) {
        // Foto
        $cedula_limpia = preg_replace('/[^0-9]/', '', $empleado['cedula']);
        $foto_url = file_exists("fotos/$cedula_limpia.jpg") ? "fotos/$cedula_limpia.jpg?v=".time() : "https://ui-avatars.com/api/?name=".urlencode($empleado['nombre_completo'])."&background=0D8ABC&color=fff&size=200";

        // Obtener Registros
        $stmtAsist = $pdo->prepare("SELECT * FROM asistencia WHERE id_empleado_reloj = ? AND fecha BETWEEN ? AND ? ORDER BY fecha DESC");
        $stmtAsist->execute([$id_reloj, $filtro_inicio, $filtro_fin]);
        $asistencias_raw = $stmtAsist->fetchAll();

        // Mapeo
        $datos_por_fecha = []; foreach ($asistencias_raw as $r) $datos_por_fecha[$r['fecha']] = $r;
        
        $current = strtotime($filtro_fin); $stop = strtotime($filtro_inicio);
        
        // Acumuladores Promedios
        $sum_in = 0; $cnt_in = 0; 
        $sum_out = 0; $cnt_out = 0; 
        $sum_dur = 0; $cnt_dur = 0;
        $sum_score = 0; $cnt_score = 0;

        while ($current >= $stop) {
            $f_string = date('Y-m-d', $current);
            $row = isset($datos_por_fecha[$f_string]) ? $datos_por_fecha[$f_string] : ['fecha'=>$f_string,'hora_entrada'=>null,'hora_salida'=>null];
            
            $ts = strtotime($f_string); $dia_sem = date('w', $ts);
            $es_finde = ($dia_sem == 0 || $dia_sem == 6);
            $es_feriado = isset($feriados_db[$f_string]);
            $es_fallo = isset($fallos_db[$f_string]);
            $es_hoy = ($f_string === $hoy);
            
            $row['row_class'] = "hover:bg-slate-50/50 transition-colors"; $row['meta_info'] = ""; 
            $puntos = 100; $estado = "Presente"; $row['jornada_texto'] = '-';

            if ($es_hoy) { $row['row_class'] = "bg-orange-50/60 border-l-4 border-orange-400"; }

            if ($es_fallo) { 
                $puntos=100; $estado="Fallo Sistema"; $row['row_class']="bg-yellow-50"; $row['meta_info']="FALLO SISTEMA"; 
            } elseif ($es_feriado) { 
                $puntos=100; $estado="Feriado"; $row['row_class']="bg-blue-50"; $row['meta_info']="FERIADO"; 
            } elseif ($es_finde) { 
                $puntos=100; $estado="Finde"; $row['row_class']="bg-slate-50 text-slate-400"; 
            } else {
                if (empty($row['hora_entrada'])) { 
                    $puntos=0; $estado="Ausente"; $row['row_class']="bg-red-50/50"; 
                } elseif (empty($row['hora_salida']) || $row['hora_salida']=='00:00:00') {
                    if($es_hoy) { $puntos=100; $estado="En Curso"; } 
                    else { $puntos=60; $estado="Sin Salida"; }
                } else {
                    $estado="Completo";
                }
                
                if (!$es_hoy) { $cnt_score++; $sum_score += $puntos; }
            }

            if (!$es_hoy && !$es_finde && !$es_feriado && !$es_fallo) {
                if (!empty($row['hora_entrada'])) {
                    $parts = explode(':', $row['hora_entrada']);
                    $sum_in += ($parts[0]*3600) + ($parts[1]*60) + ($parts[2]??0); $cnt_in++;
                    
                    if (!empty($row['hora_salida']) && $row['hora_salida']!='00:00:00') {
                        $t_in = strtotime($row['hora_entrada']); $t_out = strtotime($row['hora_salida']);
                        if($t_out > $t_in) { $sum_dur += ($t_out - $t_in); $cnt_dur++; }
                        $parts_out = explode(':', $row['hora_salida']);
                        $sum_out += ($parts_out[0]*3600) + ($parts_out[1]*60) + ($parts_out[2]??0); $cnt_out++;
                    }
                }
            }

            if (!empty($row['hora_entrada']) && !empty($row['hora_salida']) && $row['hora_salida']!='00:00:00') {
                 $diff = strtotime($row['hora_salida']) - strtotime($row['hora_entrada']);
                 $h = floor($diff / 3600); $m = floor(($diff % 3600) / 60);
                 $row['jornada_texto'] = "{$h}h {$m}m";
            }
            
            $row['estado_texto'] = $estado;
            $asistencia[] = $row;
            $current = strtotime('-1 day', $current);
        }

        if($cnt_score > 0) $puntuacion_promedio = (int)round($sum_score/$cnt_score);
        
        if ($cnt_in > 0) { $avg_entrada = date('g:i A', mktime(0, 0, (int)($sum_in / $cnt_in))); }
        if ($cnt_out > 0) { $avg_salida = date('g:i A', mktime(0, 0, (int)($sum_out / $cnt_out))); }
        if ($cnt_dur > 0) {
            $sec = (int)($sum_dur / $cnt_dur); $h = floor($sec / 3600); $m = floor(($sec % 3600) / 60);
            $avg_jornada = "{$h}h {$m}m";
        }

        if (isset($_POST['analizar_ia_btn'])) {
            $prompt = "Analiza asistencia de {$empleado['nombre_completo']} ({$empleado['cargo']}) entre $filtro_inicio y $filtro_fin. Puntaje: $puntuacion_promedio/100. Promedios: Ent $avg_entrada, Sal $avg_salida. Resumido.";
            $res = callOpenAI($apiKey, $prompt);
            $analisis_ia_texto = $res['success'] ? $res['data'] : "Error IA.";
        }
    }
}

function callOpenAI($key, $prompt) {
    $ch = curl_init("https://api.openai.com/v1/chat/completions");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(["model"=>"gpt-3.5-turbo","messages"=>[["role"=>"user","content"=>$prompt]]]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json", "Authorization: Bearer $key"]);
    $response = curl_exec($ch); curl_close($ch);
    $json = json_decode($response, true);
    return ['success'=>isset($json['choices']), 'data'=>$json['choices'][0]['message']['content']??'Error'];
}

require 'layout_head.php';
?>

<style>
    body { opacity: 0; transition: opacity 0.5s ease; background: #f8fafc; }
    body.ready { opacity: 1; }
    .card-exp { background: white; border-radius: 2.2rem; border: 1px solid #f1f5f9; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.02); overflow: hidden; }
    .section-title { font-size: 0.75rem; font-weight: 950; text-transform: uppercase; letter-spacing: 0.1em; border-left: 4px solid; padding-left: 12px; margin-bottom: 1.5rem; }
    
    /* FIX FOTO REDONDA 1:1 */
    .profile-circle-lg { width: 220px; height: 220px; border-radius: 50%; object-fit: cover; aspect-ratio: 1 / 1; border: 6px solid #fff; box-shadow: 0 15px 35px rgba(0,0,0,0.1); flex-shrink: 0; }
    
    .text-data { color: #1e293b !important; font-weight: 700; }
    
    @media print {
        body { opacity: 1; background: white; margin: 0; }
        nav, .no-print, form, button { display: none !important; }
        .card-exp { border: 1px solid #e2e8f0; box-shadow: none; border-radius: 1rem; overflow: visible; }
        .print-header { display: block !important; position: fixed; top: 0; left: 0; width: 100%; text-align: center; border-bottom: 2px solid #3b82f6; padding: 15px 0; background: white; z-index: 1000; }
        .main-content { margin-top: 100px; }
        .profile-circle-lg { width: 140px; height: 140px; }
        table { page-break-inside: auto; width: 100%; }
        tr { page-break-inside: avoid; page-break-after: auto; }
        thead { display: table-header-group; }
    }
    .print-header { display: none; }
</style>

<!-- ENCABEZADO PARA TODAS LAS PÁGINAS DE IMPRESIÓN -->
<?php if($empleado): ?>
<div class="print-header">
    <div class="flex justify-between items-center px-12">
        <div class="text-left">
            <span class="block text-[10px] font-black text-slate-400 uppercase tracking-widest">Reporte Oficial de Asistencia</span>
            <span class="text-lg font-black text-slate-800 uppercase tracking-tighter"><?php echo htmlspecialchars($empleado['nombre_completo']); ?></span>
        </div>
        <div class="text-right">
            <span class="block text-[10px] font-bold text-slate-400 uppercase"><?php echo date('d/m/Y H:i'); ?></span>
            <span class="text-[9px] font-black text-blue-600 uppercase tracking-widest">ID: <?php echo $empleado['id_reloj']; ?></span>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="container mx-auto px-4 pb-20 max-w-7xl main-content">
    
    <!-- BUSCADOR -->
    <div class="no-print mt-8 mb-8">
        <form action="" method="GET" class="flex flex-col md:flex-row gap-4">
            <div class="relative flex-grow">
                <i class="fas fa-search absolute left-5 top-1/2 -translate-y-1/2 text-slate-400"></i>
                <input type="text" name="q" value="<?php echo htmlspecialchars($termino); ?>" placeholder="Buscar colaborador por nombre, ID o cédula..." class="input-premium pl-12">
            </div>
            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-10 py-3 rounded-2xl font-black text-xs uppercase tracking-widest shadow-xl transition active:scale-95">Buscar</button>
        </form>
    </div>

    <?php if (!empty($resultados_busqueda) && !$empleado): ?>
        <div class="card-exp p-4 mb-8 animate-fade-in">
            <h3 class="section-title border-blue-600 text-blue-900 ml-4 mt-2 uppercase tracking-tighter">Resultados de búsqueda</h3>
            <div class="divide-y divide-slate-50">
                <?php foreach ($resultados_busqueda as $emp_res): ?>
                    <a href="?id=<?php echo $emp_res['id_reloj']; ?>" class="flex items-center justify-between p-5 hover:bg-blue-50/50 transition group rounded-2xl">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 bg-blue-100 rounded-full flex items-center justify-center text-blue-600 font-black"><?php echo substr($emp_res['nombre_completo'], 0, 1); ?></div>
                            <div>
                                <p class="font-black text-slate-800 uppercase"><?php echo htmlspecialchars($emp_res['nombre_completo']); ?></p>
                                <p class="text-[10px] text-slate-400 font-bold">ID RELOJ: <?php echo $emp_res['id_reloj']; ?></p>
                            </div>
                        </div>
                        <i class="fas fa-chevron-right text-slate-200 group-hover:text-blue-600 transition"></i>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($empleado): ?>
        
        <!-- HEADER DE REPORTE -->
        <div class="card-exp p-8 md:p-12 mb-8 relative overflow-hidden">
            <div class="absolute top-0 left-0 w-full h-2 bg-blue-600"></div>
            <div class="flex flex-col md:flex-row items-center md:items-start gap-10">
                
                <!-- Foto y Score Central -->
                <div class="text-center space-y-4">
                    <div class="profile-circle-lg overflow-hidden border-4 border-slate-50 shadow-2xl mx-auto">
                        <img src="<?php echo $foto_url; ?>" class="w-full h-full object-cover">
                    </div>
                    <div class="bg-slate-50 p-4 rounded-3xl border border-slate-100 shadow-inner">
                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Calificación 30D</p>
                        <div class="text-4xl font-black text-blue-700"><?php echo $puntuacion_promedio; ?>%</div>
                        <div class="flex justify-center text-amber-400 text-xs mt-2 gap-1">
                            <?php for($i=1; $i<=5; $i++): ?>
                                <i class="fas fa-star <?php echo $i <= round($puntuacion_promedio/20) ? '' : 'text-slate-200'; ?>"></i>
                            <?php endfor; ?>
                        </div>
                    </div>
                </div>

                <!-- Info y Métricas -->
                <div class="flex-grow space-y-6">
                    <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                        <div>
                            <h1 class="text-4xl font-black text-slate-800 tracking-tighter uppercase leading-none"><?php echo htmlspecialchars($empleado['nombre_completo']); ?></h1>
                            <p class="text-blue-600 font-black text-[11px] uppercase tracking-[0.3em] mt-2"><?php echo htmlspecialchars($empleado['cargo']); ?></p>
                        </div>
                        <div class="flex gap-2 no-print">
                            <button onclick="window.print()" class="bg-slate-100 hover:bg-slate-200 text-slate-600 px-6 py-2.5 rounded-xl font-black text-[10px] uppercase tracking-widest transition active:scale-95">
                                <i class="fas fa-file-pdf mr-2"></i> Generar PDF
                            </button>
                            <button onclick="window.print()" class="bg-slate-900 hover:bg-black text-white px-6 py-2.5 rounded-xl font-black text-[10px] uppercase tracking-widest shadow-lg transition active:scale-95">
                                <i class="fas fa-print mr-2"></i> Imprimir
                            </button>
                        </div>
                    </div>

                    <!-- FILA 1: DATOS BÁSICOS -->
                    <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                        <div class="bg-slate-50/80 p-3 rounded-2xl border border-slate-100">
                            <label class="label-black">ID Reloj</label>
                            <p class="text-data text-sm"><?php echo $empleado['id_reloj']; ?></p>
                        </div>
                        <div class="bg-slate-50/80 p-3 rounded-2xl border border-slate-100">
                            <label class="label-black">Cédula</label>
                            <p class="text-data text-sm"><?php echo $empleado['cedula']; ?></p>
                        </div>
                        <div class="bg-slate-50/80 p-3 rounded-2xl border border-slate-100">
                            <label class="label-black">Depto</label>
                            <p class="text-data text-sm uppercase"><?php echo $empleado['departamento']; ?></p>
                        </div>
                    </div>

                    <!-- FILA 2: HORARIO Y PERIODO (Nueva Línea entre Datos y Promedios) -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="bg-blue-50/50 p-4 rounded-2xl border border-blue-100 flex items-center justify-between">
                            <div>
                                <label class="label-black text-blue-600">Horario Teórico</label>
                                <p class="text-data text-sm font-mono">
                                    <?php echo date('h:i A', strtotime($empleado['horario_entrada'] ?: '09:00')); ?> - 
                                    <?php echo date('h:i A', strtotime($empleado['horario_salida'] ?: '18:00')); ?>
                                </p>
                            </div>
                            <?php if($empleado['horario_verificado']): ?>
                                <div class="flex flex-col items-end">
                                    <span class="text-[8px] font-black text-green-600 uppercase mb-1">Estatus</span>
                                    <span class="bg-green-100 text-green-700 px-3 py-1 rounded-lg text-[9px] font-black uppercase flex items-center gap-1">
                                        <i class="fas fa-check-circle"></i> Verificado
                                    </span>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="bg-slate-50/80 p-4 rounded-2xl border border-slate-100">
                            <label class="label-black">Periodo de Reporte</label>
                            <p class="text-data text-sm">Del <?php echo date('d/m/Y', strtotime($filtro_inicio)); ?> al <?php echo date('d/m/Y', strtotime($filtro_fin)); ?></p>
                        </div>
                    </div>

                    <!-- FILA 3: PROMEDIOS AJUSTADOS -->
                    <div class="grid grid-cols-3 gap-4 pt-2">
                        <div class="bg-blue-600 p-4 rounded-[1.5rem] text-center shadow-lg shadow-blue-100 transition-transform hover:scale-[1.02]">
                            <p class="text-[9px] font-black text-blue-100 uppercase tracking-widest mb-1">Entrada Promedio</p>
                            <p class="text-xl font-black text-white font-mono leading-none"><?php echo $avg_entrada; ?></p>
                        </div>
                        <div class="bg-purple-600 p-4 rounded-[1.5rem] text-center shadow-lg shadow-purple-100 transition-transform hover:scale-[1.02]">
                            <p class="text-[9px] font-black text-purple-100 uppercase tracking-widest mb-1">Salida Promedio</p>
                            <p class="text-xl font-black text-white font-mono leading-none"><?php echo $avg_salida; ?></p>
                        </div>
                        <div class="bg-green-600 p-4 rounded-[1.5rem] text-center shadow-lg shadow-green-100 transition-transform hover:scale-[1.02]">
                            <p class="text-[9px] font-black text-green-100 uppercase tracking-widest mb-1">Jornada Promedio</p>
                            <p class="text-xl font-black text-white font-mono leading-none"><?php echo $avg_jornada; ?></p>
                        </div>
                    </div>
                </div>

                <!-- ANÁLISIS IA -->
                <div class="w-full md:w-80 no-print">
                    <div class="bg-slate-900 rounded-[2.5rem] p-8 text-white shadow-2xl h-full flex flex-col relative overflow-hidden border border-white/5">
                        <i class="fas fa-robot absolute -right-6 -top-6 text-[10rem] opacity-5"></i>
                        <div class="relative z-10 flex flex-col h-full">
                            <div class="flex justify-between items-center mb-6">
                                <h3 class="font-black text-[10px] uppercase tracking-[0.3em] text-blue-400">Asistente de Auditoría</h3>
                                <form method="POST">
                                    <button type="submit" name="analizar_ia_btn" class="bg-blue-600 hover:bg-blue-500 px-4 py-1.5 rounded-full text-[9px] font-black uppercase transition shadow-lg">Analizar</button>
                                </form>
                            </div>
                            <div class="text-xs italic leading-relaxed overflow-y-auto max-h-48 scrollbar-hide text-slate-300 font-medium">
                                <?php echo $analisis_ia_texto ?: 'Presione analizar para obtener una interpretación inteligente del desempeño en este periodo.'; ?>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <!-- TABLA DE DETALLE -->
        <div class="card-exp">
            <div class="p-8 border-b border-slate-50 flex flex-col md:flex-row justify-between items-center gap-6 bg-slate-50/30 no-print">
                <h3 class="section-title theme-navy !mb-0 tracking-tighter">Registros Detallados</h3>
                <form method="GET" class="flex flex-wrap items-center gap-3">
                    <input type="hidden" name="id" value="<?php echo $id_reloj; ?>">
                    <div class="flex items-center gap-2">
                        <input type="date" name="fecha_inicio" value="<?php echo $filtro_inicio; ?>" class="input-premium !py-2 !px-4 text-xs shadow-inner">
                        <span class="text-slate-300 font-bold">al</span>
                        <input type="date" name="fecha_fin" value="<?php echo $filtro_fin; ?>" class="input-premium !py-2 !px-4 text-xs shadow-inner">
                    </div>
                    <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded-xl font-black text-[10px] uppercase tracking-widest shadow-lg transition active:scale-95">Filtrar</button>
                </form>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-[10px] font-black text-slate-400 uppercase tracking-widest border-b">
                        <tr>
                            <th class="px-8 py-5 text-left">Fecha de Marca</th>
                            <th class="px-6 py-5 text-center">Entrada</th>
                            <th class="px-6 py-5 text-center">Salida</th>
                            <th class="px-6 py-5 text-center bg-green-50/50 text-green-700">Duración</th>
                            <th class="px-8 py-5 text-center">Estatus</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        <?php foreach ($asistencia as $row): ?>
                            <tr class="<?php echo $row['row_class']; ?>">
                                <td class="px-8 py-4">
                                    <p class="font-black text-slate-700"><?php echo date('d/m/Y', strtotime($row['fecha'])); ?></p>
                                    <?php if($row['meta_info']): ?>
                                        <span class="text-[8px] font-black uppercase text-blue-500 bg-blue-50 px-2 py-0.5 rounded-full mt-1 inline-block"><?php echo $row['meta_info']; ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 text-center font-mono font-bold text-blue-700">
                                    <?php echo $row['hora_entrada'] ? date('h:i A', strtotime($row['hora_entrada'])) : '--:--'; ?>
                                </td>
                                <td class="px-6 py-4 text-center font-mono font-bold text-purple-700">
                                    <?php 
                                        if ($row['fecha'] == $hoy) {
                                            echo '<span class="text-orange-500 text-[10px] font-black uppercase animate-pulse">En curso</span>';
                                        } else {
                                            echo ($row['hora_salida'] && $row['hora_salida'] != '00:00:00') ? date('h:i A', strtotime($row['hora_salida'])) : '--:--';
                                        }
                                    ?>
                                </td>
                                <td class="px-6 py-4 text-center font-black text-slate-500 bg-green-50/20">
                                    <?php echo $row['jornada_texto']; ?>
                                </td>
                                <td class="px-8 py-4 text-center">
                                    <?php 
                                        $b_col = 'bg-slate-100 text-slate-500';
                                        if($row['estado_texto']=='Ausente' || $row['estado_texto']=='Sin Salida') $b_col = 'bg-red-100 text-red-600';
                                        elseif($row['estado_texto']=='Feriado') $b_col = 'bg-blue-100 text-blue-600';
                                        elseif($row['estado_texto']=='Finde') $b_col = 'bg-purple-100 text-purple-600';
                                        elseif($row['estado_texto']=='En Curso') $b_col = 'bg-orange-100 text-orange-600';
                                        elseif($row['estado_texto']=='Completo' || $row['estado_texto']=='Presente') $b_col = 'bg-green-100 text-green-600';
                                    ?>
                                    <span class="px-3 py-1 rounded-lg text-[9px] font-black uppercase tracking-tighter <?php echo $b_col; ?>">
                                        <?php echo $row['estado_texto']; ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require 'layout_footer.php'; ?>