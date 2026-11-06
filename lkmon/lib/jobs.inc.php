<?php
/*
 * LK Monitor - tarefas em segundo plano (lado root): criar, iniciar e executar deploy, backup e restauração.
 * Usado pelo job.php (executor / linha de comando do lk) e pelo sites.php (que inicia as tarefas).
 */
declare(strict_types=1);

const JOB_PATH = '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';
const JOB_CACHE = '/var/cache/lkmonitor';
const BK_DIR = '/var/backups/lkmonitor';
const BK_EXCLUDE = ['vendor', 'node_modules', 'storage/framework/cache', 'storage/framework/sessions', 'storage/logs'];
const SITES_AV = '/etc/apache2/sites-available';

function jobs_dir(): string
{
    return (getenv('LKM_DATA') ?: '/var/lib/lkmonitor') . '/jobs';
}

function job_valid_id(string $id): bool
{
    return (bool)preg_match('/^\d{8}-\d{6}-[a-f0-9]{6}$/', $id);
}

function job_file(string $id, string $ext): string
{
    return jobs_dir() . "/$id.$ext";
}

function job_dur(int $s): string
{
    return $s < 60 ? "{$s}s" : intdiv($s, 60) . 'min ' . ($s % 60) . 's';
}

/** Grava o estado de forma atômica, legível só pelo grupo lkmonitor. */
function job_save(array $J): void
{
    $f = job_file($J['id'], 'json');
    $tmp = $f . '.tmp';
    file_put_contents($tmp, json_encode($J, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    @chgrp($tmp, 'lkmonitor');
    @chmod($tmp, 0640);
    rename($tmp, $f);
}

function jobs_prepare_dir(): void
{
    $d = jobs_dir();
    if (!is_dir($d)) mkdir($d, 0750, true);
    @chown($d, 'root');
    @chgrp($d, 'lkmonitor');
    @chmod($d, 0750);
}

function job_alive(array $J): bool
{
    if (($J['state'] ?? '') === 'queued') return time() - (int)($J['created'] ?? 0) < 120;
    return ($J['state'] ?? '') === 'running' && !empty($J['pid']) && file_exists('/proc/' . (int)$J['pid']);
}

/** Tarefa em andamento para o mesmo site, se houver. */
function job_running_for(string $site): ?array
{
    $files = glob(jobs_dir() . '/*.json') ?: [];
    rsort($files);
    foreach (array_slice($files, 0, 40) as $f) {
        $J = json_decode((string)@file_get_contents($f), true);
        if (is_array($J) && ($J['site'] ?? '') === $site && job_alive($J)) return $J;
    }
    return null;
}

/** Cria a tarefa (estado "na fila") sem iniciar. */
function job_create(string $type, string $title, array $data): array
{
    jobs_prepare_dir();
    $id = date('Ymd-His') . '-' . bin2hex(random_bytes(3));
    $J = ['id' => $id, 'type' => $type, 'title' => $title, 'state' => 'queued', 'created' => time()] + $data;
    job_save($J);
    touch(job_file($id, 'log'));
    @chgrp(job_file($id, 'log'), 'lkmonitor');
    @chmod(job_file($id, 'log'), 0640);
    return $J;
}

/** Cria e inicia uma tarefa fora do processo do painel (unidade systemd lkm-job-<id>). */
function job_start(string $type, string $title, array $data): array
{
    $J = job_create($type, $title, $data);
    $id = $J['id'];
    $runner = [PHP_BINARY, '-d', 'memory_limit=512M', '-d', 'display_errors=stderr', __DIR__ . '/job.php', $id];
    $env = getenv('LKM_DATA') ? ['--setenv=LKM_DATA=' . getenv('LKM_DATA')] : [];
    $p = proc_open(array_merge(['/usr/bin/systemd-run', '--unit=lkm-job-' . $id, '--collect', '--quiet', '--no-block', '--property=Nice=5'], $env, $runner),
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    $rc = is_resource($p) ? proc_close($p) : 1;
    if ($rc !== 0) {
        // sem systemd: processo desacoplado
        exec('/usr/bin/setsid -f ' . implode(' ', array_map('escapeshellarg', $runner)) . ' >/dev/null 2>&1 </dev/null &');
    }
    return $J;
}

/** Executa um passo com comando de shell, como root ou outro usuário, gravando a saída no log. */
function job_exec(array $s, string $logf): int
{
    $env = ['PATH' => JOB_PATH, 'LANG' => 'C.UTF-8', 'HOME' => '/root', 'TERM' => 'dumb', 'NO_COLOR' => '1', 'COLUMNS' => '80'] + ($s['env'] ?? []);
    $timeout = (string)($s['timeout'] ?? 1800);
    $cmd = ['/usr/bin/timeout', '-k', '10', $timeout, '/bin/bash', '-c', $s['cmd']];
    $as = $s['as'] ?? 'root';
    if ($as !== 'root') {
        $pairs = [];
        foreach ($env as $k => $v) $pairs[] = "$k=$v";
        $cmd = array_merge(['/usr/sbin/runuser', '-u', $as, '--', '/usr/bin/env', '-i'], $pairs, $cmd);
    }
    $p = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $logf, 'a'], 2 => ['file', $logf, 'a']], $pipes, $s['cwd'] ?? '/', $env);
    if (!is_resource($p)) return 127;
    return proc_close($p);
}

