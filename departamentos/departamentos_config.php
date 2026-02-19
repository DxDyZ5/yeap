<?php
// departamentos_config.php - ADMINISTRACIÓN SEGMENTADA POR EMPRESA
require '../core/auth.php';
verificarPermiso(['admin', 'rrhh']);

$mensaje = '';
$tipo_mensaje = '';

// --- ACTUALIZACIÓN DE ESTRUCTURA DE TABLA (Soporte para Empresa) ---
try {
    // Intentamos agregar la columna empresa si no existe
    $pdo->exec("ALTER TABLE departamentos_meta ADD COLUMN IF NOT EXISTS empresa VARCHAR(100) NOT NULL DEFAULT '' AFTER nombre_departamento");
    
    // Ajustamos la Primary Key para que sea compuesta
    $res = $pdo->query("SHOW KEYS FROM departamentos_meta WHERE Key_name = 'PRIMARY'")->fetchAll();
    if (count($res) < 2) { // Si solo hay una columna en la PK o ninguna
        $pdo->exec("ALTER TABLE departamentos_meta DROP PRIMARY KEY, ADD PRIMARY KEY (nombre_departamento, empresa)");
    }
} catch (Exception $e) {
    // Si falla es porque ya está configurado o la tabla está vacía
}

// --- PROCESAR ACCIONES ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    // 1. CREAR DEPARTAMENTO SEGMENTADO
    if ($accion === 'crear_depto') {
        $nombre = strtoupper(trim($_POST['nuevo_nombre'] ?? ''));
        $empresa = strtoupper(trim($_POST['empresa_depto'] ?? '')); // Vacío = Todas
        if (!empty($nombre)) {
            try {
                $stmt = $pdo->prepare("INSERT IGNORE INTO departamentos_meta (nombre_departamento, empresa) VALUES (?, ?)");
                $stmt->execute([$nombre, $empresa]);
                $msg_emp = $empresa ?: 'TODAS';
                $mensaje = "✅ Depto. '$nombre' ($msg_emp) creado.";
                $tipo_mensaje = "success";
            } catch (Exception $e) { $mensaje = "❌ Error: " . $e->getMessage(); $tipo_mensaje = "error"; }
        }
    }

    // 2. FUSIONAR DEPARTAMENTOS (CON FILTRO DE EMPRESA)
    if ($accion === 'fusionar_masivo') {
        $origenes = $_POST['deptos_origen'] ?? []; 
        $destino = $_POST['depto_destino'] ?? '';
        $solo_empresa = $_POST['empresa_filtro'] ?? ''; // Si se elige, solo mueve empleados de esa empresa
        
        if (!empty($origenes) && !empty($destino)) {
            $total_movidos = 0;
            $pdo->beginTransaction();
            try {
                foreach ($origenes as $orig) {
                    if ($orig === $destino) continue;
                    
                    $sql = "UPDATE empleados SET departamento = ? WHERE departamento = ?";
                    $binds = [$destino, $orig];
                    
                    if (!empty($solo_empresa)) {
                        $sql .= " AND empresa = ?";
                        $binds[] = $solo_empresa;
                    }
                    
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($binds);
                    $total_movidos += $stmt->rowCount();
                }
                $pdo->commit();
                $mensaje = "✅ Fusión completada: $total_movidos empleados movidos.";
                $tipo_mensaje = "success";
            } catch (Exception $e) { $pdo->rollBack(); $mensaje = "❌ Error: " . $e->getMessage(); $tipo_mensaje = "error"; }
        }
    }

    // 3. ASIGNAR ENCARGADO POR EMPRESA
    if ($accion === 'asignar_encargado') {
        $depto = $_POST['nombre_departamento'];
        $empresa = $_POST['empresa_target'] ?? ''; // Segmentación
        $id_reloj = !empty($_POST['encargado_id']) ? (int)$_POST['encargado_id'] : null;
        
        $stmt = $pdo->prepare("INSERT INTO departamentos_meta (nombre_departamento, empresa, encargado_id_reloj) 
                               VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE encargado_id_reloj = VALUES(encargado_id_reloj)");
        $stmt->execute([$depto, $empresa, $id_reloj]);
        $mensaje = "✅ Encargado asignado correctamente.";
        $tipo_mensaje = "success";
    }
}

