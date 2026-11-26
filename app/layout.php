<?php
function tag(string $txt, ?string $tipo = null): string
{
    static $m = [
        'Puntual' => 'ok', 'Completó' => 'ok', 'Activo' => 'ok', 'Tardanza' => 'warn', 'Pendiente' => 'gris',
        'Sin turno' => 'gris', 'Inactivo' => 'gris', 'En turno' => 'azul', 'Sin salida' => 'rojo', 'Ausente' => 'rojo', 'Alerta' => 'rojo',
    ];
    $tipo ??= $m[$txt] ?? 'gris';
    return '<span class="tag tag-' . $tipo . '">' . e($txt) . '</span>';
}

function chip(string $nombre, ?string $rango = null, string $color = '#475569'): string
{
    return '<span class="chip" style="--c:' . e($color) . '">' . e($nombre) . ($rango ? ' <small>' . e($rango) . '</small>' : '') . '</span>';
}

function chip_turno(array $t): string
{
    return chip($t['nombre'], $t['hora_inicio'] . '–' . $t['hora_fin'], $t['color'] ?? '#475569');
}

/** Turno guardado en una fichada (con su color actual si el turno todavía existe). */
function chip_sesion(array $s): string
{
    if (empty($s['turno_nombre'])) {
        return '<span class="muted">Sin turno</span>';
    }
    return chip($s['turno_nombre'], ($s['turno_inicio'] ?? '') . '–' . ($s['turno_fin'] ?? ''), $s['turno_color'] ?? '#475569');
}

function cabecera(string $titulo, string $sub = '', string $acciones = ''): void
{
    $u = usuario();
    $f = flash();
    $actual = basename($_SERVER['SCRIPT_NAME']);
    $actual = ['empleado_form.php' => 'empleados.php', 'asistencia_form.php' => 'asistencia.php'][$actual] ?? $actual;
    $item = fn(string $href, string $ico, string $txt) =>
        '<a href="' . $href . '"' . ($actual === $href ? ' class="activo" aria-current="page"' : '') . '>'
        . '<span class="ico">' . $ico . '</span>' . $txt . '</a>';
    ?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($titulo) ?> · Control de Asistencia</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body<?= $u ? ' class="con-menu"' : '' ?>>
<?php if ($u): ?>
<header class="top">
    <button class="burger" id="burger" type="button" aria-label="Abrir menú" aria-expanded="false" aria-controls="side">☰</button>
    <strong><?= e($titulo) ?></strong>
</header>
<div class="overlay" id="overlay"></div>
<aside class="side" id="side">
    <div class="marca"><span class="logo">⏱</span><span>Control de<br>Asistencia</span></div>
    <nav>
        <small>Mi cuenta</small>
        <?= $item('index.php', '🏠', 'Inicio') ?>
        <?= $item('marcar.php', '⏱', 'Marcar') ?>
        <?= $item('mis_registros.php', '📋', 'Mis registros') ?>
        <?php if ($u['rol'] === 'admin'): ?>
            <small>Administración</small>
            <?= $item('empleados.php', '👥', 'Empleados') ?>
            <?= $item('turnos.php', '🕒', 'Turnos') ?>
            <?= $item('asistencia.php', '🗓', 'Asistencia') ?>
            <?= $item('auditoria.php', '🛡', 'Auditoría') ?>
            <?= $item('ajustes.php', '⚙', 'Ajustes') ?>
        <?php endif; ?>
    </nav>
    <div class="usuario">
        <div class="quien"><b><?= e($u['nombre'] . ' ' . $u['apellido']) ?></b><span><?= e($u['rol'] === 'admin' ? 'Administrador' : ($u['cargo'] ?: 'Empleado')) ?></span></div>
        <a href="cambiar_clave.php">🔑 Cambiar clave</a>
        <a href="logout.php">⎋ Salir</a>
    </div>
</aside>
<?php endif; ?>
<main class="contenedor">
<?php if ($f): ?><div class="aviso <?= e($f[0]) ?>"><?= e($f[1]) ?></div><?php endif; ?>
<header class="page-head<?= $sub === '' && $acciones === '' ? ' solo-titulo' : '' ?>">
    <div><h1><?= e($titulo) ?></h1><?php if ($sub !== ''): ?><p class="sub"><?= e($sub) ?></p><?php endif; ?></div>
    <?php if ($acciones !== ''): ?><div class="acciones"><?= $acciones ?></div><?php endif; ?>
</header>
<?php
}

function pie(): void
{
    ?>
</main>
<script>
// Tablas responsive: en pantallas chicas cada fila se muestra como tarjeta con etiquetas.
document.querySelectorAll('table').forEach(t => {
    t.classList.add('resp');
    const rows = [...t.rows];
    if (!rows.length) return;
    const heads = [...rows[0].cells].map(c => c.textContent.trim());
    if ([...rows[0].cells].every(c => c.tagName === 'TH')) rows[0].classList.add('cab');
    rows.slice(1).forEach(r => {
        let i = 0;
        [...r.cells].forEach(c => {
            if (c.tagName === 'TD' && c.colSpan === 1) c.dataset.label = heads[i] || '';
            if (c.tagName === 'TD' && !c.textContent.trim() && !c.querySelector('img,button,input,a')) c.classList.add('vacio');
            i += c.colSpan;
        });
    });
});
// Menú lateral deslizable (celular / tablet).
const side = document.getElementById('side'), burger = document.getElementById('burger'), overlay = document.getElementById('overlay');
function menu(abrir) {
    if (!side) return;
    side.classList.toggle('abierto', abrir);
    document.body.classList.toggle('menu-abierto', abrir);
    burger.setAttribute('aria-expanded', abrir);
}
if (burger) {
    burger.addEventListener('click', () => menu(!side.classList.contains('abierto')));
    overlay.addEventListener('click', () => menu(false));
    document.addEventListener('keydown', e => { if (e.key === 'Escape') menu(false); });
    window.addEventListener('resize', () => { if (innerWidth > 900) menu(false); });
}
</script>
</body>
</html>
<?php
}