/** Executa todos os passos de uma tarefa já criada, atualizando o estado e o log. */
function job_run(string $id): int
{
    $J = json_decode((string)@file_get_contents(job_file($id, 'json')), true);
    if (!is_array($J) || ($J['state'] ?? '') !== 'queued') return 0;
    $logf = job_file($id, 'log');
    $J['state'] = 'running';
    $J['pid'] = getmypid();
    $J['started'] = time();
    $steps = job_steps($J);
    $J['steps'] = array_map(fn($s) => ['key' => $s['key'], 'label' => $s['label'], 'state' => 'pending'], $steps);
    job_save($J);
    $log = fopen($logf, 'ab');
    $w = function (string $s) use ($log): void { fwrite($log, $s); fflush($log); };
    $w('== ' . $J['title'] . ' · ' . date('d/m/Y H:i:s') . " ==\n");
    $failed = null;
    foreach ($steps as $i => $s) {
        $J['steps'][$i]['state'] = 'running';
        $J['steps'][$i]['started'] = time();
        job_save($J);
        $w("\n▶ " . $s['label'] . "\n");
        $t0 = microtime(true);
        $note = '';
        try {
            if (isset($s['fn'])) {
                [$rc, $note] = ($s['fn'])($w, $J);
            } else {
                if (!empty($s['show'])) $w('$ ' . $s['show'] . "\n");
                $rc = job_exec($s, $logf);
                if ($rc === 1 && !empty($s['ok1'])) $rc = 0;
            }
        } catch (Throwable $e) {
            $rc = 1;
            $note = $e->getMessage();
        }
        if ($note !== '') $w($note . "\n");
        $dt = round(microtime(true) - $t0, 1);
        $J['steps'][$i]['ended'] = time();
        $J['steps'][$i]['rc'] = $rc;
        if ($rc === -1) {
            $J['steps'][$i]['state'] = 'skipped';
            $w("○ ignorado\n");
            continue;
        }
        if ($rc !== 0) {
            $J['steps'][$i]['state'] = 'error';
            $w('✖ falhou' . ($rc === 124 ? ' (tempo esgotado)' : " (código $rc)") . " em {$dt}s\n");
            $failed = $i;
            break;
        }
        $J['steps'][$i]['state'] = 'ok';
        $w("✔ concluído em {$dt}s\n");
        job_save($J);
    }
    foreach ($J['steps'] as $i => $st) if ($st['state'] === 'pending') $J['steps'][$i]['state'] = 'canceled';
    $J['state'] = $failed === null ? 'ok' : 'error';
    $J['failed'] = $failed === null ? null : $J['steps'][$failed]['label'];
    $J['ended'] = time();
    job_finish($J);
    $w("\n" . ($failed === null ? '✔ Concluído com sucesso' : '✖ Parou em: ' . $J['failed']) . ' · ' . job_dur($J['ended'] - $J['started']) . "\n");
    fclose($log);
    job_save($J);
    job_prune();
    return $failed === null ? 0 : 1;
}

