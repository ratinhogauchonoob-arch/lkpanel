<?php
/*
 * LK Monitor - helper da aba Sites.
 * Executado pelo painel via sudo (root) ou, sem acesso root, direto como lkmonitor (somente leitura).
 * Recebe um JSON no stdin e devolve um JSON no stdout. Mesmo formato de VirtualHost e mesma pasta
 * (/var/www/<domínio>) do comando lk.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
ini_set('display_errors', 'stderr');
umask(022);
set_time_limit(0);
putenv('PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin');
putenv('LANG=C.UTF-8');

const AV = '/etc/apache2/sites-available';
const EN = '/etc/apache2/sites-enabled';
const WWW = '/var/www';
const APACHE_LOG = '/var/log/apache2';
const SKIP = ['000-default', 'default-ssl', 'lkmonitor'];
const LK_DBFILE = '/etc/lk/databases.txt';
require __DIR__ . '/jobs.inc.php';

function out(array $d = []): never
{
    echo json_encode(['ok' => true] + $d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit(0);
}

function fail(string $m, array $extra = []): never
{
    echo json_encode(['ok' => false, 'error' => $m] + $extra, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit(0);
}

function is_root(): bool
{
    return function_exists('posix_geteuid') ? posix_geteuid() === 0 : trim((string)shell_exec('id -u')) === '0';
}

function need_root(): void
{
    if (!is_root()) fail('Somente leitura: o painel não tem acesso root para alterar sites.');
}

/** Executa um comando (lista de argumentos, sem shell). Devolve [código, saída]. */
function run(array $argv, ?string $stdin = null): array
{
    $p = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
    if (!is_resource($p)) return [127, 'falha ao executar ' . $argv[0]];
    if ($stdin !== null) fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    $o = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    return [proc_close($p), trim((string)$o)];
}

/** Mesmas regras do lk: minúsculas, sem http(s)://, sem caminho e sem www. no início. */
function norm_domain($d): string
{
    $d = strtolower(trim((string)$d));
    $d = preg_replace('#^https?://#', '', $d);
    $d = explode('/', $d)[0];
    if (str_starts_with($d, 'www.')) $d = substr($d, 4);
    return $d;
}

function valid_domain(string $d): bool
{
    return strlen($d) <= 200 && (bool)preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $d);
}

function site_name($n): string
{
    $n = (string)$n;
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,200}$/', $n) || in_array($n, SKIP, true) || str_ends_with($n, '-le-ssl')) fail('Site inválido');
    if (!is_file(AV . "/$n.conf")) fail("Site não encontrado: $n");
    return $n;
}

function php_versions(): array
{
    $v = [];
    foreach (glob('/etc/php/*/fpm', GLOB_ONLYDIR) ?: [] as $d) {
        $x = basename(dirname($d));
        if (preg_match('/^\d+\.\d+$/', $x)) $v[] = $x;
    }
    usort($v, 'version_compare');
    return $v;
}

function server_ip(): string
{
    foreach (preg_split('/\s+/', trim((string)shell_exec('hostname -I 2>/dev/null'))) as $ip) {
        if ($ip !== '' && !str_contains($ip, ':') && !str_starts_with($ip, '172.17.') && !str_starts_with($ip, '192.168.122.')) return $ip;
    }
    return '127.0.0.1';
}

function apache_log_path(string $p): string
{
    $p = trim($p, "\"' ");
    $p = str_replace(['${APACHE_LOG_DIR}', '$APACHE_LOG_DIR'], APACHE_LOG, $p);
    return str_starts_with($p, '/') ? $p : '/var/log/apache2/' . ltrim($p, '/');
}

