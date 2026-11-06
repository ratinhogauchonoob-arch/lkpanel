<?php
/*
 * LK Monitor - helper da aba Internet (Cloudflare Tunnel). Executado pelo painel via sudo como root.
 *
 * Túnel "gerenciado localmente": a conta é ligada uma vez (cloudflared tunnel login -> cert.pem em /root/.cloudflared),
 * o painel cria um túnel só dele e guarda tudo em /etc/lk/cloudflared (fora das pastas padrão do cloudflared, para o
 * "teste rápido" continuar funcionando). Serviço: lk-cloudflared. Teste rápido (trycloudflare.com): unidade lk-cf-quick.
 *
 *   status | login | setup | route_add | route_del | toggle | quick_start | quick_stop
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
ini_set('display_errors', 'stderr');
umask(077);
set_time_limit(0);
putenv('PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin');
putenv('HOME=/root');

const CFD = '/etc/lk/cloudflared';
const CERT = '/root/.cloudflared/cert.pem';
const UNIT = 'lk-cloudflared';
const QUNIT = 'lk-cf-quick';
const LOGIN_LOG = '/run/lk-cf-login.log';
const QUICK_LOG = '/run/lk-cf-quick.log';
const PANEL_SITE = '/etc/apache2/sites-available/lkmonitor.conf';
const HOST_RE = '/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/';

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

function run(array $argv, int $timeout = 60): array
{
    $p = @proc_open(array_merge(['/usr/bin/timeout', '-k', '5', (string)$timeout], $argv), [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes,
        null, ['PATH' => getenv('PATH'), 'HOME' => '/root', 'LANG' => 'C.UTF-8', 'TERM' => 'dumb', 'NO_COLOR' => '1']);
    if (!is_resource($p)) return [127, 'Não foi possível executar ' . $argv[0]];
    $o = (string)stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $c = proc_close($p);
    return [$c, trim((string)preg_replace('/\e\[[0-9;]*m/', '', $o))];
}

function bin(): ?string
{
    foreach (['/usr/bin/cloudflared', '/usr/local/bin/cloudflared'] as $b) if (is_executable($b)) return $b;
    return null;
}

function need_bin(): string
{
    return bin() ?? fail('O cloudflared não está instalado. Instale pela App Store (Cloudflare Tunnel).', ['nobin' => true]);
}

function need_root(): void
{
    if (function_exists('posix_geteuid') && posix_geteuid() !== 0) fail('A aba Internet precisa do terminal do LK Monitor como root (sudo lk › 33).');
}

/** Agenda um comando para daqui a $sec segundos (a resposta chega antes, mesmo se o painel estiver passando pelo túnel). */
function later(array $argv, int $sec = 2): void
{
    run(array_merge(['systemd-run', '--quiet', '--collect', '--unit=lk-cf-act-' . bin2hex(random_bytes(3)), '--on-active=' . $sec, '--timer-property=AccuracySec=100ms'], $argv), 20);
}

function jread(string $f, array $def = []): array
{
    $d = json_decode((string)@file_get_contents(CFD . '/' . $f), true);
    return is_array($d) ? $d : $def;
}

function jwrite(string $f, array $d): void
{
    if (!is_dir(CFD)) mkdir(CFD, 0700, true);
    $p = CFD . '/' . $f;
    file_put_contents($p . '.tmp', json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    rename($p . '.tmp', $p);
}

function active(string $unit): string
{
    [, $o] = run(['systemctl', 'is-active', $unit], 10);
    return $o !== '' ? strtok($o, "\n") : 'unknown';
}

/** Domínio (zona) ligado na conta: lido do cert.pem e confirmado na API da Cloudflare (guardado em zone.json). */
function zone(): ?array
{
    if (!is_file(CERT)) return null;
    $cache = jread('zone.json');
    $raw = (string)@file_get_contents(CERT);
    $hash = md5($raw);
    // guarda também o "não descobri" por 10 min, para não chamar a API a cada atualização da tela
    if (($cache['hash'] ?? '') === $hash && (!empty($cache['name']) || time() - (int)($cache['at'] ?? 0) < 600)) return $cache;
    $tok = null;
    if (preg_match('/-----BEGIN ARGO TUNNEL TOKEN-----(.*?)-----END ARGO TUNNEL TOKEN-----/s', $raw, $m)) $tok = json_decode((string)base64_decode(preg_replace('/\s+/', '', $m[1])), true);
    if (!is_array($tok) || empty($tok['zoneID'])) { jwrite('zone.json', ['hash' => $hash, 'name' => null, 'at' => time()]); return ['hash' => $hash, 'name' => null]; }
    $z = ['hash' => $hash, 'id' => $tok['zoneID'], 'name' => null, 'at' => time()];
    if (!empty($tok['apiToken']) && function_exists('curl_init')) {
        $c = curl_init('https://api.cloudflare.com/client/v4/zones/' . rawurlencode($tok['zoneID']));
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tok['apiToken']]]);
        $r = json_decode((string)curl_exec($c), true);
        curl_close($c);
        if (!empty($r['result']['name'])) $z['name'] = $r['result']['name'];
    }
    jwrite('zone.json', $z);
    return $z;
}

