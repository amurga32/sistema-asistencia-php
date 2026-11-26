<?php
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/layout.php';
$u = requiere_login();
$pdo = db();
$ahora = time();
$hoy = date('Y-m-d', $ahora);
$empId = (int) $u['id'];

$ip = client_ip();
$ipOk = ip_permitida($ip);
$requiereFoto = ajuste('foto_obligatoria', '1') === '1';
$kiosco = es_kiosco();

$abierta = sesion_abierta($empId);
$accion = $abierta ? 'salida' : 'entrada';
$cand = $abierta ? null : elegir_turno($empId, $ahora);
$fichadas = fichadas_del_dia($empId, $hoy);
$llegoAlLimite = !$abierta && $fichadas >= MAX_FICHADAS_DIA;

// Abrir la pantalla de marcación desde fuera del local queda registrado (una vez cada 30 min por empleado).
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !$ipOk) {
    $q = $pdo->prepare("SELECT COUNT(*) FROM auditoria WHERE empleado_id = ? AND evento = 'bloqueado' AND creado >= ?");
    $q->execute([$empId, date('Y-m-d H:i:s', $ahora - 1800)]);
    if (!$q->fetchColumn()) {
        auditar('bloqueado', $empId, "Abrió la marcación fuera de la red del local ($ip)", true);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if (!$ipOk) {
        auditar('bloqueado', $empId, "Intento de marcar desde una IP no permitida ($ip)", true);
        flash('Solo podés marcar desde la red del local.', 'error');
        redirect('marcar.php');
    }
    if ($llegoAlLimite) {
        flash('Llegaste al máximo de ' . MAX_FICHADAS_DIA . ' entradas por día. Pedile a un administrador que lo revise.', 'error');
        redirect('marcar.php');
    }

    // Evita marcaciones dobles por un clic repetido
    $q = $pdo->prepare('SELECT * FROM asistencias WHERE empleado_id = ? ORDER BY id DESC LIMIT 1');
    $q->execute([$empId]);
    $ultima = $q->fetch();
    $ref = 0;
    if ($ultima) {
        $ref = !empty($ultima['hora_salida']) ? ts($ultima['salida_fecha'] ?: $ultima['fecha'], $ultima['hora_salida']) : ts($ultima['fecha'], $ultima['hora_entrada']);
    }
    if ($ref && $ahora - $ref < MIN_ENTRE_FICHADAS_SEG) {
        flash('Ya registraste una marcación hace instantes. Esperá un par de minutos.', 'error');
        redirect('marcar.php');
    }

    $foto = !empty($_POST['foto']) ? guardar_foto((string) $_POST['foto']) : null;
    if ($requiereFoto && !$foto) {
        flash('Falta la foto. Permití el acceso a la cámara e intentá de nuevo.', 'error');
        redirect('marcar.php');
    }

    $hora = date('H:i', $ahora);
    $alerta = alerta_dispositivo($empId);
    $t = $cand['turno'] ?? null;

    if ($accion === 'entrada') {
        $pdo->prepare('INSERT INTO asistencias (empleado_id, fecha, fecha_turno, hora_entrada, turno_id, turno_nombre, turno_inicio, turno_fin, tolerancia, foto_entrada, ip_entrada)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$empId, $hoy, $cand['fecha_turno'] ?? $hoy, $hora, $t['id'] ?? null, $t['nombre'] ?? null,
                       $t['hora_inicio'] ?? null, $t['hora_fin'] ?? null, $t['tolerancia'] ?? null, $foto, $ip]);
        $detalle = $t ? 'Turno ' . $t['nombre'] : 'Sin turno asignado en este horario';
    } else {
        $n = $pdo->prepare('UPDATE asistencias SET hora_salida = ?, salida_fecha = ?, foto_salida = ?, ip_salida = ? WHERE id = ? AND hora_salida IS NULL');
        $n->execute([$hora, $hoy, $foto, $ip, $abierta['id']]);
        if ($n->rowCount() === 0) {
            if ($foto) {
                @unlink(__DIR__ . '/../data/fotos/' . $foto);
            }
            flash('La salida ya estaba registrada.', 'error');
            redirect('marcar.php');
        }
        $detalle = 'Turno ' . ($abierta['turno_nombre'] ?: 'sin asignar');
    }

    auditar($accion, $empId, $alerta ?? $detalle, $alerta !== null, $foto);
    $msg = ($accion === 'entrada' ? 'Entrada' : 'Salida') . " registrada a las $hora.";
    flash($msg);

    if ($kiosco) { // el próximo empleado tiene que ingresar con su propio usuario
        unset($_SESSION['uid']);
        session_regenerate_id(true);
        flash($msg . ' Sesión cerrada.');
        redirect('login.php');
    }
    redirect('marcar.php');
}

