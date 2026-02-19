<?php
// nuevo_empleado.php - REGISTRO CON MULTIMEDIA QR, OCR Y EXPORTACIÓN XLS BASADA EN MODELO ZK
require_once __DIR__ . '/../core/auth.php'; 
require_once __DIR__ . '/../core/db.php'; 

verificarAcceso('editar');

$mensaje = '';
$tipo_msg = '';

// --- LÓGICA OCR (API GEMINI) ---
if (isset($_FILES['cedula_ocr']) && $_FILES['cedula_ocr']['error'] === 0) {
    header('Content-Type: application/json');
    try {
        $imageData = base64_encode(file_get_contents($_FILES['cedula_ocr']['tmp_name']));
        $apiKey = ""; 

        $payload = [
            "contents" => [[
                "role" => "user",
                "parts" => [
                    ["text" => "Extract data from this ID card. Return ONLY a strictly valid JSON object: 
                                { 'nombre': 'FULL NAME IN CAPS', 
                                  'cedula': 'NUMBERS ONLY', 
                                  'fecha_nacimiento': 'YYYY-MM-DD' }. 
                                Do not include markdown backticks. Sanitize 'cedula' removing dashes."],
                    ["inlineData" => ["mimeType" => "image/png", "data" => $imageData]]
                ]
            ]],
            "generationConfig" => ["temperature" => 0.1, "responseMimeType" => "application/json"]
        ];

        $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-preview-09-2025:generateContent?key=" . $apiKey);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        
        $response = curl_exec($ch);
        $result = json_decode($response, true);
        $raw_text = $result['candidates'][0]['content']['parts'][0]['text'] ?? '{}';
        
        if (preg_match('/\{.*\}/s', $raw_text, $matches)) { $raw_text = $matches[0]; }
        $data = json_decode($raw_text, true);

        $output = [
            "nombre" => isset($data['nombre']) ? strtoupper(trim($data['nombre'])) : '',
            "cedula" => isset($data['cedula']) ? preg_replace('/[^0-9]/', '', (string)$data['cedula']) : '',
            "fecha_nacimiento" => $data['fecha_nacimiento'] ?? ''
        ];
        echo json_encode($output);
        exit;
    } catch (Exception $e) {
        echo json_encode(["error" => $e->getMessage()]);
        exit;
    }
}

// --- GENERACIÓN DE ID AUTOMÁTICO ---
$hoy_prefix = date('Ymd');
$stmtId = $pdo->prepare("SELECT MAX(id_reloj) FROM empleados WHERE CAST(id_reloj AS CHAR) LIKE ?");
$stmtId->execute([$hoy_prefix . '%']);
$ultimo_id = $stmtId->fetchColumn();
$nuevo_id = $ultimo_id ? $ultimo_id + 1 : (int)($hoy_prefix . "01");

// --- GUARDAR EMPLEADO Y EXPORTAR ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear_empleado'])) {
    try {
        $id_reloj = $_POST['id_reloj'];
        $nombre_completo = strtoupper(trim($_POST['nombre']));
        $ced_clean = preg_replace('/[^0-9]/', '', $_POST['cedula']);
        $tarjeta = trim($_POST['tarjeta'] ?? '');

        // 1. Procesar Fotos
        if (isset($_FILES['foto_perfil']) && $_FILES['foto_perfil']['error'] === 0) {
            if (!is_dir('fotos')) mkdir('fotos', 0777, true);
            move_uploaded_file($_FILES['foto_perfil']['tmp_name'], "fotos/$ced_clean.jpg");
        }
        if (isset($_FILES['foto_cedula']) && $_FILES['foto_cedula']['error'] === 0) {
            if (!is_dir('fotos_cedula')) mkdir('fotos_cedula', 0777, true);
            move_uploaded_file($_FILES['foto_cedula']['tmp_name'], "fotos_cedula/$ced_clean.jpg");
        }

        // 2. Insertar en DB
        $sql = "INSERT INTO empleados (
                    id_reloj, nombre_completo, cedula, telefono, fecha_nacimiento, 
                    departamento, cargo, empresa, referido_por, estatus_nomina, 
                    fecha_ingreso, tipo_personal, horario_entrada, horario_salida, tarjeta
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $pdo->prepare($sql)->execute([
            $id_reloj, $nombre_completo, $ced_clean,
            trim($_POST['telefono'] ?? ''), !empty($_POST['fecha_nacimiento']) ? $_POST['fecha_nacimiento'] : null,
            'Tecnologia', strtoupper(trim($_POST['cargo'] ?? '')),
            strtoupper(trim($_POST['empresa'] ?? '')), trim($_POST['referido_por'] ?? ''),
            'En Nomina', !empty($_POST['fecha_ingreso']) ? $_POST['fecha_ingreso'] : date('Y-m-d'),
            'planta', $_POST['horario_entrada'] ?: '09:00:00', $_POST['horario_salida'] ?: '18:00:00', $tarjeta
        ]);

        // 3. EXPORTACIÓN XLS BASADA EN MODELO (26 COLUMNAS TABULADAS)
        if (!is_dir('nuevo_ingreso')) mkdir('nuevo_ingreso', 0777, true);
        
        // Separación de nombre y apellido
        $partes_n = explode(' ', $nombre_completo, 2);
        $solo_n = $partes_n[0];
        $solo_a = $partes_n[1] ?? '';

        $filename = "nuevo_ingreso/Usuarios_" . date('YmdHis') . ".xls";
        $fp = fopen($filename, 'w');
        
        // BOM UTF-8 para compatibilidad Excel
        fwrite($fp, "\xEF\xBB\xBF");
        
        $t = "\t"; // TABULADOR

        // Fila 1: Título Usuarios
        fwrite($fp, "Usuarios" . str_repeat($t, 25) . "\n");

        // Fila 2: Cabeceras del modelo
        $headers = [
            'ID', 'Nombre', 'Apellido', 'ID de Departamento', 'Nombre de Departamento', 
            'Género', 'Cumpleaños', 'Contraseña', 'Tipo de Documento', 'Documento / Cédula', 
            'Tarjeta', 'Placa Vehicular', 'Email', 'Código de Auto Gestión', 'Celular', 
            'Tipo de Usuario', 'Contratación', 'Puesto', 'IDENTIFICACION 1', 'Calle', 
            'Lugar de Nacimiento', 'País', 'Teléfono de Casa', 'Dirección de Casa', 
            'Teléfono de Oficina', 'Dirección de Oficina'
        ];
        fwrite($fp, implode($t, $headers) . "\n");

        // Fila 3: Datos mapeados donde decía RELLENAR en el modelo
        $data_row = [
            $id_reloj,      // 1. ID (RELLENAR)
            $solo_n,        // 2. Nombre (RELLENAR)
            $solo_a,        // 3. Apellido (RELLENAR)
            'Tecnologia',   // 4. ID de Depto (Fijo según modelo)
            'Tecnologia',   // 5. Nombre de Depto (Fijo según modelo)
            '',             // 6. Género
            '',             // 7. Cumpleaños
            '',             // 8. Contraseña
            '1',            // 9. Tipo de Documento (Fijo según modelo)
            $ced_clean,     // 10. Documento / Cédula (RELLENAR)
            $tarjeta,       // 11. Tarjeta (RELLENAR)
            '', '', '', '', '', '', '', '', '', '', '', '', '', '', '' // 12-26 Vacíos
        ];
        fwrite($fp, implode($t, $data_row) . "\n");
        fclose($fp);

        redirect_to_module('personal', 'editar.php', ['id' => $id_reloj, 'msg' => 'success']);
        exit;
    } catch (Exception $e) { $mensaje = "Error: " . $e->getMessage(); }
}

