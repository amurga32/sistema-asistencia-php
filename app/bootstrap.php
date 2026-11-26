<?php
declare(strict_types=1);

date_default_timezone_set('America/Argentina/Tucuman');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();

const TOLERANCIA_DEFECTO = 10; // minutos de tolerancia de un turno nuevo
const DIAS_ABREV = ['', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];

function sql_asistencias(string $tabla): string
{
    return "CREATE TABLE $tabla (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        empleado_id INTEGER NOT NULL REFERENCES empleados(id) ON DELETE CASCADE,
        fecha TEXT NOT NULL,            -- día en que se marcó la entrada
        fecha_turno TEXT,               -- día al que pertenece el turno (un turno de noche empieza el día anterior)
        hora_entrada TEXT,
        salida_fecha TEXT,              -- día en que se marcó la salida (puede ser el día siguiente)
        hora_salida TEXT,
        turno_id INTEGER,
        turno_nombre TEXT, turno_inicio TEXT, turno_fin TEXT, tolerancia INTEGER, -- copia del turno al momento de marcar
        observacion TEXT,
        foto_entrada TEXT, foto_salida TEXT, ip_entrada TEXT, ip_salida TEXT
    )";
}

function columnas(PDO $pdo, string $tabla): array
{
    return array_column($pdo->query("PRAGMA table_info($tabla)")->fetchAll(), 'name');
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    $dir = __DIR__ . '/../data';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $nuevo = !file_exists($dir . '/asistencia.sqlite');
    $pdo = new PDO('sqlite:' . $dir . '/asistencia.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    instalar_esquema($pdo, $nuevo);
    return $pdo;
}

function instalar_esquema(PDO $pdo, bool $nuevo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS empleados (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            legajo TEXT NOT NULL UNIQUE,
            nombre TEXT NOT NULL,
            apellido TEXT NOT NULL,
            dni TEXT NOT NULL UNIQUE,
            email TEXT, cargo TEXT, departamento TEXT, fecha_ingreso TEXT,
            rol TEXT NOT NULL DEFAULT 'empleado' CHECK (rol IN ('admin','empleado')),
            password_hash TEXT NOT NULL,
            activo INTEGER NOT NULL DEFAULT 1
        );
        CREATE TABLE IF NOT EXISTS turnos (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nombre TEXT NOT NULL,
            hora_inicio TEXT NOT NULL,
            hora_fin TEXT NOT NULL,
            tolerancia INTEGER NOT NULL DEFAULT 10,
            color TEXT NOT NULL DEFAULT '#4f46e5',
            activo INTEGER NOT NULL DEFAULT 1
        );
        CREATE TABLE IF NOT EXISTS empleado_turnos (
            empleado_id INTEGER NOT NULL REFERENCES empleados(id) ON DELETE CASCADE,
            dia INTEGER NOT NULL CHECK (dia BETWEEN 1 AND 7),
            turno_id INTEGER NOT NULL REFERENCES turnos(id) ON DELETE CASCADE,
            PRIMARY KEY (empleado_id, dia, turno_id)
        );
        CREATE TABLE IF NOT EXISTS ajustes (clave TEXT PRIMARY KEY, valor TEXT NOT NULL DEFAULT '');
        CREATE TABLE IF NOT EXISTS auditoria (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            creado TEXT NOT NULL,
            empleado_id INTEGER,
            evento TEXT NOT NULL,
            ip TEXT, device_id TEXT, user_agent TEXT, foto TEXT, detalle TEXT,
            alerta INTEGER NOT NULL DEFAULT 0
        );
        CREATE INDEX IF NOT EXISTS idx_aud_creado ON auditoria(creado);
    ");

    $existe = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='asistencias'")->fetchColumn();
    if (!$existe) {
        $pdo->exec(sql_asistencias('asistencias'));
    } elseif (!in_array('fecha_turno', columnas($pdo, 'asistencias'), true)) {
        migrar_asistencias($pdo);
    }
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_asist_fecha ON asistencias(fecha);
                CREATE INDEX IF NOT EXISTS idx_asist_turno ON asistencias(empleado_id, fecha_turno)');

    if ($nuevo) {
        $pdo->prepare("INSERT INTO empleados (legajo,nombre,apellido,dni,cargo,departamento,fecha_ingreso,rol,password_hash)
            VALUES ('0001','Administrador','Sistema','00000000','Administrador','Sistemas',?, 'admin', ?)")
            ->execute([date('Y-m-d'), password_hash('admin123', PASSWORD_DEFAULT)]);
    }
    turnos_iniciales($pdo, $nuevo);
}

/** Pasa la tabla de asistencias del formato viejo (una marca por día) al nuevo (varias fichadas y turnos). */
function migrar_asistencias(PDO $pdo): void
{
    $viejas = columnas($pdo, 'asistencias');
    $emp = columnas($pdo, 'empleados');
    $col = fn(string $c) => in_array($c, $viejas, true) ? "a.$c" : 'NULL';
    $ini = in_array('hora_entrada', $emp, true) ? 'e.hora_entrada' : 'NULL';
    $fin = in_array('hora_salida', $emp, true) ? 'e.hora_salida' : 'NULL';

    $pdo->beginTransaction();
    $pdo->exec(sql_asistencias('asistencias_nueva'));
    $pdo->exec("INSERT INTO asistencias_nueva
        (id, empleado_id, fecha, fecha_turno, hora_entrada, salida_fecha, hora_salida, turno_nombre, turno_inicio, turno_fin,
         tolerancia, observacion, foto_entrada, foto_salida, ip_entrada, ip_salida)
        SELECT a.id, a.empleado_id, a.fecha, a.fecha, a.hora_entrada,
               CASE WHEN a.hora_salida IS NOT NULL THEN a.fecha END, a.hora_salida,
               'Jornada', $ini, $fin, 10, {$col('observacion')},
               {$col('foto_entrada')}, {$col('foto_salida')}, {$col('ip_entrada')}, {$col('ip_salida')}
        FROM asistencias a JOIN empleados e ON e.id = a.empleado_id");
    $pdo->exec('DROP TABLE asistencias');
    $pdo->exec('ALTER TABLE asistencias_nueva RENAME TO asistencias');
    $pdo->commit();
}

/** Crea los turnos de ejemplo, o los arma a partir de los horarios que ya tenían los empleados. */
function turnos_iniciales(PDO $pdo, bool $nuevo): void
{
    if ($pdo->query("SELECT 1 FROM ajustes WHERE clave = 'turnos_listos'")->fetchColumn()) {
        return;
    }
    $ins = $pdo->prepare('INSERT INTO turnos (nombre, hora_inicio, hora_fin, tolerancia, color) VALUES (?,?,?,?,?)');
    $colores = ['#4f46e5', '#16a34a', '#d97706', '#7c3aed', '#db2777', '#0891b2'];
    $heredado = false;

    if (!$nuevo && in_array('hora_entrada', columnas($pdo, 'empleados'), true)) {
        $pares = $pdo->query("SELECT DISTINCT hora_entrada, hora_salida FROM empleados WHERE rol = 'empleado'")->fetchAll();
        $asig = $pdo->prepare("INSERT OR IGNORE INTO empleado_turnos (empleado_id, dia, turno_id)
            SELECT id, ?, ? FROM empleados WHERE rol = 'empleado' AND hora_entrada = ? AND hora_salida = ?");
        foreach ($pares as $i => $p) {
            $ins->execute(['Turno ' . $p['hora_entrada'] . '–' . $p['hora_salida'], $p['hora_entrada'], $p['hora_salida'], 10, $colores[$i % count($colores)]]);
            $tid = (int) $pdo->lastInsertId();
            for ($d = 1; $d <= 5; $d++) { // lunes a viernes
                $asig->execute([$d, $tid, $p['hora_entrada'], $p['hora_salida']]);
            }
            $heredado = true;
        }
    }
    if (!$heredado) {
        foreach ([['Mañana', '06:00', '14:00', '#4f46e5'], ['Tarde', '14:00', '22:00', '#d97706'], ['Noche', '22:00', '06:00', '#7c3aed']] as $t) {
            $ins->execute([$t[0], $t[1], $t[2], 10, $t[3]]);
        }
    }
    $pdo->exec("INSERT INTO ajustes (clave, valor) VALUES ('turnos_listos', '1')");
}

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $ok = isset($_POST['csrf']) && hash_equals($_SESSION['csrf'] ?? '', (string) $_POST['csrf']);
    if (!$ok) {
        http_response_code(400);
        exit('Token CSRF inválido. Volvé atrás y reintentá.');
    }
}

function flash(?string $msg = null, string $tipo = 'ok'): ?array
{
    if ($msg !== null) {
        $_SESSION['flash'] = [$tipo, $msg];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function usuario(): ?array
{
    if (empty($_SESSION['uid'])) {
        return null;
    }
    static $u = null;
    if ($u === null) {
        $st = db()->prepare('SELECT * FROM empleados WHERE id = ? AND activo = 1');
        $st->execute([$_SESSION['uid']]);
        $u = $st->fetch() ?: false;
    }
    return $u ?: null;
}

function requiere_login(): array
{
    $u = usuario();
    if (!$u) {
        redirect('login.php');
    }
    return $u;
}

function requiere_admin(): array
{
    $u = requiere_login();
    if ($u['rol'] !== 'admin') {
        http_response_code(403);
        exit('Acceso restringido a administradores.');
    }
    return $u;
}

/** Minutos de diferencia entre dos "HH:MM" (b - a). */
function minutos(string $a, string $b): int
{
    [$h1, $m1] = array_map('intval', explode(':', substr($a, 0, 5)));
    [$h2, $m2] = array_map('intval', explode(':', substr($b, 0, 5)));
    return ($h2 * 60 + $m2) - ($h1 * 60 + $m1);
}

function fmt_horas(int $min): string
{
    return sprintf('%d:%02d', intdiv(max($min, 0), 60), max($min, 0) % 60);
}

function fecha_valida(string $f): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $f);
    return $d && $d->format('Y-m-d') === $f;
}

function hora_valida(string $h): bool
{
    return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $h);
}

function fecha_corta(string $ymd): string
{
    $t = strtotime($ymd);
    return DIAS_ABREV[(int) date('N', $t)] . ' ' . date('d/m/Y', $t);
}

function iniciales(array $e): string
{
    return mb_strtoupper(mb_substr((string) $e['nombre'], 0, 1) . mb_substr((string) $e['apellido'], 0, 1));
}

require __DIR__ . '/seguridad.php';
require __DIR__ . '/turnos.php';
require __DIR__ . '/reportes.php';