function site_info(string $n): array
{
    $c = (string)@file_get_contents(AV . "/$n.conf");
    $get = function (string $dir) use ($c): array {
        preg_match_all('/^\s*' . $dir . '\s+(.+?)\s*$/mi', $c, $m);
        return $m[1];
    };
    $doc = trim((string)($get('DocumentRoot')[0] ?? ''), '"');
    $proxy = null;
    foreach ($get('ProxyPass') as $pp) {
        $parts = preg_split('/\s+/', $pp);
        if (($parts[0] ?? '') === '/' && isset($parts[1])) { $proxy = $parts[1]; break; }
    }
    $php = preg_match('#php(\d+\.\d+)-fpm\.sock#', $c, $m) ? $m[1] : null;
    $root = $doc;
    if (preg_match('#/(public_html|public)/?$#', $doc)) $root = dirname(rtrim($doc, '/'));
    if ($proxy && ($root === '' || $root === WWW)) $root = WWW . "/$n";
    $type = $proxy ? 'proxy' : ((preg_match('#/public/?$#', $doc) || is_file("$root/artisan")) ? 'laravel' : 'php');
    $aliases = [];
    foreach ($get('ServerAlias') as $a) foreach (preg_split('/\s+/', $a) as $x) if ($x !== '') $aliases[] = $x;
    $err = $get('ErrorLog')[0] ?? '';
    return [
        'name' => $n,
        'domain' => trim((string)($get('ServerName')[0] ?? $n)),
        'aliases' => $aliases,
        'on' => file_exists(EN . "/$n.conf") || is_link(EN . "/$n.conf"),
        'ssl' => is_file(AV . "/$n-le-ssl.conf"),
        'type' => $type,
        'php' => $proxy ? null : $php,
        'proxy' => $proxy,
        'docroot' => $doc,
        'root' => $root,
        'exists' => $root !== '' && is_dir($root),
        'errlog' => $err !== '' ? apache_log_path($err) : null,
        'lk' => str_contains($c, 'gerado por LK'),
    ];
}

function site_names(): array
{
    $out = [];
    foreach (glob(AV . '/*.conf') ?: [] as $f) {
        $n = basename($f, '.conf');
        if (in_array($n, SKIP, true) || str_ends_with($n, '-le-ssl')) continue;
        $out[] = $n;
    }
    natcasesort($out);
    return array_values($out);
}

function apache_test(): array
{
    [$rc, $o] = run(['apache2ctl', 'configtest']);
    return [$rc === 0, $o];
}

function apache_reload(): void
{
    [$ok, $o] = apache_test();
    if (!$ok) fail('Configuração do Apache com erro, nada foi recarregado.', ['detail' => $o]);
    [$rc, $o] = run(['systemctl', 'reload', 'apache2']);
    if ($rc !== 0) fail('Falha ao recarregar o Apache.', ['detail' => $o]);
}

function mysql_ok(): bool
{
    if (!is_root() || !is_executable('/usr/bin/mysql')) return false;
    [$rc] = run(['mysql', '--protocol=socket', '-uroot', '-N', '-e', 'SELECT 1']);
    return $rc === 0;
}

function gen_pass(int $n = 20): string
{
    $a = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $s = '';
    for ($i = 0; $i < $n; $i++) $s .= $a[random_int(0, strlen($a) - 1)];
    return $s;
}

function rm_tree(string $p): void
{
    run(['rm', '-rf', '--one-file-system', '--', $p]);
}

/** Últimas $n linhas de um arquivo (lendo só o final), opcionalmente filtrando por texto. */
function tail_file(string $f, int $n, string $q = ''): array
{
    $size = (int)@filesize($f);
    $h = @fopen($f, 'rb');
    if (!$h) return [null, 0];
    $chunk = $q === '' ? min($size, max(65536, $n * 600)) : min($size, 8 * 1024 * 1024);
    fseek($h, $size - $chunk);
    $data = (string)fread($h, $chunk);
    fclose($h);
    $lines = explode("\n", rtrim($data, "\n"));
    if ($chunk < $size) array_shift($lines);
    if ($q !== '') $lines = array_values(array_filter($lines, fn($l) => stripos($l, $q) !== false));
    $total = count($lines);
    return [implode("\n", array_slice($lines, -$n)), $total];
}

/* ------------------------------------------------------------------ ações */

