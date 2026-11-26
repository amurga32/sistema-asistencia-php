<?php
require __DIR__ . '/../app/bootstrap.php';

if (usuario()) {
    redirect('index.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $st = db()->prepare('SELECT * FROM empleados WHERE (legajo = ? OR dni = ?) AND activo = 1');
    $id = trim($_POST['usuario'] ?? '');
    $st->execute([$id, $id]);
    $emp = $st->fetch();
    if ($emp && password_verify($_POST['clave'] ?? '', $emp['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['uid'] = $emp['id'];
        redirect('index.php');
    }
    auditar('login_fallido', $emp ? (int) $emp['id'] : null, 'Usuario ingresado: ' . substr($id, 0, 30));
    $error = 'Legajo/DNI o clave incorrectos.';
}
$aviso = flash();
?>
<!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Ingresar · Control de Asistencia</title><link rel="stylesheet" href="assets/style.css"></head>
<body>
<div class="login-wrap">
    <section class="login-brand">
        <span class="logo">⏱</span>
        <h1>Control de<br>Asistencia</h1>
        <p>Empleados, turnos y fichadas en un solo lugar.</p>
        <ul>
            <li>🕒 Turnos de mañana, tarde y noche, con dobles turnos</li>
            <li>📸 Foto y control de red en cada marcación</li>
            <li>🛡 Alertas ante intentos de marcar por otro</li>
        </ul>
    </section>
    <section class="login-form">
        <form method="post">
            <div><h2 style="margin:0">Ingresar</h2><p class="muted" style="margin:.2rem 0 0">Con tu legajo o DNI y tu clave.</p></div>
            <?php if ($aviso): ?><div class="aviso <?= e($aviso[0]) ?>" style="margin:0"><?= e($aviso[1]) ?></div><?php endif; ?>
            <?php if ($error): ?><div class="aviso error" style="margin:0"><?= e($error) ?></div><?php endif; ?>
            <?= csrf_field() ?>
            <label>Legajo o DNI <input name="usuario" required autofocus autocomplete="username"></label>
            <label>Clave <input type="password" name="clave" required autocomplete="current-password"></label>
            <button class="btn">Ingresar</button>
            <p class="muted" style="margin:0">Primer acceso: legajo <b>0001</b> · clave <b>admin123</b> (cambiala).</p>
        </form>
    </section>
</div>
</body></html>