function job_prune(int $keep = 150): void
{
    $files = glob(jobs_dir() . '/*.json') ?: [];
    sort($files);
    foreach (array_slice($files, 0, max(0, count($files) - $keep)) as $f) {
        $J = json_decode((string)@file_get_contents($f), true);
        if (is_array($J) && job_alive($J)) continue;
        @unlink($f);
        @unlink(substr($f, 0, -5) . '.log');
    }
}

/* ------------------------------------------------------------------ tipos de tarefa */

function job_steps(array $J): array
{
    return match ($J['type']) {
        'deploy' => deploy_steps($J),
        'backup' => backup_steps($J),
        'restore' => restore_steps($J),
        'app' => app_steps($J),
        default => [['key' => 'x', 'label' => 'Tarefa desconhecida', 'fn' => fn() => [1, 'Tipo de tarefa desconhecido: ' . $J['type']]]],
    };
}

/** App Store: instalar / desinstalar um app do catálogo (apps.inc.php). */
function app_steps(array $J): array
{
    require_once __DIR__ . '/apps.inc.php';
    $a = app_find((string)($J['app'] ?? ''));
    if (!$a) return [['key' => 'x', 'label' => 'App desconhecido', 'fn' => fn() => [1, 'App não encontrado no catálogo: ' . ($J['app'] ?? '')]]];
    return app_job_steps($a, ($J['op'] ?? '') === 'remove' ? 'remove' : 'install');
}

/** Ao terminar: limpa pastas temporárias e anota o arquivo gerado. */
function job_finish(array &$J): void
{
    if (in_array($J['type'], ['backup', 'restore'], true)) {
        foreach ([BK_DIR . '/.tmp-' . $J['id'], BK_DIR . '/.tmp-' . $J['id'] . '-s'] as $t) if (is_dir($t)) exec('rm -rf ' . escapeshellarg($t));
        $made = BK_DIR . '/' . $J['site'] . '/.made-' . $J['id'];
        if (is_file($made)) {
            $J['file'] = trim((string)file_get_contents($made));
            @unlink($made);
        }
    }
}

/** Opções conhecidas do deploy, na ordem em que rodam. */
const DEPLOY_OPTS = ['hot', 'perms', 'composer', 'migrate', 'optimize', 'storage', 'npm'];

function php_for(?string $v): string
{
    return $v && is_executable("/usr/bin/php$v") ? "/usr/bin/php$v" : PHP_BINARY;
}

function www_step(string $key, string $label, string $root, string $cmd, array $env = []): array
{
    return ['key' => $key, 'label' => $label, 'as' => 'www-data', 'cwd' => $root, 'cmd' => $cmd, 'show' => $cmd, 'timeout' => 1800,
        'env' => $env + ['HOME' => JOB_CACHE . '/npm']];
}

function composer_bin(): string
{
    return trim((string)shell_exec('PATH=' . JOB_PATH . ' command -v composer 2>/dev/null'));
}

function job_caches(): void
{
    foreach (['composer', 'npm'] as $c) {
        if (!is_dir(JOB_CACHE . "/$c")) @mkdir(JOB_CACHE . "/$c", 0750, true);
        @chown(JOB_CACHE . "/$c", 'www-data');
        @chgrp(JOB_CACHE . "/$c", 'www-data');
    }
}

function composer_step(string $root, string $php, bool $nodev): array
{
    $composer = composer_bin();
    if ($composer === '') return ['key' => 'composer', 'label' => 'composer install', 'fn' => fn() => [1, 'Composer não está instalado. Instale pelo lk (opção 16).']];
    return www_step('composer', 'composer install' . ($nodev ? ' --no-dev' : ''), $root,
        "$php " . escapeshellarg($composer) . ' install --no-interaction --prefer-dist --optimize-autoloader' . ($nodev ? ' --no-dev' : ''),
        ['COMPOSER_HOME' => JOB_CACHE . '/composer', 'COMPOSER_CACHE_DIR' => JOB_CACHE . '/composer/cache', 'HOME' => JOB_CACHE . '/composer']);
}

