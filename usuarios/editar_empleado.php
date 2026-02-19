<?php
// editar_empleado.php - GESTIÓN INTEGRAL PREMIUM: ESTRELLAS, PROMEDIOS Y SCORE UNIFICADO
require_once __DIR__ . '/../core/auth.php'; 
require_once __DIR__ . '/../core/db.php'; 

// Seguridad: Verifica permiso de visualización
verificarAcceso('ver');

$mensaje = '';
$tipo_msg = '';
$es_admin = ($_SESSION['rol'] === 'admin');

$id_reloj = $_GET['id'] ?? null;
if (!$id_reloj) { 
    redirect_to_module('personal', 'perfil_empleado.php'); 
    exit; 
}

// --- AUTO-SETUP: TABLAS Y CATEGORÍAS ---
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS historial_vacaciones (id INT AUTO_INCREMENT PRIMARY KEY, id_empleado_reloj INT, fecha_inicio DATE, fecha_fin DATE, creado_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS historial_licencias (id INT AUTO_INCREMENT PRIMARY KEY, id_empleado_reloj INT, fecha_inicio DATE, fecha_fin DATE, descripcion VARCHAR(255), creado_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS documentos_empleado (id INT AUTO_INCREMENT PRIMARY KEY, id_empleado_reloj INT, titulo VARCHAR(100), nombre_archivo VARCHAR(255), tipo_archivo VARCHAR(50), categoria ENUM('General', 'Vacaciones', 'Licencia') DEFAULT 'General', creado_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS solicitudes_carnet (id INT AUTO_INCREMENT PRIMARY KEY, id_empleado_reloj INT, solicitado_por VARCHAR(50), fecha_solicitud TIMESTAMP DEFAULT CURRENT_TIMESTAMP, estado ENUM('Pendiente', 'Procesado') DEFAULT 'Pendiente')");
} catch (Exception $e) {}

// --- LÓGICA DE CÁLCULO UNIFICADA (Igual a historial.php) ---

function obtenerScoreUnificado($id, $pdo) {
    $fecha_fin = date('Y-m-d');
    $fecha_ini = date('Y-m-d', strtotime('-30 days'));
    
    // Obtener feriados para no penalizar
    $feriados = [];
    try {
        $res = $pdo->query("SELECT fecha FROM feriados")->fetchAll(PDO::FETCH_COLUMN);
        $feriados = $res ?: [];
    } catch(Exception $e) {}

    // Obtener registros
    $stmt = $pdo->prepare("SELECT fecha, hora_entrada, hora_salida FROM asistencia WHERE id_empleado_reloj = ? AND fecha BETWEEN ? AND ?");
    $stmt->execute([$id, $fecha_ini, $fecha_fin]);
    $asis = [];
    while($r = $stmt->fetch()) { $asis[$r['fecha']] = $r; }

    $suma_puntos = 0;
    $dias_validos = 0;
    $current = strtotime($fecha_ini);
    $end = strtotime($fecha_fin);

    while($current <= $end) {
        $f = date('Y-m-d', $current);
        $day_w = date('w', $current);
        
        // No contar domingos (0) ni sábados (6) ni feriados
        if($day_w != 0 && $day_w != 6 && !in_array($f, $feriados)) {
            $puntos = 0;
            if(isset($asis[$f])) {
                $h_in = $asis[$f]['hora_entrada'];
                $h_out = $asis[$f]['hora_salida'];
                
                if(!empty($h_in) && $h_in != '00:00:00') {
                    if(!empty($h_out) && $h_out != '00:00:00') {
                        $puntos = 100; // Día completo
                    } else {
                        $puntos = 60; // Solo entrada
                    }
                }
            }
            $suma_puntos += $puntos;
            $dias_validos++;
        }
        $current = strtotime('+1 day', $current);
    }

    $score = ($dias_validos > 0) ? round($suma_puntos / $dias_validos) : 100;
    return ['val' => $score, 'stars' => round($score / 20)];
}

function calcularAntiguedad($fecha) {
    if (!$fecha) return "S/D";
    $inicio = new DateTime($fecha);
    $diff = $inicio->diff(new DateTime());
    if ($diff->y > 0) return $diff->y . " años y " . $diff->m . " meses";
    return $diff->m . " meses";
}

function obtenerPromedios($id, $pdo) {
    $stmt = $pdo->prepare("SELECT hora_entrada, hora_salida FROM asistencia WHERE id_empleado_reloj = ? AND hora_entrada != '00:00:00' AND hora_salida != '00:00:00' AND fecha >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $stmt->execute([$id]);
    $rows = $stmt->fetchAll();
    if (!$rows) return ['entrada' => '--:--', 'salida' => '--:--', 'jornada' => '--:--'];
    
    $sum_in = 0; $sum_out = 0; $sum_dur = 0; $count = count($rows);
    foreach($rows as $r) {
        $in = strtotime($r['hora_entrada']);
        $out = strtotime($r['hora_salida']);
        $sum_in += $in; $sum_out += $out;
        $sum_dur += ($out - $in);
    }
    
    $avg_in_ts = (int)($sum_in / $count);
    $avg_out_ts = (int)($sum_out / $count);
    $avg_dur_total = (int)($sum_dur / $count);

    return [
        'entrada' => date('h:i A', $avg_in_ts),
        'salida' => date('h:i A', $avg_out_ts),
        'jornada' => floor($avg_dur_total / 3600) . "h " . round(($avg_dur_total % 3600) / 60) . "m"
    ];
}

// --- ACCIONES POST ---
if (isset($_POST['solicitar_carnet'])) {
    $pdo->prepare("INSERT INTO solicitudes_carnet (id_empleado_reloj, solicitado_por) VALUES (?,?)")->execute([$id_reloj, $_SESSION['usuario']]);
    $mensaje = "✅ Solicitud de carnet enviada."; $tipo_msg = "success";
}

if (isset($_POST['borrar_doc_id'])) { $pdo->prepare("DELETE FROM documentos_empleado WHERE id = ?")->execute([$_POST['borrar_doc_id']]); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_empleado'])) {
    try {
        $nuevo_id = $_POST['id_reloj_val'];
        $ced_clean = preg_replace('/[^0-9]/', '', $_POST['cedula']);
        if ($nuevo_id != $id_reloj && $es_admin) {
            $pdo->prepare("UPDATE empleados SET id_reloj = ? WHERE id_reloj = ?")->execute([$nuevo_id, $id_reloj]);
            $id_reloj = $nuevo_id;
        }
        if(!empty($_FILES['foto_perfil']['name'])) move_uploaded_file($_FILES['foto_perfil']['tmp_name'], "fotos/$ced_clean.jpg");
        
        $h_verif = isset($_POST['horario_verificado']) ? 1 : 0;

        $sql = "UPDATE empleados SET nombre_completo=?, cedula=?, telefono=?, departamento=?, cargo=?, empresa=?, fecha_ingreso=?, tipo_personal=?, horario_entrada=?, horario_salida=?, tarjeta=?, estatus_nomina=?, horario_verificado=? WHERE id_reloj=?";
        $pdo->prepare($sql)->execute([
            strtoupper($_POST['nombre']), $ced_clean, $_POST['telefono'], strtoupper($_POST['departamento']), 
            strtoupper($_POST['cargo']), strtoupper($_POST['empresa']), $_POST['fecha_ingreso'], $_POST['tipo_personal'], 
            $_POST['horario_entrada'], $_POST['horario_salida'], $_POST['tarjeta'], $_POST['estatus_nomina'], $h_verif, $id_reloj
        ]);
        $mensaje = "✅ Expediente actualizado correctamente."; $tipo_msg = "success";
    } catch (Exception $e) { $mensaje = "❌ Error: " . $e->getMessage(); $tipo_msg = "error"; }
}

if (isset($_POST['eliminar_perfil']) && $es_admin) {
    $pdo->prepare("DELETE FROM empleados WHERE id_reloj = ?")->execute([$id_reloj]);
    redirect_to_module('personal', 'perfil_empleado.php', ['msg' => 'deleted']);
    exit;
}

// --- CARGA DE DATOS ---
$emp = $pdo->prepare("SELECT e.*, a.hora_entrada as hoy_p FROM empleados e LEFT JOIN asistencia a ON e.id_reloj = a.id_empleado_reloj AND a.fecha = CURDATE() WHERE id_reloj = ?");
$emp->execute([$id_reloj]); $emp = $emp->fetch();
if (!$emp) die("Empleado no encontrado");

$eval = obtenerScoreUnificado($id_reloj, $pdo);
$promedios = obtenerPromedios($id_reloj, $pdo);
$ced_l = preg_replace('/[^0-9]/', '', $emp['cedula']);
$foto_url = foto_path($emp['cedula']);

$carnet_path = "carnets_generados/" . $ced_l . "-front.jpg";
$carnet_existe = file_exists($carnet_path);
$fecha_carnet = $carnet_existe ? date("d/m/Y h:i A", filemtime($carnet_path)) : null;

$list_v = $pdo->prepare("SELECT * FROM historial_vacaciones WHERE id_empleado_reloj = ? ORDER BY fecha_inicio DESC"); $list_v->execute([$id_reloj]); $list_v = $list_v->fetchAll();
$list_l = $pdo->prepare("SELECT * FROM historial_licencias WHERE id_empleado_reloj = ? ORDER BY fecha_inicio DESC"); $list_l->execute([$id_reloj]); $list_l = $list_l->fetchAll();
$list_d = $pdo->prepare("SELECT * FROM documentos_empleado WHERE id_empleado_reloj = ? ORDER BY creado_at DESC"); $list_d->execute([$id_reloj]); $list_d = $list_d->fetchAll();

$token_qr = md5($id_reloj . "master_v10"); 
$url_base_qr = (isset($_SERVER['HTTPS']) ? "https" : "http") . "://{$_SERVER['HTTP_HOST']}" . dirname($_SERVER['PHP_SELF']) . "/subir_foto_movil.php?id=$id_reloj&token=$token_qr";

require 'layout_head.php';
?>

<style>
    body { opacity: 0; transition: opacity 0.5s; background: #f8fafc; }
    body.ready { opacity: 1; }
    .label-black { color: #000 !important; font-weight: 950 !important; font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.05em; display: block; margin-bottom: 0.3rem; }
    .input-premium { background: #fff; border: 1px solid #e2e8f0; color: #1e293b !important; font-weight: 700; padding: 0.75rem 1rem; border-radius: 0.9rem; width: 100%; font-size: 0.85rem; }
    .input-premium:focus { border-color: #3b82f6; outline: none; box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1); }
    .card-exp { background: white; border-radius: 2.2rem; border: 1px solid #f1f5f9; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.02); overflow: hidden; }
    .section-title { font-size: 0.75rem; font-weight: 950; text-transform: uppercase; letter-spacing: 0.1em; border-left: 4px solid; padding-left: 12px; margin-bottom: 1.5rem; }
    
    .profile-circle { width: 240px; height: 240px; border-radius: 50%; object-fit: cover; border: 8px solid #fff; box-shadow: 0 15px 35px rgba(0,0,0,0.1); transition: transform 0.3s; }
    .theme-blue { border-color: #3b82f6; color: #1e40af; }
    .theme-red { border-color: #ef4444; color: #991b1b; }
    .theme-emerald { border-color: #10b981; color: #064e3b; }
    .theme-navy { border-color: #1e293b; color: #0f172a; }

    /* Switch Estilo iOS */
    .switch { position: relative; display: inline-block; width: 44px; height: 24px; }
    .switch input { opacity: 0; width: 0; height: 0; }
    .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #e2e8f0; transition: .4s; border-radius: 34px; }
    .slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px; background-color: white; transition: .4s; border-radius: 50%; }
    input:checked + .slider { background-color: #3b82f6; }
    input:checked + .slider:before { transform: translateX(20px); }
</style>

<!-- Modal QR -->
<div id="modal-qr" class="fixed inset-0 bg-slate-900/70 backdrop-blur-md z-[200] hidden flex items-center justify-center p-4">
    <div class="bg-white w-full max-w-sm rounded-[2.5rem] shadow-2xl p-8 text-center animate-fade-in relative">
        <button onclick="document.getElementById('modal-qr').classList.add('hidden')" class="absolute top-6 right-6 text-gray-400 text-2xl">&times;</button>
        <div class="w-16 h-16 bg-blue-50 text-blue-600 rounded-2xl flex items-center justify-center mx-auto mb-4"><i class="fas fa-qrcode text-2xl"></i></div>
        <h3 id="qr-title" class="text-xl font-black text-slate-800 mb-1">Carga Móvil</h3>
        <p id="qr-desc" class="text-[10px] text-gray-400 font-bold uppercase tracking-widest mb-6">Escanea con tu celular</p>
        <div class="bg-slate-50 p-4 rounded-3xl mb-6 border border-slate-100"><img id="qr-image" class="mx-auto w-48 h-48 mix-blend-multiply"></div>
        <button onclick="location.reload()" class="w-full bg-slate-900 text-white py-3 rounded-xl font-black text-[10px] uppercase transition">Finalizar</button>
    </div>
</div>

<div class="container mx-auto px-4 pb-20 max-w-7xl">
    
    <!-- HEADER INTEGRADO CON ACCIONES UNIFICADAS -->
    <div class="flex flex-col md:flex-row justify-between items-center mb-10 mt-8 gap-6">
        <div class="flex items-center gap-5">
            <div class="w-16 h-16 bg-blue-600 rounded-3xl flex items-center justify-center text-white shadow-xl shadow-blue-200"><i class="fas fa-user-check text-2xl"></i></div>
            <div>
                <h1 class="text-3xl font-black text-slate-800 tracking-tighter">Expediente Maestro</h1>
                <p class="text-[10px] text-gray-400 font-black uppercase tracking-widest mt-1">Control Administrativo de Personal</p>
            </div>
        </div>

        <div class="flex flex-wrap items-center justify-center gap-3">
            <?php if($es_admin): ?>
                <form method="POST" onsubmit="return confirm('¿Borrar expediente permanentemente?')">
                    <button type="submit" name="eliminar_perfil" class="bg-red-50 text-red-600 border border-red-100 px-6 py-2.5 rounded-xl font-black text-[10px] uppercase hover:bg-red-600 hover:text-white transition shadow-sm active:scale-95">Eliminar Perfil</button>
                </form>
            <?php endif; ?>
            <a href="perfil_empleado.php" class="bg-white border border-slate-200 text-slate-500 px-6 py-2.5 rounded-xl font-black text-[10px] uppercase hover:bg-slate-50 transition shadow-sm active:scale-95">Volver al Listado</a>
            <button type="submit" name="actualizar_empleado" form="formMain" class="bg-blue-600 hover:bg-blue-700 text-white px-8 py-2.5 rounded-xl font-black text-[10px] uppercase shadow-xl transition active:scale-95 transform">Guardar Cambios</button>
        </div>
    </div>

    <?php if($mensaje): ?><div class="mb-8 p-5 rounded-2xl border <?php echo $tipo_msg=='success'?'bg-green-50 border-green-200 text-green-700':'bg-red-50 border-red-200 text-red-700';?> font-bold animate-fade-in flex items-center gap-3"><i class="fas <?php echo $tipo_msg=='success'?'fa-check-circle':'fa-exclamation-triangle';?> text-xl"></i><?php echo $mensaje;?></div><?php endif;?>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
        
        <!-- SIDEBAR -->
        <div class="lg:col-span-4 space-y-6">
            
            <div class="card-exp p-8 text-center border-t-8 border-blue-600 relative">
                <div class="relative inline-block mb-6 group">
                    <img src="<?php echo $foto_url; ?>" class="profile-circle <?php echo !empty($emp['hoy_p'])?'border-green-500':'border-red-500';?>">
                    <button type="button" onclick="showQR('perfil')" class="absolute bottom-2 right-2 w-12 h-12 bg-blue-600 rounded-full text-white border-4 border-white shadow-xl hover:scale-110 transition"><i class="fas fa-qrcode text-sm"></i></button>
                    <button type="button" onclick="document.getElementById('in_p').click()" class="absolute top-2 right-2 w-10 h-10 bg-slate-800 rounded-full text-white border-2 border-white shadow-lg"><i class="fas fa-camera text-xs"></i></button>
                </div>
                
                <h2 class="text-2xl font-black text-slate-800 tracking-tight leading-tight uppercase"><?php echo $emp['nombre_completo']; ?></h2>
                
                <!-- Calificación Estrellas Dinámicas -->
                <div class="mt-4 flex flex-col items-center">
                    <div class="flex gap-1 mb-1">
                        <?php for($i=1;$i<=5;$i++): ?>
                            <i class="fas fa-star <?php echo $i<=$eval['stars']?'text-amber-400':'text-slate-100';?> text-lg"></i>
                        <?php endfor; ?>
                    </div>
                    <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Calificación 30d: <span class="text-blue-600"><?php echo $eval['val']; ?>%</span></p>
                    <a href="historial.php?id=<?php echo $id_reloj; ?>" class="mt-4 bg-slate-50 border border-slate-200 text-slate-500 px-5 py-2 rounded-xl text-[9px] font-black uppercase hover:bg-slate-100 transition shadow-sm">Reporte de Asistencia</a>
                </div>

                <!-- TIEMPO EN EMPRESA (Resaltado Azul) -->
                <div class="mt-8 p-5 bg-blue-600 rounded-[1.5rem] text-white shadow-lg shadow-blue-200">
                    <p class="text-[9px] font-black text-blue-100 uppercase tracking-widest mb-1">Tiempo en la Empresa</p>
                    <p class="text-xl font-black leading-tight"><?php echo calcularAntiguedad($emp['fecha_ingreso']); ?></p>
                </div>
            </div>

            <!-- MODULO: IDENTIDAD CORPORATIVA -->
            <div class="card-exp p-6">
                <div class="flex items-center justify-between mb-4 border-b pb-2">
                    <h3 class="font-bold text-gray-700 text-sm flex items-center"><i class="fas fa-id-badge mr-2 text-blue-500"></i> Identidad Corporativa</h3>
                    <?php if($carnet_existe): ?><span class="bg-green-100 text-green-700 text-[9px] font-black px-2 py-0.5 rounded uppercase">Generado</span><?php endif; ?>
                </div>
                <?php if($carnet_existe): ?>
                    <div class="mb-2 bg-gray-100 rounded-2xl overflow-hidden border border-gray-300 shadow-inner group relative">
                        <img src="<?php echo $carnet_path . '?v='.time(); ?>" class="w-full h-auto block">
                    </div>
                    <div class="flex items-center gap-2 mb-4 ml-1">
                        <i class="fas fa-calendar-check text-blue-500 text-xs"></i>
                        <p class="text-[10px] font-black text-gray-400 uppercase tracking-tighter">Creado el: <span class="text-slate-700"><?php echo $fecha_carnet; ?></span></p>
                    </div>
                <?php endif; ?>
                <div class="grid grid-cols-1 gap-2">
                    <button type="submit" name="solicitar_carnet" form="formMain" class="w-full bg-slate-900 hover:bg-black text-white py-3 rounded-xl font-black text-[10px] uppercase shadow-lg flex items-center justify-center gap-2 transition">Solicitar Carnet</button>
                    <a href="carnets_diseno.php?id=<?php echo $id_reloj; ?>" class="block w-full bg-blue-600 hover:bg-blue-700 text-white text-center py-3 rounded-xl font-black text-[10px] uppercase shadow-lg transition">Confeccionar / Imprimir</a>
                </div>
            </div>

            <!-- MODULO FOTO CÉDULA -->
            <div class="card-exp p-6">
                <h3 class="section-title theme-navy">Imagen del Documento (ID)</h3>
                <div class="relative aspect-video bg-slate-50 rounded-2xl border-2 border-dashed border-slate-200 overflow-hidden flex items-center justify-center group">
                    <?php $cp = "fotos_cedula/$ced_l.jpg"; if(file_exists($cp)): ?>
                        <img src="<?php echo $cp."?v=".time();?>" class="w-full h-full object-cover">
                    <?php else: ?>
                        <div class="text-center"><i class="fas fa-address-card text-3xl text-slate-200 mb-2"></i><p class="text-[8px] font-black text-slate-300 uppercase">Sin Imagen</p></div>
                    <?php endif; ?>
                    <div class="absolute inset-0 bg-black/50 opacity-0 group-hover:opacity-100 transition flex items-center justify-center gap-2">
                        <button type="button" onclick="document.getElementById('in_c').click()" class="bg-white text-slate-800 px-4 py-2 rounded-lg font-black text-[9px] uppercase shadow-lg">Archivo</button>
                        <button type="button" onclick="showQR('cedula')" class="bg-blue-600 text-white w-10 h-10 rounded-lg flex items-center justify-center shadow-lg hover:scale-110 transition"><i class="fas fa-qrcode"></i></button>
                    </div>
                    <input type="file" name="foto_cedula" id="in_c" form="formMain" class="hidden">
                </div>
            </div>

        </div>

        <!-- MAIN CONTENT -->
        <div class="lg:col-span-8 space-y-6">
            
            <!-- INFORMACIÓN MAESTRA -->
            <form method="POST" id="formMain" enctype="multipart/form-data" class="card-exp p-10">
                <h3 class="section-title theme-navy">Información Maestra</h3>
                
                <input type="file" name="foto_perfil" id="in_p" class="hidden">

                <div class="grid grid-cols-1 md:grid-cols-12 gap-6 mb-8">
                    <!-- ID DE RELOJ INTEGRADO AQUÍ -->
                    <div class="md:col-span-3">
                        <label class="label-black">ID Reloj</label>
                        <input type="text" name="id_reloj_val" value="<?php echo $id_reloj; ?>" <?php echo !$es_admin?'readonly disabled':''; ?> class="input-premium bg-blue-50/50 border-blue-100 text-blue-700 font-black">
                    </div>
                    <div class="md:col-span-6">
                        <label class="label-black">Nombre Completo</label>
                        <input type="text" name="nombre" value="<?php echo $emp['nombre_completo'];?>" required class="input-premium uppercase">
                    </div>
                    <div class="md:col-span-3">
                        <label class="label-black">Estatus de Nómina</label>
                        <?php 
                            $estatus = $emp['estatus_nomina'] ?? 'En Nomina';
                            $clase_estatus = ($estatus == 'En Nomina') ? 'text-green-600 border-green-200 bg-green-50' : 'text-red-600 border-red-200 bg-red-50';
                        ?>
                        <select name="estatus_nomina" class="input-premium font-black <?php echo $clase_estatus; ?>">
                            <option value="En Nomina" <?php echo $estatus == 'En Nomina' ? 'selected' : ''; ?>>EN NÓMINA</option>
                            <option value="Fuera de Nomina" <?php echo $estatus == 'Fuera de Nomina' ? 'selected' : ''; ?>>FUERA NÓMINA</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                    <div><label class="label-black">Cédula de Identidad</label><input type="text" name="cedula" value="<?php echo $emp['cedula'];?>" class="input-premium"></div>
                    <div><label class="label-black">Departamento / Área</label><input type="text" name="departamento" value="<?php echo $emp['departamento'];?>" class="input-premium uppercase"></div>
                    <div><label class="label-black">Cargo / Puesto</label><input type="text" name="cargo" value="<?php echo $emp['cargo'];?>" class="input-premium uppercase"></div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8 border-t pt-8">
                    <div><label class="label-black">Empresa</label><input type="text" name="empresa" value="<?php echo $emp['empresa'];?>" class="input-premium uppercase"></div>
                    <div><label class="label-black">Fecha Ingreso</label><input type="date" name="fecha_ingreso" value="<?php echo $emp['fecha_ingreso'];?>" class="input-premium"></div>
                    <div><label class="label-black">Tarjeta de Acceso</label><input type="text" name="tarjeta" value="<?php echo $emp['tarjeta'];?>" class="input-premium"></div>
                </div>

                <!-- HORARIOS Y PROMEDIOS REALES -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8 border-t pt-8 mt-4">
                    <!-- Configuración de Horario Teórico -->
                    <div class="space-y-6">
                        <div class="flex items-center justify-between">
                            <h4 class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Horario Teórico</h4>
                            <div class="flex items-center gap-2">
                                <span class="text-[9px] font-black text-blue-500 uppercase">Verificado</span>
                                <label class="switch">
                                    <input type="checkbox" name="horario_verificado" value="1" <?php echo $emp['horario_verificado']?'checked':''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div><label class="label-black text-blue-600">Entrada</label><input type="time" name="horario_entrada" value="<?php echo $emp['horario_entrada'];?>" class="input-premium"></div>
                            <div><label class="label-black text-red-500">Salida</label><input type="time" name="horario_salida" value="<?php echo $emp['horario_salida'];?>" class="input-premium"></div>
                        </div>
                    </div>
                    <!-- Promedios de Asistencia Real -->
                    <div class="bg-slate-50 p-6 rounded-3xl border border-slate-100">
                        <h4 class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-4">Promedios Reales (30 días)</h4>
                        <div class="grid grid-cols-3 gap-2 text-center">
                            <div class="bg-white p-2 rounded-xl border shadow-sm"><p class="text-[8px] font-black text-gray-400 uppercase">Prom. Ent</p><p class="text-sm font-black text-blue-600"><?php echo $promedios['entrada']; ?></p></div>
                            <div class="bg-white p-2 rounded-xl border shadow-sm"><p class="text-[8px] font-black text-gray-400 uppercase">Prom. Sal</p><p class="text-sm font-black text-red-500"><?php echo $promedios['salida']; ?></p></div>
                            <div class="bg-white p-2 rounded-xl border shadow-sm"><p class="text-[8px] font-black text-gray-400 uppercase">Jornada</p><p class="text-sm font-black text-slate-700"><?php echo $promedios['jornada']; ?></p></div>
                        </div>
                    </div>
                </div>
            </form>

            <!-- VACACIONES Y LICENCIAS -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="card-exp p-8 border-t-8 border-blue-500">
                    <h3 class="section-title theme-blue">Control de Vacaciones</h3>
                    <div class="space-y-3 mb-6">
                        <?php foreach($list_v as $v): ?>
                            <div class="flex justify-between items-center p-3.5 bg-blue-50 rounded-2xl border border-blue-100 group">
                                <span class="text-[10px] font-black text-blue-700"><?php echo date('d/m/Y',strtotime($v['fecha_inicio']))." → ".date('d/m/Y',strtotime($v['fecha_fin']));?></span>
                                <form method="POST" onsubmit="return confirm('¿Borrar registro?')"><button name="del_vac" value="<?php echo $v['id'];?>" class="text-red-300 opacity-0 group-hover:opacity-100 transition"><i class="fas fa-trash-alt"></i></button></form>
                            </div>
                        <?php endforeach; if(empty($list_v)) echo "<p class='text-[10px] text-gray-400 font-bold uppercase'>Sin registros</p>";?>
                    </div>
                    <button type="button" onclick="openModal('modal-v')" class="w-full py-3.5 bg-blue-600 text-white rounded-2xl font-black text-[10px] uppercase shadow-lg shadow-blue-200 transition active:scale-95">+ Programar Vacación</button>
                </div>
                <div class="card-exp p-8 border-t-8 border-red-500">
                    <h3 class="section-title theme-red">Licencias Médicas</h3>
                    <div class="space-y-3 mb-6">
                        <?php foreach($list_l as $l): ?>
                            <div class="p-3.5 bg-red-50 rounded-2xl border border-red-100 relative group">
                                <p class="text-[10px] font-black text-red-800"><?php echo date('d/m/Y',strtotime($l['fecha_inicio']));?> (<?php echo $l['descripcion'];?>)</p>
                                <form method="POST" onsubmit="return confirm('¿Borrar licencia?')"><button name="del_lic" value="<?php echo $l['id'];?>" class="absolute top-3.5 right-3.5 text-red-300 opacity-0 group-hover:opacity-100 transition"><i class="fas fa-trash-alt"></i></button></form>
                            </div>
                        <?php endforeach; if(empty($list_l)) echo "<p class='text-[10px] text-gray-400 font-bold uppercase'>Sin registros</p>";?>
                    </div>
                    <button type="button" onclick="openModal('modal-l')" class="w-full py-3.5 bg-red-600 text-white rounded-2xl font-black text-[10px] uppercase shadow-lg shadow-red-200 transition active:scale-95">+ Registrar Licencia</button>
                </div>
            </div>

            <!-- BÓVEDA ESMERALDA -->
            <div class="card-exp p-10 border-t-8 border-emerald-500">
                <div class="flex justify-between items-center mb-8 border-b pb-4">
                    <h3 class="section-title theme-emerald !mb-0">Bóveda de Documentos Digitales</h3>
                    <button type="button" onclick="openModal('modal-doc')" class="bg-emerald-50 text-emerald-600 px-5 py-2 rounded-xl font-black text-[9px] uppercase border border-emerald-100 hover:bg-emerald-600 hover:text-white transition shadow-sm">+ Nuevo Archivo</button>
                </div>
                <div class="overflow-hidden rounded-2xl border border-slate-50">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-[10px] font-black text-gray-400 uppercase border-b">
                            <tr><th class="px-6 py-4 text-left">Título del Documento</th><th class="px-6 py-4 text-center">Categoría</th><th class="px-6 py-4 text-right">Acción</th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            <?php foreach($list_d as $d): 
                                $badge = ($d['categoria']=='Vacaciones') ? 'bg-blue-100 text-blue-600' : (($d['categoria']=='Licencia') ? 'bg-red-100 text-red-600' : 'bg-gray-100 text-gray-500');
                            ?>
                                <tr class="hover:bg-slate-50 transition">
                                    <td class="px-6 py-4 font-black text-slate-700 text-xs"><?php echo $d['titulo'];?></td>
                                    <td class="px-6 py-4 text-center"><span class="px-2.5 py-1 rounded-md text-[8px] font-black uppercase <?php echo $badge;?>"><?php echo $d['categoria'];?></span></td>
                                    <td class="px-6 py-4 text-right">
                                        <a href="uploads/docs/<?php echo $d['nombre_archivo'];?>" target="_blank" class="text-blue-500 hover:text-blue-700 mr-2"><i class="fas fa-eye"></i></a>
                                        <form method="POST" class="inline" onsubmit="return confirm('¿Eliminar documento?')"><button name="borrar_doc_id" value="<?php echo $d['id'];?>" class="text-red-300 hover:text-red-500 transition"><i class="fas fa-trash-alt"></i></button></form>
                                    </td>
                                </tr>
                            <?php endforeach; if(empty($list_d)) echo "<tr><td colspan='3' class='py-12 text-center text-gray-300 uppercase text-[10px] font-black'>Expediente Digital Vacío</td></tr>";?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
    window.onload = () => { setTimeout(() => document.body.classList.add('ready'), 120); };
    function openModal(id) { document.getElementById(id).classList.remove('hidden'); document.getElementById(id).classList.add('flex'); }
    function closeModal(id) { document.getElementById(id).classList.add('hidden'); document.getElementById(id).classList.remove('flex'); }

    function showQR(type) {
        const url = "<?php echo $url_base_qr; ?>&type=" + type;
        const img = document.getElementById('qr-image');
        img.src = "https://api.qrserver.com/v1/create-qr-code/?size=450x450&data=" + encodeURIComponent(url);
        document.getElementById('modal-qr').classList.remove('hidden');
    }
</script>

<?php require 'layout_footer.php'; ?>