<?php
require __DIR__ . '/../app/bootstrap.php';
requiere_admin();

[$desde, $hasta, $emp, $turno, $estado] = filtros_get();
$rows = consultar_asistencia($desde, $hasta, $emp, $turno, $estado);

header('Content-Type: text/csv; charset=utf-8');
header("Content-Disposition: attachment; filename=asistencia_{$desde}_{$hasta}.csv");

// Neutraliza inyección de fórmulas en Excel/LibreOffice.
$safe = fn($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : $v;

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM para que Excel lea UTF-8
fputcsv($out, ['Fecha', 'Legajo', 'Apellido', 'Nombre', 'Turno', 'Entrada', 'Fecha salida', 'Salida', 'Horas', 'Estado', 'Observación'], ';');
foreach ($rows as $r) {
    fputcsv($out, [
        $r['fecha'], $safe($r['legajo']), $safe($r['apellido']), $safe($r['nombre']), $safe($r['turno_nombre'] ?? ''),
        $r['hora_entrada'], $r['hora_salida'] ? ($r['salida_fecha'] ?: $r['fecha']) : '', $r['hora_salida'] ?? '',
        $r['hora_salida'] ? fmt_horas(minutos_sesion($r)) : '', estado_final($r), $safe($r['observacion'] ?? ''),
    ], ';');
}
fclose($out);