function deploy_steps(array $J): array
{
    $root = $J['root'];
    $o = $J['opts'];
    $php = php_for($J['php'] ?? null);
    $q = 'escapeshellarg';
    job_caches();
    $s = [];
    if (!empty($o['hot'])) {
        $s[] = ['key' => 'hot', 'label' => 'Remover public/hot', 'fn' => function () use ($root) {
            $f = "$root/public/hot";
            if (!file_exists($f) && !is_link($f)) return [-1, 'public/hot não existe'];
            return @unlink($f) ? [0, 'public/hot removido'] : [1, 'não foi possível apagar public/hot'];
        }];
    }
    if (!empty($o['perms'])) {
        $dirs = array_values(array_filter(['storage', 'bootstrap/cache', 'database'], fn($d) => is_dir("$root/$d")));
        $cmd = 'chown -R www-data:www-data ' . $q($root) . ($dirs ? ' && chmod -R ug+rwX ' . implode(' ', array_map($q, $dirs)) : '');
        $s[] = ['key' => 'perms', 'label' => 'Corrigir dono (www-data) e permissões', 'cwd' => $root, 'cmd' => $cmd, 'show' => $cmd, 'timeout' => 600];
    }
    if (!empty($o['composer'])) $s[] = composer_step($root, $php, !empty($o['nodev']));
    if (!empty($o['migrate'])) $s[] = www_step('migrate', 'php artisan migrate --force', $root, "$php artisan migrate --force --no-interaction");
    if (!empty($o['optimize'])) $s[] = www_step('optimize', 'php artisan optimize:clear', $root, "$php artisan optimize:clear");
    if (!empty($o['storage'])) {
        $s[] = is_link("$root/public/storage") || file_exists("$root/public/storage")
            ? ['key' => 'storage', 'label' => 'php artisan storage:link', 'fn' => fn() => [-1, 'public/storage já existe']]
            : www_step('storage', 'php artisan storage:link', $root, "$php artisan storage:link");
    }
    if (!empty($o['npm'])) {
        $inst = is_file("$root/package-lock.json") ? 'npm ci' : 'npm install';
        $s[] = www_step('npm', $inst . ' && npm run build', $root, "$inst --no-audit --no-fund && npm run build", ['npm_config_cache' => JOB_CACHE . '/npm/cache']);
    }
    return $s;
}

/* ------------------------------------------------------------------ sites e bancos (lado root) */

function site_valid(string $n): bool
{
    return (bool)preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,200}$/', $n) && !in_array($n, ['000-default', 'default-ssl', 'lkmonitor'], true)
        && !str_ends_with($n, '-le-ssl') && is_file(SITES_AV . "/$n.conf");
}

/** Pasta do site, pelo mesmo critério da aba Sites (DocumentRoot sem public/ ou public_html/). */
function site_root_of(string $n): string
{
    $c = (string)@file_get_contents(SITES_AV . "/$n.conf");
    $doc = preg_match('/^\s*DocumentRoot\s+"?([^"\s]+)/mi', $c, $m) ? $m[1] : '';
    if (preg_match('#/(public_html|public)/?$#', $doc)) $doc = dirname(rtrim($doc, '/'));
    if ($doc === '' || $doc === '/var/www') $doc = "/var/www/$n";
    return rtrim($doc, '/');
}

function env_parse(string $f): array
{
    $e = [];
    foreach (@file($f, FILE_IGNORE_NEW_LINES) ?: [] as $l) {
        if (preg_match('/^\s*([A-Z0-9_]+)\s*=\s*(.*?)\s*$/', $l, $m)) $e[$m[1]] = trim($m[2], "\"'");
    }
    return $e;
}

/** Banco usado pelo site, conforme o .env (MySQL/MariaDB ou SQLite). */
function site_db(string $root): array
{
    $e = env_parse("$root/.env");
    $conn = strtolower($e['DB_CONNECTION'] ?? '');
    if (in_array($conn, ['mysql', 'mariadb'], true) && preg_match('/^[A-Za-z0-9_$-]{1,64}$/', $e['DB_DATABASE'] ?? '')) {
        return ['type' => 'mysql', 'name' => $e['DB_DATABASE']];
    }
    if ($conn === 'sqlite') {
        $p = $e['DB_DATABASE'] ?? '';
        $p = $p === '' ? "$root/database/database.sqlite" : (str_starts_with($p, '/') ? $p : "$root/$p");
        if (is_file($p)) return ['type' => 'sqlite', 'path' => realpath($p)];
    }
    return ['type' => null];
}