// Obtener listas actualizadas
$empresas = $pdo->query("SELECT DISTINCT empresa FROM empleados WHERE empresa IS NOT NULL AND empresa != '' ORDER BY empresa ASC")->fetchAll(PDO::FETCH_COLUMN);
$deptos_existentes = $pdo->query("SELECT DISTINCT departamento FROM empleados WHERE departamento IS NOT NULL AND departamento != '' ORDER BY departamento ASC")->fetchAll(PDO::FETCH_COLUMN);
$empleados_select = $pdo->query("SELECT id_reloj, nombre_completo, cargo, empresa FROM empleados WHERE estatus_nomina = 'En Nomina' ORDER BY nombre_completo ASC")->fetchAll();

require '../layout/layout_head.php';
?>

<style>
    .search-results-list { max-height: 250px; overflow-y: auto; display: none; }
    .search-results-list.active { display: block; }
</style>

<div class="max-w-6xl mx-auto space-y-8 pb-20">
    <div class="flex items-center justify-between border-b pb-4">
        <div>
            <h1 class="text-3xl font-black text-slate-800 tracking-tight">Estructura Organizacional</h1>
            <p class="text-slate-500 text-sm">Gestiona departamentos y encargados segmentados por empresa.</p>
        </div>
        <i class="fas fa-city text-4xl text-slate-200"></i>
    </div>

    <?php if($mensaje): ?>
        <div class="p-4 rounded-xl border <?php echo $tipo_mensaje === 'success' ? 'bg-green-50 border-green-200 text-green-700' : 'bg-red-50 border-red-200 text-red-700'; ?> animate-bounce-short">
            <?php echo $mensaje; ?>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
        
        <!-- CREAR DEPARTAMENTO -->
        <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-200">
            <h2 class="font-bold text-slate-700 mb-4 flex items-center gap-2">
                <i class="fas fa-plus-circle text-blue-500"></i> Nuevo Departamento
            </h2>
            <form method="POST" class="space-y-3">
                <input type="hidden" name="accion" value="crear_depto">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <input type="text" name="nuevo_nombre" required placeholder="NOMBRE DEPARTAMENTO" class="p-3 bg-slate-50 border rounded-xl outline-none focus:ring-2 focus:ring-blue-500 transition uppercase font-bold text-xs">
                    <select name="empresa_depto" class="p-3 bg-slate-50 border rounded-xl outline-none text-xs font-bold uppercase">
                        <option value="">-- TODAS LAS EMPRESAS --</option>
                        <?php foreach($empresas as $e): ?><option value="<?php echo $e; ?>"><?php echo $e; ?></option><?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white py-3 rounded-xl font-bold transition">Registrar</button>
            </form>
        </div>

        <!-- DEFINIR ENCARGADO SEGMENTADO -->
        <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-200">
            <h2 class="font-bold text-slate-700 mb-4 flex items-center gap-2">
                <i class="fas fa-user-tie text-indigo-500"></i> Definir Encargado
            </h2>
            <form method="POST" class="space-y-3" autocomplete="off">
                <input type="hidden" name="accion" value="asignar_encargado">
                <div class="grid grid-cols-2 gap-3">
                    <select name="nombre_departamento" required class="p-3 bg-slate-50 border rounded-xl text-xs font-bold">
                        <option value="">DEPARTAMENTO...</option>
                        <?php foreach($deptos_existentes as $d): ?><option value="<?php echo $d; ?>"><?php echo $d; ?></option><?php endforeach; ?>
                    </select>
                    <select name="empresa_target" class="p-3 bg-slate-50 border rounded-xl text-xs font-bold">
                        <option value="">TODAS LAS EMPRESAS</option>
                        <?php foreach($empresas as $e): ?><option value="<?php echo $e; ?>"><?php echo $e; ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="relative">
                    <input type="text" id="encargado_search" placeholder="Escriba Nombre o ID del Líder..." class="w-full p-3 bg-slate-50 border rounded-xl outline-none text-xs focus:ring-2 focus:ring-indigo-500">
                    <input type="hidden" name="encargado_id" id="encargado_id_hidden">
                    <div id="search_results" class="search-results-list absolute left-0 right-0 mt-1 bg-white border rounded-xl shadow-2xl z-50"></div>
                </div>
                <button type="submit" id="btn_save_leader" disabled class="w-full py-3 bg-indigo-600 hover:bg-indigo-700 disabled:bg-slate-300 text-white font-bold rounded-xl shadow-lg transition">Guardar Líder</button>
            </form>
        </div>

        <!-- FUSIÓN MASIVA CON SEGMENTACIÓN -->
        <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-200 lg:col-span-2">
            <h2 class="font-bold text-slate-700 mb-4 flex items-center gap-2">
                <i class="fas fa-object-group text-orange-500"></i> Fusión Masiva de Departamentos
            </h2>
            <form method="POST" class="space-y-6">
                <input type="hidden" name="accion" value="fusionar_masivo">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div class="space-y-2">
                        <label class="block text-[10px] font-bold text-slate-400 uppercase">1. Orígenes (Eliminar)</label>
                        <div class="max-h-48 overflow-y-auto p-3 bg-slate-50 rounded-xl border border-slate-100">
                            <?php foreach($deptos_existentes as $d): ?>
                            <label class="flex items-center gap-2 p-1 hover:bg-white rounded cursor-pointer transition">
                                <input type="checkbox" name="deptos_origen[]" value="<?php echo $d; ?>" class="rounded text-orange-500">
                                <span class="text-[11px] font-bold text-slate-600"><?php echo $d; ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="space-y-4">
                        <label class="block text-[10px] font-bold text-slate-400 uppercase">2. Destino y Filtro</label>
                        <select name="depto_destino" required class="w-full p-3 bg-slate-100 border rounded-xl font-bold text-xs uppercase">
                            <option value="">-- SELECCIONAR DESTINO --</option>
                            <?php foreach($deptos_existentes as $d): ?><option value="<?php echo $d; ?>"><?php echo $d; ?></option><?php endforeach; ?>
                        </select>
                        <select name="empresa_filtro" class="w-full p-3 bg-slate-50 border-2 border-dashed border-slate-200 rounded-xl font-bold text-xs">
                            <option value="">SOLO MOVER DE TODAS LAS EMPRESAS</option>
                            <?php foreach($empresas as $e): ?><option value="<?php echo $e; ?>">SOLO MOVER DE: <?php echo $e; ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="flex flex-col justify-end">
                        <div class="bg-orange-50 p-3 rounded-xl border border-orange-100 mb-3 text-[10px] text-orange-800 leading-tight">
                            <i class="fas fa-info-circle mr-1"></i> Puedes mover "PRENSA" de "TELEMICRO" a un nuevo departamento sin afectar a "PRENSA" de otras empresas usando el filtro.
                        </div>
                        <button type="submit" class="w-full py-4 bg-orange-600 hover:bg-orange-700 text-white font-black rounded-xl shadow-lg transition transform hover:-translate-y-1 active:scale-95 uppercase text-xs">
                            Ejecutar Fusión
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    const empleados = <?php echo json_encode($empleados_select); ?>;
    const searchInput = document.getElementById('encargado_search');
    const resultsDiv = document.getElementById('search_results');
    const hiddenIdInput = document.getElementById('encargado_id_hidden');
    const btnSave = document.getElementById('btn_save_leader');

    searchInput.addEventListener('input', function() {
        const val = this.value.toLowerCase().trim();
        resultsDiv.innerHTML = '';
        if (val.length < 1) { resultsDiv.classList.remove('active'); hiddenIdInput.value = ''; btnSave.disabled = true; return; }

        const filtered = empleados.filter(e => 
            e.nombre_completo.toLowerCase().includes(val) || 
            e.id_reloj.toString().includes(val) || 
            (e.empresa && e.empresa.toLowerCase().includes(val))
        ).slice(0, 10);

        if (filtered.length > 0) {
            resultsDiv.classList.add('active');
            filtered.forEach(e => {
                const div = document.createElement('div');
                div.className = 'p-3 hover:bg-indigo-50 cursor-pointer border-b border-slate-50 last:border-0 transition';
                div.innerHTML = `
                    <div class="flex justify-between items-center">
                        <span class="text-xs font-bold text-slate-800">${e.nombre_completo}</span>
                        <span class="text-[9px] bg-slate-100 px-1 rounded font-mono">${e.id_reloj}</span>
                    </div>
                    <div class="text-[9px] text-slate-400 uppercase font-medium">${e.empresa || 'S/E'} - ${e.cargo || 'S/C'}</div>
                `;
                div.onclick = () => {
                    searchInput.value = e.nombre_completo;
                    hiddenIdInput.value = e.id_reloj;
                    resultsDiv.classList.remove('active');
                    btnSave.disabled = false;
                };
                resultsDiv.appendChild(div);
            });
        } else {
            resultsDiv.innerHTML = '<div class="p-3 text-xs text-slate-400">No hay resultados</div>';
            resultsDiv.classList.add('active');
            btnSave.disabled = true;
        }
    });
    document.addEventListener('click', e => { if (!searchInput.contains(e.target)) resultsDiv.classList.remove('active'); });
</script>

<?php require '../layout/layout_footer.php'; ?>