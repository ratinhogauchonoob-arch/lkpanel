<?php
/*
 * LK Monitor - funções compartilhadas entre o painel (index.php) e o coletor (collect.php).
 * Instalado em /usr/local/lib/lkmonitor/lib.php.
 */
declare(strict_types=1);

function rf(string $f): string
{
    $s = @file_get_contents($f);
    return $s === false ? '' : $s;
}

function sh(string $cmd): string
{
    if (!function_exists('shell_exec')) return '';
    $o = @shell_exec($cmd . ' 2>/dev/null');
    return is_string($o) ? $o : '';
}

function temps(): array
{
    $list = [];
    foreach (glob('/sys/class/hwmon/hwmon*') ?: [] as $h) {
        $chip = trim(rf("$h/name"));
        foreach (glob("$h/temp*_input") ?: [] as $f) {
            $v = (int)trim(rf($f));
            if ($v <= 0 || $v > 150000) continue;
            $lab = trim(rf(str_replace('_input', '_label', $f)));
            $list[] = ['chip' => $chip, 'label' => $lab !== '' ? $lab : $chip, 'c' => round($v / 1000, 1)];
        }
    }
    $cpu = null;
    foreach ($list as $t) {
        if (in_array($t['label'], ['Package id 0', 'Tctl', 'Tdie'], true)) { $cpu = $t['c']; break; }
    }
    if ($cpu === null) {
        foreach ($list as $t) {
            if (in_array($t['chip'], ['coretemp', 'k10temp', 'zenpower', 'cpu_thermal', 'x86_pkg_temp', 'acpitz'], true)) $cpu = max($cpu ?? 0, $t['c']);
        }
    }
    if ($cpu === null && $list) $cpu = max(array_column($list, 'c'));
    if ($cpu === null) {
        foreach (glob('/sys/class/thermal/thermal_zone*/temp') ?: [] as $f) {
            $v = (int)trim(rf($f));
            if ($v > 0) $cpu = max($cpu ?? 0, round($v / 1000, 1));
        }
    }
    usort($list, fn($a, $b) => $b['c'] <=> $a['c']);
    return ['cpu' => $cpu, 'list' => array_slice($list, 0, 10)];
}

function disks(): array
{
    $ok = ['ext4', 'ext3', 'ext2', 'xfs', 'btrfs', 'vfat', 'exfat', 'ntfs', 'ntfs3', 'fuseblk', 'f2fs', 'zfs'];
    $seen = [];
    $out = [];
    foreach (explode("\n", rf('/proc/mounts')) as $l) {
        $p = explode(' ', $l);
        if (count($p) < 3 || !in_array($p[2], $ok, true)) continue;
        $dev = $p[0];
        $mnt = str_replace('\040', ' ', $p[1]);
        if (isset($seen[$dev]) || str_starts_with($mnt, '/snap/')) continue;
        $t = @disk_total_space($mnt);
        $f = @disk_free_space($mnt);
        if (!$t) continue;
        $seen[$dev] = 1;
        $out[] = ['mnt' => $mnt, 'dev' => basename($dev), 'fs' => $p[2], 'total' => $t, 'used' => $t - $f];
    }
    return $out;
}

function tcp_established(): int
{
    $n = 0;
    foreach (['/proc/net/tcp', '/proc/net/tcp6'] as $f) {
        foreach (array_slice(explode("\n", rf($f)), 1) as $l) {
            $p = preg_split('/\s+/', trim($l));
            if (($p[3] ?? '') === '01') $n++;
        }
    }
    return $n;
}

function meminfo(): array
{
    $mi = [];
    foreach (explode("\n", rf('/proc/meminfo')) as $l) {
        if (preg_match('/^(\w+):\s+(\d+)/', $l, $m)) $mi[$m[1]] = (int)$m[2] * 1024;
    }
    return $mi;
}

function skip_iface(string $if): bool
{
    return $if === 'lo' || (bool)preg_match('/^(veth|docker|br-|virbr|vnet)/', $if);
}

