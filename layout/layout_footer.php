<?php
// layout_footer.php - SOLUCIÓN A WARNINGS Y DISPARADOR DE LAZY LOADING
// Inicialización segura para evitar "Undefined variable"
$sys_footer = $sys_footer ?? date('Y') . " • Departamento de Recursos Humanos";
?>
    <footer class="mt-20 border-t border-gray-100 py-10 no-print">
        <div class="container mx-auto px-4 text-center">
            <p class="text-[10px] font-black text-gray-300 uppercase tracking-[0.3em]">
                <?php 
                    // Casting a string para evitar Deprecated en PHP 8.1+
                    echo htmlspecialchars((string)$sys_footer); 
                ?>
            </p>
        </div>
    </footer>

    <!-- DISPARADOR GLOBAL DE LAZY LOADING (Efecto de entrada suave) -->
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            // Un pequeño delay asegura que el navegador haya aplicado todos los estilos
            setTimeout(() => {
                document.body.classList.add('ready');
            }, 80);
        });
    </script>
</body>
</html>