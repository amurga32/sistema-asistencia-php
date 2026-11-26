<?php
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/layout.php';
requiere_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int) ($_POST['id'] ?? 0);
    $st = $pdo->prepare('SELECT * FROM asistencias WHERE id = ?');
    $st->execute([$id]);
    if ($r = $st->fetch()) {
        $pdo->prepare('DELETE FROM asistencias WHERE id = ?')->execute([$id]);
        auditar('edicion', (int) $r['empleado_id'], 'Fichada eliminada por el administrador (' . $r['fecha'] . ' ' . $r['hora_entrada'] . ')');
        flash('Fichada eliminada.');
    }
    redirect('asistencia.php?' . http_build_query(array_intersect_key($_GET, array_flip(['desde', 'hasta', 'empleado', 'turno', 'estado']))));
}

[$desde, $hasta, $filtroEmp, $filtroTurno, $filtroEstado] = filtros_get();
$rows = consultar_asistencia($desde, $hasta, $filtroEmp, $filtroTurno, $filtroEstado);
$res = resumen_sesiones($rows);
$empleados = $pdo->query('SELECT id, legajo, nombre, apellido FROM empleados WHERE activo = 1 ORDER BY apellido, nombre')->fetchAll();
$turnos = turnos_todos(false);
$hayObs = (bool) array_filter($rows, fn($r) => !empty($r['observacion']));
$qs = http_build_query(['desde' => $desde, 'hasta' => $hasta, 'empleado' => $filtroEmp, 'turno' => $filtroTurno, 'estado' => $filtroEstado]);

cabecera('Asistencia', 'Fichadas por turno, con entrada, salida y horas trabajadas.',
    '<a class="btn sec" href="exportar.php?' . e($qs) . '">⬇ Exportar CSV</a><a class="btn" href="asistencia_form.php">+ Cargar fichada</a>');
?>
<form class="fila card" method="get">
    <label>Desde <input type="date" name="desde" value="<?= e($desde) ?>"></label>
    <label>Hasta <input type="date" name="hasta" value="<?= e($hasta) ?>"></label>
    <label>Empleado
        <select name="empleado"><option value="0">Todos</option>
        <?php foreach ($empleados as $x): ?>
            <option value="<?= (int) $x['id'] ?>" <?= $filtroEmp === (int) $x['id'] ? 'selected' : '' ?>><?= e($x['apellido'] . ', ' . $x['nombre']) ?></option>
        <?php endforeach; ?></select>
    </label>
    <label>Turno
        <select name="turno"><option value="0">Todos</option><option value="-1" <?= $filtroTurno === -1 ? 'selected' : '' ?>>Sin turno</option>
        <?php foreach ($turnos as $t): ?>
            <option value="<?= (int) $t['id'] ?>" <?= $filtroTurno === (int) $t['id'] ? 'selected' : '' ?>><?= e($t['nombre']) ?></option>
        <?php endforeach; ?></select>
    </label>
    <label>Estado
        <select name="estado"><option value="">Todos</option>
        <?php foreach (['Puntual', 'Tardanza', 'Sin turno', 'Sin salida'] as $x): ?>
            <option <?= $filtroEstado === $x ? 'selected' : '' ?>><?= $x ?></option>
        <?php endforeach; ?></select>
    </label>
    <button class="btn">Filtrar</button>
</form>

<div class="kpis">
    <div class="kpi2 azul"><span class="kico">🕒</span><div><div class="n"><?= $res['fichadas'] ?></div><div class="t">Fichadas</div></div></div>
    <div class="kpi2 gris"><span class="kico">📅</span><div><div class="n"><?= $res['dias'] ?></div><div class="t">Días con asistencia</div></div></div>
    <div class="kpi2 verde"><span class="kico">⏱</span><div><div class="n"><?= fmt_horas($res['min']) ?></div><div class="t">Horas trabajadas</div></div></div>
    <div class="kpi2 ambar"><span class="kico">⏰</span><div><div class="n"><?= $res['tardanzas'] ?></div><div class="t">Llegadas tarde</div></div></div>
</div>

<div class="tabla-wrap"><table>
    <tr><th>Fecha</th><th>Empleado</th><th>Turno</th><th>Entrada</th><th>Salida</th><th>Horas</th><th>Estado</th><th>Fotos</th><?php if ($hayObs): ?><th>Obs.</th><?php endif; ?><th></th></tr>
    <?php foreach ($rows as $r): ?>
    <tr>
        <td><?= e(fecha_corta($r['fecha'])) ?></td>
        <td><div class="persona"><span class="av"><?= e(iniciales($r)) ?></span><div><b><?= e($r['apellido'] . ', ' . $r['nombre']) ?></b><small>Legajo <?= e($r['legajo']) ?></small></div></div></td>
        <td><?= chip_sesion($r) ?></td>
        <td><?= e($r['hora_entrada']) ?></td>
        <td><?= e($r['hora_salida'] ?? '—') ?><?= $r['hora_salida'] && ($r['salida_fecha'] ?: $r['fecha']) !== $r['fecha'] ? ' <small class="muted">(+1)</small>' : '' ?></td>
        <td><?= $r['hora_salida'] ? fmt_horas(minutos_sesion($r)) : '—' ?></td>
        <td><?= tag(estado_final($r)) ?></td>
        <td><?php foreach (['entrada' => 'Entrada', 'salida' => 'Salida'] as $k => $t): if (!empty($r["foto_$k"])): ?>
            <a href="foto.php?f=<?= e($r["foto_$k"]) ?>" target="_blank" title="<?= $t ?> · IP <?= e($r["ip_$k"]) ?>"><img class="mini" loading="lazy" alt="<?= $t ?>" src="foto.php?f=<?= e($r["foto_$k"]) ?>"></a>
        <?php endif; endforeach; ?></td>
        <?php if ($hayObs): ?><td><?= e($r['observacion']) ?></td><?php endif; ?>
        <td style="white-space:nowrap">
            <a class="btn chico sec" href="asistencia_form.php?id=<?= (int) $r['id'] ?>">Editar</a>
            <form method="post" style="display:inline" onsubmit="return confirm('¿Eliminar esta fichada?')"><?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn chico rojo">Borrar</button></form>
        </td>
    </tr>
    <?php endforeach; if (!$rows): ?><tr><td colspan="<?= $hayObs ? 10 : 9 ?>">No hay fichadas con esos filtros.</td></tr><?php endif; ?>
</table></div>
<?php pie();
