<?php
// Turnos, horario semanal y fichadas. Soporta turnos nocturnos (cruzan la medianoche),
// turnos partidos y dobles turnos (varios turnos el mismo día, cada uno con su entrada y salida).

const ADELANTO_MIN = 90;            // se puede marcar la entrada hasta 90 min antes del inicio del turno
const MAX_SESION_HORAS = 16;        // una entrada sin salida más vieja que esto se considera olvidada
const MAX_FICHADAS_DIA = 4;         // entradas máximas por día
const MIN_ENTRE_FICHADAS_SEG = 120; // evita marcaciones dobles por error
const DIAS_SEMANA = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];
const COLORES_TURNO = ['#4f46e5', '#16a34a', '#d97706', '#7c3aed', '#db2777', '#0891b2', '#475569'];

function ts(string $fecha, string $hora): int
{
    return (int) strtotime("$fecha $hora:00");
}

function turnos_todos(bool $soloActivos = true): array
{
    return db()->query('SELECT * FROM turnos' . ($soloActivos ? ' WHERE activo = 1' : '') . ' ORDER BY hora_inicio, nombre')->fetchAll();
}

function cruza_medianoche(array $t): bool
{
    return minutos($t['hora_inicio'], $t['hora_fin']) <= 0;
}

function dur_turno(array $t): int
{
    $d = minutos($t['hora_inicio'], $t['hora_fin']);
    return $d <= 0 ? $d + 1440 : $d;
}

/** Diferencia con signo en minutos (hora - ref), en el rango -12h..+12h, correcta aunque se cruce la medianoche. */
function dif_signed(string $ref, string $hora): int
{
    $x = minutos($ref, $hora);
    return ((($x + 720) % 1440) + 1440) % 1440 - 720;
}

function turnos_asignados(int $emp, string $fecha): array
{
    $st = db()->prepare('SELECT t.* FROM empleado_turnos et JOIN turnos t ON t.id = et.turno_id
        WHERE et.empleado_id = ? AND et.dia = ? AND t.activo = 1 ORDER BY t.hora_inicio');
    $st->execute([$emp, (int) date('N', strtotime($fecha))]);
    return $st->fetchAll();
}

/** [dia => [turnos]] del horario semanal de un empleado. */
function plan_semanal(int $emp): array
{
    $st = db()->prepare('SELECT et.dia, t.* FROM empleado_turnos et JOIN turnos t ON t.id = et.turno_id
        WHERE et.empleado_id = ? AND t.activo = 1 ORDER BY et.dia, t.hora_inicio');
    $st->execute([$emp]);
    $plan = array_fill_keys(array_keys(DIAS_SEMANA), []);
    foreach ($st->fetchAll() as $r) {
        $plan[(int) $r['dia']][] = $r;
    }
    return $plan;
}

function turno_usado(int $emp, int $turno, string $fechaTurno): bool
{
    $st = db()->prepare('SELECT COUNT(*) FROM asistencias WHERE empleado_id = ? AND turno_id = ? AND fecha_turno = ?');
    $st->execute([$emp, $turno, $fechaTurno]);
    return (int) $st->fetchColumn() > 0;
}

function fichadas_del_dia(int $emp, string $fecha): int
{
    $st = db()->prepare('SELECT COUNT(*) FROM asistencias WHERE empleado_id = ? AND fecha = ?');
    $st->execute([$emp, $fecha]);
    return (int) $st->fetchColumn();
}

function sesion_vencida(array $s): bool
{
    return empty($s['hora_salida']) && !empty($s['hora_entrada'])
        && time() - ts($s['fecha'], $s['hora_entrada']) > MAX_SESION_HORAS * 3600;
}

/** Entrada sin salida todavía vigente (el empleado está "en turno"). */
function sesion_abierta(int $emp): ?array
{
    $st = db()->prepare('SELECT * FROM asistencias WHERE empleado_id = ? AND hora_salida IS NULL AND hora_entrada IS NOT NULL
        ORDER BY fecha DESC, hora_entrada DESC LIMIT 1');
    $st->execute([$emp]);
    $s = $st->fetch();
    return $s && !sesion_vencida($s) ? $s : null;
}

function minutos_sesion(array $s): int
{
    if (empty($s['hora_salida']) || empty($s['hora_entrada'])) {
        return 0;
    }
    $fs = $s['salida_fecha'] ?: $s['fecha'];
    return max((int) round((ts($fs, $s['hora_salida']) - ts($s['fecha'], $s['hora_entrada'])) / 60), 0);
}

function estado_sesion(array $s): string
{
    if (empty($s['turno_inicio']) || empty($s['hora_entrada'])) {
        return 'Sin turno';
    }
    $tol = (int) ($s['tolerancia'] ?? TOLERANCIA_DEFECTO);
    return dif_signed($s['turno_inicio'], $s['hora_entrada']) > $tol ? 'Tardanza' : 'Puntual';
}

function estado_final(array $s): string
{
    return sesion_vencida($s) ? 'Sin salida' : estado_sesion($s);
}

/** Si la entrada de un turno de noche se marca pasada la medianoche, el turno pertenece al día anterior. */
function calcular_fecha_turno(array $t, string $fecha, string $horaEntrada): string
{
    if (cruza_medianoche($t) && $horaEntrada < $t['hora_inicio'] && $horaEntrada <= $t['hora_fin']) {
        return date('Y-m-d', strtotime($fecha . ' -1 day'));
    }
    return $fecha;
}

/** Turno que corresponde a una entrada marcada ahora: el asignado, aún no usado, cuyo inicio esté más cerca. */
function elegir_turno(int $emp, int $ahora): ?array
{
    $hoy = date('Y-m-d', $ahora);
    $cands = [];
    foreach ([$hoy, date('Y-m-d', $ahora - 86400)] as $f) {
        foreach (turnos_asignados($emp, $f) as $t) {
            if ($f !== $hoy && !cruza_medianoche($t)) {
                continue; // de ayer solo sirven los que siguen corriendo después de medianoche
            }
            $ini = ts($f, $t['hora_inicio']);
            $fin = $ini + dur_turno($t) * 60;
            if ($ahora < $ini - ADELANTO_MIN * 60 || $ahora > $fin || turno_usado($emp, (int) $t['id'], $f)) {
                continue;
            }
            $cands[] = ['turno' => $t, 'fecha_turno' => $f, 'dist' => abs($ahora - $ini)];
        }
    }
    usort($cands, fn($a, $b) => $a['dist'] <=> $b['dist']);
    return $cands[0] ?? null;
}