// Fichadas de hoy (incluye un turno de noche que empezó ayer)
$q = $pdo->prepare('SELECT a.*, t.color AS turno_color FROM asistencias a LEFT JOIN turnos t ON t.id = a.turno_id
    WHERE a.empleado_id = ? AND (a.fecha = ? OR a.fecha_turno = ?) ORDER BY a.fecha, a.hora_entrada');
$q->execute([$empId, $hoy, $hoy]);
$deHoy = $q->fetchAll();
$misTurnos = turnos_asignados($empId, $hoy);

cabecera('Marcar asistencia', 'Registrá tu entrada y tu salida de cada turno.');
?>
<div class="marcar-grid">
<div class="card centro">
    <div class="reloj" id="reloj"><?= date('H:i:s', $ahora) ?></div>
    <p class="muted"><?= e(fecha_corta($hoy)) ?></p>

    <?php if (!$ipOk): ?>
        <div class="aviso error">Estás fuera de la red del local (<?= e($ip) ?>). Conectate al wifi del local para marcar.</div>
    <?php elseif ($llegoAlLimite): ?>
        <div class="aviso error">Ya marcaste el máximo de entradas de hoy. Si falta algo, avisale a un administrador.</div>
    <?php else: ?>
        <div class="turno-info">
            <?php if ($abierta): ?>
                <span>En turno desde las <b><?= e($abierta['hora_entrada']) ?></b></span>
                <?= chip_sesion($abierta + ['turno_color' => '#4f46e5']) ?>
            <?php elseif ($cand): ?>
                <span>Vas a registrar la entrada de:</span> <?= chip_turno($cand['turno']) ?>
            <?php else: ?>
                <span>No tenés un turno asignado en este horario.</span>
                <span class="muted">La entrada quedará registrada como "Sin turno".</span>
            <?php endif; ?>
        </div>

        <form method="post" id="f">
            <?= csrf_field() ?>
            <input type="hidden" name="foto" id="foto">
            <div class="camara" id="camara"><video id="v" autoplay playsinline muted></video></div>
            <p id="msg" class="muted"><?= $requiereFoto ? 'Se tomará una foto al marcar.' : '' ?></p>
            <button class="btn grande <?= $accion === 'salida' ? 'rojo' : '' ?>" id="btn" disabled>
                Marcar <?= $accion === 'entrada' ? 'ENTRADA' : 'SALIDA' ?></button>
        </form>
        <canvas id="c" hidden></canvas>
    <?php endif; ?>
</div>

<aside>
    <section class="card">
        <h3>Mis turnos de hoy</h3>
        <?php if ($misTurnos): ?><div class="chips"><?php foreach ($misTurnos as $t) { echo chip_turno($t); } ?></div>
        <?php else: ?><p class="muted" style="margin:0">No tenés turnos asignados hoy.</p><?php endif; ?>
    </section>
    <section class="card">
        <h3>Marcaciones de hoy <span class="pill"><?= count($deHoy) ?></span></h3>
        <ul class="lista">
        <?php foreach ($deHoy as $s): ?>
            <li><span class="av <?= empty($s['hora_salida']) ? 'verde' : '' ?>"><?= empty($s['hora_salida']) ? '●' : '✓' ?></span>
                <div class="info"><b><?= e($s['hora_entrada']) ?> → <?= e($s['hora_salida'] ?? 'en curso') ?></b>
                    <small><?= e($s['turno_nombre'] ?: 'Sin turno') ?><?= !empty($s['hora_salida']) ? ' · ' . fmt_horas(minutos_sesion($s)) . ' h' : '' ?></small></div>
                <?= tag(estado_final($s)) ?></li>
        <?php endforeach; if (!$deHoy): ?><li class="vacio-lista">Todavía no marcaste hoy.</li><?php endif; ?>
        </ul>
    </section>
</aside>
</div>
<script>
const base = <?= $ahora * 1000 ?> - Date.now();
setInterval(() => { document.getElementById('reloj').textContent =
    new Date(Date.now() + base).toLocaleTimeString('es-AR', {hour12: false}); }, 1000);

const form = document.getElementById('f');
if (form) {
    const req = <?= $requiereFoto ? 'true' : 'false' ?>;
    const v = document.getElementById('v'), btn = document.getElementById('btn'),
          msg = document.getElementById('msg'), cam = document.getElementById('camara');
    const sinCamara = (t) => {
        msg.textContent = t + (req ? ' No se puede marcar sin foto.' : '');
        cam.hidden = true;
        if (!req) btn.disabled = false;
    };
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        sinCamara('La cámara no está disponible (hace falta abrir el sistema por HTTPS).');
    } else {
        navigator.mediaDevices.getUserMedia({video: {facingMode: 'user', width: {ideal: 640}, height: {ideal: 480}}, audio: false})
            .then(s => { v.srcObject = s; v.onloadedmetadata = () => { btn.disabled = false; }; })
            .catch(() => sinCamara('No se pudo acceder a la cámara: permití el acceso en el navegador.'));
    }
    form.addEventListener('submit', () => {
        if (v.srcObject && v.videoWidth) {
            const c = document.getElementById('c'), w = Math.min(480, v.videoWidth);
            c.width = w; c.height = Math.round(v.videoHeight * w / v.videoWidth);
            c.getContext('2d').drawImage(v, 0, 0, c.width, c.height);
            document.getElementById('foto').value = c.toDataURL('image/jpeg', 0.7);
        }
        btn.disabled = true;
    });
}
</script>
<?php pie();
