<?php
// exportar_datos.php - VERSIÓN: EXPORTACIÓN DIRECTA CON ID RELOJ COMO PRIMERA COLUMNA
require 'auth.php';
verificarPermiso(['admin', 'rrhh']);

/** * LIMPIEZA DE BÚFER:
 * Es vital que no haya espacios en blanco ni errores antes de enviar los headers del Excel.
 */
while (ob_get_level()) { ob_end_clean(); }
ob_start();

if (!file_exists('vendor/autoload.php')) { die("Error: Librería PhpSpreadsheet no encontrada. Ejecute composer install."); }
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    try {
        $selected_ids = !empty($_POST['selected_ids']) ? explode(',', $_POST['selected_ids']) : [];
        
        // Definición de Columnas: ID RELOJ es ahora la primera columna obligatoria
        $headers = ['ID RELOJ', 'EMPRESA', 'NOMBRE COMPLETO', 'CEDULA', 'INGRESO', 'CUENTA', 'NACIMIENTO', 'SUELDO', 'DEPARTAMENTO', 'CARGO'];
        $campos_db = ['id_reloj', 'empresa', 'nombre_completo', 'cedula', 'fecha_ingreso', 'cuenta', 'fecha_nacimiento', 'sueldo', 'departamento', 'cargo'];

        if (!empty($selected_ids)) {
            // Caso: Selección manual por checkboxes
            $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
            $sql = "SELECT " . implode(', ', $campos_db) . " FROM empleados WHERE id_reloj IN ($placeholders) ORDER BY empresa, nombre_completo";
            $params = $selected_ids;
        } else {
            // Caso: Exportar lo filtrado en la vista (Si no hay selección manual)
            $termino = trim((string)($_POST['q'] ?? ''));
            $f_empresa = $_POST['f_empresa'] ?? '';
            $f_depto = $_POST['f_depto'] ?? '';
            $f_estatus = $_POST['f_estatus'] ?? 'En Nomina';
            $f_tipo = $_POST['f_tipo'] ?? '';
            $f_color = $_POST['f_color'] ?? '';
            $sin_cedula = $_POST['sin_cedula'] ?? '0';

            $where = ["(nombre_completo LIKE ? OR cedula LIKE ? OR id_reloj LIKE ?)"];
            $where[] = "nombre_completo IS NOT NULL AND TRIM(nombre_completo) != '' AND nombre_completo NOT LIKE 'INV%'";
            $params = ["%$termino%", "%$termino%", "%$termino%"];

            if ($f_empresa) { $where[] = "empresa = ?"; $params[] = $f_empresa; }
            if ($f_depto) { $where[] = "departamento = ?"; $params[] = $f_depto; }
            if ($f_estatus !== 'todos') { $where[] = "estatus_nomina = ?"; $params[] = $f_estatus; }
            if ($f_tipo !== '') { $where[] = "tipo_personal = ?"; $params[] = $f_tipo; }
            if ($sin_cedula === '0') { $where[] = "cedula != '' AND cedula IS NOT NULL"; }

            $sql = "SELECT " . implode(', ', $campos_db) . " FROM empleados WHERE " . implode(' AND ', $where) . " ORDER BY empresa, departamento, nombre_completo";
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $data_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Filtro por COLOR (Lógica de Asistencia aplicada en PHP para el Excel)
        if (empty($selected_ids) && $f_color !== '') {
            function obtenerCalificacionExport($id, $pdo) {
                $fecha_ini = date('Y-m-d', strtotime('-30 days'));
                $fecha_fin = date('Y-m-d');
                $stmt = $pdo->prepare("SELECT fecha, hora_entrada, hora_salida FROM asistencia WHERE id_empleado_reloj = ? AND fecha BETWEEN ? AND ?");
                $stmt->execute([$id, $fecha_ini, $fecha_fin]);
                $asistencias = $stmt->fetchAll();
                $map = []; foreach($asistencias as $a) $map[$a['fecha']] = $a;
                $suma = 0; $dias = 0;
                $curr = strtotime($fecha_fin); $stop = strtotime($fecha_ini);
                while($curr >= $stop) {
                    $f = date('Y-m-d', $curr);
                    if (date('w', $curr) != 0 && date('w', $curr) != 6) {
                        $p = 100;
                        if (!isset($map[$f])) $p = 0;
                        elseif (empty($map[$f]['hora_entrada'])) $p = 0;
                        elseif (empty($map[$f]['hora_salida']) || $map[$f]['hora_salida'] == '00:00:00') $p = 60;
                        $suma += $p; $dias++;
                    }
                    $curr = strtotime('-1 day', $curr);
                }
                return ($dias > 0) ? round($suma / $dias) : 100;
            }

            $data_rows = array_filter($data_rows, function($row) use ($f_color, $pdo) {
                $score = obtenerCalificacionExport($row['id_reloj'], $pdo);
                if ($f_color === 'verde') return $score >= 80;
                if ($f_color === 'amarillo') return $score >= 50 && $score < 80;
                if ($f_color === 'rojo') return $score < 50;
                return true;
            });
        }

        // Generar Excel
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Lista Empleados');

        // Estilo Encabezado (Verde)
        $styleHeader = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '059669']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
        ];

        // Escribir Cabeceras
        foreach ($headers as $idx => $title) {
            $colLetter = Coordinate::stringFromColumnIndex($idx + 1);
            $sheet->setCellValue($colLetter . '1', $title);
            $sheet->getColumnDimension($colLetter)->setAutoSize(true);
        }
        $sheet->getStyle('A1:' . Coordinate::stringFromColumnIndex(count($headers)) . '1')->applyFromArray($styleHeader);

        // Escribir Datos
        $fila = 2;
        foreach ($data_rows as $row) {
            $colIdx = 1;
            foreach ($campos_db as $campo) {
                $valor = $row[$campo] ?? '';
                $colLetter = Coordinate::stringFromColumnIndex($colIdx);
                
                // Formato de texto para datos numéricos largos (Cédula, Cuenta, ID Reloj) para evitar notación científica
                if (in_array($campo, ['cedula', 'cuenta', 'id_reloj'])) {
                    $sheet->setCellValueExplicit($colLetter . $fila, $valor, DataType::TYPE_STRING);
                } else {
                    $sheet->setCellValue($colLetter . $fila, $valor);
                }
                $colIdx++;
            }
            $fila++;
        }

        // --- ENCABEZADOS PARA DESCARGA ---
        $filename = "Reporte_Empleados_" . date('Ymd_His') . ".xlsx";
        
        ob_end_clean(); 

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        header('Pragma: public');

        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;

    } catch (Exception $e) {
        ob_end_clean();
        die("Error técnico al generar el archivo: " . $e->getMessage());
    }
}

header("Location: perfil_empleado.php");
exit;