<?php
// config_evaluacion.php - CONFIGURACIÓN DE CRITERIOS CON REGLAS DE PUNTUALIDAD Y SALIDAS
require 'auth.php';
require_once 'db.php';

verificarAcceso('ver');

// --- 1. MANTENIMIENTO DE ESTRUCTURA ---
try {
    // Asegurar tabla de criterios
    $pdo->exec("CREATE TABLE IF NOT EXISTS criterios_evaluacion (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(100) NOT NULL,
        descripcion VARCHAR(255),
        peso_maximo INT NOT NULL DEFAULT 0,
        es_sistema TINYINT DEFAULT 1,
        variable_sistema VARCHAR(50) UNIQUE
    )");

    // Sincronizar Criterios Solicitados (Separación Puntualidad/Asistencia y retiro de subjetivos)
    $stmtDelete = $pdo->prepare("DELETE FROM criterios_evaluacion WHERE nombre IN ('Desempeño y Calidad', 'Trabajo en Equipo')");
    $stmtDelete->execute();

    $criterios_base = [
        ['Asistencia', 'Frecuencia de presencia en días laborables', 30, 'asistencia'],
        ['Puntualidad', 'Llegadas antes del margen de tolerancia definido', 30, 'puntualidad'],
        ['Permanencia', 'Cumplimiento del horario de salida (sin salidas anticipadas)', 20, 'salida_anticipada'],
        ['Horas Extras', 'Puntos acumulados por tiempo adicional trabajado', 20, 'horas_extras']
    ];

    foreach ($criterios_base as $cb) {
        $stmt = $pdo->prepare("INSERT IGNORE INTO criterios_evaluacion (nombre, descripcion, peso_maximo, es_sistema, variable_sistema) VALUES (?,?,?,1,?)");
        $stmt->execute($cb);
    }

    // Asegurar tabla de reglas
    $pdo->exec("CREATE TABLE IF NOT EXISTS reglas_calculo (
        clave VARCHAR(50) PRIMARY KEY,
        valor DECIMAL(10,2) NOT NULL,
        descripcion VARCHAR(255)
    )");
    
    // Insertar reglas de flexibilidad y verificación
    $reglas_base = [
        ['margen_entrada', 5, 'Minutos de tolerancia después de la entrada'],
        ['margen_salida', 0, 'Minutos de tolerancia antes de la salida'],
        ['solo_verificados_entrada', 1, '1: Solo evaluar puntualidad a verificados, 0: A todos'],
        ['solo_verificados_salida', 1, '1: Solo evaluar permanencia a verificados, 0: A todos'],
        ['valor_hora_extra', 2.0, 'Puntos por cada hora extra'],
        ['max_puntos_extras', 20.0, 'Tope máximo de puntos por extras']
    ];

    foreach ($reglas_base as $rb) {
        $stmt = $pdo->prepare("INSERT IGNORE INTO reglas_calculo (clave, valor, descripcion) VALUES (?,?,?)");
        $stmt->execute($rb);
    }

} catch (Exception $e) { die("Error de Base de Datos: " . $e->getMessage()); }

$mensaje = '';
$tipo_msg = '';

// --- 2. PROCESAR GUARDADO ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_todo'])) {
    try {
        $pdo->beginTransaction();
        $suma_total = 0;

        // Guardar Pesos de Criterios
        if (isset($_POST['criterios'])) {
            foreach ($_POST['criterios'] as $id => $data) {
                $peso = (int)$data['peso'];
                $suma_total += $peso;
                $stmt = $pdo->prepare("UPDATE criterios_evaluacion SET peso_maximo = ? WHERE id = ?");
                $stmt->execute([$peso, $id]);
            }
        }

        if ($suma_total !== 100) {
            throw new Exception("El peso total debe sumar exactamente 100 pts. Actualmente suma: $suma_total pts.");
        }

        // Guardar Reglas de Negocio
        if (isset($_POST['reglas'])) {
            foreach ($_POST['reglas'] as $clave => $valor) {
                $stmt = $pdo->prepare("UPDATE reglas_calculo SET valor = ? WHERE clave = ?");
                $stmt->execute([$valor, $clave]);
            }
        }

        $pdo->commit();
        $mensaje = "✅ Configuración de evaluación actualizada correctamente."; $tipo_msg = "success";
    } catch (Exception $e) {
        $pdo->rollBack();
        $mensaje = "❌ " . $e->getMessage(); $tipo_msg = "error";
    }
}

// Cargar Datos
$criterios = $pdo->query("SELECT * FROM criterios_evaluacion ORDER BY id ASC")->fetchAll();
$reglas = $pdo->query("SELECT clave, valor FROM reglas_calculo")->fetchAll(PDO::FETCH_KEY_PAIR);

