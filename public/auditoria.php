<?php
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/layout.php';
requiere_admin();

$desde = $_GET['desde'] ?? date('Y-m-d', strtotime('-7 days'));
$hasta = $_GET['hasta'] ?? date('Y-m-d');
if (!fecha_valida($desde)) {
    $desde = date('Y-m-d', strtotime('-7 days'));
}
if (!fecha_valida($hasta)) {
    $hasta = date('Y-m-d');
}
$soloAlertas = isset($_GET['alertas']);

$sql = 'SELECT a.*, e.legajo, e.nombre, e.apellido FROM auditoria a LEFT JOIN empleados e ON e.id = a.empleado_id
        WHERE date(a.creado) BETWEEN ? AND ?' . ($soloAlertas ? ' AND a.alerta = 1' : '') . ' ORDER BY a.id DESC LIMIT 500';
$st = db()->prepare($sql);
$st->execute([$desde, $hasta]);
$rows = $st->fetchAll();

$eventos = ['entrada' => 'Entrada', 'salida' => 'Salida', 'bloqueado' => 'Fuera de la red', 'login_fallido' => 'Login fallido', 'edicion' => 'Edición manual'];

cabecera('Auditoría y alertas', 'Quién marcó, desde dónde y con qué dispositivo. Muestra hasta 500 eventos.');
?>
<form class="fila card" method="get">
    <label>Desde <input type="date" name="desde" value="<?= e($desde) ?>"></label>
    <label>Hasta <input type="date" name="hasta" value="<?= e($hasta) ?>"></label>
    <label class="check-fila"><input type="checkbox" name="alertas" value="1" <?= $soloAlertas ? 'checked' : '' ?>> Solo alertas</label>
    <button class="btn">Filtrar</button>
</form>
<div class="tabla-wrap"><table>
    <tr><th>Fecha y hora</th><th>Empleado</th><th>Evento</th><th>IP</th><th>Dispositivo</th><th>Foto</th><th>Detalle</th><th>Alerta</th></tr>
    <?php foreach ($rows as $r): ?>
    <tr>
        <td><?= e(date('d/m/Y H:i', strtotime($r['creado']))) ?></td>
        <td><?= $r['legajo'] ? e($r['apellido'] . ', ' . $r['nombre']) : '—' ?></td>
        <td><?= e($eventos[$r['evento']] ?? $r['evento']) ?></td>
        <td><?= e($r['ip']) ?></td>
        <td title="<?= e($r['user_agent']) ?>"><code><?= e(substr((string) $r['device_id'], 0, 8)) ?></code></td>
        <td><?php if ($r['foto']): ?><a href="foto.php?f=<?= e($r['foto']) ?>" target="_blank"><img class="mini" loading="lazy" alt="Foto" src="foto.php?f=<?= e($r['foto']) ?>"></a><?php else: ?>—<?php endif; ?></td>
        <td><?= e($r['detalle']) ?></td>
        <td><?= $r['alerta'] ? tag('Alerta') : '—' ?></td>
    </tr>
    <?php endforeach; if (!$rows): ?><tr><td colspan="8">Sin eventos en el período.</td></tr><?php endif; ?>
</table></div>
<?php pie();
