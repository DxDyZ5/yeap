<?php
// login.php - ENTRADA AL SISTEMA CON SEGURIDAD REFORZADA
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/core/db.php';

// Si ya está logueado, mandarlo al Dashboard
if (isset($_SESSION['user_id'])) {
    require_once __DIR__ . '/core/resources.php';
    $dashboard_url = base_url() . '/dashboard/index.php';
    header("Location: $dashboard_url");
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_input = trim($_POST['usuario']);
    $pass_input = $_POST['password'];

    if (!empty($user_input) && !empty($pass_input)) {
        try {
            // Buscar usuario y su rol
            $stmt = $pdo->prepare("
                SELECT u.*, r.nombre_rol 
                FROM usuarios_sistema u 
                JOIN roles_sistema r ON u.id_rol = r.id 
                WHERE u.usuario = ? AND u.activo = 1 
                LIMIT 1
            ");
            $stmt->execute([$user_input]);
            $user = $stmt->fetch();

            if ($user && password_verify($pass_input, $user['password'])) {
                // Credenciales válidas: Iniciar Sesión
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['usuario'] = $user['usuario'];
                $_SESSION['nombre_usuario'] = $user['nombre_real'];
                $_SESSION['rol'] = $user['nombre_rol'];
                
                // Registrar último acceso
                $pdo->prepare("UPDATE usuarios_sistema SET ultimo_acceso = NOW() WHERE id = ?")->execute([$user['id']]);
                
                // Redirigir al dashboard usando función base_url
                require_once __DIR__ . '/core/resources.php';
                $dashboard_url = base_url() . '/dashboard/index.php';
                header("Location: $dashboard_url");
                exit;
            } else {
                $error = "Usuario o contraseña incorrectos.";
            }
        } catch (Exception $e) {
            $error = "Error de sistema: " . $e->getMessage();
        }
    } else {
        $error = "Por favor, complete todos los campos.";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso al Sistema | RRHH</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;700;900&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #f8fafc; }
        .glass { background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(10px); }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4 bg-slate-50">
    <div class="w-full max-w-md">
        <!-- Logo -->
        <div class="text-center mb-8">
            <div class="w-20 h-20 bg-blue-600 rounded-[2rem] flex items-center justify-center text-white text-4xl mx-auto shadow-2xl shadow-blue-200 mb-4">
                <i class="fas fa-fingerprint"></i>
            </div>
            <h1 class="text-3xl font-black text-slate-800 tracking-tighter">CONTROL RRHH</h1>
            <p class="text-gray-400 text-xs font-bold uppercase tracking-widest mt-1">Gestión de Asistencia Telemicro</p>
        </div>

        <!-- Formulario -->
        <div class="glass p-10 rounded-[2.5rem] shadow-2xl border border-white relative overflow-hidden">
            <div class="absolute top-0 left-0 w-full h-2 bg-blue-600"></div>
            
            <form method="POST" class="space-y-6">
                <?php if($error): ?>
                    <div class="bg-red-50 text-red-600 p-4 rounded-2xl text-xs font-bold border border-red-100 flex items-center gap-3 animate-bounce">
                        <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                    </div>
                <?php endif; ?>

                <div>
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2 ml-1">Usuario de Sistema</label>
                    <div class="relative">
                        <i class="fas fa-user absolute left-5 top-1/2 -translate-y-1/2 text-slate-300"></i>
                        <input type="text" name="usuario" required autofocus class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl py-4 pl-12 pr-5 focus:border-blue-500 focus:bg-white transition-all outline-none font-bold text-slate-700 shadow-inner" placeholder="Ej: admin">
                    </div>
                </div>

                <div>
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2 ml-1">Contraseña</label>
                    <div class="relative">
                        <i class="fas fa-lock absolute left-5 top-1/2 -translate-y-1/2 text-slate-300"></i>
                        <input type="password" name="password" required class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl py-4 pl-12 pr-5 focus:border-blue-500 focus:bg-white transition-all outline-none font-bold text-slate-700 shadow-inner" placeholder="••••••••">
                    </div>
                </div>

                <div class="pt-4">
                    <button type="submit" class="w-full bg-slate-900 hover:bg-black text-white py-5 rounded-[1.5rem] font-black text-xs uppercase tracking-[0.2em] shadow-xl transition transform active:scale-95">
                        Iniciar Sesión
                    </button>
                </div>
            </form>
        </div>

        <p class="text-center mt-8 text-[10px] font-black text-slate-300 uppercase tracking-widest">
            Acceso restringido a personal autorizado
        </p>
    </div>
</body>
</html>