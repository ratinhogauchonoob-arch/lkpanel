<?php
/*
 * LK Monitor - executor de tarefas em segundo plano (deploy, backup, restauração) e comandos de backup do lk.
 *   php job.php <id>                        executa a tarefa (chamado via systemd-run pelos helpers)
 *   php job.php --auto                      backup automático (lkmonitor-backup.timer)
 *   php job.php --backup <site>|all         backup agora, mostrando o andamento (lk backup)
 *   php job.php --restore <site> <arquivo>  restaura um backup (lk restore)
 *   php job.php --list [site]               lista os backups (lk backups)
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
ini_set('display_errors', 'stderr');
umask(027);
set_time_limit(0);
require __DIR__ . '/jobs.inc.php';

$a = (string)($argv[1] ?? '');

/** Inicia a tarefa e mostra o log até terminar (para o terminal). */
function follow(array $J): int
{
    $logf = job_file($J['id'], 'log');
    $pos = 0;
    while (true) {
        clearstatcache();
        $size = (int)@filesize($logf);
        if ($size > $pos) {
            $h = fopen($logf, 'rb');
            fseek($h, $pos);
            echo fread($h, $size - $pos);
            fclose($h);
            $pos = $size;
        }
        $S = json_decode((string)@file_get_contents(job_file($J['id'], 'json')), true);
        if (is_array($S) && in_array($S['state'] ?? '', ['ok', 'error'], true)) {
            clearstatcache();
            if ((int)@filesize($logf) > $pos) continue;
            if (!empty($S['file'])) echo "Arquivo: {$S['file']}\n";
            return $S['state'] === 'ok' ? 0 : 1;
        }
        if (is_array($S) && !job_alive($S) && ($S['state'] ?? '') !== 'queued') { echo "\nA tarefa parou sem terminar.\n"; return 1; }
        usleep(400000);
    }
}

function need_site(string $s): string
{
    if (!site_valid($s)) {
        fwrite(STDERR, "Site não encontrado: $s (veja: lk sites)\n");
        exit(1);
    }
    return $s;
}

if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    fwrite(STDERR, "Execute como root.\n");
    exit(1);
}

switch ($a) {
    case '--auto':
        exit(bk_auto());

    case '--backup': {
        $t = (string)($argv[2] ?? '');
        if ($t === '') { fwrite(STDERR, "Uso: lk backup <site>|all\n"); exit(1); }
        $sites = $t === 'all' ? array_values(array_filter(array_map(fn($f) => basename($f, '.conf'), glob(SITES_AV . '/*.conf') ?: []), 'site_valid')) : [need_site($t)];
        if (!$sites) { echo "Nenhum site para copiar.\n"; exit(0); }
        $rc = 0;
        foreach ($sites as $s) {
            if (job_running_for($s)) { echo "$s: já existe uma tarefa em andamento, pulando.\n"; $rc = 1; continue; }
            $rc |= follow(job_start('backup', 'Backup ' . $s, ['site' => $s, 'root' => site_root_of($s), 'kind' => 'manual']));
            echo "\n";
        }
        exit($rc);
    }

    case '--restore': {
        $s = need_site((string)($argv[2] ?? ''));
        $f = basename((string)($argv[3] ?? ''));
        try { $p = bk_file_of($s, $f); } catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . " (veja: lk backups $s)\n"); exit(1); }
        if (job_running_for($s)) { fwrite(STDERR, "Já existe uma tarefa em andamento para $s.\n"); exit(1); }
        $php = preg_match('#php(\d+\.\d+)-fpm\.sock#', (string)@file_get_contents(SITES_AV . "/$s.conf"), $m) ? $m[1] : null;
        exit(follow(job_start('restore', 'Restaurar ' . $s, ['site' => $s, 'root' => site_root_of($s), 'file_in' => $p, 'php' => $php])));
    }

    case '--list': {
        $only = (string)($argv[2] ?? '');
        $sites = $only !== '' ? [need_site($only)] : array_map('basename', glob(BK_DIR . '/*', GLOB_ONLYDIR) ?: []);
        $n = 0;
        $kinds = ['manual' => 'manual', 'auto' => 'automático', 'seguranca' => 'antes de restaurar'];
        foreach ($sites as $s) {
            $l = bk_list($s);
            if (!$l) continue;
            echo "\n  $s\n";
            foreach ($l as $m) {
                $k = $kinds[$m['kind'] ?? ''] ?? '-';
                printf("    %-22s %-10s %s %s\n", $m['file'], bk_size($m['size'] ?? 0), $k . str_repeat(' ', max(0, 20 - mb_strlen($k))),($m['db']['type'] ?? null) ? 'banco ' . ($m['db']['name'] ?? basename($m['db']['path'] ?? '')) : 'sem banco');
                $n++;
            }
        }
        echo $n ? "\n  Pasta: " . BK_DIR . "/<site>/\n  Restaurar: lk restore <site> <arquivo>\n" : "Nenhum backup ainda. Crie com: lk backup <site>\n";
        exit(0);
    }
}

if (!job_valid_id($a) || !is_file(job_file($a, 'json'))) {
    fwrite(STDERR, "tarefa inválida\n");
    exit(2);
}
exit(job_run($a));