// URL QR para Móvil
$token_qr = md5($nuevo_id . "fixed_salt_v_model"); 
$url_base_qr = base_url() . "/datos/subir_foto_movil.php?id=$nuevo_id&token=$token_qr";

require 'layout_head.php';
?>

<style>
    body { opacity: 0; transition: opacity 0.3s ease; }
    body.ready { opacity: 1; }
    
    /* Labels en Negro Puro */
    label { color: #000000 !important; font-weight: 950 !important; font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.05em; display: block; margin-bottom: 0.3rem; margin-left: 0.3rem; }
    
    /* Inputs con datos en Gris Pizarra Oscuro */
    .input-premium { 
        background-color: #f8fafc; border: 1px solid #e2e8f0; 
        color: #1e293b; font-weight: 700; padding: 0.75rem 1rem;
        border-radius: 0.85rem; width: 100%; font-size: 0.85rem;
    }
    .input-premium:focus { border-color: #3b82f6; outline: none; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1); }
    
    .card-main { background: white; border-radius: 1.5rem; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.05); padding: 1.5rem; border: 1px solid #f1f5f9; }
    
    .multimedia-zone { 
        background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); 
        border-radius: 2.5rem; padding: 2rem; color: white; 
        box-shadow: 0 25px 50px -12px rgba(15, 23, 42, 0.4); margin-bottom: 2rem; border: 1px solid rgba(255,255,255,0.05);
    }

    /* Área circular de foto ampliada */
    .photo-container {
        width: 220px; height: 220px; position: relative; cursor: pointer; transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        border-radius: 50%; background: rgba(255,255,255,0.05); border: 4px dashed rgba(255,255,255,0.2);
        display: flex; align-items: center; justify-content: center; overflow: hidden; margin: 0 auto;
    }
    .photo-container:hover { border-color: #3b82f6; background: rgba(59, 130, 246, 0.1); transform: scale(1.05); }
    .photo-overlay {
        position: absolute; inset: 0; background: rgba(0,0,0,0.6); display: flex; flex-direction: column; 
        align-items: center; justify-content: center; opacity: 0; transition: opacity 0.3s ease;
    }
    .photo-container:hover .photo-overlay { opacity: 1; }
</style>

<div id="modal-qr" class="fixed inset-0 bg-slate-900/70 backdrop-blur-md z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white w-full max-w-sm rounded-[2.5rem] shadow-2xl p-8 text-center relative animate-fade-in">
        <button onclick="document.getElementById('modal-qr').classList.add('hidden')" class="absolute top-4 right-4 text-gray-400 hover:text-gray-600 text-xl">&times;</button>
        <div id="qr-icon-container" class="w-16 h-16 bg-blue-50 text-blue-600 rounded-2xl flex items-center justify-center mx-auto mb-4"><i class="fas fa-qrcode text-2xl"></i></div>
        <h3 id="qr-title" class="text-xl font-black text-slate-800 mb-1">Carga Móvil</h3>
        <p id="qr-desc" class="text-[10px] text-gray-400 font-bold uppercase tracking-widest mb-6">Usa tu celular para la captura</p>
        <div class="bg-slate-50 p-4 rounded-3xl mb-6 border border-slate-100 shadow-inner">
            <img id="qr-image" class="mx-auto w-48 h-48 mix-blend-multiply">
        </div>
        <button onclick="location.reload()" class="w-full bg-slate-900 text-white py-3 rounded-xl font-black text-[10px] uppercase tracking-widest transition hover:bg-black">Sincronizar</button>
    </div>
</div>

<div class="container mx-auto px-4 pb-12 max-w-4xl">
    
    <div class="flex items-center gap-4 mb-8 mt-6">
        <div class="w-12 h-12 bg-blue-600 rounded-2xl flex items-center justify-center text-white shadow-lg"><i class="fas fa-user-plus text-xl"></i></div>
        <div>
            <h1 class="text-2xl font-black text-slate-800 tracking-tighter leading-tight">Nuevo Ingreso</h1>
            <p class="text-[10px] text-gray-400 font-black uppercase tracking-widest">Generación de Perfil según Modelo XLS</p>
        </div>
    </div>

    <form method="POST" id="formEmpleado" enctype="multipart/form-data">
        
        <div class="multimedia-zone">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-10 items-center">
                
                <div class="flex flex-col items-center space-y-4">
                    <label style="color: #94a3b8; margin-left: 0;">Fotografía del Empleado</label>
                    <div class="relative">
                        <div id="profile-preview" class="photo-container" onclick="document.getElementById('input_foto_perfil').click()">
                            <i class="fas fa-user text-6xl text-white/10" id="placeholder-icon"></i>
                            <div class="photo-overlay"><i class="fas fa-camera text-3xl mb-1 text-blue-400"></i><span class="text-[10px] font-black uppercase">Subir Foto</span></div>
                        </div>
                        <button type="button" onclick="mostrarModalQR('perfil')" class="absolute bottom-2 right-2 w-12 h-12 bg-blue-600 hover:bg-blue-500 text-white rounded-2xl shadow-xl flex items-center justify-center border-4 border-slate-900 transition-all hover:scale-110"><i class="fas fa-qrcode"></i></button>
                    </div>
                    <input type="file" name="foto_perfil" id="input_foto_perfil" accept="image/*" class="hidden" onchange="previewImage(this, 'profile-preview')">
                </div>

                <div class="space-y-4">
                    <label style="color: #94a3b8; margin-left: 0;">Captura de Cédula</label>
                    <div id="cedula-preview-cont" class="hidden h-28 w-full bg-white/5 rounded-2xl border border-white/10 overflow-hidden shadow-inner mb-4">
                        <img id="img_cedula_preview" class="w-full h-full object-contain">
                    </div>
                    <div class="bg-white/5 p-4 rounded-2xl border border-white/10 space-y-3">
                        <div class="flex gap-2">
                            <button type="button" onclick="document.getElementById('input_cedula_img').click()" class="flex-1 bg-white/10 hover:bg-white/20 text-white py-2.5 rounded-xl font-black text-[9px] uppercase tracking-widest flex items-center justify-center gap-2"><i class="fas fa-upload"></i> Subir Doc</button>
                            <button type="button" onclick="mostrarModalQR('cedula_ocr')" class="w-12 h-12 bg-blue-600 hover:bg-blue-500 text-white rounded-xl flex items-center justify-center shadow-lg transition-all hover:scale-110"><i class="fas fa-qrcode"></i></button>
                        </div>
                        <button type="button" onclick="lanzarOCR()" id="btn-ocr" class="w-full bg-emerald-600 hover:bg-emerald-500 text-white py-3 rounded-xl font-black text-[10px] uppercase tracking-widest transition flex items-center justify-center gap-2 shadow-lg shadow-emerald-900/20"><i class="fas fa-bolt"></i> Iniciar Escaneo IA</button>
                    </div>
                    <input type="file" name="foto_cedula" id="input_cedula_img" accept="image/*" class="hidden" onchange="previewImage(this, 'cedula-preview-cont')">
                </div>
            </div>
        </div>

        <div class="card-main">
            <div class="flex justify-between items-center mb-8 border-b border-slate-50 pb-6">
                <h2 class="text-lg font-black text-slate-800 uppercase tracking-tighter">Información Maestro</h2>
                <div class="bg-blue-50 px-5 py-2 rounded-xl border border-blue-100 text-center">
                    <label class="block text-[8px] mb-0 font-black">ID Sugerido</label>
                    <input type="text" name="id_reloj" value="<?php echo $nuevo_id; ?>" class="bg-transparent border-none p-0 text-lg font-black text-blue-700 focus:ring-0 w-24 text-center">
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                <div><label>Nombre Completo</label><input type="text" name="nombre" id="nombre" required placeholder="NOMBRE DEL EMPLEADO" class="input-premium uppercase"></div>
                <div><label>Número de Cédula</label><input type="text" name="cedula" id="cedula" required placeholder="SÓLO NÚMEROS" class="input-premium"></div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                <div><label>F. Nacimiento</label><input type="date" name="fecha_nacimiento" id="fecha_nacimiento" class="input-premium"></div>
                <div><label>Teléfono</label><input type="text" name="telefono" placeholder="8090000000" class="input-premium"></div>
                <div><label>F. Ingreso</label><input type="date" name="fecha_ingreso" value="<?php echo date('Y-m-d'); ?>" class="input-premium"></div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                <div><label>Empresa</label><input type="text" name="empresa" placeholder="TELEMICRO" class="input-premium uppercase"></div>
                <div><label>Departamento</label><input type="text" name="departamento" value="Tecnologia" readonly class="input-premium bg-slate-100 uppercase"></div>
                <div><label>Cargo / Puesto</label><input type="text" name="cargo" placeholder="CARGO ACTUAL" class="input-premium uppercase"></div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-10">
                <div><label>Tarjeta Acceso</label><input type="text" name="tarjeta" placeholder="0000000" class="input-premium"></div>
                <div><label>Horario Entrada</label><input type="time" name="horario_entrada" value="09:00" class="input-premium"></div>
                <div><label>Horario Salida</label><input type="time" name="horario_salida" value="18:00" class="input-premium"></div>
            </div>

            <div class="pt-6 border-t border-slate-50">
                <button type="submit" name="crear_empleado" class="w-full bg-slate-900 hover:bg-black text-white py-4 rounded-2xl font-black text-xs uppercase tracking-[0.2em] shadow-xl transition transform active:scale-[0.98]">
                    Crear y Exportar .XLS
                </button>
            </div>
        </div>
    </form>
</div>

<script>
    document.addEventListener("DOMContentLoaded", () => document.body.classList.add('ready'));

    function previewImage(input, targetId) {
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = e => {
                const target = document.getElementById(targetId);
                if (targetId.includes('preview-cont')) {
                    target.classList.remove('hidden');
                    document.getElementById('img_cedula_preview').src = e.target.result;
                } else {
                    document.getElementById('placeholder-icon').classList.add('hidden');
                    target.innerHTML = `<img src="${e.target.result}" class="w-full h-full object-cover">
                        <div class="photo-overlay"><i class="fas fa-camera text-2xl mb-2 text-blue-400"></i><span class="text-[10px] font-black uppercase tracking-tighter">Cambiar Foto</span></div>`;
                }
            };
            reader.readAsDataURL(input.files[0]);
        }
    }

    function mostrarModalQR(type) {
        let url = "<?php echo $url_base_qr; ?>&type=" + type;
        document.getElementById('qr-image').src = "https://api.qrserver.com/v1/create-qr-code/?size=400x400&data=" + encodeURIComponent(url);
        document.getElementById('modal-qr').classList.remove('hidden');
    }

    function lanzarOCR() {
        const fileInput = document.getElementById('input_cedula_img');
        if (!fileInput.files[0]) { alert("Selecciona la foto de la cédula."); return; }
        const formData = new FormData();
        formData.append('cedula_ocr', fileInput.files[0]);
        fetch('nuevo_empleado.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.nombre) document.getElementById('nombre').value = data.nombre;
            if (data.cedula) document.getElementById('cedula').value = data.cedula.replace(/[^0-9]/g, '');
            if (data.fecha_nacimiento) document.getElementById('fecha_nacimiento').value = data.fecha_nacimiento;
        })
        .catch(err => console.error(err));
    }
</script>

<?php require 'layout_footer.php'; ?>