<?php
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/layout.php';
requiere_admin();
$pdo = db();

$id = (int) ($_GET['id'] ?? 0);
$emp = [
    'legajo' => '', 'nombre' => '', 'apellido' => '', 'dni' => '', 'email' => '', 'cargo' => '',
    'departamento' => '', 'fecha_ingreso' => date('Y-m-d'), 'rol' => 'empleado',
];
$sel = []; // "dia-turno" => true
if ($id) {
    $st = $pdo->prepare('SELECT * FROM empleados WHERE id = ?');
    $st->execute([$id]);
    $emp = $st->fetch() ?: redirect('empleados.php');
    foreach ($pdo->query('SELECT dia, turno_id FROM empleado_turnos WHERE empleado_id = ' . $id)->fetchAll() as $r) {
        $sel[$r['dia'] . '-' . $r['turno_id']] = true;
    }
}
$turnos = turnos_todos();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $d = [];
    foreach (['legajo', 'nombre', 'apellido', 'dni', 'email', 'cargo', 'departamento', 'fecha_ingreso', 'rol'] as $c) {
        $d[$c] = trim($_POST[$c] ?? '');
    }
    $clave = $_POST['clave'] ?? '';

    // Horario semanal: plan[dia][] = turno_id
    $validos = array_map('intval', array_column($turnos, 'id'));
    $pares = [];
    $sel = [];
    foreach (($_POST['plan'] ?? []) as $dia => $ids) {
        $dia = (int) $dia;
        if ($dia < 1 || $dia > 7 || !is_array($ids)) {
            continue;
        }
        foreach ($ids as $tid) {
            $tid = (int) $tid;
            if (in_array($tid, $validos, true)) {
                $pares[] = [$dia, $tid];
                $sel["$dia-$tid"] = true;
            }
        }
    }

    $err = null;
    if ($d['legajo'] === '' || $d['nombre'] === '' || $d['apellido'] === '' || $d['dni'] === '') {
        $err = 'Legajo, nombre, apellido y DNI son obligatorios.';
    } elseif (!preg_match('/^\d{7,9}$/', $d['dni'])) {
        $err = 'El DNI debe tener entre 7 y 9 dígitos, sin puntos.';
    } elseif ($d['email'] !== '' && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
        $err = 'Email inválido.';
    } elseif ($d['fecha_ingreso'] !== '' && !fecha_valida($d['fecha_ingreso'])) {
        $err = 'Fecha de ingreso inválida.';
    } elseif (!in_array($d['rol'], ['admin', 'empleado'], true)) {
        $err = 'Rol inválido.';
    } elseif (!$id && strlen($clave) < 8) {
        $err = 'La clave inicial debe tener al menos 8 caracteres.';
    } elseif ($id && $clave !== '' && strlen($clave) < 8) {
        $err = 'La clave nueva debe tener al menos 8 caracteres.';
    }
    if (!$err) {
        $dup = $pdo->prepare('SELECT COUNT(*) FROM empleados WHERE (legajo = ? OR dni = ?) AND id <> ?');
        $dup->execute([$d['legajo'], $d['dni'], $id]);
        if ($dup->fetchColumn() > 0) {
            $err = 'Ya existe un empleado con ese legajo o DNI.';
        }
    }

    if ($err) {
        flash($err, 'error');
        $emp = array_merge($emp, $d);
    } else {
        $pdo->beginTransaction();
        if ($id) {
            $pdo->prepare('UPDATE empleados SET legajo=:legajo, nombre=:nombre, apellido=:apellido, dni=:dni, email=:email,
                cargo=:cargo, departamento=:departamento, fecha_ingreso=:fecha_ingreso, rol=:rol WHERE id=:id')->execute($d + ['id' => $id]);
            if ($clave !== '') {
                $pdo->prepare('UPDATE empleados SET password_hash=? WHERE id=?')->execute([password_hash($clave, PASSWORD_DEFAULT), $id]);
            }
        } else {
            $pdo->prepare('INSERT INTO empleados (legajo,nombre,apellido,dni,email,cargo,departamento,fecha_ingreso,rol,password_hash)
                VALUES (:legajo,:nombre,:apellido,:dni,:email,:cargo,:departamento,:fecha_ingreso,:rol,:ph)')
                ->execute($d + ['ph' => password_hash($clave, PASSWORD_DEFAULT)]);
            $id = (int) $pdo->lastInsertId();
        }
        $pdo->prepare('DELETE FROM empleado_turnos WHERE empleado_id = ?')->execute([$id]);
        $ins = $pdo->prepare('INSERT OR IGNORE INTO empleado_turnos (empleado_id, dia, turno_id) VALUES (?,?,?)');
        foreach ($pares as [$dia, $tid]) {
            $ins->execute([$id, $dia, $tid]);
        }
        $pdo->commit();
        flash('Empleado guardado.');
        redirect('empleados.php');
    }
}

$editando = isset($_GET['id']) && (int) $_GET['id'] > 0;
cabecera($editando ? 'Editar empleado' : 'Nuevo empleado', 'Datos personales, acceso y horario semanal por turnos.');
$campo = fn(string $n, string $t, string $tipo = 'text', string $extra = '') =>
    '<label>' . $t . ' <input type="' . $tipo . '" name="' . $n . '" value="' . e($emp[$n] ?? '') . '" ' . $extra . '></label>';
?>
<form method="post">
    <?= csrf_field() ?>
    <section class="card">
        <p class="seccion-t">Datos personales</p>
        <div class="form-grid">
            <?= $campo('legajo', 'Legajo', 'text', 'required') ?>
            <?= $campo('dni', 'DNI (sin puntos)', 'text', 'required inputmode="numeric"') ?>
            <?= $campo('nombre', 'Nombre', 'text', 'required') ?>
            <?= $campo('apellido', 'Apellido', 'text', 'required') ?>
            <?= $campo('email', 'Email', 'email') ?>
            <?= $campo('fecha_ingreso', 'Fecha de ingreso', 'date') ?>
            <?= $campo('cargo', 'Cargo') ?>
            <?= $campo('departamento', 'Departamento') ?>
        </div>
    </section>

    <section class="card">
        <p class="seccion-t">Acceso al sistema</p>
        <div class="form-grid">
            <label>Rol
                <select name="rol">
                    <?php foreach (['empleado' => 'Empleado', 'admin' => 'Administrador'] as $k => $v): ?>
                        <option value="<?= $k ?>" <?= $emp['rol'] === $k ? 'selected' : '' ?>><?= $v ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label><?= $editando ? 'Nueva clave (vacío = no cambiar)' : 'Clave inicial' ?>
                <input type="password" name="clave" minlength="8" <?= $editando ? '' : 'required' ?> autocomplete="new-password"></label>
        </div>
    </section>

    <section class="card">
        <p class="seccion-t">Horario semanal</p>
        <p class="muted" style="margin-top:0">Marcá los turnos que trabaja cada día. Para un <b>doble turno</b> o turno partido, marcá dos turnos el mismo día. Los días sin turnos son franco.</p>
        <?php if (!$turnos): ?>
            <div class="vacio-bloque">Todavía no hay turnos. <a href="turnos.php">Creá los turnos</a> primero.</div>
        <?php else: ?>
        <div class="plan-tools">
            <button type="button" class="btn chico sec" id="copiar">Copiar el lunes a Lun–Vie</button>
            <button type="button" class="btn chico sec" id="limpiar">Limpiar todo</button>
        </div>
        <div class="plan">
            <?php foreach (DIAS_SEMANA as $dia => $nombre): ?>
            <div class="plan-dia"><span class="dia-n"><?= e($nombre) ?></span>
                <div class="plan-opts">
                <?php foreach ($turnos as $t): ?>
                    <label class="opt" style="--c:<?= e($t['color']) ?>">
                        <input type="checkbox" name="plan[<?= $dia ?>][]" value="<?= (int) $t['id'] ?>" data-dia="<?= $dia ?>" <?= isset($sel["$dia-{$t['id']}"]) ? 'checked' : '' ?>>
                        <span><?= e($t['nombre']) ?> <small><?= e($t['hora_inicio'] . '–' . $t['hora_fin']) ?></small></span>
                    </label>
                <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>

    <div class="barra-guardar">
        <button class="btn">Guardar empleado</button> <a class="btn sec" href="empleados.php">Cancelar</a>
    </div>
</form>
<script>
const cajas = [...document.querySelectorAll('input[name^="plan"]')];
document.getElementById('copiar')?.addEventListener('click', () => {
    const lunes = cajas.filter(c => c.dataset.dia === '1').map(c => [c.value, c.checked]);
    cajas.filter(c => ['2', '3', '4', '5'].includes(c.dataset.dia)).forEach(c => {
        c.checked = lunes.some(([v, on]) => v === c.value && on);
    });
});
document.getElementById('limpiar')?.addEventListener('click', () => cajas.forEach(c => c.checked = false));
</script>
<?php pie();