/** Contadores acumulados de CPU (total/ocioso) e rede (bytes recebidos/enviados), para calcular taxas. */
function sys_counters(): array
{
    $cpu = [0, 0];
    if (preg_match('/^cpu\s+(.*)$/m', rf('/proc/stat'), $m)) {
        $v = array_map('intval', preg_split('/\s+/', trim($m[1])));
        $cpu = [array_sum(array_slice($v, 0, 8)), ($v[3] ?? 0) + ($v[4] ?? 0)];
    }
    $rx = 0;
    $tx = 0;
    foreach (explode("\n", rf('/proc/net/dev')) as $l) {
        if (!preg_match('/^\s*([^:\s]+):\s*(.*)$/', $l, $m) || skip_iface($m[1])) continue;
        $p = preg_split('/\s+/', trim($m[2]));
        $rx += (int)($p[0] ?? 0);
        $tx += (int)($p[8] ?? 0);
    }
    return ['t' => microtime(true), 'cpu' => $cpu, 'rx' => $rx, 'tx' => $tx];
}

/* ------------------------------------------------------------------ histórico (SQLite) */

function metrics_file(): string
{
    return (defined('LKM_DATA') ? LKM_DATA : (getenv('LKM_DATA') ?: '/var/lib/lkmonitor')) . '/metrics.sqlite';
}

function metrics_db(bool $create = false): ?PDO
{
    if (!class_exists('PDO') || !in_array('sqlite', PDO::getAvailableDrivers(), true)) return null;
    $f = metrics_file();
    if (!$create && !is_file($f)) return null;
    try {
        $db = new PDO('sqlite:' . $f, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]);
        $db->exec('PRAGMA busy_timeout=10000');
        if ($create) {
            $db->exec('PRAGMA journal_mode=WAL');
            $db->exec('CREATE TABLE IF NOT EXISTS m (ts INTEGER PRIMARY KEY, cpu REAL, l1 REAL, l5 REAL, l15 REAL,
                mem INTEGER, mem_total INTEGER, swap INTEGER, swap_total INTEGER, temp REAL, rx REAL, tx REAL, tcp INTEGER)');
            $db->exec('CREATE TABLE IF NOT EXISTS disk (ts INTEGER NOT NULL, mnt TEXT NOT NULL, used INTEGER, total INTEGER, PRIMARY KEY (ts, mnt)) WITHOUT ROWID');
        }
        return $db;
    } catch (Throwable $e) {
        return null;
    }
}

/** Histórico agrupado para os gráficos. $range: 1h, 24h ou 7d. Cada série vem com média, mínimo e máximo por intervalo. */
function metrics_history(string $range): array
{
    $cfg = ['1h' => [3600, 60], '24h' => [86400, 300], '7d' => [604800, 1800]];
    if (!isset($cfg[$range])) return ['ok' => false, 'error' => 'Período inválido'];
    [$span, $step] = $cfg[$range];
    $db = metrics_db();
    if (!$db) {
        $why = class_exists('PDO') && in_array('sqlite', PDO::getAvailableDrivers(), true) ? 'empty' : 'sqlite';
        return ['ok' => false, 'error' => $why];
    }
    $now = time();
    $since = $now - $span;
    $cols = [
        'cpu' => 'cpu',
        'mem' => 'CASE WHEN mem_total > 0 THEN mem * 100.0 / mem_total END',
        'rx' => 'rx', 'tx' => 'tx', 'temp' => 'temp', 'load' => 'l1',
        'swap' => 'CASE WHEN swap_total > 0 THEN swap * 100.0 / swap_total END',
        'tcp' => 'tcp',
    ];
    $sel = ["(ts / $step) * $step AS b"];
    foreach ($cols as $k => $expr) $sel[] = "AVG($expr) AS {$k}_a, MIN($expr) AS {$k}_lo, MAX($expr) AS {$k}_hi";
    try {
        $st = $db->query('SELECT ' . implode(', ', $sel) . " FROM m WHERE ts >= $since GROUP BY b ORDER BY b");
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $last = $db->query('SELECT MAX(ts) FROM m')->fetchColumn();
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'empty'];
    }
    $t = [];
    $s = [];
    foreach ($cols as $k => $_) $s[$k] = ['a' => [], 'lo' => [], 'hi' => []];
    $r1 = fn($v) => $v === null ? null : round((float)$v, 1);
    foreach ($rows as $r) {
        $t[] = (int)$r['b'] + intdiv($step, 2);
        foreach ($cols as $k => $_) {
            $s[$k]['a'][] = $r1($r[$k . '_a']);
            $s[$k]['lo'][] = $r1($r[$k . '_lo']);
            $s[$k]['hi'][] = $r1($r[$k . '_hi']);
        }
    }
    return ['ok' => true, 'range' => $range, 'step' => $step, 'from' => $since, 'to' => $now, 'last' => $last ? (int)$last : null, 't' => $t, 's' => $s];
}
