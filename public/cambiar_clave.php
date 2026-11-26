<?php
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/layout.php';
$u = requiere_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $nueva = $_POST['nueva'] ?? '';
    if (!password_verify($_POST['actual'] ?? '', $u['password_hash'])) {
        flash('La clave actual no es correcta.', 'error');
    } elseif (strlen($nueva) < 8 || $nueva !== ($_POST['repetir'] ?? '')) {
        flash('La nueva clave debe tener 8+ caracteres y coincidir en ambos campos.', 'error');
    } else {
        db()->prepare('UPDATE empleados SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($nueva, PASSWORD_DEFAULT), $u['id']]);
        flash('Clave actualizada.');
    }
    redirect('cambiar_clave.php');
}
cabecera('Cambiar clave');
?>
<form method="post" class="card form-grid" style="max-width:420px;grid-template-columns:1fr">
    <?= csrf_field() ?>
    <label>Clave actual <input type="password" name="actual" required></label>
    <label>Nueva clave <input type="password" name="nueva" minlength="8" required></label>
    <label>Repetir nueva clave <input type="password" name="repetir" minlength="8" required></label>
    <button class="btn">Guardar</button>
</form>
<?php pie();
