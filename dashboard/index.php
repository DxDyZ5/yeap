<?php
// index.php - DASHBOARD COMMAND CENTER: MONITOR DE ASISTENCIA Y SALIDAS CON CALIFICACIÓN CENTRALIZADA
require_once __DIR__ . '/../../core/auth.php'; 
require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../core/resources.php'; 

verificarAcceso('ver');

$hoy = date('Y-m-d');
$ayer = date('Y-m-d', strtotime('-1 day'));

// Definición de lectores que determinan la SALIDA física
$puntos_salida = [
    'Torniquete 2-2', 
    'Torniquete 1-2', 
    'Torniquete 3-2'
];

// =================================================================================
// 0. INFORMACIÓN DE ÚLTIMA ACTUALIZACIÓN
// =================================================================================
$ultimo_evento_db = "Sin registros";
try {
    $rowLast = $pdo->query("SELECT fecha, hora_salida FROM asistencia ORDER BY fecha DESC, hora_salida DESC LIMIT 1")->fetch();
    if ($rowLast) {
        $ultimo_evento_db = date('d/m/Y', strtotime($rowLast['fecha'])) . " a las " . date('h:i:s A', strtotime($rowLast['hora_salida']));
    }
} catch (Exception $e) {}

// =================================================================================
// 1. DATA PARA KPIs Y MÓDULOS
// =================================================================================
$stats_query = $pdo->query("
    SELECT 
        COUNT(CASE WHEN a.hora_entrada IS NOT NULL THEN 1 END) as presentes,
        COUNT(CASE WHEN e.horario_verificado = 1 AND a.hora_entrada > ADDTIME(e.horario_entrada, '00:30:00') THEN 1 END) as tardanzas
    FROM empleados e
    LEFT JOIN asistencia a ON e.id_reloj = a.id_empleado_reloj AND a.fecha = '$hoy'
    WHERE e.estatus_nomina = 'En Nomina'
");
$stats = $stats_query->fetch();
$total_activos = $pdo->query("SELECT COUNT(*) FROM empleados WHERE estatus_nomina = 'En Nomina'")->fetchColumn();

// A) Cumpleaños del día
$cumpleanos = $pdo->query("SELECT id_reloj, nombre_completo, cargo, cedula, fecha_nacimiento 
    FROM empleados WHERE MONTH(fecha_nacimiento) = MONTH('$hoy') AND DAY(fecha_nacimiento) = DAY('$hoy') AND estatus_nomina = 'En Nomina'")->fetchAll();

// B) Tardanzas Verificadas Hoy (> 30 min)
$tardanzas_verificadas = $pdo->query("
    SELECT e.id_reloj, e.nombre_completo, e.cargo, e.cedula, a.hora_entrada, e.horario_entrada
    FROM empleados e 
    JOIN asistencia a ON e.id_reloj = a.id_empleado_reloj AND a.fecha = '$hoy'
    WHERE e.horario_verificado = 1 
    AND a.hora_entrada > ADDTIME(e.horario_entrada, '00:30:00')
    AND e.estatus_nomina = 'En Nomina'
")->fetchAll();

// =================================================================================
// 2. MONITORES (ASISTENCIA HOY Y SALIDAS)
// =================================================================================

// MONITOR DE ASISTENCIA: Eventos de hoy
$sqlAsis = "SELECT e.nombre_completo, e.cedula, e.cargo, a.hora_entrada, a.hora_salida, a.fecha, e.id_reloj, a.ultimo_punto, a.total_eventos
          FROM asistencia a JOIN empleados e ON a.id_empleado_reloj = e.id_reloj
          WHERE a.fecha = :hoy ORDER BY GREATEST(IFNULL(a.hora_entrada, '00:00:00'), IFNULL(a.hora_salida, '00:00:00')) DESC LIMIT 15";
$stmtAsis = $pdo->prepare($sqlAsis);
$stmtAsis->execute([':hoy' => $hoy]);
$asistencia_hoy = $stmtAsis->fetchAll();

// MONITOR DE SALIDA
$sqlSalidas = "SELECT e.nombre_completo, e.cedula, e.cargo, a.hora_entrada, a.hora_salida, a.fecha, e.id_reloj, a.ultimo_punto
               FROM asistencia a JOIN empleados e ON a.id_empleado_reloj = e.id_reloj
               WHERE a.fecha = :hoy AND a.ultimo_punto IN ('" . implode("','", $puntos_salida) . "')
               ORDER BY a.hora_salida DESC";
$stmtSal = $pdo->prepare($sqlSalidas);
$stmtSal->execute([':hoy' => $hoy]);
$personal_fuera = $stmtSal->fetchAll();

require_once __DIR__ . '/../../core/resources.php';
require __DIR__ . '/../../templates/layout_head.php'; 
?>

<style>
    body { opacity: 0; transition: opacity 0.4s ease-in-out; }
    body.ready { opacity: 1; }
    .img-placeholder { background: #f8fafc; border-radius: 50%; }
</style>

<div class="container mx-auto pb-10 px-4 mt-6">
    
    <!-- Panel de Estado -->
    <div class="mb-8 flex flex-col md:flex-row justify-between items-center gap-4 bg-slate-900 p-5 rounded-3xl text-white shadow-xl border border-slate-800">
        <div class="flex items-center gap-4">
            <div class="w-12 h-12 bg-blue-600 rounded-full flex items-center justify-center animate-pulse">
                <i class="fas fa-sync-alt text-lg"></i>
            </div>
            <div>
                <p class="text-[10px] font-black text-blue-400 uppercase tracking-widest">Monitor en Tiempo Real</p>
                <p class="text-sm font-bold">Base de datos sincronizada: <span class="text-blue-100"><?php echo $ultimo_evento_db; ?></span></p>
            </div>
        </div>
        <div class="bg-white/5 px-4 py-2 rounded-2xl border border-white/5">
            <span class="text-[10px] font-black uppercase text-green-400">Sistema en Línea</span>
        </div>
    </div>

    <!-- KPIs -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-10">
        <div class="bg-white p-5 rounded-2xl shadow-sm border-b-4 border-green-500 transition hover:shadow-lg">
            <div class="flex justify-between items-start">
                <div><p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Presentes Hoy</p><h3 class="text-3xl font-black text-slate-700"><?php echo $stats['presentes']; ?></h3></div>
                <div class="bg-green-50 p-2 rounded-lg text-green-600"><i class="fas fa-user-check"></i></div>
            </div>
        </div>
        <div class="bg-white p-5 rounded-2xl shadow-sm border-b-4 border-red-500 transition hover:shadow-lg">
            <div class="flex justify-between items-start">
                <div><p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Ausentes</p><h3 class="text-3xl font-black text-slate-700"><?php echo $total_activos - $stats['presentes']; ?></h3></div>
                <div class="bg-red-50 p-2 rounded-lg text-red-600"><i class="fas fa-user-times"></i></div>
            </div>
        </div>
        <div class="bg-white p-5 rounded-2xl shadow-sm border-b-4 border-orange-500 transition hover:shadow-lg">
            <div class="flex justify-between items-start">
                <div><p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Tardanzas (+30m)</p><h3 class="text-3xl font-black text-slate-700"><?php echo $stats['tardanzas']; ?></h3></div>
                <div class="bg-orange-100 p-2 rounded-lg text-orange-600"><i class="fas fa-user-clock"></i></div>
            </div>
        </div>
        <div class="bg-white p-5 rounded-2xl shadow-sm border-b-4 border-blue-500 transition hover:shadow-lg">
            <div class="flex justify-between items-start">
                <div><p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Plantilla Activa</p><h3 class="text-3xl font-black text-slate-700"><?php echo $total_activos; ?></h3></div>
                <div class="bg-blue-50 p-2 rounded-lg text-blue-600"><i class="fas fa-users"></i></div>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
        
        <!-- COLUMNA IZQUIERDA: MONITOR DE ASISTENCIA -->
        <div class="lg:col-span-8 space-y-8">
            
            <div class="bg-white rounded-[2rem] shadow-xl border border-gray-100 overflow-hidden">
                <div class="px-8 py-6 border-b border-gray-50 bg-gray-50/50 flex justify-between items-center">
                    <h2 class="font-black text-slate-700 uppercase tracking-tighter flex items-center gap-2">
                        <i class="fas fa-user-clock text-blue-500"></i> Monitor de Asistencia (Hoy)
                    </h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-gray-400 font-bold text-[10px] uppercase tracking-widest border-b">
                            <tr>
                                <th class="px-8 py-4 text-left">Empleado / Cargo</th>
                                <th class="px-6 py-4 text-center">Evento</th>
                                <th class="px-6 py-4 text-center">Score Global</th>
                                <th class="px-6 py-4 text-center">Tiempo de Salida</th>
                                <th class="px-8 py-4 text-right">Hora de Asistencia</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            <?php foreach($asistencia_hoy as $ev): 
                                // LLAMADA AL MOTOR CENTRALIZADO
                                $scoreData = obtenerScoreGlobal($ev['id_reloj'], $pdo);

                                $foto = foto_path($ev['cedula'] ?? '');
                                
                                // LÓGICA DE IDENTIFICACIÓN DE EVENTOS
                                $es_punto_salida = in_array($ev['ultimo_punto'] ?? '', $puntos_salida);
                                
                                // Determinar label
                                if ($es_punto_salida) {
                                    $label = 'SALIDA'; $color = 'bg-red-100 text-red-700';
                                } elseif ($ev['total_eventos'] <= 1) {
                                    $label = 'ENTRADA'; $color = 'bg-green-100 text-green-700';
                                } else {
                                    $label = 'RETORNO'; $color = 'bg-blue-100 text-blue-700';
                                }

                                // Cálculo de duración fuera
                                $duracion = htmlspecialchars($ev['ultimo_punto'] ?? 'S/D');
                                if ($label === 'RETORNO' && !empty($ev['hora_entrada']) && !empty($ev['hora_salida'])) {
                                    $t_retorno = strtotime($ev['hora_salida']);
                                    $t_salida_last = strtotime($ev['hora_entrada']); 
                                    $diff = abs($t_retorno - $t_salida_last);
                                    $duracion = '<span class="text-blue-600 font-black"><i class="fas fa-history mr-1"></i>Fuera: ' . floor($diff/3600) . "h " . floor(($diff%3600)/60) . "m</span>";
                                } elseif ($label === 'SALIDA') {
                                    $duracion = '<span class="text-red-500 font-bold italic">En Exterior</span>';
                                }
                                
                                $borde = (!$es_punto_salida && !empty($ev['hora_entrada'])) ? 'border-green-500 ring-4 ring-green-100' : 'border-red-500 ring-4 ring-red-100';
                                $dot = (!$es_punto_salida) ? 'bg-green-500' : 'bg-red-500';
                                $icon = (!$es_punto_salida) ? 'fa-check' : 'fa-times';
                            ?>
                            <tr class="hover:bg-blue-50/40 transition group">
                                <td class="px-8 py-5">
                                    <div class="flex items-center gap-4">
                                        <div class="relative flex-shrink-0 img-placeholder w-16 h-16">
                                            <img src="<?php echo $foto; ?>" loading="lazy" class="w-16 h-16 rounded-full object-cover border-2 shadow-sm <?php echo $borde; ?>">
                                            <div class="absolute bottom-0 right-0 w-4 h-4 rounded-full border-2 border-white <?php echo $dot; ?> shadow-sm flex items-center justify-center">
                                                <i class="fas <?php echo $icon; ?> text-white text-[8px]"></i>
                                            </div>
                                        </div>
                                        <div>
                                            <a href="../usuarios/editar_empleado.php?id=<?php echo $ev['id_reloj']; ?>" class="font-black text-slate-800 leading-tight hover:text-blue-600 transition text-base uppercase"><?php echo htmlspecialchars($ev['nombre_completo']); ?></a>
                                            <div class="text-[10px] text-gray-400 uppercase font-bold"><?php echo htmlspecialchars($ev['cargo']); ?></div>
                                            <div class="text-[9px] text-blue-400 font-mono">ID: <?php echo $ev['id_reloj']; ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-5 text-center"><span class="<?php echo $color; ?> text-[10px] font-black px-3 py-1 rounded-md border border-black/5 shadow-sm"><?php echo $label; ?></span></td>
                                <td class="px-6 py-5 text-center">
                                    <div class="flex flex-col items-center">
                                        <span class="font-black text-sm <?php echo $scoreData['val'] < 70 ? 'text-red-500':'text-blue-700';?>"><?php echo $scoreData['val']; ?>%</span>
                                        <div class="flex gap-0.5 text-[7px] text-amber-400">
                                            <?php for($i=1;$i<=5;$i++) echo '<i class="fas fa-star '.($i<=$scoreData['stars']?'':'text-slate-200').'"></i>'; ?>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-5 text-center"><div class="text-slate-600 text-[11px] font-bold uppercase"><?php echo $duracion; ?></div></td>
                                <td class="px-8 py-5 text-right font-mono font-black text-slate-700 text-lg"><?php echo $ev['hora_entrada'] ? date('h:i A', strtotime($ev['hora_entrada'])) : '--:--'; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- MONITOR DE SALIDA -->
            <div class="bg-white rounded-[2rem] shadow-xl border border-gray-100 overflow-hidden">
                <div class="px-8 py-6 border-b border-red-50 bg-red-50/20 flex justify-between items-center">
                    <h2 class="font-black text-slate-700 uppercase tracking-tighter flex items-center gap-2">
                        <i class="fas fa-door-open text-red-500"></i> Monitor de Salida
                    </h2>
                    <span class="text-[10px] font-black bg-red-600 text-white px-3 py-1 rounded-full uppercase"><?php echo count($personal_fuera); ?> Fuera Ahora</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-gray-400 font-bold text-[10px] uppercase tracking-widest border-b">
                            <tr>
                                <th class="px-8 py-4 text-left">Empleado</th>
                                <th class="px-6 py-4 text-center">Punto de Salida</th>
                                <th class="px-8 py-4 text-right">Hora Salida</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            <?php if(empty($personal_fuera)): ?>
                                <tr><td colspan="3" class="px-8 py-10 text-center text-gray-400 italic font-medium">No hay personal fuera en este momento.</td></tr>
                            <?php else: foreach($personal_fuera as $sal): 
                                $foto = foto_path($sal['cedula'] ?? '');
                            ?>
                            <tr class="hover:bg-red-50/30 transition group">
                                <td class="px-8 py-4">
                                    <div class="flex items-center gap-4">
                                        <img src="<?php echo $foto; ?>" loading="lazy" class="w-12 h-12 rounded-full object-cover border-2 border-red-400 ring-4 ring-red-50 shadow-sm">
                                        <div><p class="font-black text-slate-800 text-sm leading-tight uppercase"><?php echo htmlspecialchars($sal['nombre_completo']); ?></p><p class="text-[9px] text-gray-400 font-bold uppercase"><?php echo htmlspecialchars($sal['cargo']); ?></p></div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-center"><span class="text-red-600 text-[10px] font-black bg-red-50 px-3 py-1 rounded-full border border-red-100 uppercase tracking-tighter"><?php echo htmlspecialchars($sal['ultimo_punto']); ?></span></td>
                                <td class="px-8 py-4 text-right font-mono font-black text-slate-700 text-base"><?php echo date('h:i:s A', strtotime($sal['hora_salida'])); ?></td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

        <!-- COLUMNA DERECHA -->
        <div class="lg:col-span-4 space-y-6">
            <!-- Cumpleaños -->
            <div class="bg-white rounded-3xl shadow-xl border border-gray-100 overflow-hidden">
                <div class="px-6 py-4 border-b border-pink-50 bg-pink-50/30 flex justify-between items-center">
                    <h2 class="font-black text-pink-700 text-xs uppercase tracking-widest flex items-center gap-2"><i class="fas fa-cake-candles"></i> Cumpleaños Hoy</h2>
                    <span class="bg-pink-100 text-pink-700 text-[10px] font-black px-2 py-0.5 rounded-full"><?php echo count($cumpleanos); ?></span>
                </div>
                <div class="p-5 max-h-64 overflow-y-auto space-y-4">
                    <?php if(empty($cumpleanos)): ?><p class="text-gray-400 text-xs text-center py-4 italic font-medium">No hay festejados hoy.</p><?php endif; ?>
                    <?php foreach($cumpleanos as $c): ?>
                    <div class="flex items-center gap-3"><div class="w-10 h-10 rounded-full bg-pink-100 text-pink-600 flex items-center justify-center font-black text-xs shadow-sm">🎂</div><div><p class="text-sm font-black text-slate-800 leading-none uppercase"><?php echo $c['nombre_completo']; ?></p><p class="text-[9px] text-gray-400 uppercase font-bold mt-1"><?php echo $c['cargo']; ?></p></div></div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Tardanzas -->
            <div class="bg-white rounded-3xl shadow-xl border border-gray-100 overflow-hidden border-l-8 border-l-orange-500">
                <div class="px-6 py-4 border-b border-orange-50 bg-orange-50/30 flex justify-between items-center">
                    <h2 class="font-black text-orange-700 text-xs uppercase tracking-widest flex items-center gap-2"><i class="fas fa-user-clock"></i> Tardanzas (+30m)</h2>
                </div>
                <div class="p-5 max-h-64 overflow-y-auto space-y-3">
                    <?php if(empty($tardanzas_verificadas)): ?><p class="text-gray-400 text-xs text-center py-4 italic font-medium">Sin incidencias hoy.</p><?php endif; ?>
                    <?php foreach($tardanzas_verificadas as $t): ?>
                    <div class="flex items-center gap-3 p-3 bg-orange-50/50 rounded-2xl border border-orange-100">
                        <div class="w-2.5 h-2.5 rounded-full bg-orange-500 animate-pulse"></div>
                        <div><p class="text-sm font-black text-slate-800 leading-none uppercase"><?php echo $t['nombre_completo']; ?></p><p class="text-[10px] text-orange-600 font-black uppercase mt-1">Llegó: <?php echo date('h:i A', strtotime($t['hora_entrada'])); ?></p></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Accesos rápidos -->
            <div class="grid grid-cols-2 gap-3">
                <a href="../usuarios/index.php" class="bg-blue-600 p-6 rounded-[2rem] text-center text-white hover:bg-blue-700 transition shadow-xl group"><i class="fas fa-users block mb-2 text-2xl group-hover:scale-110 transition"></i><span class="text-[10px] font-black uppercase tracking-widest">Personal</span></a>
                <a href="../usuarios/usuarios_sistema.php" class="bg-slate-800 p-6 rounded-[2rem] text-center text-white hover:bg-black transition shadow-xl group"><i class="fas fa-user-shield block mb-2 text-2xl group-hover:scale-110 transition"></i><span class="text-[10px] font-black uppercase tracking-widest">Accesos</span></a>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener("DOMContentLoaded", function() {
        setTimeout(function() { document.body.classList.add('ready'); }, 120);
    });
</script>

<?php require __DIR__ . '/../../templates/layout_footer.php'; ?>
