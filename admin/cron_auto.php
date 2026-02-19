<?php
// cron_auto.php - SCRIPT ROBUSTO AUTOMÁTICO (MOTOR UNIFICADO ACTUALIZADO)
// Se ejecuta en segundo plano. No requiere sesión. Soporta formato accTransactionToday.

date_default_timezone_set('America/Santo_Domingo');
ini_set('display_errors', 1);
error_reporting(E_ALL);
ini_set('memory_limit', '1024M');
set_time_limit(900);

// Rutas
$base_dir = __DIR__;
$vendor_file = $base_dir . '/vendor/autoload.php';
$datanew_dir = $base_dir . '/datanew';

echo "\n========================================\n";
echo "[" . date('Y-m-d H:i:s') . "] INICIANDO ESCANEO AUTOMÁTICO\n";
echo "========================================\n";

$use_library = file_exists($vendor_file);
if ($use_library) {
    require $vendor_file;
    echo "Librería Excel cargada.\n";
} else {
    echo "AVISO: No se encuentra vendor/autoload.php. Solo se procesarán CSVs.\n";
}

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

// --- CONEXIÓN BD ---
$host = 'localhost';
$db   = 'reynoteja_control_asistencia';
$user = 'reynoteja_carlos';
$pass = 'M22300435397'; 
$charset = 'utf8mb4';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, 
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) { 
    die("Error de conexión: " . $e->getMessage() . "\n"); 
}

// --- HELPERS PARA EL MOTOR ---

function esIdValidoCron($val, $valid_ids) {
    if(!is_scalar($val)) return false; 
    $s = trim((string)$val); 
    $clean = ltrim($s, '0'); 
    return isset($valid_ids[$s]) || isset($valid_ids[$clean]) || (ctype_digit($clean) && isset($valid_ids[(int)$clean]));
}

function esFechaCron($val) { 
    return preg_match('/^(\d{4}[-\/]\d{2}[-\/]\d{2}|\d{2}[-\/]\d{2}[-\/]\d{4})[ T]\d{2}:\d{2}:\d{2}/', trim($val)); 
}

function validarYAgregarCron(&$agrupado, $raw_id, $raw_date, $valid_ids, &$stats) {
    $id_str = trim((string)$raw_id); 
    $id_clean = ltrim($id_str, '0'); 
    $final_id = null;
    
    if(isset($valid_ids[$id_str])) $final_id = $id_str; 
    elseif(isset($valid_ids[$id_clean])) $final_id = $id_clean; 
    elseif(ctype_digit($id_clean) && isset($valid_ids[(int)$id_clean])) $final_id = (int)$id_clean;
    
    if ($final_id === null) return false;

    $clean_date = str_replace(['T', '/'], [' ', '-'], $raw_date); 
    $ts = strtotime($clean_date);
    
    if ($ts && date('Y', $ts) > 2000) {
        $fecha = date('Y-m-d', $ts); 
        $hora = date('H:i:s', $ts);
        
        if (!isset($agrupado[$final_id][$fecha])) {
            $agrupado[$final_id][$fecha] = ['min' => $hora, 'max' => $hora, 'count' => 0];
        }
        
        if ($hora < $agrupado[$final_id][$fecha]['min']) $agrupado[$final_id][$fecha]['min'] = $hora;
        if ($hora > $agrupado[$final_id][$fecha]['max']) $agrupado[$final_id][$fecha]['max'] = $hora;
        
        $agrupado[$final_id][$fecha]['count']++; 
        $stats['encontrados']++; 
        return true;
    }
    return false;
}

function procesarJsonRecursivoCron($data, $valid_ids, &$agrupado, &$stats) {
    if (is_array($data)) {
        $date_found = null; $id_found = null;
        foreach ($data as $key => $val) {
            if (is_array($val)) { procesarJsonRecursivoCron($val, $valid_ids, $agrupado, $stats); continue; }
            if (!$date_found && is_string($val) && esFechaCron($val)) $date_found = $val;
            if (!$id_found && in_array(strtolower($key), ['pin', 'user_id', 'id'])) {
                if(esIdValidoCron($val, $valid_ids)) $id_found = $val;
            }
        }
        if ($date_found && $id_found) validarYAgregarCron($agrupado, $id_found, $date_found, $valid_ids, $stats);
    }
}