$req = json_decode((string)stream_get_contents(STDIN), true);
if (!is_array($req)) fail('Requisição inválida');
$action = (string)($req['action'] ?? '');
if (!is_dir('/etc/apache2')) fail('Apache não encontrado neste servidor. Instale o LAMP pelo lk (opção 1).');

switch ($action) {

    case 'list': {
        $sites = array_map('site_info', site_names());
        out(['sites' => $sites, 'php' => php_versions(), 'mysql' => mysql_ok(), 'ip' => server_ip(), 'root' => is_root()]);
    }

    case 'size': {
        $i = site_info(site_name($req['name'] ?? ''));
        if (!$i['exists']) fail('Pasta do site não encontrada: ' . $i['root']);
        [$rc, $o] = run(['timeout', '40', 'du', '-sb', '--', $i['root']]);
        if (!preg_match('/^(\d+)/', $o, $m)) fail('Não foi possível calcular o tamanho' . ($rc === 124 ? ' (demorou demais)' : ''));
        out(['size' => (int)$m[1]]);
    }

    case 'logs': {
        $i = site_info(site_name($req['name'] ?? ''));
        $which = (string)($req['which'] ?? 'error');
        $n = max(20, min(2000, (int)($req['lines'] ?? 200)));
        $q = trim((string)($req['q'] ?? ''));
        $avail = [];
        if ($i['errlog']) $avail['error'] = $i['errlog'];
        if (preg_match('/^\s*CustomLog\s+(\S+)/mi', (string)@file_get_contents(AV . '/' . $i['name'] . '.conf'), $m)) $avail['access'] = apache_log_path($m[1]);
        $lv = glob($i['root'] . '/storage/logs/laravel*.log') ?: [];
        usort($lv, fn($a, $b) => filemtime($b) <=> filemtime($a));
        if ($lv) $avail['laravel'] = $lv[0];
        elseif ($i['type'] === 'laravel') $avail['laravel'] = $i['root'] . '/storage/logs/laravel.log';
        $f = $avail[$which] ?? null;
        if (!$f) fail('Este site não tem esse log.', ['avail' => array_keys($avail)]);
        if (!is_file($f)) out(['file' => $f, 'text' => '', 'missing' => true, 'avail' => array_keys($avail)]);
        if (!is_readable($f)) fail('Sem permissão para ler ' . $f . ' (o painel precisa de acesso root).', ['avail' => array_keys($avail)]);
        [$text, $total] = tail_file($f, $n, $q);
        clearstatcache();
        out(['file' => $f, 'text' => $text ?? '', 'matches' => $total, 'size' => filesize($f), 'mtime' => filemtime($f), 'avail' => array_keys($avail)]);
    }

    case 'toggle': {
        need_root();
        $n = site_name($req['name'] ?? '');
        $on = !empty($req['on']);
        $ssl = is_file(AV . "/$n-le-ssl.conf");
        if ($on) {
            run(['a2ensite', '-q', $n]);
            if ($ssl) run(['a2ensite', '-q', "$n-le-ssl"]);
            [$ok, $o] = apache_test();
            if (!$ok) {
                run(['a2dissite', '-q', $n]);
                if ($ssl) run(['a2dissite', '-q', "$n-le-ssl"]);
                fail("Não foi possível ligar $n: a configuração do Apache tem erro.", ['detail' => $o]);
            }
        } else {
            run(['a2dissite', '-q', $n]);
            if ($ssl) run(['a2dissite', '-q', "$n-le-ssl"]);
        }
        apache_reload();
        out(['site' => site_info($n)]);
    }

    case 'create': {
        need_root();
        $d = norm_domain($req['domain'] ?? '');
        if (!valid_domain($d)) fail("Domínio inválido: $d");
        if (in_array($d, SKIP, true) || is_file(AV . "/$d.conf")) fail("O site $d já existe");
        $type = (string)($req['type'] ?? 'php');
        $www = !empty($req['www']);
        $root = WWW . "/$d";
        $created = !file_exists($root);
        $mods = ['proxy', 'proxy_fcgi', 'setenvif'];
        if ($type === 'php' || $type === 'laravel') {
            $pv = (string)($req['php'] ?? '');
            if (!in_array($pv, php_versions(), true)) fail('Versão do PHP inválida ou sem PHP-FPM: ' . $pv);
            $docroot = $type === 'php' ? "$root/public_html" : "$root/public";
            if ($type === 'php') {
                @mkdir($docroot, 0755, true);
                if (!file_exists("$docroot/index.php") && !file_exists("$docroot/index.html")) {
                    $t = htmlspecialchars($d);
                    file_put_contents("$docroot/index.php", "<!doctype html>\n<html lang=\"pt-br\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\"><title>$t</title>\n<style>body{font-family:system-ui,sans-serif;background:#0f172a;color:#e2e8f0;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0}div{text-align:center}small{color:#64748b}</style>\n</head><body><div><h1>$t</h1><p>Site no ar.</p><small>PHP <?= PHP_VERSION ?></small></div></body></html>\n");
                }
            } else {
                @mkdir($root, 0755, true);
            }
            $body = "    DocumentRoot $docroot\n    <Directory $docroot>\n        Options -Indexes +FollowSymLinks\n        AllowOverride All\n        Require all granted\n    </Directory>\n"
                . "    <FilesMatch \"\\.php$\">\n        SetHandler \"proxy:unix:/run/php/php$pv-fpm.sock|fcgi://localhost\"\n    </FilesMatch>";
        } elseif ($type === 'proxy') {
            $port = (int)($req['port'] ?? 0);
            if ($port < 1 || $port > 65535) fail('Porta inválida');
            $mods = array_merge($mods, ['proxy_http', 'proxy_wstunnel', 'rewrite', 'headers']);
            @mkdir($root, 0755, true);
            $body = "    ProxyPreserveHost On\n    ProxyPass / http://127.0.0.1:$port/\n    ProxyPassReverse / http://127.0.0.1:$port/\n"
                . "    RewriteEngine On\n    RewriteCond %{HTTP:Upgrade} =websocket [NC]\n    RewriteRule /(.*) ws://127.0.0.1:$port/\$1 [P,L]";
        } else {
            fail('Tipo de site inválido');
        }
        run(array_merge(['a2enmod', '-q'], $mods));
        $conf = "# Site $d - gerado por LK em " . date('Y-m-d H:i:s') . "\n<VirtualHost *:80>\n    ServerName $d\n"
            . ($www ? "    ServerAlias www.$d\n" : '') . $body . "\n"
            . "    ErrorLog \${APACHE_LOG_DIR}/{$d}_error.log\n    CustomLog \${APACHE_LOG_DIR}/{$d}_access.log combined\n</VirtualHost>\n";
        if (@file_put_contents(AV . "/$d.conf", $conf) === false) fail('Não foi possível gravar ' . AV . "/$d.conf");
        run(['chown', '-R', 'www-data:www-data', $root]);
        run(['a2ensite', '-q', $d]);
        [$ok, $o] = apache_test();
        if (!$ok) {
            run(['a2dissite', '-q', $d]);
            @unlink(AV . "/$d.conf");
            if ($created) rm_tree($root);
            fail('A configuração do Apache ficou com erro e o site foi desfeito.', ['detail' => $o]);
        }
        apache_reload();
        $db = null;
        $dbErr = null;
        if (!empty($req['db']) && $type !== 'proxy') {
            if (!mysql_ok()) {
                $dbErr = 'MySQL/MariaDB não encontrado ou sem acesso root pelo socket.';
            } else {
                $name = substr(preg_replace('/[^a-z0-9]/', '_', $d), 0, 64);
                $user = substr($name, 0, 32);
                $pass = gen_pass();
                $sql = "CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n"
                    . "CREATE USER IF NOT EXISTS '$user'@'localhost' IDENTIFIED BY '$pass';\n"
                    . "ALTER USER '$user'@'localhost' IDENTIFIED BY '$pass';\n"
                    . "GRANT ALL PRIVILEGES ON `$name`.* TO '$user'@'localhost';\nFLUSH PRIVILEGES;\n";
                [$rc, $o] = run(['mysql', '--protocol=socket', '-uroot'], $sql);
                if ($rc !== 0) {
                    $dbErr = 'Falha ao criar o banco: ' . $o;
                } else {
                    $db = ['name' => $name, 'user' => $user, 'pass' => $pass, 'host' => 'localhost'];
                    @mkdir('/etc/lk', 0700, true);
                    @file_put_contents(LK_DBFILE, date('Y-m-d H:i:s') . " | db=$name | user=$user | pass=$pass | host=localhost\n", FILE_APPEND);
                    @chmod(LK_DBFILE, 0600);
                }
            }
        }
        @file_put_contents('/var/log/lk.log', date('Y-m-d H:i:s') . " OK: site $d criado pelo LK Monitor ($type)\n", FILE_APPEND);
        $ip = server_ip();
        out(['site' => site_info($d), 'hosts' => "$ip $d" . ($www ? " www.$d" : ''), 'ip' => $ip, 'db' => $db, 'db_error' => $dbErr]);
    }

    case 'remove': {
        need_root();
        $n = site_name($req['name'] ?? '');
        if ((string)($req['confirm'] ?? '') !== $n) fail('Confirmação não confere: digite o domínio exatamente.');
        $info = site_info($n);
        run(['a2dissite', '-q', $n]);
        if (is_file(AV . "/$n-le-ssl.conf")) run(['a2dissite', '-q', "$n-le-ssl"]);
        $notes = [];
        if (is_executable('/usr/bin/certbot')) {
            [$rc, $o] = run(['certbot', 'certificates', '--cert-name', $n]);
            if ($rc === 0 && str_contains($o, "Certificate Name: $n")) {
                [$rc2] = run(['certbot', 'delete', '--cert-name', $n, '--non-interactive']);
                if ($rc2 === 0) $notes[] = 'Certificado SSL removido';
            }
        }
        @unlink(AV . "/$n.conf");
        @unlink(AV . "/$n-le-ssl.conf");
        [$ok, $o] = apache_test();
        if ($ok) run(['systemctl', 'reload', 'apache2']);
        else $notes[] = 'Atenção: a configuração do Apache tem erro em outro arquivo; o Apache não foi recarregado.';
        $dir = WWW . "/$n";
        if (!empty($req['files']) && valid_domain($n) && is_dir($dir) && !is_link($dir) && realpath($dir) === $dir) {
            rm_tree($dir);
            clearstatcache();
            $notes[] = is_dir($dir) ? "Não foi possível apagar todos os arquivos de $dir" : "Arquivos apagados ($dir)";
        } elseif (is_dir($dir)) {
            $notes[] = "Arquivos mantidos em $dir";
        }
        @file_put_contents('/var/log/lk.log', date('Y-m-d H:i:s') . " OK: site $n removido pelo LK Monitor\n", FILE_APPEND);
        out(['notes' => $notes, 'root' => $info['root']]);
    }

    case 'deploy_info': {
        $i = site_info(site_name($req['name'] ?? ''));
        $r = $i['root'];
        $node = trim((string)shell_exec('PATH=' . JOB_PATH . ' command -v node 2>/dev/null')) !== '';
        $run = is_root() ? job_running_for($i['name']) : null;
        out([
            'laravel' => is_file("$r/artisan"), 'root' => $r, 'php' => $i['php'],
            'composer' => trim((string)shell_exec('PATH=' . JOB_PATH . ' command -v composer 2>/dev/null')) !== '',
            'node' => $node, 'pkg' => is_file("$r/package.json"), 'hot' => file_exists("$r/public/hot"),
            'vendor' => is_dir("$r/vendor"), 'running' => $run['id'] ?? null,
        ]);
    }

    case 'deploy': {
        need_root();
        $i = site_info(site_name($req['name'] ?? ''));
        if (!is_file($i['root'] . '/artisan')) fail('Este site não é um projeto Laravel (artisan não encontrado em ' . $i['root'] . ').');
        if (!str_starts_with($i['root'] . '/', WWW . '/') || realpath($i['root']) !== $i['root']) fail('Pasta do site fora de ' . WWW . ': ' . $i['root']);
        if ($run = job_running_for($i['name'])) fail('Já existe um deploy em andamento para este site.', ['running' => $run['id']]);
        $o = [];
        foreach (array_merge(DEPLOY_OPTS, ['nodev']) as $k) $o[$k] = !empty($req['opts'][$k]);
        if (!array_filter(array_intersect_key($o, array_flip(DEPLOY_OPTS)))) fail('Marque pelo menos um passo.');
        if ($o['npm'] && !is_file($i['root'] . '/package.json')) $o['npm'] = false;
        $J = job_start('deploy', 'Deploy ' . $i['name'], ['site' => $i['name'], 'root' => $i['root'], 'php' => $i['php'], 'opts' => $o]);
        out(['job' => $J['id']]);
    }

    case 'backups': {
        $i = site_info(site_name($req['name'] ?? ''));
        $run = job_running_for($i['name']);
        $cfg = bk_cfg();
        out(['backups' => array_map(fn($m) => array_diff_key($m, ['excluded' => 1]), bk_list($i['name'])), 'db' => site_db($i['root']), 'root' => $i['root'],
            'running' => $run['id'] ?? null, 'running_type' => $run['type'] ?? null,
            'auto' => ['enabled' => $cfg['enabled'], 'time' => $cfg['time'], 'keep' => $cfg['keep'], 'on' => in_array($i['name'], $cfg['sites'], true)]]);
    }

    case 'backup': {
        need_root();
        $i = site_info(site_name($req['name'] ?? ''));
        if ($run = job_running_for($i['name'])) fail('Já existe uma tarefa em andamento para este site.', ['running' => $run['id']]);
        $J = job_start('backup', 'Backup ' . $i['name'], ['site' => $i['name'], 'root' => $i['root'], 'kind' => 'manual']);
        out(['job' => $J['id']]);
    }

    case 'backup_delete': {
        need_root();
        $n = site_name($req['name'] ?? '');
        try { bk_delete($n, (string)($req['file'] ?? '')); } catch (Throwable $e) { fail($e->getMessage()); }
        out();
    }

    case 'restore': {
        need_root();
        $i = site_info(site_name($req['name'] ?? ''));
        if ((string)($req['confirm'] ?? '') !== $i['name']) fail('Confirmação não confere: digite o domínio exatamente.');
        try { $p = bk_file_of($i['name'], (string)($req['file'] ?? '')); } catch (Throwable $e) { fail($e->getMessage()); }
        if ($run = job_running_for($i['name'])) fail('Já existe uma tarefa em andamento para este site.', ['running' => $run['id']]);
        $J = job_start('restore', 'Restaurar ' . $i['name'], ['site' => $i['name'], 'root' => $i['root'], 'file_in' => $p, 'php' => $i['php']]);
        out(['job' => $J['id']]);
    }

    case 'backup_cfg': {
        $next = trim((string)shell_exec('systemctl show lkmonitor-backup.timer -p NextElapseUSecRealtime --value 2>/dev/null'));
        $active = trim((string)shell_exec('systemctl is-active lkmonitor-backup.timer 2>/dev/null')) === 'active';
        out(['cfg' => bk_cfg(), 'sites' => site_names(), 'next' => $active && $next !== '' && $next !== 'n/a' ? (strtotime($next) ?: null) : null, 'timer' => $active]);
    }

    case 'backup_cfg_set': {
        need_root();
        try { $c = bk_cfg_save((array)($req['cfg'] ?? [])); } catch (Throwable $e) { fail($e->getMessage()); }
        out(['cfg' => $c]);
    }

    default:
        fail('Ação desconhecida: ' . $action);
}
