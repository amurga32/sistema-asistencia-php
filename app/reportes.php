<?php
/** Fichadas entre dos fechas; $turno: 0 = todos, -1 = sin turno, >0 = ese turno. */
function consultar_asistencia(string $desde, string $hasta, int $empleado = 0, int $turno = 0, string $estado = ''): array
{
    $sql = 'SELECT a.*, e.legajo, e.nombre, e.apellido, t.color AS turno_color
            FROM asistencias a JOIN empleados e ON e.id = a.empleado_id LEFT JOIN turnos t ON t.id = a.turno_id
            WHERE a.fecha BETWEEN ? AND ?';
    $p = [$desde, $hasta];
    if ($empleado > 0) {
        $sql .= ' AND a.empleado_id = ?';
        $p[] = $empleado;
    }
    if ($turno > 0) {
        $sql .= ' AND a.turno_id = ?';
        $p[] = $turno;
    } elseif ($turno < 0) {
        $sql .= ' AND a.turno_id IS NULL';
    }
    $sql .= ' ORDER BY a.fecha DESC, a.hora_entrada DESC, e.apellido, e.nombre';
    $st = db()->prepare($sql);
    $st->execute($p);
    $rows = $st->fetchAll();
    if ($estado !== '') {
        $rows = array_values(array_filter($rows, fn($s) => estado_final($s) === $estado));
    }
    return $rows;
}

function resumen_sesiones(array $rows): array
{
    $min = 0;
    $tard = 0;
    $dias = [];
    foreach ($rows as $s) {
        $min += minutos_sesion($s);
        $tard += estado_sesion($s) === 'Tardanza' ? 1 : 0;
        $dias[$s['empleado_id'] . '|' . $s['fecha']] = 1;
    }
    return ['fichadas' => count($rows), 'min' => $min, 'tardanzas' => $tard, 'dias' => count($dias)];
}

/** Filtros leídos de la URL: [desde, hasta, empleado, turno, estado]. */
function filtros_get(): array
{
    $desde = $_GET['desde'] ?? date('Y-m-01');
    $hasta = $_GET['hasta'] ?? date('Y-m-d');
    if (!fecha_valida($desde)) {
        $desde = date('Y-m-01');
    }
    if (!fecha_valida($hasta)) {
        $hasta = date('Y-m-d');
    }
    $estado = $_GET['estado'] ?? '';
    if (!in_array($estado, ['Puntual', 'Tardanza', 'Sin turno', 'Sin salida'], true)) {
        $estado = '';
    }
    return [$desde, $hasta, (int) ($_GET['empleado'] ?? 0), (int) ($_GET['turno'] ?? 0), $estado];
}
