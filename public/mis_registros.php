<?php
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/layout.php';
$u = requiere_login();

$mes = $_GET['mes'] ?? date('Y-m');
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes)) {
    $mes = date('Y-m');
}
$st = db()->prepare('SELECT a.*, t.color AS turno_color FROM asistencias a LEFT JOIN turnos t ON t.id = a.turno_id
    WHERE a.empleado_id = ? AND a.fecha LIKE ? ORDER BY a.fecha DESC, a.hora_entrada DESC');
$st->execute([$u['id'], $mes . '-%']);
$rows = $st->fetchAll();
$res = resumen_sesiones($rows);
$plan = plan_semanal((int) $u['id']);

cabecera('Mis registros', 'Tus fichadas por turno y tu horario semanal.');
?>
<div class="kpis">
    <div class="kpi2 azul"><span class="kico">📅</span><div><div class="n"><?= $res['dias'] ?></div><div class="t">Días trabajados</div></div></div>
    <div class="kpi2 gris"><span class="kico">🕒</span><div><div class="n"><?= $res['fichadas'] ?></div><div class="t">Fichadas</div></div></div>
    <div class="kpi2 verde"><span class="kico">⏱</span><div><div class="n"><?= fmt_horas($res['min']) ?></div><div class="t">Horas trabajadas</div></div></div>
    <div class="kpi2 ambar"><span class="kico">⏰</span><div><div class="n"><?= $res['tardanzas'] ?></div><div class="t">Llegadas tarde</div></div></div>
</div>

<form class="fila card" method="get">
    <label>Mes <input type="month" name="mes" value="<?= e($mes) ?>"></label>
    <button class="btn">Ver</button>
</form>

<div class="tabla-wrap"><table>
    <tr><th>Fecha</th><th>Turno</th><th>Entrada</th><th>Salida</th><th>Horas</th><th>Estado</th></tr>
    <?php foreach ($rows as $s): ?>
    <tr>
        <td><?= e(fecha_corta($s['fecha'])) ?></td>
        <td><?= chip_sesion($s) ?></td>
        <td><?= e($s['hora_entrada']) ?></td>
        <td><?= e($s['hora_salida'] ?? '—') ?><?= $s['hora_salida'] && ($s['salida_fecha'] ?? $s['fecha']) !== $s['fecha'] ? ' <small class="muted">(+1)</small>' : '' ?></td>
        <td><?= $s['hora_salida'] ? fmt_horas(minutos_sesion($s)) : '—' ?></td>
        <td><?= tag(estado_final($s)) ?></td>
    </tr>
    <?php endforeach; if (!$rows): ?><tr><td colspan="6">Sin fichadas en este mes.</td></tr><?php endif; ?>
    <tr><th colspan="4">Total del mes</th><th colspan="2"><?= fmt_horas($res['min']) ?> h</th></tr>
</table></div>

<section class="card">
    <h3>Mi horario semanal</h3>
    <div class="plan">
    <?php foreach (DIAS_SEMANA as $d => $nombre): ?>
        <div class="plan-dia"><span class="dia-n"><?= e($nombre) ?></span>
            <div class="chips"><?php foreach ($plan[$d] as $t) { echo chip_turno($t); } if (!$plan[$d]) { echo '<span class="muted">Franco</span>'; } ?></div></div>
    <?php endforeach; ?>
    </div>
</section>
<?php pie();
