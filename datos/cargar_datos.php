<?php
// cargar_datos.php - VERSIÓN ULTRA-ROBUSTA: FILTRADO DE ENCABEZADOS DOBLES + AUTO-ID
require '../core/auth.php';
require 'db.php'; 

verificarPermiso(['admin', 'rrhh']);

// --- CONFIGURACIÓN DE ENTORNO ---
ini_set('display_errors', 1);
error_reporting(E_ALL);
ini_set('memory_limit', '1024M'); 
set_time_limit(900);
date_default_timezone_set('America/Santo_Domingo'); 

// Verificar presencia de la librería PhpSpreadsheet
$use_library = file_exists('vendor/autoload.php');
if ($use_library) {
    require 'vendor/autoload.php';
}

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

// --- VARIABLES DE ESTADO ---
$mensaje = '';
$tipo_mensaje = '';

// --- FUNCIONES AUXILIARES ---

function obtenerUltimoEvento($pdo) {
    try {
        $sql = "SELECT fecha, hora_salida FROM asistencia ORDER BY fecha DESC, hora_salida DESC LIMIT 1";
        $stmt = $pdo->query($sql);
        $row = $stmt->fetch();
        return $row ? date('d-m-Y h:i:s A', strtotime($row['fecha'] . ' ' . $row['hora_salida'])) : 'Sin registros';
    } catch (Exception $e) {
        return 'Error al consultar';
    }
}

function parsearFechaSegura($val) {
    if ($val === null || $val === '') return null;
    $val = trim((string)$val);
    
    // Si es formato serial de Excel (numérico)
    if (is_numeric($val) && $val > 30000) {
        return Date::excelToDateTimeObject($val)->format('Y-m-d');
    }
    
    // Intentar formatos comunes
    $formatos = ['d/m/Y', 'Y-m-d', 'd-m-Y', 'm/d/Y'];
    foreach ($formatos as $f) {
        $d = DateTime::createFromFormat($f, $val);
        if ($d) return $d->format('Y-m-d');
    }
    return null;
}

function parsearHoraSegura($val) {
    if ($val === null || $val === '') return '00:00:00';
    $val = trim((string)$val);
    
    if (is_numeric($val) && $val < 1) {
        return Date::excelToDateTimeObject($val)->format('H:i:s');
    }
    
    $timestamp = strtotime($val);
    return $timestamp ? date('H:i:s', $timestamp) : '00:00:00';
}

// Obtener info para el Dashboard
$ultimo_evento = obtenerUltimoEvento($pdo);