function lkm_version(): string
{
    return preg_match("/const LKM_VERSION = '([^']+)'/", (string)@file_get_contents('/var/www/lkmonitor/index.php'), $m) ? $m[1] : '?';
}

/* ------------------------------------------------------------------ backup */

function bk_site_dir(string $site): string
{
    $d = BK_DIR . "/$site";
    if (!is_dir($d)) mkdir($d, 0700, true);
    @chmod(BK_DIR, 0700);
    return $d;
}

/** Copia o banco para $tmp/db (MySQL: mysqldump | gzip; SQLite: .backup consistente, como o dono do arquivo). */
function bk_dump_db(array $db, string $tmp, string $logf): array
{
    @mkdir("$tmp/db", 0700, true);
    if ($db['type'] === 'mysql') {
        $n = $db['name'];
        $cmd = 'set -o pipefail; mysqldump --single-transaction --quick --routines --triggers --events ' . escapeshellarg($n) . ' | gzip > ' . escapeshellarg("$tmp/db/$n.sql.gz");
        $rc = job_exec(['cmd' => $cmd, 'timeout' => 3600], $logf);
        return [$rc, $rc === 0 ? "MySQL: banco $n copiado (" . bk_size(filesize("$tmp/db/$n.sql.gz")) . ')' : "falha no mysqldump do banco $n"];
    }
    if ($db['type'] === 'sqlite') {
        $src = $db['path'];
        $st = stat($src);
        $owner = function_exists('posix_getpwuid') ? (posix_getpwuid($st['uid'])['name'] ?? 'root') : 'root';
        $work = trim((string)shell_exec('mktemp -d /tmp/lkm-sqlite.XXXXXX'));
        @chown($work, $st['uid']);
        $dst = "$work/" . basename($src);
        if (is_executable('/usr/bin/sqlite3')) {
            $rc = job_exec(['cmd' => 'sqlite3 ' . escapeshellarg($src) . ' ' . escapeshellarg('.backup ' . "'" . str_replace("'", "''", $dst) . "'"), 'as' => $owner, 'timeout' => 1800], $logf);
        } else {
            $rc = copy($src, $dst) ? 0 : 1;
        }
        if ($rc === 0) rename($dst, "$tmp/db/" . basename($src));
        exec('rm -rf ' . escapeshellarg($work));
        return [$rc, $rc === 0 ? 'SQLite: ' . $src . ' copiado com .backup (' . bk_size(filesize("$tmp/db/" . basename($src))) . ')' : 'falha ao copiar o SQLite ' . $src];
    }
    return [-1, 'nenhum banco no .env do site'];
}

function bk_size(int|false $b): string
{
    $b = (int)$b;
    $u = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $x = (float)$b;
    while ($x >= 1024 && $i < 4) { $x /= 1024; $i++; }
    return ($i ? number_format($x, $x >= 100 ? 0 : 1, ',', '') : (string)$b) . ' ' . $u[$i];
}