require 'layout_head.php';
?>

<style>
    body { opacity: 0; transition: opacity 0.4s ease; background: #f8fafc; }
    body.ready { opacity: 1; }
    .card-premium { background: white; border-radius: 2rem; border: 1px solid #f1f5f9; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.02); overflow: hidden; }
    .label-black { color: #000 !important; font-weight: 950 !important; font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.05em; display: block; margin-bottom: 0.3rem; }
    .input-premium { background: #fff; border: 1px solid #e2e8f0; color: #1e293b !important; font-weight: 700; padding: 0.65rem 1rem; border-radius: 0.8rem; width: 100%; font-size: 0.85rem; }
    
    /* Switch Estilo iOS para Toggles */
    .switch { position: relative; display: inline-block; width: 40px; height: 22px; }
    .switch input { opacity: 0; width: 0; height: 0; }
    .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #e2e8f0; transition: .4s; border-radius: 34px; }
    .slider:before { position: absolute; content: ""; height: 16px; width: 16px; left: 3px; bottom: 3px; background-color: white; transition: .4s; border-radius: 50%; }
    input:checked + .slider { background-color: #3b82f6; }
    input:checked + .slider:before { transform: translateX(18px); }
</style>

<div class="container mx-auto px-4 pb-20 max-w-6xl">
    
    <div class="flex flex-col md:flex-row justify-between items-center mb-10 mt-8 gap-6">
        <div class="flex items-center gap-5">
            <div class="w-16 h-16 bg-slate-900 rounded-3xl flex items-center justify-center text-white shadow-xl">
                <i class="fas fa-sliders-h text-2xl text-blue-400"></i>
            </div>
            <div>
                <h1 class="text-3xl font-black text-slate-800 tracking-tighter uppercase leading-none">Método de Calificación</h1>
                <p class="text-[10px] text-gray-400 font-black uppercase tracking-widest mt-2">Algoritmo de Desempeño Técnico Centralizado</p>
            </div>
        </div>
        <div class="text-right">
            <div id="total-badge" class="text-3xl font-black text-slate-800 bg-white border-2 border-slate-100 px-6 py-2 rounded-2xl shadow-sm">0 / 100</div>
            <p class="text-[9px] font-black text-gray-400 uppercase mt-2">Puntos Totales Requeridos</p>
        </div>
    </div>

    <?php if($mensaje): ?><div class="mb-8 p-5 rounded-2xl border <?php echo $tipo_msg=='success'?'bg-green-50 border-green-200 text-green-700':'bg-red-50 border-red-200 text-red-700';?> font-bold animate-fade-in flex items-center gap-3"><i class="fas <?php echo $tipo_msg=='success'?'fa-check-circle':'fa-exclamation-triangle';?> text-xl"></i><?php echo $mensaje;?></div><?php endif;?>

    <form method="POST">
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
            
            <!-- LISTA DE CRITERIOS (Distribución de 100 puntos) -->
            <div class="lg:col-span-7">
                <div class="card-premium p-10">
                    <h3 class="text-lg font-black text-slate-800 uppercase tracking-tighter mb-8 flex items-center gap-3">
                        <i class="fas fa-weight-hanging text-blue-600"></i> Distribución de Puntos
                    </h3>
                    <div class="space-y-4">
                        <?php foreach($criterios as $c): ?>
                        <div class="p-5 bg-slate-50 rounded-[1.5rem] border border-slate-100 flex items-center gap-5 transition hover:bg-white hover:shadow-xl">
                            <div class="flex-grow">
                                <p class="font-black text-slate-700 uppercase text-xs mb-1"><?php echo htmlspecialchars($c['nombre']); ?></p>
                                <p class="text-[10px] text-gray-400 font-bold leading-tight"><?php echo htmlspecialchars($c['descripcion']); ?></p>
                            </div>
                            <div class="flex-shrink-0 w-24">
                                <input type="number" name="criterios[<?php echo $c['id']; ?>][peso]" value="<?php echo $c['peso_maximo']; ?>" class="input-peso input-premium text-center !text-lg !text-blue-700 font-black" oninput="calcularTotal()">
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- REGLAS DE FLEXIBILIDAD Y SEGURIDAD -->
            <div class="lg:col-span-5 space-y-6">
                
                <!-- Puntualidad -->
                <div class="card-premium p-8 border-l-8 border-blue-600">
                    <h3 class="text-[10px] font-black text-blue-600 uppercase tracking-widest mb-6 flex items-center gap-2">
                        <i class="fas fa-clock"></i> Reglas de Puntualidad
                    </h3>
                    <div class="space-y-5">
                        <div class="flex items-center justify-between">
                            <label class="label-black !mb-0">Solo evaluar si tiene Horario Verificado</label>
                            <label class="switch">
                                <input type="hidden" name="reglas[solo_verificados_entrada]" value="0">
                                <input type="checkbox" name="reglas[solo_verificados_entrada]" value="1" <?php echo ($reglas['solo_verificados_entrada']==1)?'checked':''; ?>>
                                <span class="slider"></span>
                            </label>
                        </div>
                        <div>
                            <label class="label-black">Margen de Tolerancia (Minutos)</label>
                            <input type="number" name="reglas[margen_entrada]" value="<?php echo (int)$reglas['margen_entrada']; ?>" class="input-premium">
                            <p class="text-[9px] text-gray-400 mt-2 italic">* Ejemplo: Entrada 09:00 + 5 min = Impuntual a las 09:06.</p>
                        </div>
                    </div>
                </div>

                <!-- Salidas Anticipadas -->
                <div class="card-premium p-8 border-l-8 border-orange-600">
                    <h3 class="text-[10px] font-black text-orange-600 uppercase tracking-widest mb-6 flex items-center gap-2">
                        <i class="fas fa-door-open"></i> Reglas de Salida
                    </h3>
                    <div class="space-y-5">
                        <div class="flex items-center justify-between">
                            <label class="label-black !mb-0">Solo evaluar si tiene Horario Verificado</label>
                            <label class="switch">
                                <input type="hidden" name="reglas[solo_verificados_salida]" value="0">
                                <input type="checkbox" name="reglas[solo_verificados_salida]" value="1" <?php echo ($reglas['solo_verificados_salida']==1)?'checked':''; ?>>
                                <span class="slider"></span>
                            </label>
                        </div>
                        <div>
                            <label class="label-black">Margen de Salida Anticipada (Minutos)</label>
                            <input type="number" name="reglas[margen_salida]" value="<?php echo (int)$reglas['margen_salida']; ?>" class="input-premium">
                            <p class="text-[9px] text-gray-400 mt-2 italic">* Ejemplo: Salida 18:00 - 5 min = Penaliza si sale antes de 17:55.</p>
                        </div>
                    </div>
                </div>

                <!-- Horas Extras -->
                <div class="card-premium p-8 border-l-8 border-indigo-600">
                    <h3 class="text-[10px] font-black text-indigo-600 uppercase tracking-widest mb-6 flex items-center gap-2">
                        <i class="fas fa-bolt"></i> Horas Extras
                    </h3>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="label-black">Valor pts/hora</label>
                            <input type="number" step="0.1" name="reglas[valor_hora_extra]" value="<?php echo $reglas['valor_hora_extra']; ?>" class="input-premium">
                        </div>
                        <div>
                            <label class="label-black">Límite pts</label>
                            <input type="number" name="reglas[max_puntos_extras]" value="<?php echo $reglas['max_puntos_extras']; ?>" class="input-premium">
                        </div>
                    </div>
                </div>

                <div class="pt-4">
                    <button type="submit" name="guardar_todo" id="btn-save" class="w-full bg-slate-900 hover:bg-black text-white py-5 rounded-[1.5rem] font-black text-xs uppercase tracking-[0.2em] shadow-xl transition transform active:scale-95">
                        Aplicar Cambios Globales
                    </button>
                </div>
            </div>

        </div>
    </form>
</div>

<script>
    function calcularTotal() {
        const inputs = document.querySelectorAll('.input-peso');
        let total = 0;
        inputs.forEach(i => total += parseInt(i.value || 0));
        
        const badge = document.getElementById('total-badge');
        const btn = document.getElementById('btn-save');
        badge.innerText = total + " / 100";
        
        if (total === 100) {
            badge.className = "text-3xl font-black text-green-600 bg-green-50 border-2 border-green-200 px-6 py-2 rounded-2xl shadow-sm transition-all";
            btn.disabled = false;
            btn.className = "w-full bg-slate-900 hover:bg-black text-white py-5 rounded-[1.5rem] font-black text-xs uppercase tracking-[0.2em] shadow-xl transition transform active:scale-95";
        } else {
            badge.className = "text-3xl font-black text-red-600 bg-red-50 border-2 border-red-200 px-6 py-2 rounded-2xl shadow-sm transition-all";
            btn.disabled = true;
            btn.className = "w-full bg-slate-200 text-slate-400 py-5 rounded-[1.5rem] font-black text-xs uppercase tracking-[0.2em] cursor-not-allowed";
        }
    }

    document.addEventListener("DOMContentLoaded", () => {
        calcularTotal();
        setTimeout(() => document.body.classList.add('ready'), 100);
    });
</script>

<?php require 'layout_footer.php'; ?>