function parsearTextoPlanoCron($content, $valid_ids, &$agrupado, &$stats) {
    if (preg_match_all('/(\d{4}[-\/]\d{2}[-\/]\d{2}|\d{2}[-\/]\d{2}[-\/]\d{4})[ T]\d{2}:\d{2}:\d{2}/', $content, $dates)) {
        $clean_content = $content;
        foreach ($dates[0] as $d) $clean_content = str_replace($d, ' ', $clean_content);
        if (preg_match_all('/\b(\d+)\b/', $clean_content, $nums)) {
            foreach ($nums[1] as $n) { 
                if (esIdValidoCron($n, $valid_ids)) { 
                    foreach($dates[0] as $date_str) { validarYAgregarCron($agrupado, $n, $date_str, $valid_ids, $stats); } 
                    return true; 
                } 
            }
        }
    }
    return false;
}

// --- FUNCIÓN PRINCIPAL DE PROCESAMIENTO ---

function procesarArchivoCron($archivo, $pdo, $valid_ids, $use_library) {
    try {
        $ext = strtolower(pathinfo($archivo, PATHINFO_EXTENSION));
        $hoja = []; 

        if ($ext === 'csv' || $ext === 'txt') {
            $delimiter = ",";
            if (($handle = fopen($archivo, "r")) !== FALSE) {
                $line = fgets($handle);
                if (substr_count($line, "\t") > substr_count($line, ",")) $delimiter = "\t";
                elseif (substr_count($line, ";") > substr_count($line, ",")) $delimiter = ";";
                rewind($handle);
                while (($data = fgetcsv($handle, 0, $delimiter)) !== FALSE) {
                    $clean_data = array_map(function($val) {
                        return trim(preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $val ?? ''));
                    }, $data);
                    $hoja[] = $clean_data;
                }
                fclose($handle);
            }
        } else {
            if (!$use_library) return ['status' => false, 'count' => 0, 'msg' => "Sin librería Excel"];
            $spreadsheet = IOFactory::load($archivo);
            $hoja = $spreadsheet->getActiveSheet()->toArray();
        }

        $agrupado = [];
        $stats = ['leidos' => 0, 'encontrados' => 0, 'ids_invalidos' => 0];
        $col_tiempo = -1; $col_id = -1; $col_log = -1; $found_header = false;
        
        foreach ($hoja as $row_idx => $row) {
            if (empty($row)) continue;
            
            if (!$found_header) {
                foreach ($row as $key => $cell) {
                    $cell = trim(strtolower((string)$cell)); 
                    if (in_array($cell, ['msg', 'raw_text', 'response', 'body', 'ret', 'data', 'content', 'message'])) $col_log = $key;
                    if (in_array($cell, ['tiempo', 'time', 'fecha', 'date', 'fecha/hora', 'datetime', 'checktime', 'hora'])) $col_tiempo = $key;
                    if (in_array($cell, ['id', 'no.', 'ac-no.', 'enrol. no', 'user id', 'pin', 'usuario', 'empleado'])) $col_id = $key;
                }
                if ($col_log !== -1 || ($col_tiempo !== -1 && $col_id !== -1)) { $found_header = true; continue; }
            }
            
            $stats['leidos']++;
            $row_processed = false;

            // Detección de Columna de LOG (Donde suele estar el formato accTransactionToday)
            if ($col_log !== -1 && isset($row[$col_log])) {
                $content = $row[$col_log];
                
                // --- SOPORTE ESPECÍFICO PARA FORMATO BIOMÉTRICO (accTransactionToday) ---
                if (strpos($content, "['") !== false) {
                    $clean = trim($content, "[] ");
                    $parts = str_getcsv($clean, ",", "'");
                    // Índice 1: Fecha/Hora, Índice 3: ID de empleado (PIN)
                    $found_d = isset($parts[1]) ? trim($parts[1]) : null;
                    $found_i = isset($parts[3]) ? trim($parts[3]) : null;
                    
                    if ($found_d && $found_i && esFechaCron($found_d) && esIdValidoCron($found_i, $valid_ids)) {
                        validarYAgregarCron($agrupado, $found_i, $found_d, $valid_ids, $stats);
                        $row_processed = true;
                    }
                }

                if (!$row_processed) {
                    $json_data = json_decode(trim($content), true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        procesarJsonRecursivoCron($json_data, $valid_ids, $agrupado, $stats); $row_processed = true;
                    } else {
                        if(parsearTextoPlanoCron($content, $valid_ids, $agrupado, $stats)) $row_processed = true;
                    }
                }
            }
            
            // Detección por columnas separadas (ID y Fecha)
            if (!$row_processed && ($col_tiempo !== -1 && $col_id !== -1)) {
                $raw_date = $row[$col_tiempo] ?? ''; 
                $raw_id = $row[$col_id] ?? ''; 
                $fecha_final = (is_numeric($raw_date) && $ext !== 'csv' && $ext !== 'txt') ? Date::excelToDateTimeObject($raw_date)->format('Y-m-d H:i:s') : $raw_date;
                if(validarYAgregarCron($agrupado, $raw_id, $fecha_final, $valid_ids, $stats)) $row_processed = true;
            }
            
            // Intento final: Texto plano en toda la fila
            if (!$row_processed) { 
                $fila_entera = implode(" ", $row); 
                parsearTextoPlanoCron($fila_entera, $valid_ids, $agrupado, $stats); 
            }
        }

        if (!empty($agrupado)) {
            $pdo->beginTransaction();
            $sqlInsert = "INSERT INTO asistencia (id_empleado_reloj, fecha, hora_entrada, hora_salida, total_eventos) 
                          VALUES (?, ?, ?, ?, ?)
                          ON DUPLICATE KEY UPDATE 
                          hora_entrada = IF(hora_entrada IS NULL OR hora_entrada = '00:00:00', VALUES(hora_entrada), LEAST(hora_entrada, VALUES(hora_entrada))),
                          hora_salida  = GREATEST(IFNULL(hora_salida, '00:00:00'), VALUES(hora_salida)),
                          total_eventos = total_eventos + VALUES(total_eventos)";
            $stmt = $pdo->prepare($sqlInsert);
            $total = 0;
            foreach ($agrupado as $id => $fechas) { 
                foreach ($fechas as $fecha => $info) { 
                    $stmt->execute([$id, $fecha, $info['min'], $info['max'], $info['count']]); 
                    $total++; 
                } 
            }
            $pdo->commit();
            return ['status' => true, 'count' => $total, 'msg' => "OK"];
        }
        return ['status' => false, 'count' => 0, 'msg' => "Datos insuficientes."];

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['status' => false, 'count' => 0, 'msg' => $e->getMessage()];
    }
}