/** Porta HTTP do painel (a linha "Listen N" sem https no site do Apache). */
function panel_port(): int
{
    $s = (string)@file_get_contents(PANEL_SITE);
    return preg_match('/^Listen\s+(\d+)\s*$/m', $s, $m) ? (int)$m[1] : 8088;
}

/** Sites do Apache: nome do arquivo, domínio principal, apelidos e se tem HTTPS (porta 443) próprio. */
function apache_sites(): array
{
    $out = [];
    foreach (glob('/etc/apache2/sites-enabled/*.conf') ?: [] as $f) {
        $n = basename($f, '.conf');
        if (in_array($n, ['lkmonitor', '000-default', 'default-ssl'], true) || str_ends_with($n, '-le-ssl')) continue;
        $c = (string)@file_get_contents($f);
        if (!preg_match('/^\s*ServerName\s+(\S+)/mi', $c, $m)) continue;
        $dom = strtolower($m[1]);
        $alias = preg_match_all('/^\s*ServerAlias\s+(.+)$/mi', $c, $mm) ? preg_split('/\s+/', strtolower(trim(implode(' ', $mm[1])))) : [];
        $ssl = false;
        foreach (glob('/etc/apache2/sites-enabled/*.conf') ?: [] as $g) {
            $t = (string)@file_get_contents($g);
            if (preg_match('/<VirtualHost[^>]*:443>/i', $t) && preg_match('/^\s*Server(Name|Alias)\s+(.*\s)?' . preg_quote($dom, '/') . '(\s|$)/mi', $t)) { $ssl = true; break; }
        }
        $out[] = ['name' => $n, 'domain' => $dom, 'alias' => array_values(array_filter($alias)), 'ssl' => $ssl];
    }
    usort($out, fn($a, $b) => strcmp($a['domain'], $b['domain']));
    return $out;
}

/** Para onde o endereço aponta: [service, originRequest|null, rótulo]. */
function target_service(array $t, string $host): array
{
    $k = (string)($t['type'] ?? '');
    if ($k === 'panel') return ['http://localhost:' . panel_port(), null, 'Painel LK'];
    if ($k === 'site') {
        foreach (apache_sites() as $s) if ($s['name'] === ($t['name'] ?? '')) {
            // site com HTTPS próprio (Certbot): vai direto no 443, senão o redirecionamento 80 -> 443 entra em laço
            return $s['ssl'] ? ['https://localhost:443', ['noTLSVerify' => true, 'originServerName' => $host], 'Site ' . $s['domain']]
                : ['http://localhost:80', null, 'Site ' . $s['domain']];
        }
        fail('Site não encontrado no Apache: ' . ($t['name'] ?? ''));
    }
    if ($k === 'port') {
        $p = (int)($t['port'] ?? 0);
        if ($p < 1 || $p > 65535) fail('Porta inválida');
        $https = !empty($t['https']);
        return [($https ? 'https' : 'http') . '://localhost:' . $p, $https ? ['noTLSVerify' => true] : null, ($t['label'] ?? '') !== '' ? (string)$t['label'] : 'Porta ' . $p];
    }
    fail('Destino inválido');
}