// --- 1. PROCESAMIENTO DE MAESTRO DE EMPLEADOS ---
if (isset($_FILES['file_maestro'])) {
    $file = $_FILES['file_maestro']['tmp_name'];
    try {
        if (!$use_library) throw new Exception("Librería PhpSpreadsheet no encontrada.");
        
        $spreadsheet = IOFactory::load($file);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray();
        
        $count = 0;
        foreach ($rows as $index => $row) {
            // LÓGICA DE FILTRADO ROBUSTA:
            // Saltamos la fila si:
            // 1. Está vacía.
            // 2. El primer campo es el texto "ID" (Cabecera).
            // 3. El primer campo es el texto "Usuarios" (Título ZK).
            // 4. El primer campo no es un número (Garantiza que id_reloj sea entero).
            if (empty($row[0]) || trim($row[0]) === 'ID' || trim($row[0]) === 'Usuarios' || !is_numeric($row[0])) {
                continue;
            }

            $id_reloj = trim($row[0]);
            $nombre   = strtoupper(trim($row[1]));
            $apellido = strtoupper(trim($row[2] ?? ''));
            $nombre_completo = trim($nombre . ' ' . $apellido);
            $cedula   = preg_replace('/[^0-9]/', '', (string)($row[9] ?? $row[2] ?? '')); // Adaptado a 26 columnas o 8 columnas
            $depto    = strtoupper(trim($row[4] ?? 'Administración'));
            $cargo    = strtoupper(trim($row[17] ?? $row[4] ?? 'EMPLEADO'));
            $empresa  = strtoupper(trim($row[5] ?? 'TELEMICRO'));
            $tarjeta  = trim($row[10] ?? $row[7] ?? '');

            // Prioridad Maestra: Insertar o actualizar datos básicos
            $stmt = $pdo->prepare("INSERT INTO empleados (id_reloj, nombre_completo, cedula, departamento, cargo, empresa, tarjeta, estatus_nomina) 
                                   VALUES (?, ?, ?, ?, ?, ?, ?, 'En Nomina') 
                                   ON DUPLICATE KEY UPDATE nombre_completo=VALUES(nombre_completo), cedula=VALUES(cedula), departamento=VALUES(departamento), cargo=VALUES(cargo), tarjeta=VALUES(tarjeta)");
            $stmt->execute([$id_reloj, $nombre_completo, $cedula, $depto, $cargo, $empresa, $tarjeta]);
            $count++;
        }
        $mensaje = "✅ Maestro actualizado: $count empleados procesados correctamente.";
        $tipo_mensaje = "success";
    } catch (Exception $e) {
        $mensaje = "❌ Error en Maestro: " . $e->getMessage();
        $tipo_mensaje = "error";
    }
}

// --- 2. PROCESAMIENTO DE NÓMINA (RRHH) ---
if (isset($_FILES['file_nomina'])) {
    $file = $_FILES['file_nomina']['tmp_name'];
    try {
        $spreadsheet = IOFactory::load($file);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray();
        
        $count = 0;
        foreach ($rows as $index => $row) {
            if ($index === 0 || empty($row[0]) || !is_numeric(preg_replace('/[^0-9]/', '', (string)$row[0]))) continue;

            $cedula = preg_replace('/[^0-9]/', '', (string)$row[0]);
            $estatus = trim($row[1]); 

            $stmt = $pdo->prepare("UPDATE empleados SET estatus_nomina = ? WHERE cedula = ?");
            $stmt->execute([$estatus, $cedula]);
            $count++;
        }
        $mensaje = "✅ Nómina sincronizada: $count registros afectados.";
        $tipo_mensaje = "success";
    } catch (Exception $e) {
        $mensaje = "❌ Error en Nómina: " . $e->getMessage();
        $tipo_mensaje = "error";
    }
}

// --- 3. PROCESAMIENTO DE EVENTOS DE ASISTENCIA ---
if (isset($_FILES['file_eventos'])) {
    $file = $_FILES['file_eventos']['tmp_name'];
    try {
        $spreadsheet = IOFactory::load($file);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray();
        
        $insertados = 0;
        $pdo->beginTransaction();

        foreach ($rows as $index => $row) {
            if ($index === 0 || empty($row[0]) || !is_numeric($row[0])) continue;

            $id_reloj = trim($row[0]);
            $fecha    = parsearFechaSegura($row[1]);
            $hora     = parsearHoraSegura($row[2]);
            $punto    = trim($row[3] ?? 'Punto Desconocido');

            if (!$fecha) continue;

            $check = $pdo->prepare("SELECT id_asistencia, hora_entrada FROM asistencia WHERE id_empleado_reloj = ? AND fecha = ?");
            $check->execute([$id_reloj, $fecha]);
            $asis = $check->fetch();

            if ($asis) {
                $stmt = $pdo->prepare("UPDATE asistencia SET hora_salida = ?, ultimo_punto = ?, total_eventos = total_eventos + 1 WHERE id_asistencia = ?");
                $stmt->execute([$hora, $punto, $asis['id_asistencia']]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO asistencia (id_empleado_reloj, fecha, hora_entrada, hora_salida, ultimo_punto, total_eventos) VALUES (?, ?, ?, ?, ?, 1)");
                $stmt->execute([$id_reloj, $fecha, $hora, $hora, $punto]);
            }
            $insertados++;
        }
        
        $pdo->commit();
        $mensaje = "✅ Asistencia procesada: $insertados eventos registrados.";
        $tipo_mensaje = "success";
    } catch (Exception $e) {
        $pdo->rollBack();
        $mensaje = "❌ Error en Asistencia: " . $e->getMessage();
        $tipo_mensaje = "error";
    }
}

require 'layout_head.php';
?>

<div class="max-w-5xl mx-auto pb-20 px-4">
    <div class="flex justify-between items-center mb-8 mt-6">
        <div>
            <h1 class="text-3xl font-black text-slate-800 tracking-tighter">Carga Masiva de Datos</h1>
            <p class="text-sm text-gray-500 uppercase font-bold tracking-widest">Sincronización de Reloj y RRHH</p>
        </div>
        <div class="text-right">
            <p class="text-[10px] font-black text-gray-400 uppercase">Último Evento en DB</p>
            <p class="text-sm font-bold text-blue-600"><?php echo $ultimo_evento; ?></p>
        </div>
    </div>

    <?php if ($mensaje): ?>
        <div class="mb-8 p-5 rounded-2xl border <?php echo $tipo_mensaje == 'success' ? 'bg-green-50 border-green-200 text-green-700' : 'bg-red-50 border-red-200 text-red-700'; ?> font-bold animate-fade-in flex items-center gap-3">
            <i class="fas <?php echo $tipo_mensaje == 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle'; ?> text-xl"></i>
            <?php echo $mensaje; ?>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        
        <!-- Bloque 1: Maestro -->
        <div class="bg-white p-8 rounded-[2rem] shadow-xl border border-gray-100 flex flex-col justify-between">
            <div>
                <div class="w-12 h-12 bg-indigo-100 text-indigo-600 rounded-2xl flex items-center justify-center mb-4 text-xl shadow-inner">
                    <i class="fas fa-users-cog"></i>
                </div>
                <h2 class="text-xl font-black text-slate-800 mb-2">1. Maestro de Empleados</h2>
                <p class="text-xs text-gray-400 mb-6 leading-relaxed">Actualiza nombres, departamentos y cargos desde el archivo de usuarios del reloj.</p>
            </div>
            <form method="POST" enctype="multipart/form-data" class="space-y-4">
                <div class="relative group">
                    <input type="file" name="file_maestro" accept=".xlsx,.xls,.csv" required class="absolute inset-0 w-full h-full opacity-0 cursor-pointer z-10">
                    <div class="bg-gray-50 border-2 border-dashed border-gray-200 rounded-2xl p-6 text-center group-hover:border-indigo-400 transition-all">
                        <i class="fas fa-cloud-upload-alt text-gray-300 text-2xl mb-2"></i>
                        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Seleccionar archivo</p>
                    </div>
                </div>
                <button class="w-full bg-indigo-600 hover:bg-indigo-700 text-white py-4 rounded-2xl font-black text-xs uppercase tracking-widest shadow-lg shadow-indigo-100 transition transform active:scale-95">Sincronizar Maestro</button>
            </form>
        </div>

        <!-- Bloque 2: Nómina -->
        <div class="bg-white p-8 rounded-[2rem] shadow-xl border border-gray-100 flex flex-col justify-between">
            <div>
                <div class="w-12 h-12 bg-emerald-100 text-emerald-600 rounded-2xl flex items-center justify-center mb-4 text-xl shadow-inner">
                    <i class="fas fa-file-invoice-dollar"></i>
                </div>
                <h2 class="text-xl font-black text-slate-800 mb-2">2. Estatus de Nómina</h2>
                <p class="text-xs text-gray-400 mb-6 leading-relaxed">Carga el reporte de RRHH para activar o desactivar empleados según su estatus.</p>
            </div>
            <form method="POST" enctype="multipart/form-data" class="space-y-4">
                <div class="relative group">
                    <input type="file" name="file_nomina" accept=".xlsx,.xls,.csv" required class="absolute inset-0 w-full h-full opacity-0 cursor-pointer z-10">
                    <div class="bg-gray-50 border-2 border-dashed border-gray-200 rounded-2xl p-6 text-center group-hover:border-emerald-400 transition-all">
                        <i class="fas fa-user-shield text-gray-300 text-2xl mb-2"></i>
                        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Seleccionar archivo</p>
                    </div>
                </div>
                <button class="w-full bg-emerald-600 hover:bg-emerald-700 text-white py-4 rounded-2xl font-black text-xs uppercase tracking-widest shadow-lg shadow-emerald-100 transition transform active:scale-95">Actualizar Estatus</button>
            </form>
        </div>

        <!-- Bloque 3: Eventos -->
        <div class="md:col-span-2 bg-slate-900 p-8 rounded-[2.5rem] shadow-2xl text-white relative overflow-hidden">
            <div class="absolute -right-10 -bottom-10 opacity-10 text-9xl">
                <i class="fas fa-history"></i>
            </div>
            <div class="relative z-10">
                <div class="flex flex-col md:flex-row md:items-center justify-between mb-8 gap-4">
                    <div>
                        <h2 class="text-2xl font-black tracking-tight">3. Log de Eventos de Asistencia</h2>
                        <p class="text-slate-400 text-sm">Procesamiento masivo de marcas de entrada y salida diarias.</p>
                    </div>
                    <div class="bg-white/10 px-4 py-2 rounded-xl border border-white/10">
                        <span class="text-[9px] font-black text-blue-400 uppercase block">Formato Requerido</span>
                        <span class="text-xs font-bold">ID | FECHA | HORA | PUNTO</span>
                    </div>
                </div>
                <form method="POST" enctype="multipart/form-data" class="flex flex-col md:flex-row gap-4 items-end">
                    <div class="flex-grow w-full">
                        <label class="text-[10px] font-black text-slate-500 uppercase ml-2 mb-2 block">Archivo de Registros (.xls, .xlsx, .csv)</label>
                        <input type="file" name="file_eventos" accept=".xlsx,.xls,.csv" required class="w-full bg-white/5 border border-white/10 rounded-2xl p-4 text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                    </div>
                    <button type="submit" class="w-full md:w-auto bg-blue-600 hover:bg-blue-500 text-white py-4 px-10 rounded-2xl font-black text-xs uppercase tracking-widest shadow-xl transition transform active:scale-95">
                        Procesar Eventos
                    </button>
                </form>
            </div>
        </div>

    </div>
</div>

<?php require 'layout_footer.php'; ?>