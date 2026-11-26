<?php
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/layout.php';
requiere_admin();

$ip = client_ip();
$did = device_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    switch ($_POST['accion'] ?? '') {
        case 'guardar':
            $reglas = lineas($_POST['ips'] ?? '');
            $mal = array_filter($reglas, fn($r) => !regla_valida($r));
            if ($mal) {
                flash('Reglas inválidas: ' . implode(', ', $mal), 'error');
                break;
            }
            set_ajuste('ips_permitidas', implode("\n", $reglas));
            set_ajuste('foto_obligatoria', isset($_POST['foto_obligatoria']) ? '1' : '0');
            flash('Ajustes guardados.');
            break;
        case 'agregar_ip':
            $reglas = lineas(ajuste('ips_permitidas'));
            if (!in_array($ip, $reglas, true)) {
                $reglas[] = $ip;
            }
            set_ajuste('ips_permitidas', implode("\n", $reglas));
            flash("Se agregó $ip a las redes permitidas.");
            break;
        case 'kiosco_on':
        case 'kiosco_off':
            $k = array_diff(lineas(ajuste('kioscos')), [$did]);
            if ($_POST['accion'] === 'kiosco_on') {
                $k[] = $did;
            }
            set_ajuste('kioscos', implode("\n", $k));
            flash($_POST['accion'] === 'kiosco_on' ? 'Este dispositivo ahora es un kiosco compartido.' : 'Este dispositivo ya no es kiosco.');
            break;
    }
    redirect('ajustes.php');
}

$reglas = ajuste('ips_permitidas');
$fotoObl = ajuste('foto_obligatoria', '1') === '1';
$kiosco = es_kiosco();

cabecera('Ajustes de seguridad');
?>
<form method="post" class="card">
    <?= csrf_field() ?>
    <h2>Marcar solo desde el local</h2>
    <p class="muted">Una IP o rango por línea (ej. <code>190.12.34.56</code> o <code>190.12.34.0/24</code>). Si queda vacío, se puede marcar desde cualquier lugar.
    Solo se restringe la <b>marcación</b>: el resto del sistema sigue accesible desde afuera.</p>
    <label>IPs / redes permitidas
        <textarea name="ips" rows="4" placeholder="190.12.34.56"><?= e($reglas) ?></textarea>
    </label>
    <p>Tu IP actual: <b><?= e($ip) ?></b> · <?= ip_permitida($ip) ? 'permitida' : 'NO permitida' ?></p>
    <h2>Foto</h2>
    <label style="flex-direction:row;align-items:center;gap:.6rem">
        <input type="checkbox" name="foto_obligatoria" value="1" <?= $fotoObl ? 'checked' : '' ?> style="min-height:auto">
        Exigir foto en cada entrada y salida
    </label>
    <p style="margin-top:1rem"><button class="btn" name="accion" value="guardar">Guardar ajustes</button></p>
</form>

<form method="post" class="card">
    <?= csrf_field() ?>
    <h2>Atajos</h2>
    <p><button class="btn sec" name="accion" value="agregar_ip">Agregar mi IP actual (<?= e($ip) ?>)</button></p>
    <p class="muted">Hacelo desde el wifi del local. Si tu proveedor de internet te cambia la IP (IP dinámica), tenés que actualizarla acá;
    pedile una IP fija o usá un rango.</p>
</form>

<form method="post" class="card">
    <?= csrf_field() ?>
    <h2>Kiosco compartido</h2>
    <p class="muted">Si los empleados marcan desde una sola PC o tablet del local, marcala como kiosco: no genera alertas por "mismo dispositivo"
    y cierra la sesión después de cada marcación, para que el siguiente no marque como el anterior.</p>
    <p>Este dispositivo: <b><?= $kiosco ? 'es kiosco' : 'no es kiosco' ?></b></p>
    <button class="btn <?= $kiosco ? 'rojo' : 'sec' ?>" name="accion" value="<?= $kiosco ? 'kiosco_off' : 'kiosco_on' ?>">
        <?= $kiosco ? 'Quitar kiosco de este dispositivo' : 'Usar este dispositivo como kiosco' ?></button>
</form>
<?php pie();