function yq(string $s): string
{
    return '"' . addcslashes($s, "\"\\") . '"';
}

/** Gera o config.yml a partir de routes.json (sempre termina com a regra "pega tudo" 404). */
function write_config(): void
{
    $T = jread('tunnel.json');
    if (empty($T['id'])) fail('Túnel ainda não criado.');
    $y = "# Gerado pelo LK Monitor (aba Internet). Mudanças feitas à mão aqui são perdidas.\n";
    $y .= 'tunnel: ' . $T['id'] . "\ncredentials-file: " . CFD . "/creds.json\nno-autoupdate: true\ningress:\n";
    foreach (jread('routes.json') as $r) {
        $y .= '  - hostname: ' . yq($r['host']) . "\n    service: " . yq($r['service']) . "\n";
        if (!empty($r['origin'])) {
            $y .= "    originRequest:\n";
            foreach ($r['origin'] as $k => $v) $y .= '      ' . $k . ': ' . (is_bool($v) ? ($v ? 'true' : 'false') : yq((string)$v)) . "\n";
        }
    }
    $y .= "  - service: http_status:404\n";
    file_put_contents(CFD . '/config.yml.new', $y);
    [$c, $o] = run([need_bin(), 'tunnel', '--config', CFD . '/config.yml.new', 'ingress', 'validate'], 30);
    if ($c !== 0) { @unlink(CFD . '/config.yml.new'); fail("Configuração inválida:\n" . $o); }
    rename(CFD . '/config.yml.new', CFD . '/config.yml');
}

function write_unit(): void
{
    $u = "[Unit]\nDescription=Cloudflare Tunnel do LK Monitor\nAfter=network-online.target\nWants=network-online.target\n\n[Service]\nType=simple\n"
        . 'ExecStart=' . need_bin() . ' --no-autoupdate --config ' . CFD . "/config.yml tunnel run\nRestart=on-failure\nRestartSec=5\nTimeoutStartSec=0\n\n[Install]\nWantedBy=multi-user.target\n";
    file_put_contents('/etc/systemd/system/' . UNIT . '.service', $u);
    chmod('/etc/systemd/system/' . UNIT . '.service', 0644);
    run(['systemctl', 'daemon-reload'], 30);
}

/** IP real dos visitantes no Apache (senão tudo que vem pelo túnel aparece como 127.0.0.1). */
function apache_remoteip(): void
{
    $f = '/etc/apache2/conf-available/lk-cloudflare.conf';
    if (!is_dir('/etc/apache2/conf-available')) return;
    $c = "# LK Monitor: IP real de quem chega pelo Cloudflare Tunnel (o cabeçalho só vale vindo do próprio servidor)\n"
        . "<IfModule remoteip_module>\n    RemoteIPHeader CF-Connecting-IP\n    RemoteIPInternalProxy 127.0.0.1 ::1\n</IfModule>\n";
    if (@file_get_contents($f) === $c && is_link('/etc/apache2/conf-enabled/lk-cloudflare.conf')) return;
    file_put_contents($f, $c);
    chmod($f, 0644);
    run(['a2enmod', '-q', 'remoteip'], 30);
    run(['a2enconf', '-q', 'lk-cloudflare'], 30);
    [$ok] = run(['apache2ctl', 'configtest'], 30);
    if ($ok === 0) later(['/bin/systemctl', 'reload', 'apache2'], 2);
}

function quick_url(): ?string
{
    return preg_match('#https://[a-z0-9-]+\.trycloudflare\.com#', (string)@file_get_contents(QUICK_LOG), $m) ? $m[0] : null;
}

