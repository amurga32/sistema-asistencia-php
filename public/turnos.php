<?php
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/layout.php';
requiere_admin();
$pdo = db();

$edit = ['id' => 0, 'nombre' => '', 'hora_inicio' => '08:00', 'hora_fin' => '16:00', 'tolerancia' => TOLERANCIA_DEFECTO, 'color' => COLORES_TURNO[0], 'activo' => 1];
$mostrarForm = isset($_GET['nuevo']) || isset($_GET['editar']);
if (isset($_GET['editar'])) {
    $st = $pdo->prepare('SELECT * FROM turnos WHERE id = ?');
    $st->execute([(int) $_GET['editar']]);
    $edit = $st->fetch() ?: redirect('turnos.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $accion = $_POST['accion'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);

    if ($accion === 'guardar') {
        $d = [
            'nombre' => trim($_POST['nombre'] ?? ''),
            'hora_inicio' => trim($_POST['hora_inicio'] ?? ''),
            'hora_fin' => trim($_POST['hora_fin'] ?? ''),
            'tolerancia' => (int) ($_POST['tolerancia'] ?? 0),
            'color' => $_POST['color'] ?? '',
        ];
        $err = null;
        if ($d['nombre'] === '' || mb_strlen($d['nombre']) > 40) {
            $err = 'Poné un nombre de hasta 40 caracteres.';
        } elseif (!hora_valida($d['hora_inicio']) || !hora_valida($d['hora_fin']) || $d['hora_inicio'] === $d['hora_fin']) {
            $err = 'Horario inválido: el inicio y el fin tienen que ser distintos.';
        } elseif ($d['tolerancia'] < 0 || $d['tolerancia'] > 120) {
            $err = 'La tolerancia va de 0 a 120 minutos.';
        } elseif (!in_array($d['color'], COLORES_TURNO, true)) {
            $err = 'Elegí un color de la lista.';
        }
        if ($err) {
            flash($err, 'error');
            $edit = array_merge($edit, $d, ['id' => $id]);
            $mostrarForm = true;
        } else {
            if ($id) {
                $pdo->prepare('UPDATE turnos SET nombre=:nombre, hora_inicio=:hora_inicio, hora_fin=:hora_fin, tolerancia=:tolerancia, color=:color WHERE id=:id')
                    ->execute($d + ['id' => $id]);
            } else {
                $pdo->prepare('INSERT INTO turnos (nombre, hora_inicio, hora_fin, tolerancia, color) VALUES (:nombre,:hora_inicio,:hora_fin,:tolerancia,:color)')
                    ->execute($d);
            }
            flash('Turno guardado. Las fichadas anteriores conservan el horario que tenía el turno cuando se marcaron.');
            redirect('turnos.php');
        }
    } elseif ($accion === 'estado') {
        $pdo->prepare('UPDATE turnos SET activo = 1 - activo WHERE id = ?')->execute([$id]);
        flash('Estado del turno actualizado.');
        redirect('turnos.php');
    } elseif ($accion === 'borrar') {
        $q = $pdo->prepare('SELECT COUNT(*) FROM asistencias WHERE turno_id = ?');
        $q->execute([$id]);
        if ($q->fetchColumn() > 0) {
            flash('Ese turno tiene fichadas registradas: no se puede eliminar, pero sí desactivarlo.', 'error');
        } else {
            $pdo->prepare('DELETE FROM turnos WHERE id = ?')->execute([$id]);
            flash('Turno eliminado.');
        }
        redirect('turnos.php');
    }
}

$turnos = turnos_todos(false);
$empPorTurno = [];
foreach ($pdo->query('SELECT turno_id, COUNT(DISTINCT empleado_id) AS n FROM empleado_turnos GROUP BY turno_id')->fetchAll() as $r) {
    $empPorTurno[$r['turno_id']] = (int) $r['n'];
}

cabecera('Turnos', 'Definí los turnos del negocio. Después asignáselos a cada empleado en su horario semanal.',
    $mostrarForm ? '' : '<a class="btn" href="turnos.php?nuevo=1">+ Nuevo turno</a>');
?>
<?php if ($mostrarForm): ?>
<form method="post" class="card">
    <?= csrf_field() ?>
    <input type="hidden" name="accion" value="guardar">
    <input type="hidden" name="id" value="<?= (int) $edit['id'] ?>">
    <p class="seccion-t"><?= $edit['id'] ? 'Editar turno' : 'Nuevo turno' ?></p>
    <div class="form-grid">
        <label>Nombre <input name="nombre" value="<?= e($edit['nombre']) ?>" maxlength="40" required placeholder="Mañana, Tarde, Noche..."></label>
        <label>Hora de inicio <input type="time" name="hora_inicio" value="<?= e($edit['hora_inicio']) ?>" required></label>
        <label>Hora de fin <input type="time" name="hora_fin" value="<?= e($edit['hora_fin']) ?>" required></label>
        <label>Tolerancia de llegada (min) <input type="number" name="tolerancia" min="0" max="120" value="<?= (int) $edit['tolerancia'] ?>" required></label>
    </div>
    <p class="muted">Si la hora de fin es menor que la de inicio (por ejemplo 22:00 a 06:00), el turno cruza la medianoche y se toma como turno de noche.</p>
    <div style="display:flex;flex-direction:column;gap:.4rem">
        <span class="muted">Color</span>
        <div class="swatches">
        <?php foreach (COLORES_TURNO as $c): ?>
            <label style="--c:<?= $c ?>"><input type="radio" name="color" value="<?= $c ?>" <?= $edit['color'] === $c ? 'checked' : '' ?> aria-label="Color <?= $c ?>"><span></span></label>
        <?php endforeach; ?>
        </div>
    </div>
    <div class="barra-guardar" style="margin-top:1rem">
        <button class="btn">Guardar turno</button> <a class="btn sec" href="turnos.php">Cancelar</a>
    </div>
</form>
<?php endif; ?>

<div class="turnos-grid">
<?php foreach ($turnos as $t):
    $dur = dur_turno($t); ?>
    <article class="turno-card <?= $t['activo'] ? '' : 'off' ?>" style="--c:<?= e($t['color']) ?>">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:.5rem">
            <h3><?= e($t['nombre']) ?></h3><?= $t['activo'] ? '' : tag('Inactivo') ?>
        </div>
        <div class="rango"><?= e($t['hora_inicio']) ?> – <?= e($t['hora_fin']) ?></div>
        <div class="meta">
            <span>⏳ <?= fmt_horas($dur) ?> h</span>
            <span>⏰ Tolerancia <?= (int) $t['tolerancia'] ?> min</span>
            <span>👥 <?= $empPorTurno[$t['id']] ?? 0 ?> empleado(s)</span>
            <?php if (cruza_medianoche($t)): ?><span>🌙 Cruza la medianoche</span><?php endif; ?>
        </div>
        <div class="btns">
            <a class="btn chico sec" href="turnos.php?editar=<?= (int) $t['id'] ?>">Editar</a>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                <button class="btn chico sec" name="accion" value="estado"><?= $t['activo'] ? 'Desactivar' : 'Activar' ?></button></form>
            <form method="post" onsubmit="return confirm('¿Eliminar este turno? También se quita del horario de los empleados.')"><?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                <button class="btn chico rojo" name="accion" value="borrar">Eliminar</button></form>
        </div>
    </article>
<?php endforeach; if (!$turnos): ?>
    <div class="card vacio-bloque" style="grid-column:1/-1">Todavía no hay turnos. Creá el primero con "+ Nuevo turno".</div>
<?php endif; ?>
</div>
<?php pie();
