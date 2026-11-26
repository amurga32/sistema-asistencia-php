<?php
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/layout.php';
$u = requiere_login();
$pdo = db();
$ahora = time();
$hoy = date('Y-m-d', $ahora);

$h = (int) date('G');
$saludo = $h < 12 ? 'Buen día' : ($h < 20 ? 'Buenas tardes' : 'Buenas noches');
$meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
$fechaLarga = ucfirst($dias[(int) date('w')]) . ' ' . date('j') . ' de ' . $meses[(int) date('n') - 1] . ' de ' . date('Y');

// Mi estado y mis turnos de hoy
$abierta = sesion_abierta((int) $u['id']);
$misTurnos = turnos_asignados((int) $u['id'], $hoy);
$proximo = null;
foreach ($misTurnos as $t) {
    if (!turno_usado((int) $u['id'], (int) $t['id'], $hoy)) {
        $proximo = $t;
        break;
    }
}

cabecera('Inicio');
?>
<section class="hero">
    <div>
        <p class="hero-fecha"><?= e($fechaLarga) ?></p>
        <h2><?= e($saludo . ', ' . $u['nombre']) ?> 👋</h2>
        <p class="hero-estado">
            <?php if ($abierta): ?>Estás en turno desde las <b><?= e($abierta['hora_entrada']) ?></b><?= $abierta['turno_nombre'] ? ' · ' . e($abierta['turno_nombre']) : '' ?>. Acordate de marcar la salida.
            <?php elseif ($proximo): ?>Tu próximo turno de hoy es <b><?= e($proximo['nombre']) ?></b>, de <?= e($proximo['hora_inicio']) ?> a <?= e($proximo['hora_fin']) ?>.
            <?php elseif ($misTurnos): ?>Completaste todos tus turnos de hoy. ¡Buen descanso!
            <?php else: ?>Hoy no tenés turnos asignados.<?php endif; ?>
        </p>
        <?php if ($misTurnos): ?><div class="chips"><?php foreach ($misTurnos as $t) { echo chip_turno($t); } ?></div><?php endif; ?>
    </div>
    <?php if ($abierta): ?><a class="btn blanco" href="marcar.php">Marcar salida</a>
    <?php elseif ($proximo || !$misTurnos): ?><a class="btn blanco" href="marcar.php">Marcar entrada</a>
    <?php else: ?><span class="hero-ok">✔ Todo listo por hoy</span><?php endif; ?>
</section>