/** Gera o .tar.gz: meta.json + db/ + files/ (pasta do site sem vendor, node_modules, caches e logs). */
function bk_archive(string $site, string $root, array $db, string $tmp, string $kind, string $logf): array
{
    $dir = bk_site_dir($site);
    $stamp = date('Ymd-His');
    while (file_exists("$dir/$stamp.tar.gz")) { sleep(1); $stamp = date('Ymd-His'); }
    $out = "$dir/$stamp.tar.gz";
    $meta = ['site' => $site, 'created' => time(), 'kind' => $kind, 'root' => $root, 'db' => $db, 'version' => lkm_version(),
        'format' => 1, 'host' => gethostname(), 'excluded' => BK_EXCLUDE];
    file_put_contents("$tmp/meta.json", json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    $base = basename($root);
    $ex = [];
    foreach (BK_EXCLUDE as $x) $ex[] = '--exclude=' . escapeshellarg("$base/$x");
    if (($db['type'] ?? null) === 'sqlite' && str_starts_with($db['path'], "$root/")) {
        $rel = $base . substr($db['path'], strlen($root));
        foreach (['', '-wal', '-shm', '-journal'] as $suf) $ex[] = '--exclude=' . escapeshellarg($rel . $suf);
    }
    $parts = '-C ' . escapeshellarg($tmp) . ' meta.json' . (is_dir("$tmp/db") ? ' db' : '');
    if (is_dir($root)) $parts .= ' ' . implode(' ', $ex) . ' -C ' . escapeshellarg(dirname($root)) . " --transform 's,^" . str_replace([',', "'"], ['\\,', ''], preg_quote($base)) . ",files,S' " . escapeshellarg($base);
    $cmd = 'tar --warning=no-file-changed -czf ' . escapeshellarg($out) . ' ' . $parts;
    $rc = job_exec(['cmd' => $cmd, 'show' => 'tar -czf ' . $out, 'timeout' => 7200], $logf);
    if ($rc > 1 || !is_file($out)) {
        @unlink($out);
        return [$rc ?: 1, 'falha ao gerar o arquivo', null];
    }
    @chmod($out, 0600);
    $meta['file'] = basename($out);
    $meta['size'] = filesize($out);
    file_put_contents("$dir/$stamp.json", json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    @chmod("$dir/$stamp.json", 0600);
    return [0, 'Arquivo: ' . $out . ' (' . bk_size($meta['size']) . ')', $out];
}

function backup_steps(array $J): array
{
    $site = $J['site'];
    $root = $J['root'];
    $db = site_db($root);
    $tmp = BK_DIR . '/.tmp-' . $J['id'];
    @mkdir($tmp, 0700, true);
    @chmod(BK_DIR, 0700);
    $logf = job_file($J['id'], 'log');
    $kind = $J['kind'] ?? 'manual';
    return [
        ['key' => 'db', 'label' => 'Copiar o banco de dados', 'fn' => fn() => array_slice(bk_dump_db($db, $tmp, $logf), 0, 2)],
        ['key' => 'files', 'label' => 'Compactar os arquivos do site', 'fn' => function () use ($site, $root, $db, $tmp, $kind, $logf, $J) {
            [$rc, $note, $out] = bk_archive($site, $root, $db, $tmp, $kind, $logf);
            if ($out) file_put_contents(BK_DIR . "/$site/.made-" . $J['id'], $out);
            return [$rc, $note];
        }],
    ];
}

/** Lista os backups de um site (mais novo primeiro). */
function bk_list(string $site): array
{
    $out = [];
    foreach (glob(BK_DIR . "/$site/*.json") ?: [] as $f) {
        $m = json_decode((string)file_get_contents($f), true);
        if (!is_array($m) || empty($m['file']) || !is_file(BK_DIR . "/$site/" . $m['file'])) continue;
        $m['path'] = BK_DIR . "/$site/" . $m['file'];
        $out[] = $m;
    }
    usort($out, fn($a, $b) => strcmp($b['file'], $a['file']));
    return $out;
}

function bk_file_of(string $site, string $file): string
{
    if (!preg_match('/^\d{8}-\d{6}\.tar\.gz$/', $file)) throw new RuntimeException('Arquivo de backup inválido');
    $p = BK_DIR . "/$site/$file";
    if (!is_file($p)) throw new RuntimeException('Backup não encontrado: ' . $file);
    return $p;
}

function bk_delete(string $site, string $file): void
{
    $p = bk_file_of($site, $file);
    @unlink($p);
    @unlink(substr($p, 0, -7) . '.json');
}

/** Mantém só os últimos $keep backups automáticos do site. */
function bk_prune(string $site, int $keep): array
{
    $auto = array_values(array_filter(bk_list($site), fn($m) => ($m['kind'] ?? '') === 'auto'));
    $gone = [];
    foreach (array_slice($auto, max(1, $keep)) as $m) {
        bk_delete($site, $m['file']);
        $gone[] = $m['file'];
    }
    return $gone;
}

/* ------------------------------------------------------------------ restauração */

function restore_steps(array $J): array
{
    $site = $J['site'];
    $root = $J['root'];
    $file = $J['file_in'];
    $php = php_for($J['php'] ?? null);
    $tmp = BK_DIR . '/.tmp-' . $J['id'];
    $logf = job_file($J['id'], 'log');
    $q = 'escapeshellarg';
    job_caches();
    return [
        ['key' => 'safety', 'label' => 'Backup de segurança do estado atual', 'fn' => function ($w) use ($site, $root, $J, $logf) {
            $t = BK_DIR . '/.tmp-' . $J['id'] . '-s';
            @mkdir($t, 0700, true);
            $db = site_db($root);
            [$rc, $note] = bk_dump_db($db, $t, $logf);
            if ($rc > 0) return [$rc, $note];
            $w($note . "\n");
            [$rc, $note] = bk_archive($site, $root, $db, $t, 'seguranca', $logf);
            return [$rc, $note];
        }],
        ['key' => 'extract', 'label' => 'Abrir o backup', 'fn' => function ($w) use ($file, $tmp, $logf) {
            @mkdir($tmp, 0700, true);
            $rc = job_exec(['cmd' => 'tar -xzf ' . escapeshellarg($file) . ' -C ' . escapeshellarg($tmp)], $logf);
            if ($rc !== 0) return [$rc, 'falha ao abrir ' . $file];
            $m = json_decode((string)@file_get_contents("$tmp/meta.json"), true);
            if (!is_array($m)) return [1, 'meta.json ausente: este arquivo não parece um backup do LK Monitor'];
            return [0, 'Backup de ' . date('d/m/Y H:i', (int)$m['created']) . ' (' . ($m['site'] ?? '?') . ', versão ' . ($m['version'] ?? '?') . ')'];
        }],
        ['key' => 'files', 'label' => 'Restaurar os arquivos', 'fn' => function ($w) use ($root, $tmp, $logf, $q) {
            if (!is_dir("$tmp/files")) return [-1, 'o backup não tem arquivos'];
            if (!is_dir($root)) mkdir($root, 0755, true);
            $ex = '';
            foreach (BK_EXCLUDE as $x) $ex .= ' --exclude=' . $q("/$x/");
            $db = site_db($root);
            if (($db['type'] ?? null) === 'sqlite' && str_starts_with($db['path'], "$root/")) {
                foreach (['', '-wal', '-shm', '-journal'] as $suf) $ex .= ' --exclude=' . $q(substr($db['path'], strlen($root)) . $suf);
            }
            $cmd = 'rsync -a --delete' . $ex . ' ' . $q("$tmp/files/") . ' ' . $q("$root/");
            $w('$ ' . $cmd . "\n");
            return [job_exec(['cmd' => $cmd, 'timeout' => 7200], $logf), 'Arquivos restaurados em ' . $root . ' (vendor, node_modules, caches e logs foram mantidos)'];
        }],
        ['key' => 'db', 'label' => 'Restaurar o banco de dados', 'fn' => function ($w) use ($root, $tmp, $logf, $q) {
            $m = json_decode((string)@file_get_contents("$tmp/meta.json"), true);
            $db = $m['db'] ?? ['type' => null];
            if (($db['type'] ?? null) === 'mysql') {
                $n = $db['name'];
                if (!preg_match('/^[A-Za-z0-9_$-]{1,64}$/', $n)) return [1, 'nome de banco inválido no backup'];
                $dump = "$tmp/db/$n.sql.gz";
                if (!is_file($dump)) return [1, 'arquivo do banco ausente no backup'];
                $sql = "DROP DATABASE IF EXISTS `$n`; CREATE DATABASE `$n` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;";
                $cmd = 'set -o pipefail; mysql -e ' . $q($sql) . ' && gunzip -c ' . $q($dump) . ' | mysql ' . $q($n);
                $rc = job_exec(['cmd' => $cmd, 'timeout' => 7200], $logf);
                return [$rc, $rc === 0 ? "MySQL: banco $n restaurado" : "falha ao restaurar o banco $n"];
            }
            if (($db['type'] ?? null) === 'sqlite') {
                $src = "$tmp/db/" . basename($db['path']);
                if (!is_file($src)) return [1, 'arquivo SQLite ausente no backup'];
                $cur = site_db($root);
                $dst = ($cur['type'] ?? null) === 'sqlite' ? $cur['path'] : $db['path'];
                if (!str_starts_with($dst, '/var/www/')) return [1, 'destino do SQLite fora de /var/www: ' . $dst];
                $st = is_file($dst) ? stat($dst) : stat(dirname($dst));
                foreach (['-wal', '-shm', '-journal'] as $suf) @unlink($dst . $suf);
                if (!copy($src, $dst)) return [1, 'falha ao copiar para ' . $dst];
                @chown($dst, $st['uid']);
                @chgrp($dst, $st['gid']);
                @chmod($dst, 0664);
                return [0, 'SQLite restaurado em ' . $dst];
            }
            return [-1, 'o backup não tem banco de dados'];
        }],
        is_file("$root/composer.json") || is_file("$tmp/files/composer.json")
            ? ['key' => 'composer', 'label' => 'composer install (se faltar vendor)', 'fn' => function ($w) use ($root, $php, $logf) {
                if (is_dir("$root/vendor")) return [-1, 'a pasta vendor já existe'];
                if (!is_file("$root/composer.json")) return [-1, 'sem composer.json'];
                $s = composer_step($root, $php, false);
                if (isset($s['fn'])) return ($s['fn'])();
                $w('$ ' . $s['show'] . "\n");
                return [job_exec($s, $logf), ''];
            }]
            : ['key' => 'composer', 'label' => 'composer install (se faltar vendor)', 'fn' => fn() => [-1, 'site sem composer.json']],
        ['key' => 'cache', 'label' => 'Limpar caches do Laravel', 'fn' => function ($w) use ($root, $php, $logf) {
            if (!is_file("$root/artisan")) return [-1, 'não é um projeto Laravel'];
            $s = www_step('cache', 'optimize:clear', $root, "$php artisan optimize:clear");
            $w('$ ' . $s['show'] . "\n");
            return [job_exec($s, $logf), ''];
        }],
    ];
}

/* ------------------------------------------------------------------ backup automático */

function bk_cfg_file(): string
{
    return (getenv('LKM_DATA') ?: '/var/lib/lkmonitor') . '/backup.json';
}

function bk_cfg(): array
{
    $c = json_decode((string)@file_get_contents(bk_cfg_file()), true);
    $c = is_array($c) ? $c : [];
    return [
        'enabled' => (bool)($c['enabled'] ?? true),
        'time' => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)($c['time'] ?? '')) ? $c['time'] : '03:00',
        'keep' => max(1, min(365, (int)($c['keep'] ?? 7))),
        'sites' => array_values(array_filter((array)($c['sites'] ?? []), 'is_string')),
    ];
}

