<?php
// layout_menu.php - MENÚ DE NAVEGACIÓN EN ESPAÑOL CON INTERFAZ ESTABLE
$current_page = basename($_SERVER['PHP_SELF']);
$user_rol = $_SESSION['rol'] ?? 'seguridad';

// Calcular ruta relativa desde templates/ hasta la raíz
// templates/ está en adesarrollo/templates/
// Necesitamos llegar a adesarrollo/
$relative_to_root = '../';
?>
<nav class="bg-white border-b border-gray-100 sticky top-0 z-50 no-print">
    <div class="container mx-auto px-4 flex justify-between items-center h-20">
        
        <!-- Logotipo / Marca -->
        <a href="<?php echo $relative_to_root; ?>dashboard/index.php" class="flex items-center gap-3 group">
            <div class="w-10 h-10 bg-blue-600 rounded-xl flex items-center justify-center text-white shadow-lg group-hover:scale-110 transition-transform">
                <i class="fas fa-fingerprint text-xl"></i>
            </div>
            <div>
                <span class="block font-black text-slate-800 text-lg leading-tight tracking-tighter">RRHH TELEMICRO</span>
                <span class="block text-[8px] text-gray-400 font-bold uppercase tracking-widest">Control de Asistencia</span>
            </div>
        </a>

        <!-- Enlaces Principales -->
        <div class="hidden lg:flex items-center gap-1">
            <a href="<?php echo $relative_to_root; ?>dashboard/index.php" class="px-5 py-2.5 rounded-xl text-xs font-black uppercase transition-colors <?php echo $current_page == 'index.php' ? 'bg-blue-50 text-blue-600' : 'text-gray-400 hover:text-slate-700'; ?>">
                Dashboard
            </a>
            <a href="<?php echo $relative_to_root; ?>usuarios/index.php" class="px-5 py-2.5 rounded-xl text-xs font-black uppercase transition-colors <?php echo (strpos($current_page, 'personal') !== false || strpos($current_page, 'empleado') !== false) ? 'bg-blue-50 text-blue-600' : 'text-gray-400 hover:text-slate-700'; ?>">
                Personal
            </a>
            <a href="<?php echo $relative_to_root; ?>asistencia/historial.php" class="px-5 py-2.5 rounded-xl text-xs font-black uppercase transition-colors <?php echo strpos($current_page, 'historial') !== false ? 'bg-blue-50 text-blue-600' : 'text-gray-400 hover:text-slate-700'; ?>">
                Asistencia
            </a>
            
            <?php if($user_rol == 'admin' || $user_rol == 'rrhh'): ?>
            <a href="<?php echo $relative_to_root; ?>admin/cargar_datos.php" class="px-5 py-2.5 rounded-xl text-xs font-black uppercase transition-colors <?php echo $current_page == 'cargar_datos.php' ? 'bg-blue-50 text-blue-600' : 'text-gray-400 hover:text-slate-700'; ?>">
                Carga Masiva
            </a>
            <a href="<?php echo $relative_to_root; ?>asistencia/ausencias_prolongadas.php" class="px-5 py-2.5 rounded-xl text-xs font-black uppercase transition-colors <?php echo strpos($current_page, 'ausencias') !== false ? 'bg-blue-50 text-blue-600' : 'text-gray-400 hover:text-slate-700'; ?>">
                Ausencias
            </a>
            <?php endif; ?>

            <!-- MENÚ DE ADMINISTRACIÓN (ESTABLE AL HOVER) -->
            <?php if($user_rol == 'admin'): ?>
            <div class="relative group ml-4">
                <button class="bg-slate-900 text-white px-6 py-3 rounded-2xl text-[10px] font-black uppercase flex items-center gap-2 hover:bg-black transition-all shadow-lg shadow-slate-100">
                    <i class="fas fa-shield-halved"></i> Administración
                    <i class="fas fa-chevron-down text-[8px] opacity-50 group-hover:rotate-180 transition-transform"></i>
                </button>
                
                <!-- 
                    CONTENEDOR DEL DESPLEGABLE: 
                    'pt-4' crea un puente invisible para que el mouse no pierda el foco.
                    Transición de opacidad y visibilidad para mayor estabilidad.
                -->
                <div class="absolute right-0 top-full pt-4 w-64 opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-300 ease-in-out">
                    <div class="bg-white rounded-3xl shadow-2xl border border-gray-100 py-4 overflow-hidden">
                        <a href="<?php echo $relative_to_root; ?>admin/usuarios_sistema.php" class="flex items-center gap-3 px-6 py-3 text-xs font-bold text-slate-700 hover:bg-blue-50 transition border-l-4 border-transparent hover:border-blue-500">
                            <i class="fas fa-users-cog w-5"></i> Gestión de Usuarios
                        </a>
                        <a href="<?php echo $relative_to_root; ?>admin/gestion_roles.php" class="flex items-center gap-3 px-6 py-3 text-xs font-bold text-slate-700 hover:bg-indigo-50 transition border-l-4 border-transparent hover:border-indigo-500">
                            <i class="fas fa-key w-5"></i> Roles y Permisos
                        </a>
                        <a href="<?php echo $relative_to_root; ?>usuarios/fusionar_usuarios.php" class="flex items-center gap-3 px-6 py-3 text-xs font-bold text-slate-700 hover:bg-purple-50 transition border-l-4 border-transparent hover:border-purple-500">
                            <i class="fas fa-object-group w-5"></i> Fusionar Perfiles
                        </a>
                        <div class="h-px bg-gray-50 my-2 mx-6"></div>
                        <a href="<?php echo $relative_to_root; ?>carnets_config.php" class="flex items-center gap-3 px-6 py-3 text-xs font-bold text-slate-700 hover:bg-yellow-50 transition border-l-4 border-transparent hover:border-yellow-500">
                            <i class="fas fa-id-card w-5"></i> Configurar Carnets
                        </a>
                        <div class="h-px bg-gray-50 my-2 mx-6"></div>
                        <a href="<?php echo $relative_to_root; ?>modulos/analisis/reporte_departamentos.php" class="flex items-center gap-3 px-6 py-3 text-xs font-bold text-slate-700 hover:bg-green-50 transition border-l-4 border-transparent hover:border-green-500">
                            <i class="fas fa-chart-bar w-5"></i> Reporte Departamentos
                        </a>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Perfil de Usuario y Salida -->
        <div class="flex items-center gap-4 border-l pl-6 ml-6 border-gray-100">
            <div class="text-right hidden xl:block">
                <span class="block text-[9px] font-black text-gray-400 uppercase tracking-tighter"><?php echo $user_rol; ?></span>
                <span class="block text-xs font-bold text-slate-700 leading-none"><?php echo htmlspecialchars((string)$_SESSION['nombre_usuario']); ?></span>
            </div>
            <a href="<?php echo $relative_to_root; ?>logout.php" class="w-10 h-10 rounded-xl bg-red-50 text-red-500 flex items-center justify-center hover:bg-red-500 hover:text-white transition-all shadow-sm" title="Cerrar Sesión">
                <i class="fas fa-power-off"></i>
            </a>
        </div>
    </div>
</nav>