function status(): array
{
    $b = bin();
    $ver = null;
    if ($b) { [, $v] = run([$b, '--version'], 10); $ver = preg_match('/(\d{4}\.\d+\.\d+)/', $v, $m) ? $m[1] : null; }
    $T = jread('tunnel.json');
    $loginLog = (string)@file_get_contents(LOGIN_LOG);
    $Q = jread('quick.json');
    $qa = active(QUNIT);
    return [
        'installed' => $b !== null, 'version' => $ver,
        'cert' => is_file(CERT), 'zone' => is_file(CERT) ? (zone()['name'] ?? null) : null,
        'login' => ['running' => active('lk-cf-login') === 'active', 'url' => preg_match('#https://dash\.cloudflare\.com/argotunnel\S+#', $loginLog, $m) ? $m[0] : null],
        'tunnel' => $T ? ['id' => $T['id'] ?? null, 'name' => $T['name'] ?? null] : null,
        'service' => is_file('/etc/systemd/system/' . UNIT . '.service') ? active(UNIT) : null,
        'routes' => array_map(fn($r) => ['host' => $r['host'], 'label' => $r['label'], 'service' => $r['service'], 'target' => $r['target']], jread('routes.json')),
        'quick' => ['active' => $qa === 'active' || $qa === 'activating', 'url' => quick_url(), 'label' => $Q['label'] ?? null],
        'sites' => apache_sites(), 'panel_port' => panel_port(),
    ];
}

/* ------------------------------------------------------------------ */

$req = json_decode((string)stream_get_contents(STDIN), true);
if (!is_array($req)) fail('Pedido inválido');
$act = (string)($req['action'] ?? '');

if ($act === 'status') out(status());
need_root();

