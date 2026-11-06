<?php
/*
 * LK Monitor - coletor de métricas.
 * Roda a cada 60 s pelo lkmonitor-collect.timer (usuário lkmonitor) e grava em LKM_DATA/metrics.sqlite.
 * Guarda 7 dias; os registros mais antigos são apagados aqui mesmo.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
ini_set('display_errors', 'stderr');
umask(027);
require __DIR__ . '/lib.php';

define('LKM_DATA', getenv('LKM_DATA') ?: '/var/lib/lkmonitor');
const KEEP_DAYS = 7;

$stateFile = LKM_DATA . '/collect.state';
$cur = sys_counters();
$prev = json_decode(rf($stateFile), true);
if (!is_array($prev) || !isset($prev['t'], $prev['cpu'], $prev['rx'], $prev['tx']) || $cur['t'] - $prev['t'] > 600 || $cur['t'] <= $prev['t']) {
    // primeira coleta (ou depois de muito tempo parado): mede 1 segundo
    $prev = $cur;
    sleep(1);
    $cur = sys_counters();
}
@file_put_contents($stateFile, json_encode($cur), LOCK_EX);

$dt = max(0.5, $cur['t'] - $prev['t']);
$dTot = $cur['cpu'][0] - $prev['cpu'][0];
$cpu = $dTot > 0 ? round(max(0, min(100, 100 * (1 - ($cur['cpu'][1] - $prev['cpu'][1]) / $dTot))), 1) : null;
$rate = fn(int $a, int $b) => $a >= $b ? round(($a - $b) / $dt, 1) : null; // contador zerado (reboot/interface nova)
$rx = $rate($cur['rx'], $prev['rx']);
$tx = $rate($cur['tx'], $prev['tx']);

$mi = meminfo();
$memTotal = $mi['MemTotal'] ?? 0;
$load = array_map('floatval', array_slice(explode(' ', rf('/proc/loadavg')), 0, 3));
$temp = temps()['cpu'];
$disks = disks();
$now = time();

$db = metrics_db(true);
if (!$db) {
    fwrite(STDERR, "LK Monitor: SQLite indisponível (instale php-sqlite3)\n");
    exit(1);
}
try {
    $db->beginTransaction();
    $st = $db->prepare('INSERT OR REPLACE INTO m (ts, cpu, l1, l5, l15, mem, mem_total, swap, swap_total, temp, rx, tx, tcp) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $st->execute([
        $now, $cpu, $load[0] ?? null, $load[1] ?? null, $load[2] ?? null,
        $memTotal - ($mi['MemAvailable'] ?? 0), $memTotal,
        ($mi['SwapTotal'] ?? 0) - ($mi['SwapFree'] ?? 0), $mi['SwapTotal'] ?? 0,
        $temp, $rx, $tx, tcp_established(),
    ]);
    $sd = $db->prepare('INSERT OR REPLACE INTO disk (ts, mnt, used, total) VALUES (?,?,?,?)');
    foreach ($disks as $d) $sd->execute([$now, $d['mnt'], (int)$d['used'], (int)$d['total']]);
    $old = $now - KEEP_DAYS * 86400;
    $db->exec("DELETE FROM m WHERE ts < $old");
    $db->exec("DELETE FROM disk WHERE ts < $old");
    $db->commit();
    if ((int)date('i') === 0) $db->exec('PRAGMA wal_checkpoint(TRUNCATE)');
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    fwrite(STDERR, 'LK Monitor: falha ao gravar métricas: ' . $e->getMessage() . "\n");
    exit(1);
}

// alertas: avaliados a cada coleta (um erro aqui não afeta o histórico)
try {
    require __DIR__ . '/alerts.inc.php';
    al_eval(['temp' => $temp, 'mem_pct' => $memTotal ? ($memTotal - ($mi['MemAvailable'] ?? 0)) / $memTotal * 100 : null, 'disks' => $disks], $db);
} catch (Throwable $e) {
    fwrite(STDERR, 'LK Monitor: falha ao avaliar alertas: ' . $e->getMessage() . "\n");
}