// --- EJECUCIÓN DEL SCANNER ---

if (!is_dir($datanew_dir)) mkdir($datanew_dir, 0777, true);
$archivos = glob("$datanew_dir/*.{csv,xls,xlsx,txt}", GLOB_BRACE);

if (empty($archivos)) {
    echo "Directorio /datanew vacío. Esperando archivos...\n";
} else {
    // Cache de IDs válidos para optimizar rendimiento
    $valid_ids = $pdo->query("SELECT id_reloj FROM empleados")->fetchAll(PDO::FETCH_COLUMN);
    $valid_ids = array_flip($valid_ids);
    
    foreach ($archivos as $archivo) {
        $nombre = basename($archivo);
        echo "Procesando: $nombre ... ";
        $res = procesarArchivoCron($archivo, $pdo, $valid_ids, $use_library);
        
        if ($res['status']) {
            if (unlink($archivo)) {
                echo "OK ({$res['count']} eventos). Archivo eliminado.\n";
            } else {
                echo "OK ({$res['count']} eventos). ERROR al eliminar archivo.\n";
            }
        } else {
            echo "ERROR: " . $res['msg'] . "\n";
        }
    }
}

echo "========================================\n";
echo "[" . date('Y-m-d H:i:s') . "] TAREA FINALIZADA\n";
echo "========================================\n";