switch ($act) {
    case 'login': {
        need_bin();
        if (is_file(CERT)) out(['done' => true]);
        if (active('lk-cf-login') !== 'active') {
            @unlink(LOGIN_LOG);
            run(['systemctl', 'reset-failed', 'lk-cf-login'], 10);
            [$c, $o] = run(['systemd-run', '--quiet', '--collect', '--unit=lk-cf-login', '--setenv=HOME=/root', '--property=RuntimeMaxSec=900',
                '--property=StandardOutput=file:' . LOGIN_LOG, '--property=StandardError=file:' . LOGIN_LOG, need_bin(), 'tunnel', 'login'], 20);
            if ($c !== 0) fail('Não foi possível iniciar o login: ' . $o);
        }
        for ($i = 0; $i < 20; $i++) {          // espera o link aparecer (até ~10 s)
            if (preg_match('#https://dash\.cloudflare\.com/argotunnel\S+#', (string)@file_get_contents(LOGIN_LOG), $m)) out(['url' => $m[0]]);
            usleep(500000);
        }
        fail("O cloudflared não mostrou o link de login. Saída:\n" . trim((string)@file_get_contents(LOGIN_LOG)));
    }

    case 'setup': {
        $b = need_bin();
        if (!is_file(CERT)) fail('Conecte a conta da Cloudflare primeiro.');
        if (!is_dir(CFD)) mkdir(CFD, 0700, true);
        $T = jread('tunnel.json');
        if (empty($T['id']) || !is_file(CFD . '/creds.json')) {
            $name = 'lk-' . substr(preg_replace('/[^a-z0-9]+/', '-', strtolower((string)gethostname())), 0, 30) . '-' . bin2hex(random_bytes(2));
            [$c, $o] = run([$b, 'tunnel', '--origincert', CERT, 'create', '--credentials-file', CFD . '/creds.json', $name], 60);
            if ($c !== 0 || !preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/', $o, $m)) fail("Não foi possível criar o túnel:\n" . $o);
            $T = ['id' => $m[0], 'name' => $name, 'created' => time()];
            jwrite('tunnel.json', $T);
            if (!is_file(CFD . '/routes.json')) jwrite('routes.json', []);
        }
        write_config();
        write_unit();
        apache_remoteip();
        run(['systemctl', 'enable', UNIT], 30);
        later(['/bin/systemctl', 'restart', UNIT], 1);
        out(['msg' => 'Túnel ' . $T['name'] . ' pronto.', 'zone' => zone()['name'] ?? null]);
    }

    case 'route_add': {
        $b = need_bin();
        $T = jread('tunnel.json');
        if (empty($T['id'])) fail('Conecte a conta e crie o túnel primeiro.');
        $host = strtolower(trim((string)($req['host'] ?? '')));
        if (!preg_match(HOST_RE, $host)) fail('Endereço inválido. Exemplo: painel.seudominio.com');
        $z = zone()['name'] ?? null;
        if ($z && $host !== $z && !str_ends_with($host, '.' . $z)) fail("A conta foi ligada ao domínio $z. Use $z ou um endereço terminado em .$z.");
        $routes = jread('routes.json');
        foreach ($routes as $r) if ($r['host'] === $host) fail("$host já está publicado.");
        [$service, $origin, $label] = target_service((array)($req['target'] ?? []), $host);
        $args = [$b, 'tunnel', '--origincert', CERT, 'route', 'dns'];
        if (!empty($req['overwrite'])) $args[] = '--overwrite-dns';
        [$c, $o] = run(array_merge($args, [$T['id'], $host]), 60);
        if ($c !== 0) {
            if (stripos($o, 'already exists') !== false) fail("Já existe um registro DNS para $host na Cloudflare (talvez apontando para outro lugar).", ['exists' => true]);
            fail("A Cloudflare recusou o endereço $host:\n" . $o);
        }
        $routes[] = ['host' => $host, 'service' => $service, 'origin' => $origin, 'label' => $label, 'target' => (array)$req['target'], 'added' => time()];
        jwrite('routes.json', $routes);
        write_config();
        later(['/bin/systemctl', 'restart', UNIT], 2);
        out(['msg' => "$host publicado. Pode levar 1 ou 2 minutos para abrir pela primeira vez.", 'url' => 'https://' . $host]);
    }

    case 'route_del': {
        $host = strtolower(trim((string)($req['host'] ?? '')));
        $routes = jread('routes.json');
        $left = array_values(array_filter($routes, fn($r) => $r['host'] !== $host));
        if (count($left) === count($routes)) fail('Endereço não encontrado.');
        jwrite('routes.json', $left);
        write_config();
        later(['/bin/systemctl', 'restart', UNIT], 2);
        out(['msg' => "$host saiu do ar. O registro DNS continua na Cloudflare (mostra erro 404) até você apagar lá."]);
    }

    case 'toggle': {
        if (!is_file('/etc/systemd/system/' . UNIT . '.service')) fail('Túnel ainda não configurado.');
        $on = !empty($req['on']);
        run(['systemctl', $on ? 'enable' : 'disable', UNIT], 30);
        later(['/bin/systemctl', $on ? 'restart' : 'stop', UNIT], 2);
        out(['msg' => $on ? 'Túnel ligando…' : 'Túnel desligado. Os endereços publicados saem do ar.']);
    }

    case 'quick_start': {
        $b = need_bin();
        $t = (array)($req['target'] ?? []);
        $host = '';
        if (($t['type'] ?? '') === 'site') foreach (apache_sites() as $s) if ($s['name'] === ($t['name'] ?? '')) $host = $s['domain'];
        [$service, $origin, $label] = target_service($t, $host !== '' ? $host : 'localhost');
        run(['systemctl', 'stop', QUNIT], 20);
        run(['systemctl', 'reset-failed', QUNIT], 10);
        @unlink(QUICK_LOG);
        $args = [$b, 'tunnel', '--no-autoupdate', '--url', $service];
        if ($host !== '') array_push($args, '--http-host-header', $host);
        if (!empty($origin['noTLSVerify'])) $args[] = '--no-tls-verify';
        if (!empty($origin['originServerName'])) array_push($args, '--origin-server-name', $origin['originServerName']);
        [$c, $o] = run(array_merge(['systemd-run', '--quiet', '--collect', '--unit=' . QUNIT, '--setenv=HOME=/tmp', '--property=RuntimeMaxSec=21600',
            '--property=StandardOutput=file:' . QUICK_LOG, '--property=StandardError=file:' . QUICK_LOG], $args), 20);
        if ($c !== 0) fail('Não foi possível iniciar o teste: ' . $o);
        jwrite('quick.json', ['label' => $label, 'started' => time()]);
        for ($i = 0; $i < 40; $i++) {          // o endereço aparece em uns 5-15 s
            if ($u = quick_url()) out(['url' => $u, 'label' => $label]);
            usleep(500000);
        }
        out(['url' => null, 'label' => $label, 'msg' => 'Ainda gerando o endereço…']);
    }

    case 'quick_stop':
        run(['systemctl', 'stop', QUNIT], 20);
        @unlink(QUICK_LOG);
        out(['msg' => 'Teste rápido desligado.']);
}
fail('Ação inválida');