function bk_cfg_save(array $c): array
{
    $c = ['enabled' => !empty($c['enabled']), 'time' => (string)($c['time'] ?? '03:00'), 'keep' => (int)($c['keep'] ?? 7),
        'sites' => array_values(array_unique(array_filter((array)($c['sites'] ?? []), fn($s) => is_string($s) && site_valid($s))))];
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $c['time'])) throw new RuntimeException('Horário inválido (use HH:MM)');
    if ($c['keep'] < 1 || $c['keep'] > 365) throw new RuntimeException('Quantidade inválida (1 a 365)');
    $f = bk_cfg_file();
    file_put_contents($f, json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    @chgrp($f, 'lkmonitor');
    @chmod($f, 0640);
    bk_timer_write($c);
    return $c;
}

/** Grava o lkmonitor-backup.timer com o horário configurado. */
function bk_timer_write(array $c): void
{
    $t = "[Unit]\nDescription=LK Monitor - backup automático diário\n\n[Timer]\nOnCalendar=*-*-* {$c['time']}:00\nRandomizedDelaySec=60\n\n[Install]\nWantedBy=timers.target\n";
    file_put_contents('/etc/systemd/system/lkmonitor-backup.timer', $t);
    exec('systemctl daemon-reload 2>&1');
    exec('systemctl ' . ($c['enabled'] ? 'enable --now' : 'disable --now') . ' lkmonitor-backup.timer 2>&1');
    if ($c['enabled']) exec('systemctl restart lkmonitor-backup.timer 2>&1');
}

/** Rodada do timer: um backup por site escolhido, depois apaga os automáticos mais antigos. */
function bk_auto(): int
{
    $c = bk_cfg();
    if (!$c['enabled'] || !$c['sites']) return 0;
    $fail = 0;
    foreach ($c['sites'] as $site) {
        if (!site_valid($site)) continue;
        if (job_running_for($site)) { $fail++; continue; }
        $J = job_create('backup', 'Backup automático ' . $site, ['site' => $site, 'root' => site_root_of($site), 'kind' => 'auto']);
        if (job_run($J['id']) !== 0) $fail++;
        bk_prune($site, $c['keep']);
    }
    return $fail ? 1 : 0;
}
