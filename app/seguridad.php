<?php
// Controles anti "marcar por otro": red del local, foto, auditoría y alertas.

const TRUST_PROXY = false;               // true solo si estás detrás de un proxy/CDN propio que pone X-Forwarded-For
const ALERTA_MISMO_DISPOSITIVO_MIN = 10; // minutos para considerar sospechoso el mismo dispositivo con otro empleado

function client_ip(): string
{
    if (TRUST_PROXY && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function ajuste(string $clave, string $def = ''): string
{
    $st = db()->prepare('SELECT valor FROM ajustes WHERE clave = ?');
    $st->execute([$clave]);
    $v = $st->fetchColumn();
    return $v === false ? $def : (string) $v;
}

function set_ajuste(string $clave, string $valor): void
{
    db()->prepare('INSERT INTO ajustes (clave, valor) VALUES (?, ?) ON CONFLICT(clave) DO UPDATE SET valor = excluded.valor')
        ->execute([$clave, $valor]);
}

function lineas(string $texto): array
{
    return array_values(array_filter(array_map('trim', preg_split('/\R/', $texto) ?: [])));
}

/** Regla válida: una IP (v4/v6) o un rango IPv4 en formato CIDR (ej. 190.1.2.0/24). */
function regla_valida(string $regla): bool
{
    if (!str_contains($regla, '/')) {
        return filter_var($regla, FILTER_VALIDATE_IP) !== false;
    }
    [$red, $bits] = explode('/', $regla, 2);
    return ip2long($red) !== false && ctype_digit($bits) && (int) $bits <= 32;
}

function ip_en_regla(string $ip, string $regla): bool
{
    if (!str_contains($regla, '/')) {
        $a = @inet_pton($ip);
        return $a !== false && $a === @inet_pton($regla);
    }
    [$red, $bits] = explode('/', $regla, 2);
    $a = ip2long($ip);
    $r = ip2long($red);
    if ($a === false || $r === false || !ctype_digit($bits) || (int) $bits > 32) {
        return false;
    }
    $mask = (int) $bits === 0 ? 0 : (-1 << (32 - (int) $bits)) & 0xFFFFFFFF;
    return ($a & $mask) === ($r & $mask);
}

/** Sin reglas cargadas = sin restricción. */
function ip_permitida(string $ip): bool
{
    $reglas = lineas(ajuste('ips_permitidas'));
    if (!$reglas) {
        return true;
    }
    foreach ($reglas as $r) {
        if (ip_en_regla($ip, $r)) {
            return true;
        }
    }
    return false;
}

/** Identificador anónimo y persistente del navegador/dispositivo (cookie). */
function device_id(): string
{
    $d = $_COOKIE['did'] ?? '';
    if (!preg_match('/^[a-f0-9]{32}$/', $d)) {
        $d = bin2hex(random_bytes(16));
        setcookie('did', $d, ['expires' => time() + 5 * 365 * 86400, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
        $_COOKIE['did'] = $d;
    }
    return $d;
}

/** Un kiosco es un dispositivo compartido del local (varios empleados lo usan legítimamente). */
function es_kiosco(): bool
{
    return in_array(device_id(), lineas(ajuste('kioscos')), true);
}

function auditar(string $evento, ?int $empleado, string $detalle = '', bool $alerta = false, ?string $foto = null): void
{
    db()->prepare('INSERT INTO auditoria (creado, empleado_id, evento, ip, device_id, user_agent, foto, detalle, alerta)
                   VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([
            date('Y-m-d H:i:s'), $empleado, $evento, client_ip(), device_id(),
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255), $foto, $detalle !== '' ? $detalle : null, $alerta ? 1 : 0,
        ]);
}

/** Texto de alerta si este dispositivo fue usado hace poco por OTRO empleado (salvo kioscos). */
function alerta_dispositivo(int $empleado): ?string
{
    if (es_kiosco()) {
        return null;
    }
    $desde = date('Y-m-d H:i:s', time() - ALERTA_MISMO_DISPOSITIVO_MIN * 60);
    $st = db()->prepare("SELECT a.creado, e.nombre, e.apellido FROM auditoria a JOIN empleados e ON e.id = a.empleado_id
        WHERE a.device_id = ? AND a.empleado_id <> ? AND a.evento IN ('entrada','salida') AND a.creado >= ?
        ORDER BY a.creado DESC LIMIT 1");
    $st->execute([device_id(), $empleado, $desde]);
    $r = $st->fetch();
    return $r ? 'Mismo dispositivo usado por ' . $r['apellido'] . ', ' . $r['nombre'] . ' a las ' . substr($r['creado'], 11, 5) : null;
}

/** Valida y guarda una foto JPEG (data URL). Devuelve el nombre de archivo o null. */
function guardar_foto(string $dataUrl): ?string
{
    $pref = 'data:image/jpeg;base64,';
    if (!str_starts_with($dataUrl, $pref)) {
        return null;
    }
    $bin = base64_decode(substr($dataUrl, strlen($pref)), true);
    if ($bin === false || strlen($bin) < 100 || strlen($bin) > 600 * 1024 || !str_starts_with($bin, "\xFF\xD8\xFF")) {
        return null;
    }
    $info = @getimagesizefromstring($bin);
    if (!$info || $info[2] !== IMAGETYPE_JPEG || $info[0] > 1280 || $info[1] > 1280) {
        return null;
    }
    $dir = __DIR__ . '/../data/fotos';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $nombre = bin2hex(random_bytes(16)) . '.jpg';
    file_put_contents($dir . '/' . $nombre, $bin);
    return $nombre;
}