<?php if ($u['rol'] === 'admin'):
    $emps = $pdo->query('SELECT id, legajo, nombre, apellido FROM empleados WHERE activo = 1 ORDER BY apellido, nombre')->fetchAll();

    $q = $pdo->prepare('SELECT * FROM asistencias WHERE fecha_turno = ? AND turno_id IS NOT NULL');
    $q->execute([$hoy]);
    $sesTurno = [];
    foreach ($q->fetchAll() as $s) {
        $sesTurno[$s['empleado_id'] . '-' . $s['turno_id']] = $s;
    }

    // Agenda de hoy: por turno, quién debería venir y cómo va
    $agenda = [];
    $esperados = $cumplidos = $ausentes = $tardes = 0;
    foreach ($emps as $emp) {
        foreach (turnos_asignados((int) $emp['id'], $hoy) as $t) {
            $s = $sesTurno[$emp['id'] . '-' . $t['id']] ?? null;
            $extra = null;
            if ($s) {
                $cumplidos++;
                $est = empty($s['hora_salida']) ? 'En turno' : 'Completó';
                if (estado_sesion($s) === 'Tardanza') {
                    $tardes++;
                    $extra = 'Tardanza';
                }
            } else {
                $limite = ts($hoy, $t['hora_inicio']) + (int) $t['tolerancia'] * 60;
                $est = $ahora <= $limite ? 'Pendiente' : 'Ausente';
                $ausentes += $est === 'Ausente' ? 1 : 0;
            }
            $esperados++;
            $agenda[$t['id']]['turno'] = $t;
            $agenda[$t['id']]['items'][] = ['emp' => $emp, 'ses' => $s, 'est' => $est, 'extra' => $extra];
        }
    }
    uasort($agenda, fn($a, $b) => strcmp($a['turno']['hora_inicio'], $b['turno']['hora_inicio']));
    $pct = $esperados ? (int) round($cumplidos * 100 / $esperados) : 0;

    // En el local ahora (entradas sin salida vigentes, aunque sean de un turno que empezó ayer)
    $enLocal = array_filter($pdo->query('SELECT a.*, e.nombre, e.apellido, t.color AS turno_color FROM asistencias a
        JOIN empleados e ON e.id = a.empleado_id LEFT JOIN turnos t ON t.id = a.turno_id
        WHERE a.hora_salida IS NULL AND a.hora_entrada IS NOT NULL ORDER BY a.fecha, a.hora_entrada')->fetchAll(),
        fn($s) => !sesion_vencida($s));

    $qa = $pdo->prepare('SELECT COUNT(*) FROM auditoria WHERE alerta = 1 AND creado >= ?');
    $qa->execute([$hoy . ' 00:00:00']);
    $alertas = (int) $qa->fetchColumn();
    $ultimas = $pdo->query('SELECT a.creado, a.detalle, e.nombre, e.apellido FROM auditoria a
        LEFT JOIN empleados e ON e.id = a.empleado_id WHERE a.alerta = 1 ORDER BY a.id DESC LIMIT 4')->fetchAll();
?>
<div class="kpis">
    <div class="kpi2 azul"><span class="kico">📅</span><div><div class="n"><?= $esperados ?></div><div class="t">Turnos esperados hoy</div></div></div>
    <div class="kpi2 verde"><span class="kico">🏢</span><div><div class="n"><?= count($enLocal) ?></div><div class="t">En el local ahora</div></div></div>
    <div class="kpi2 ambar"><span class="kico">⏰</span><div><div class="n"><?= $tardes ?></div><div class="t">Llegadas tarde</div></div></div>
    <div class="kpi2 gris"><span class="kico">🚫</span><div><div class="n"><?= $ausentes ?></div><div class="t">Ausentes</div></div></div>
    <a class="kpi2 <?= $alertas ? 'rojo' : 'verde' ?>" href="auditoria.php?alertas=1&desde=<?= $hoy ?>"><span class="kico"><?= $alertas ? '🚨' : '🛡' ?></span><div><div class="n"><?= $alertas ?></div><div class="t">Alertas de hoy</div></div></a>
</div>

<div class="card">
    <div class="barra-tit"><b>Cumplimiento de turnos de hoy</b><span class="muted"><?= $cumplidos ?> de <?= $esperados ?> · <?= $pct ?>%</span></div>
    <div class="barra"><span style="width:<?= $pct ?>%"></span></div>
</div>

<div class="dos">
    <section class="card">
        <h3>Turnos de hoy</h3>
        <?php foreach ($agenda as $bloque): ?>
        <div class="turno-bloque">
            <div class="cab-turno"><?= chip_turno($bloque['turno']) ?><span class="pill"><?= count($bloque['items']) ?></span></div>
            <ul class="lista">
            <?php foreach ($bloque['items'] as $it): $s = $it['ses']; ?>
                <li><span class="av <?= $it['est'] === 'Ausente' ? 'rojo' : ($it['est'] === 'Pendiente' ? 'gris' : '') ?>"><?= e(iniciales($it['emp'])) ?></span>
                    <div class="info"><b><?= e($it['emp']['apellido'] . ', ' . $it['emp']['nombre']) ?></b>
                        <small><?= $s ? 'Entrada ' . e($s['hora_entrada']) . ' · Salida ' . e($s['hora_salida'] ?? '—') : 'Legajo ' . e($it['emp']['legajo']) ?></small></div>
                    <?= $it['extra'] ? tag($it['extra']) : '' ?> <?= tag($it['est']) ?></li>
            <?php endforeach; ?>
            </ul>
        </div>
        <?php endforeach; if (!$agenda): ?>
            <div class="vacio-bloque">Hoy no hay turnos asignados.<br><a href="empleados.php">Asigná turnos a tus empleados</a> o <a href="turnos.php">configurá los turnos</a>.</div>
        <?php endif; ?>
    </section>

    <div>
        <section class="card">
            <h3>En el local ahora <span class="pill"><?= count($enLocal) ?></span></h3>
            <ul class="lista">
            <?php foreach ($enLocal as $s): ?>
                <li><span class="av verde"><?= e(iniciales($s)) ?></span>
                    <div class="info"><b><?= e($s['apellido'] . ', ' . $s['nombre']) ?></b>
                        <small>Desde <?= e($s['hora_entrada']) ?><?= $s['fecha'] !== $hoy ? ' (ayer)' : '' ?></small></div>
                    <?= chip_sesion($s) ?></li>
            <?php endforeach; if (!$enLocal): ?><li class="vacio-lista">No hay nadie en turno en este momento.</li><?php endif; ?>
            </ul>
        </section>

        <?php if ($ultimas): ?>
        <section class="card">
            <h3>Últimas alertas <a class="ver-todas" href="auditoria.php?alertas=1">Ver todas →</a></h3>
            <ul class="lista">
            <?php foreach ($ultimas as $a): ?>
                <li><span class="av rojo">!</span>
                    <div class="info"><b><?= e($a['apellido'] ? $a['apellido'] . ', ' . $a['nombre'] : 'Sistema') ?></b><small><?= e($a['detalle']) ?></small></div>
                    <small class="muted"><?= e(date('d/m H:i', strtotime($a['creado']))) ?></small></li>
            <?php endforeach; ?>
            </ul>
        </section>
        <?php endif; ?>
    </div>
</div>

<?php else:
    // Vista del empleado: resumen del mes y últimas fichadas
    $q = $pdo->prepare('SELECT a.*, t.color AS turno_color FROM asistencias a LEFT JOIN turnos t ON t.id = a.turno_id
        WHERE a.empleado_id = ? AND a.fecha LIKE ? ORDER BY a.fecha DESC, a.hora_entrada DESC');
    $q->execute([$u['id'], date('Y-m') . '-%']);
    $delMes = $q->fetchAll();
    $res = resumen_sesiones($delMes);
?>
<div class="kpis">
    <div class="kpi2 azul"><span class="kico">📅</span><div><div class="n"><?= $res['dias'] ?></div><div class="t">Días trabajados este mes</div></div></div>
    <div class="kpi2 verde"><span class="kico">⏱</span><div><div class="n"><?= fmt_horas($res['min']) ?></div><div class="t">Horas del mes</div></div></div>
    <div class="kpi2 ambar"><span class="kico">⏰</span><div><div class="n"><?= $res['tardanzas'] ?></div><div class="t">Llegadas tarde</div></div></div>
</div>
<section class="card">
    <h3>Mis últimas fichadas <a class="ver-todas" href="mis_registros.php">Ver todas →</a></h3>
    <ul class="lista">
    <?php foreach (array_slice($delMes, 0, 6) as $s): ?>
        <li><span class="av"><?= e(date('d', strtotime($s['fecha']))) ?></span>
            <div class="info"><b><?= e(fecha_corta($s['fecha'])) ?></b>
                <small>Entrada <?= e($s['hora_entrada']) ?> · Salida <?= e($s['hora_salida'] ?? '—') ?></small></div>
            <?= chip_sesion($s) ?> <?= tag(estado_final($s)) ?></li>
    <?php endforeach; if (!$delMes): ?><li class="vacio-lista">Todavía no tenés fichadas este mes.</li><?php endif; ?>
    </ul>
</section>
<?php endif;
pie();
