<?php
// db.php - CONFIGURACIÓN CENTRALIZADA + MOTOR DE EVALUACIÓN GLOBAL V2
ini_set('display_errors', 0);
error_reporting(E_ALL);

$host = 'localhost';
$db   = 'reynoteja_control_asistencia';
$user = 'reynoteja_carlos';
$pass = 'M22300435397'; 
$charset = 'utf8mb4';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, 
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false, 
    ]);
} catch (PDOException $e) { 
    die("Fallo de conexión: " . $e->getMessage()); 
}

/**
 * FUNCIÓN MAESTRA: Calcula la calificación centralizada para todo el sistema
 * Basada en 4 pilares: Asistencia, Puntualidad, Permanencia y Horas Extras
 */
function obtenerScoreGlobal($id, $pdo) {
    // 1. Cargar configuración de pesos y reglas
    $criterios = $pdo->query("SELECT variable_sistema, peso_maximo FROM criterios_evaluacion")->fetchAll(PDO::FETCH_KEY_PAIR);
    $reglas = $pdo->query("SELECT clave, valor FROM reglas_calculo")->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $peso_asistencia = (int)($criterios['asistencia'] ?? 30);
    $peso_puntualidad = (int)($criterios['puntualidad'] ?? 30);
    $peso_salida_ant = (int)($criterios['salida_anticipada'] ?? 20);
    $peso_extras = (int)($criterios['horas_extras'] ?? 20);

    // Margenes y Toggles
    $margen_in = (int)($reglas['margen_entrada'] ?? 5);
    $margen_out = (int)($reglas['margen_salida'] ?? 0);
    $solo_ver_in = (int)($reglas['solo_verificados_entrada'] ?? 1);
    $solo_ver_out = (int)($reglas['solo_verificados_salida'] ?? 1);

    // 2. Obtener data de asistencia (30 días)
    $fecha_ini = date('Y-m-d', strtotime('-30 days'));
    $fecha_fin = date('Y-m-d');
    $feriados = $pdo->query("SELECT fecha FROM feriados")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    
    // Datos del empleado para horarios teóricos
    $stmtE = $pdo->prepare("SELECT horario_entrada, horario_salida, horario_verificado FROM empleados WHERE id_reloj = ?");
    $stmtE->execute([$id]);
    $emp = $stmtE->fetch();
    
    $stmtA = $pdo->prepare("SELECT fecha, hora_entrada, hora_salida FROM asistencia WHERE id_empleado_reloj = ? AND fecha BETWEEN ? AND ?");
    $stmtA->execute([$id, $fecha_ini, $fecha_fin]);
    $asis_rows = [];
    while($r = $stmtA->fetch()) { $asis_rows[$r['fecha']] = $r; }

    $sum_p_asistencia = 0;
    $sum_p_puntualidad = 0;
    $sum_p_permanencia = 0;
    $total_segundos_extras = 0;
    $dias_validos = 0;

    $curr = strtotime($fecha_ini);
    $stop = strtotime($fecha_fin);

    while($curr <= $stop) {
        $f = date('Y-m-d', $curr);
        $dw = date('w', $curr);
        $es_hoy = ($f === date('Y-m-d'));

        // Omitir fines de semana y feriados
        if($dw != 0 && $dw != 6 && !in_array($f, $feriados)) {
            $p_asis = 0;
            $p_punt = 100; // Asumimos puntual si no hay horario que verificar
            $p_perm = 100;

            if(isset($asis_rows[$f])) {
                $h_in = $asis_rows[$f]['hora_entrada'];
                $h_out = $asis_rows[$f]['hora_salida'];

                if(!empty($h_in) && $h_in != '00:00:00') {
                    $p_asis = 100; // Marcó entrada = Presente

                    // Evaluación de Puntualidad (Solo si es verificado o la regla está apagada)
                    if (($solo_ver_in == 0 || $emp['horario_verificado'] == 1) && !empty($emp['horario_entrada'])) {
                        $teorico_in = strtotime($f . ' ' . $emp['horario_entrada']);
                        $real_in = strtotime($f . ' ' . $h_in);
                        $limite_in = $teorico_in + ($margen_in * 60);
                        
                        if ($real_in > $limite_in) {
                            $p_punt = 0; // Tardanza
                        }
                    }

                    // Evaluación de Salida Anticipada
                    if (!empty($h_out) && $h_out != '00:00:00') {
                        if (($solo_ver_out == 0 || $emp['horario_verificado'] == 1) && !empty($emp['horario_salida'])) {
                            $teorico_out = strtotime($f . ' ' . $emp['horario_salida']);
                            $real_out = strtotime($f . ' ' . $h_out);
                            $limite_out = $teorico_out - ($margen_out * 60);

                            if ($real_out < $limite_out) {
                                $p_perm = 0; // Salida anticipada
                            }
                        }

                        // Cálculo de Extras
                        if (!empty($emp['horario_salida'])) {
                            $teorico_out_full = strtotime($f . ' ' . $emp['horario_salida']);
                            $real_out_full = strtotime($f . ' ' . $h_out);
                            if ($real_out_full > $teorico_out_full) {
                                $total_segundos_extras += ($real_out_full - $teorico_out_full);
                            }
                        }
                    } else {
                        // Si no marcó salida (y no es hoy), penalizamos permanencia
                        $p_perm = $es_hoy ? 100 : 0;
                    }
                }
            }
            
            $sum_p_asistencia += $p_asis;
            $sum_p_puntualidad += $p_punt;
            $sum_p_permanencia += $p_perm;
            $dias_validos++;
        }
        $curr = strtotime('+1 day', $curr);
    }

    // Proporciones finales
    $score_asis = ($dias_validos > 0) ? ($sum_p_asistencia / $dias_validos) : 100;
    $score_punt = ($dias_validos > 0) ? ($sum_p_puntualidad / $dias_validos) : 100;
    $score_perm = ($dias_validos > 0) ? ($sum_p_permanencia / $dias_validos) : 100;

    $puntos_asis_fin = ($score_asis / 100) * $peso_asistencia;
    $puntos_punt_fin = ($score_punt / 100) * $peso_puntualidad;
    $puntos_perm_fin = ($score_perm / 100) * $peso_salida_ant;
    
    $horas_extras_total = $total_segundos_extras / 3600;
    $puntos_extras_fin = min($horas_extras_total * (float)($reglas['valor_hora_extra'] ?? 1), $peso_extras);

    $score_final = round($puntos_asis_fin + $puntos_punt_fin + $puntos_perm_fin + $puntos_extras_fin);

    return [
        'val' => (int)$score_final,
        'stars' => (int)round($score_final / 20),
        'asis' => round($score_asis, 1),
        'punt' => round($score_punt, 1),
        'perm' => round($score_perm, 1),
        'extras_h' => round($horas_extras_total, 1)
    ];
}