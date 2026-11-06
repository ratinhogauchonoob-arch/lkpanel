<?php
/*
 * LK Monitor - helper da App Store (aba Apps). Executado pelo painel via sudo como o usuário do terminal (root).
 *   list               catálogo com o estado de cada app (instalado, versão, serviço, tarefa em andamento)
 *   install / remove   inicia uma tarefa em segundo plano (job.php) e devolve o id para acompanhar o log
 *   svc                iniciar / parar / reiniciar o serviço ou container do app
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
require __DIR__ . '/apps.inc.php';
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
    if (!is_root()) fail('A App Store precisa do terminal do LK Monitor como root. No servidor: sudo lk › opção 33 › instalar / atualizar e escolha root.');
}

/** Executa sem shell e devolve [código, saída]. */
function run(array $argv, int $timeout = 20): array
{
    $p = @proc_open(array_merge(['/usr/bin/timeout', '-k', '3', (string)$timeout], $argv), [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
        null, ['PATH' => getenv('PATH'), 'LANG' => 'C.UTF-8', 'HOME' => '/root', 'COMPOSER_ALLOW_SUPERUSER' => '1']);
    if (!is_resource($p)) return [127, ''];
    $o = (string)stream_get_contents($pipes[1]);
    $e = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $c = proc_close($p);
    return [$c, trim($o !== '' ? $o : $e)];
}

function which(string $b): ?string
{
    foreach (explode(':', (string)getenv('PATH')) as $d) if (is_file("$d/$b") && is_executable("$d/$b")) return "$d/$b";
    return null;
}

/** A unidade do systemd existe? (o lk-cloudflared, por exemplo, só é criado depois de conectar a conta na aba Internet) */
function unit_exists(string $u): bool
{
    if (!str_contains($u, '.')) $u .= '.service';
    foreach (['/etc/systemd/system', '/usr/lib/systemd/system', '/lib/systemd/system', '/run/systemd/system'] as $d) if (is_file("$d/$u") || is_link("$d/$u")) return true;
    return false;
}

function short_ver(string $v): string
{
    $v = preg_replace('/^\d+:/', '', trim($v));                  // época do dpkg
    if (preg_match('/(\d+\.\d+(?:\.\d+)?)/', $v, $m)) return $m[1];
    return mb_substr($v, 0, 24);
}

/** Tarefas da App Store ainda rodando, e a última de cada app. */
function app_jobs(): array
{
    $files = glob(jobs_dir() . '/*.json') ?: [];
    rsort($files);
    $alive = null;
    $last = [];
    foreach (array_slice($files, 0, 80) as $f) {
        $J = json_decode((string)@file_get_contents($f), true);
        if (!is_array($J) || ($J['type'] ?? '') !== 'app') continue;
        $id = (string)($J['app'] ?? '');
        $on = job_alive($J);
        if ($on && !$alive) $alive = $J;
        if (!isset($last[$id])) $last[$id] = ['id' => $J['id'], 'op' => $J['op'] ?? 'install', 'state' => $on ? 'running' : ($J['state'] ?? ''), 'ended' => $J['ended'] ?? null];
    }
    return [$alive, $last];
}

function apps_state(): array
{
    $cat = apps_catalog();
    // pacotes: uma chamada só
    $pk = [];
    $all = [];
    foreach ($cat as $a) foreach ($a['pkgs'] ?? [] as $p) $all[$p] = 1;
    if ($all) {
        [, $o] = run(array_merge(['dpkg-query', '-W', '-f=${Package} ${db:Status-Abbrev} ${Version}\n'], array_keys($all)));
        foreach (explode("\n", $o) as $l) {
            $p = preg_split('/\s+/', trim($l));
            if (count($p) >= 3 && str_starts_with($p[1], 'ii')) $pk[$p[0]] = $p[2];
        }
    }
    // containers
    $ct = [];
    $docker = which('docker');
    if ($docker && is_root()) {
        [$c, $o] = run([$docker, 'ps', '-a', '--format', '{{.Names}}|{{.State}}|{{.Image}}'], 15);
        if ($c === 0) foreach (explode("\n", $o) as $l) {
            $p = explode('|', trim($l));
            if (count($p) === 3) $ct[$p[0]] = ['state' => $p[1], 'image' => $p[2]];
        }
    }
    // serviços: uma chamada só
    $svcs = array_values(array_unique(array_filter(array_map(fn($a) => $a['svc'] ?? null, $cat))));
    $st = [];
    if ($svcs) {
        [, $o] = run(array_merge(['systemctl', 'is-active'], $svcs));
        foreach (explode("\n", $o) as $i => $l) $st[$svcs[$i]] = trim($l) ?: 'unknown';
    }
    [$alive, $last] = app_jobs();

    $out = [];
    $inst = [];
    foreach ($cat as $a) {
        $ver = null;
        if (isset($a['docker'])) {
            $c = $ct[$a['docker']] ?? null;
            $ok = $c !== null;
            if ($ok) $ver = preg_replace('#^.*/#', '', $c['image']);
            $state = $c ? ($c['state'] === 'running' ? 'active' : $c['state']) : null;
        } else {
            if (!empty($a['pkgs'])) {
                $ok = !array_diff($a['pkgs'], array_keys($pk));
                if ($ok) $ver = short_ver($pk[$a['pkgs'][0]]);
            } elseif (!empty($a['bin'])) {
                $ok = which($a['bin']) !== null;
                if ($ok && !empty($a['ver'])) { [, $v] = run(['/bin/sh', '-c', $a['ver']], 8); $ver = $v !== '' ? short_ver($v) : null; }
            } else {
                $ok = is_file((string)($a['file'] ?? ''));
            }
            $state = $ok && !empty($a['svc']) && unit_exists($a['svc']) ? ($st[$a['svc']] ?? 'unknown') : null;
        }
        // a página do WhatsApp fica numa porta própria (8089 ou a próxima livre)
        if ($a['id'] === 'whatsapp' && preg_match('/^Listen\s+(\d+)/m', (string)@file_get_contents('/etc/apache2/sites-available/whatsapp-panel.conf'), $m)) $a['port'] = (int)$m[1];
        if ($a['id'] === 'whatsapp' && !$ok && !is_file('/usr/local/share/lk/install-whatsapp.sh')) $a['note'] = 'Atualize o LK (install-lk.sh mais recente) para instalar o WhatsApp pela loja.';
        $inst[$a['id']] = $ok;
        $out[] = [
            'id' => $a['id'], 'name' => $a['name'], 'cat' => $a['cat'], 'desc' => $a['desc'], 'color' => $a['color'] ?? '#6b7280',
            'installed' => $ok, 'version' => $ver, 'state' => $state, 'svc' => $state !== null ? ($a['svc'] ?? null) : null, 'docker' => isset($a['docker']),
            'port' => $a['port'] ?? null, 'path' => $a['path'] ?? null, 'https' => !empty($a['https']),
            'needs' => $a['needs'] ?? null, 'protect' => $a['protect'] ?? null, 'note' => $a['note'] ?? null,
            'job' => $last[$a['id']] ?? null,
        ];
    }
    foreach ($out as &$o) $o['needs_ok'] = !$o['needs'] || !empty($inst[$o['needs']]);
    return ['apps' => $out, 'cats' => APP_CATS, 'busy' => $alive ? ['id' => $alive['id'], 'app' => $alive['app'] ?? '', 'title' => $alive['title'] ?? ''] : null, 'root' => is_root()];
}

/* ------------------------------------------------------------------ */

$req = json_decode((string)stream_get_contents(STDIN), true);
if (!is_array($req)) fail('Pedido inválido');
$act = (string)($req['action'] ?? '');

if ($act === 'list') out(apps_state());

need_root();
$a = app_find((string)($req['id'] ?? ''));
if (!$a) fail('App desconhecido');
$S = null;
foreach (apps_state()['apps'] as $x) if ($x['id'] === $a['id']) $S = $x;

switch ($act) {
    case 'install':
    case 'remove':
        [$alive] = app_jobs();
        if ($alive) fail('Espere terminar: ' . ($alive['title'] ?? 'outra instalação') . '.', ['running' => $alive['id']]);
        if ($act === 'install') {
            if ($S['installed']) fail($a['name'] . ' já está instalado.');
            if (!$S['needs_ok']) {
                $n = app_find((string)$a['needs']);
                fail('Instale antes: ' . ($n['name'] ?? $a['needs']) . '.');
            }
        } else {
            if (!empty($a['protect'])) fail($a['protect']);
            if (!$S['installed']) fail($a['name'] . ' não está instalado.');
        }
        $J = job_start('app', ($act === 'install' ? 'Instalar ' : 'Desinstalar ') . $a['name'], ['app' => $a['id'], 'op' => $act]);
        out(['job' => $J['id']]);

    case 'svc':
        $op = (string)($req['op'] ?? '');
        if (!in_array($op, ['start', 'stop', 'restart'], true)) fail('Ação inválida');
        if (!$S['installed']) fail($a['name'] . ' não está instalado.');
        if (isset($a['docker'])) [$c, $o] = run(['docker', $op, $a['docker']], 120);
        elseif (!empty($a['svc']) && unit_exists($a['svc'])) [$c, $o] = run(['systemctl', $op, $a['svc']], 120);
        elseif ($a['id'] === 'cloudflared') fail('O túnel ainda não foi criado. Conecte a conta da Cloudflare na aba Internet (passo 2).');
        else fail($a['name'] . ' não tem serviço para ' . $op . '.');
        if ($c !== 0) fail('Falha: ' . ($o ?: 'código ' . $c));
        out(['msg' => $a['name'] . ' ' . ['start' => 'iniciado', 'stop' => 'parado', 'restart' => 'reiniciado'][$op]]);
}
fail('Ação inválida');
