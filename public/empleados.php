<?php
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/layout.php';
$yo = requiere_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int) ($_POST['id'] ?? 0);
    if ($id === (int) $yo['id']) {
        flash('No podés desactivarte a vos mismo.', 'error');
    } else {
        $pdo->prepare('UPDATE empleados SET activo = 1 - activo WHERE id = ?')->execute([$id]);
        flash('Estado actualizado.');
    }
    redirect('empleados.php');
}

$q = trim($_GET['q'] ?? '');
$estado = $_GET['estado'] ?? 'activos';
$where = $estado === 'activos' ? 'AND activo = 1' : ($estado === 'inactivos' ? 'AND activo = 0' : '');
$st = $pdo->prepare("SELECT * FROM empleados WHERE (legajo LIKE :q OR nombre LIKE :q OR apellido LIKE :q OR dni LIKE :q) $where
                     ORDER BY activo DESC, apellido, nombre");
$st->execute([':q' => "%$q%"]);
$rows = $st->fetchAll();

// Turnos distintos que trabaja cada empleado y en cuántos días
$turnosDe = [];
foreach ($pdo->query('SELECT et.empleado_id, t.*, COUNT(*) AS dias FROM empleado_turnos et JOIN turnos t ON t.id = et.turno_id
        WHERE t.activo = 1 GROUP BY et.empleado_id, t.id ORDER BY t.hora_inicio')->fetchAll() as $r) {
    $turnosDe[$r['empleado_id']][] = $r;
}

cabecera('Empleados', count($rows) . ' resultado(s)', '<a class="btn" href="empleado_form.php">+ Nuevo empleado</a>');
?>
<form class="fila card" method="get">
    <label>Buscar <input name="q" value="<?= e($q) ?>" placeholder="Legajo, nombre o DNI"></label>
    <label>Mostrar
        <select name="estado">
            <?php foreach (['activos' => 'Activos', 'inactivos' => 'Inactivos', 'todos' => 'Todos'] as $k => $v): ?>
                <option value="<?= $k ?>" <?= $estado === $k ? 'selected' : '' ?>><?= $v ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <button class="btn">Buscar</button>
</form>

<div class="tabla-wrap"><table>
    <tr><th>Empleado</th><th>DNI</th><th>Cargo</th><th>Turnos</th><th>Rol</th><th>Estado</th><th></th></tr>
    <?php foreach ($rows as $r): ?>
    <tr>
        <td><div class="persona"><span class="av <?= $r['activo'] ? '' : 'gris' ?>"><?= e(iniciales($r)) ?></span>
            <div><b><?= e($r['apellido'] . ', ' . $r['nombre']) ?></b><small>Legajo <?= e($r['legajo']) ?></small></div></div></td>
        <td><?= e($r['dni']) ?></td>
        <td><?= e(trim($r['cargo'] . ($r['departamento'] ? ' · ' . $r['departamento'] : ''), ' ·')) ?></td>
        <td><div class="chips"><?php foreach ($turnosDe[$r['id']] ?? [] as $t) {
            echo chip($t['nombre'], $t['dias'] . ' d', $t['color']);
        } ?></div></td>
        <td><?= $r['rol'] === 'admin' ? tag('Admin', 'azul') : 'Empleado' ?></td>
        <td><?= tag($r['activo'] ? 'Activo' : 'Inactivo') ?></td>
        <td style="white-space:nowrap">
            <a class="btn chico sec" href="empleado_form.php?id=<?= (int) $r['id'] ?>">Editar</a>
            <form method="post" style="display:inline"><?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                <button class="btn chico sec"><?= $r['activo'] ? 'Desactivar' : 'Activar' ?></button>
            </form>
        </td>
    </tr>
    <?php endforeach; if (!$rows): ?><tr><td colspan="7">Sin resultados.</td></tr><?php endif; ?>
</table></div>
<?php pie();
