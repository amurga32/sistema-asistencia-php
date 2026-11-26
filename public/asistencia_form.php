<?php
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/layout.php';
$admin = requiere_admin();
$pdo = db();

$id = (int) ($_GET['id'] ?? 0);
$f = ['empleado_id' => (int) ($_GET['empleado'] ?? 0), 'fecha' => date('Y-m-d'), 'turno_id' => 0, 'hora_entrada' => '', 'hora_salida' => '', 'observacion' => ''];
$actual = null;
if ($id) {
    $st = $pdo->prepare('SELECT * FROM asistencias WHERE id = ?');
    $st->execute([$id]);
    $actual = $st->fetch() ?: redirect('asistencia.php');
    $f = [
        'empleado_id' => (int) $actual['empleado_id'], 'fecha' => $actual['fecha'], 'turno_id' => (int) $actual['turno_id'],
        'hora_entrada' => (string) $actual['hora_entrada'], 'hora_salida' => (string) $actual['hora_salida'], 'observacion' => (string) $actual['observacion'],
    ];
}
$empleados = $pdo->query('SELECT id, legajo, nombre, apellido FROM empleados WHERE activo = 1 ORDER BY apellido, nombre')->fetchAll();
$turnos = turnos_todos();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $f = [
        'empleado_id' => (int) ($_POST['empleado_id'] ?? 0), 'fecha' => $_POST['fecha'] ?? '', 'turno_id' => (int) ($_POST['turno_id'] ?? 0),
        'hora_entrada' => trim($_POST['hora_entrada'] ?? ''), 'hora_salida' => trim($_POST['hora_salida'] ?? ''), 'observacion' => trim($_POST['observacion'] ?? ''),
    ];
    $err = null;
    $existe = $pdo->prepare('SELECT COUNT(*) FROM empleados WHERE id = ?');
    $existe->execute([$f['empleado_id']]);
    if (!$existe->fetchColumn()) {
        $err = 'Elegí un empleado.';
    } elseif (!fecha_valida($f['fecha'])) {
        $err = 'Fecha inválida.';
    } elseif (!hora_valida($f['hora_entrada'])) {
        $err = 'La hora de entrada es obligatoria (formato HH:MM).';
    } elseif ($f['hora_salida'] !== '' && !hora_valida($f['hora_salida'])) {
        $err = 'Hora de salida inválida.';
    } elseif (mb_strlen($f['observacion']) > 200) {
        $err = 'La observación puede tener hasta 200 caracteres.';
    }

    $turno = null;
    if (!$err && $f['turno_id'] > 0) {
        $q = $pdo->prepare('SELECT * FROM turnos WHERE id = ?');
        $q->execute([$f['turno_id']]);
        $turno = $q->fetch() ?: null;
        if (!$turno) {
            $err = 'El turno elegido no existe.';
        }
    }

    if ($err) {
        flash($err, 'error');
    } else {
        // Si la salida es menor que la entrada, es del día siguiente (turno de noche).
        $salidaFecha = null;
        if ($f['hora_salida'] !== '') {
            $salidaFecha = $f['hora_salida'] < $f['hora_entrada'] ? date('Y-m-d', strtotime($f['fecha'] . ' +1 day')) : $f['fecha'];
        }
        // Se conserva la copia del turno si no cambió; si cambió, se toma la definición actual.
        $mismoTurno = $actual && (int) $actual['turno_id'] === $f['turno_id'];
        if ($mismoTurno) {
            $snap = [$actual['turno_id'] ?: null, $actual['turno_nombre'], $actual['turno_inicio'], $actual['turno_fin'], $actual['tolerancia']];
        } elseif ($turno) {
            $snap = [$turno['id'], $turno['nombre'], $turno['hora_inicio'], $turno['hora_fin'], $turno['tolerancia']];
        } else {
            $snap = [null, null, null, null, null];
        }
        $fechaTurno = $turno ? calcular_fecha_turno($turno, $f['fecha'], $f['hora_entrada']) : $f['fecha'];
        $datos = [$f['empleado_id'], $f['fecha'], $fechaTurno, $f['hora_entrada'], $salidaFecha, $f['hora_salida'] ?: null, ...$snap, $f['observacion'] ?: null];

        if ($id) {
            $pdo->prepare('UPDATE asistencias SET empleado_id=?, fecha=?, fecha_turno=?, hora_entrada=?, salida_fecha=?, hora_salida=?,
                turno_id=?, turno_nombre=?, turno_inicio=?, turno_fin=?, tolerancia=?, observacion=? WHERE id=?')->execute([...$datos, $id]);
        } else {
            $pdo->prepare('INSERT INTO asistencias (empleado_id, fecha, fecha_turno, hora_entrada, salida_fecha, hora_salida,
                turno_id, turno_nombre, turno_inicio, turno_fin, tolerancia, observacion) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')->execute($datos);
        }
        auditar('edicion', $f['empleado_id'], 'Fichada ' . ($id ? 'editada' : 'cargada') . ' a mano por ' . $admin['apellido'] . ' (' . $f['fecha'] . ' ' . $f['hora_entrada'] . ')');
        flash('Fichada guardada.');
        redirect('asistencia.php?desde=' . substr($f['fecha'], 0, 8) . '01&hasta=' . $f['fecha']);
    }
}

cabecera($id ? 'Editar fichada' : 'Cargar fichada', 'Corregí o agregá una entrada y salida a mano. Queda registrado en la auditoría.');
?>
<form method="post" class="card">
    <?= csrf_field() ?>
    <div class="form-grid">
        <label>Empleado
            <select name="empleado_id" required>
                <option value="">Elegí…</option>
                <?php foreach ($empleados as $x): ?>
                    <option value="<?= (int) $x['id'] ?>" <?= $f['empleado_id'] === (int) $x['id'] ? 'selected' : '' ?>><?= e($x['legajo'] . ' · ' . $x['apellido'] . ', ' . $x['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Fecha de la entrada <input type="date" name="fecha" value="<?= e($f['fecha']) ?>" required></label>
        <label>Turno
            <select name="turno_id">
                <option value="0">Sin turno</option>
                <?php foreach ($turnos as $t): ?>
                    <option value="<?= (int) $t['id'] ?>" <?= $f['turno_id'] === (int) $t['id'] ? 'selected' : '' ?>><?= e($t['nombre'] . ' (' . $t['hora_inicio'] . '–' . $t['hora_fin'] . ')') ?></option>
                <?php endforeach; ?>
                <?php if ($actual && $actual['turno_id'] && !in_array((int) $actual['turno_id'], array_map('intval', array_column($turnos, 'id')), true)): ?>
                    <option value="<?= (int) $actual['turno_id'] ?>" selected><?= e($actual['turno_nombre'] . ' (turno inactivo)') ?></option>
                <?php endif; ?>
            </select>
        </label>
        <label>Hora de entrada <input type="time" name="hora_entrada" value="<?= e($f['hora_entrada']) ?>" required></label>
        <label>Hora de salida <input type="time" name="hora_salida" value="<?= e($f['hora_salida']) ?>"></label>
        <label>Observación <input name="observacion" value="<?= e($f['observacion']) ?>" maxlength="200"></label>
    </div>
    <p class="muted">Para un turno de noche, cargá la entrada del día en que empezó y una salida menor (por ejemplo 22:00 → 06:00): se toma como del día siguiente.</p>
    <div class="barra-guardar"><button class="btn">Guardar fichada</button> <a class="btn sec" href="asistencia.php">Cancelar</a></div>
</form>
<?php pie();
