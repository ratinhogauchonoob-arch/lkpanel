<?php
/* LK Monitor - painel web de uso do servidor */
declare(strict_types=1);
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
ini_set('display_errors', '0');
require __DIR__ . '/config.php';
defined('LKM_DATA') || define('LKM_DATA', '/var/lib/lkmonitor');
defined('LKM_CMD_TIMEOUT') || define('LKM_CMD_TIMEOUT', 30);
defined('LKM_PHP_CLI') || define('LKM_PHP_CLI', '/usr/bin/php');
defined('LKM_FM_HELPER') || define('LKM_FM_HELPER', '/usr/local/lib/lkmonitor/fm.php');
defined('LKM_HTTPS_PORT') || define('LKM_HTTPS_PORT', '0');
defined('LKM_TERM_PORT') || define('LKM_TERM_PORT', '0');
defined('LKM_USER') || define('LKM_USER', 'root');
define('LKM_LIB', dirname(LKM_FM_HELPER));
require LKM_LIB . '/lib.php';
const LKM_VERSION = '2.2.0';
const LKM_LOCK_MAX = 5;     // senhas erradas seguidas no login...
const LKM_LOCK_MIN = 15;    // ...dentro destes minutos = bloqueio do IP por este tempo
const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';   // base32 do 2FA (const precisa vir antes do código que roda)
const LKM_API_KEYS = ['api', 'cmd', 'fm', 'hist', 'sites', 'ca', 'jobs', 'db', 'cfg', 'term', 'sys', 'apps', 'cf', 'tfa', 'pw'];

session_name('lkmon');
session_set_cookie_params(['lifetime' => 0, 'httponly' => true, 'samesite' => 'Strict']);
session_start();
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: ./');
    exit;
}

if (empty($_SESSION['auth'])) {
    $err = '';
    // senha certa, falta o código do 2FA (vale 5 minutos)
    $step2 = !empty($_SESSION['pre2fa']) && time() - (int)$_SESSION['pre2fa'] < 300 && tfa_on();
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $ip = client_ip();
        $left = login_blocked($ip);
        if ($left > 0) {
            sleep(1);
            unset($_SESSION['pre2fa']);
            $step2 = false;
            $err = 'Muitas senhas erradas. Tente de novo em ' . (int)ceil($left / 60) . ' min.';
        } elseif ($step2 && isset($_POST['code'])) {
            $how = tfa_check((string)$_POST['code']);
            if ($how) {
                login_record(true, $ip);
                unset($_SESSION['pre2fa']);
                session_regenerate_id(true);
                $_SESSION['auth'] = 1;
                if ($how === 'recovery') lkm_log('[login] entrou com um código de recuperação do 2FA (restam ' . count(tfa_get()['recovery'] ?? []) . ')');
                header('Location: ./');
                exit;
            }
            sleep(2);
            login_record(false, $ip);
            $left = login_blocked($ip);
            if ($left > 0) { unset($_SESSION['pre2fa']); $step2 = false; }
            $err = $left > 0 ? 'Código errado. Muitas tentativas: este endereço está bloqueado por ' . LKM_LOCK_MIN . ' min.' : 'Código errado ou vencido. Use o código que aparece agora no app.';
        } elseif (password_verify((string)($_POST['pass'] ?? ''), pass_hash())
            && (!isset($_POST['user']) || hash_equals(strtolower(LKM_USER), strtolower(trim((string)$_POST['user']))))) {
            if (tfa_on()) {
                session_regenerate_id(true);
                $_SESSION['pre2fa'] = time();
                header('Location: ./');
                exit;
            }
            login_record(true, $ip);
            session_regenerate_id(true);
            $_SESSION['auth'] = 1;
            header('Location: ./');
            exit;
        } else {
            sleep(2);
            login_record(false, $ip);
            $left = login_blocked($ip);
            $err = $left > 0 ? 'Usuário ou senha incorretos. Muitas tentativas: este endereço está bloqueado por ' . LKM_LOCK_MIN . ' min.' : 'Usuário ou senha incorretos';
        }
    }
    if (array_intersect(LKM_API_KEYS, array_keys($_GET))) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo '{"error":"auth"}';
        exit;
    }
    session_write_close();
    page_login($err, $step2);
    exit;
}

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
// senha temporária do instalador: só entra no painel depois de trocar
if (must_change()) {
    $err = '';
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['new'])) {
        if (!hash_equals((string)$_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) $err = 'Sessão expirada. Tente de novo.';
        elseif ((string)$_POST['new'] !== (string)($_POST['new2'] ?? '')) $err = 'As duas senhas não são iguais.';
        else {
            $r = pw_apply((string)$_POST['new'], !empty($_POST['root']), !empty($_POST['root']));
            if ($r['ok']) {
                $_SESSION['pwdone'] = $r;
                session_regenerate_id(true);
                header('Location: ./');
                exit;
            }
            $err = $r['error'];
        }
    }
    if (array_intersect(LKM_API_KEYS, array_keys($_GET))) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo '{"error":"auth"}';
        exit;
    }
    page_setpw($err);
    exit;
}
if (isset($_GET['cmd'])) {
    run_cmd_endpoint();
    exit;
}
if (isset($_GET['fm'])) {
    fm_endpoint();
    exit;
}
if (isset($_GET['sites'])) {
    sites_endpoint();
    exit;
}
if (isset($_GET['db'])) {
    db_endpoint();
    exit;
}
if (isset($_GET['cfg'])) {
    cfg_endpoint();
    exit;
}
if (isset($_GET['term'])) {
    term_endpoint();
    exit;
}
if (isset($_GET['sys'])) {
    sys_endpoint();
    exit;
}
if (isset($_GET['apps'])) {
    apps_endpoint();
    exit;
}
if (isset($_GET['cf'])) {
    cf_endpoint();
    exit;
}
if (isset($_GET['pw'])) {
    pw_endpoint();
    exit;
}
if (isset($_GET['tfa'])) {
    tfa_endpoint();
    exit;
}

if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(collect_stats(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}
if (isset($_GET['hist'])) {
    session_write_close();
    json_out(metrics_history((string)$_GET['hist']));
    exit;
}
if (isset($_GET['jobs'])) {
    session_write_close();
    jobs_endpoint();
    exit;
}
if (isset($_GET['ca'])) {
    session_write_close();
    ca_download();
    exit;
}
session_write_close();
page_dashboard();

/* ------------------------------------------------------------------ login: IP real, bloqueio e histórico */

/**
 * IP de quem acessa. Pelo Cloudflare Tunnel e pelo Tailscale Funnel tudo chega de 127.0.0.1: aí vale o IP que o
 * próprio túnel informa (CF-Connecting-IP, ou o último do X-Forwarded-For). Só é aceito vindo do próprio servidor.
 */
function client_ip(): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '?');
    if (!in_array($ip, ['127.0.0.1', '::1'], true)) return $ip;
    $cf = (string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '');
    if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP)) return $cf;
    $xff = array_map('trim', explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')));
    $last = (string)end($xff);
    if ($last !== '' && filter_var($last, FILTER_VALIDATE_IP) && !in_array($last, ['127.0.0.1', '::1'], true)) return $last;
    return $ip;
}

function login_fails(callable $fn): array
{
    $f = LKM_DATA . '/login-fails.json';
    $h = @fopen($f, 'c+');
    if (!$h) return [];
    flock($h, LOCK_EX);
    $d = json_decode((string)stream_get_contents($h), true);
    if (!is_array($d)) $d = [];
    $now = time();
    foreach ($d as $k => $v) { $d[$k] = array_values(array_filter((array)$v, fn($t) => $now - (int)$t < LKM_LOCK_MIN * 60)); if (!$d[$k]) unset($d[$k]); }
    $new = $fn($d);
    if ($new !== null) { ftruncate($h, 0); rewind($h); fwrite($h, json_encode($new)); $d = $new; }
    flock($h, LOCK_UN);
    fclose($h);
    @chmod($f, 0600);
    return $d;
}

/** Segundos que ainda faltam de bloqueio para este IP (0 = liberado). */
function login_blocked(string $ip): int
{
    $l = login_fails(fn($d) => null)[$ip] ?? [];
    if (count($l) < LKM_LOCK_MAX) return 0;
    return max(0, (int)max($l) + LKM_LOCK_MIN * 60 - time());
}

function login_record(bool $ok, string $ip): void
{
    $f = LKM_DATA . '/logins.log';
    $ua = substr(preg_replace('/[^\x20-\x7e]/', '', (string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 160);
    $via = isset($_SERVER['HTTP_CF_RAY']) ? 'cloudflare' : (in_array((string)($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true) && !empty($_SERVER['HTTP_X_FORWARDED_FOR']) ? 'funnel' : (str_starts_with((string)($_SERVER['REMOTE_ADDR'] ?? ''), '100.') ? 'tailscale' : 'rede'));
    @file_put_contents($f, json_encode(['t' => time(), 'ip' => $ip, 'ok' => $ok, 'via' => $via, 'ua' => $ua]) . "\n", FILE_APPEND | LOCK_EX);
    @chmod($f, 0600);
    if (@filesize($f) > 120000) {
        $lines = @file($f, FILE_IGNORE_NEW_LINES) ?: [];
        @file_put_contents($f, implode("\n", array_slice($lines, -300)) . "\n", LOCK_EX);
    }
    $count = 0;
    login_fails(function ($d) use ($ok, $ip, &$count) {
        if ($ok) { unset($d[$ip]); return $d; }
        $d[$ip][] = time();
        $count = count($d[$ip]);
        return $d;
    });
    if (!$ok && $count === LKM_LOCK_MAX) {
        lkm_log('[login] IP bloqueado por ' . LKM_LOCK_MIN . ' min depois de ' . LKM_LOCK_MAX . ' senhas erradas');
        try {
            require_once LKM_LIB . '/alerts.inc.php';
            al_send('🔒 [' . gethostname() . '] LK Monitor: ' . LKM_LOCK_MAX . ' senhas erradas seguidas no painel, vindas de ' . $ip . ' (' . $via . '). Esse IP ficou bloqueado por ' . LKM_LOCK_MIN . ' min.', al_settings());
        } catch (Throwable $e) {
        }
    }
}

/** Últimos acessos ao painel e IPs bloqueados agora (para a aba Internet). */
function login_report(): array
{
    $rows = [];
    foreach (array_reverse(array_slice(@file(LKM_DATA . '/logins.log', FILE_IGNORE_NEW_LINES) ?: [], -30)) as $l) {
        $r = json_decode($l, true);
        if (is_array($r)) $rows[] = $r;
    }
    $blocked = [];
    foreach (login_fails(fn($d) => null) as $ip => $l) {
        if (count($l) >= LKM_LOCK_MAX) $blocked[] = ['ip' => $ip, 'until' => (int)max($l) + LKM_LOCK_MIN * 60];
    }
    return ['logins' => $rows, 'blocked' => $blocked, 'lock' => ['max' => LKM_LOCK_MAX, 'min' => LKM_LOCK_MIN]];
}

/* ------------------------------------------------------------------ login com 2 fatores (TOTP: Google Authenticator, Aegis, Authy…) */

function b32_enc(string $bin): string
{
    $bits = '';
    foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 5) as $ch) $out .= B32[bindec(str_pad($ch, 5, '0'))];
    return $out;
}

function b32_dec(string $s): string
{
    $s = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $s));
    $bits = '';
    foreach (str_split($s) as $c) $bits .= str_pad(decbin((int)strpos(B32, $c)), 5, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 8) as $b) if (strlen($b) === 8) $out .= chr(bindec($b));
    return $out;
}

/** Código de 6 dígitos para o intervalo de 30 s número $step. */
function totp(string $key, int $step): string
{
    $h = hash_hmac('sha1', pack('N2', 0, $step), $key, true);
    $o = ord($h[19]) & 0xf;
    $n = ((ord($h[$o]) & 0x7f) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
    return str_pad((string)($n % 1000000), 6, '0', STR_PAD_LEFT);
}

/** Aceita o código atual e o vizinho (±30 s, relógio do celular um pouco diferente). Devolve o passo usado ou null. */
function totp_match(string $secret, string $code): ?int
{
    if (!preg_match('/^\d{6}$/', $code)) return null;
    $key = b32_dec($secret);
    $now = intdiv(time(), 30);
    foreach ([0, -1, 1] as $d) if (hash_equals(totp($key, $now + $d), $code)) return $now + $d;
    return null;
}

function tfa_file(): string
{
    return LKM_DATA . '/2fa.json';
}

function tfa_get(): array
{
    $d = json_decode((string)@file_get_contents(tfa_file()), true);
    return is_array($d) ? $d : [];
}

function tfa_put(array $d): void
{
    $f = tfa_file();
    @file_put_contents($f . '.tmp', json_encode($d, JSON_PRETTY_PRINT));
    @chmod($f . '.tmp', 0600);
    @rename($f . '.tmp', $f);
}

function tfa_on(): bool
{
    $d = tfa_get();
    return !empty($d['enabled']) && !empty($d['secret']);
}

/** Confere um código do app (sem reaproveitar o mesmo) ou um código de recuperação (uso único). 'totp' | 'recovery' | false. */
function tfa_check(string $code): string|false
{
    $d = tfa_get();
    if (empty($d['secret'])) return false;
    $c = preg_replace('/\s+/', '', $code);
    if (($step = totp_match($d['secret'], $c)) !== null) {
        if ($step <= (int)($d['last'] ?? 0)) return false;   // o mesmo código não entra duas vezes
        $d['last'] = $step;
        tfa_put($d);
        return 'totp';
    }
    $r = strtolower(preg_replace('/[^a-z0-9]/i', '', $c));
    if (strlen($r) === 10) {
        foreach ((array)($d['recovery'] ?? []) as $i => $h) {
            if (password_verify($r, (string)$h)) {
                array_splice($d['recovery'], $i, 1);
                tfa_put($d);
                return 'recovery';
            }
        }
    }
    return false;
}

/** 8 códigos de recuperação (mostrados uma vez; guardados só com hash). */
function tfa_new_recovery(): array
{
    $plain = [];
    $hash = [];
    $abc = 'abcdefghjkmnpqrstuvwxyz23456789';
    for ($i = 0; $i < 8; $i++) {
        $c = '';
        for ($j = 0; $j < 10; $j++) $c .= $abc[random_int(0, strlen($abc) - 1)];
        $plain[] = substr($c, 0, 5) . '-' . substr($c, 5);
        $hash[] = password_hash($c, PASSWORD_DEFAULT);
    }
    return [$plain, $hash];
}

function tfa_alert(string $text): void
{
    try {
        require_once LKM_LIB . '/alerts.inc.php';
        al_send('🔐 [' . gethostname() . '] LK Monitor: ' . $text, al_settings());
    } catch (Throwable $e) {
    }
}

/* ------------------------------------------------------------------ senha do painel (e do root) */

function pass_file(): string
{
    return LKM_DATA . '/pass.json';
}

/** Senha trocada pelo painel (pass.json) vale mais que a do instalador (config.php). */
function pass_hash(): string
{
    $d = json_decode((string)@file_get_contents(pass_file()), true);
    return is_array($d) && !empty($d['hash']) ? (string)$d['hash'] : LKM_PASS_HASH;
}

function must_change(): bool
{
    return is_file(LKM_DATA . '/must-change');
}

/** Regras da senha nova. Devolve o erro ou ''. */
function pw_weak(string $p): string
{
    if (strlen($p) < 10) return 'Use pelo menos 10 caracteres (uma frase de 3 ou 4 palavras é ótima).';
    if (strlen($p) > 200) return 'Senha longa demais.';
    if (preg_match('/[\x00-\x1f]/', $p)) return 'A senha tem um caractere inválido.';
    if (count(array_unique(str_split($p))) < 5) return 'Senha fácil demais: use caracteres variados.';
    $classes = (int)preg_match('/[a-z]/', $p) + (int)preg_match('/[A-Z]/', $p) + (int)preg_match('/\d/', $p) + (int)preg_match('/[^a-zA-Z0-9]/', $p);
    if (strlen($p) < 14 && $classes < 3) return 'Com menos de 14 caracteres, misture maiúsculas, minúsculas, números ou símbolos.';
    $low = strtolower($p);
    foreach (['12345678', 'password', 'senha123', 'qwerty', 'abcdefgh', 'lkpanel', 'lkmonitor', strtolower((string)gethostname())] as $bad) {
        if ($bad !== '' && str_contains($low, $bad)) return 'A senha tem uma parte fácil de adivinhar (' . $bad . ').';
    }
    if (password_verify($p, pass_hash())) return 'A senha nova precisa ser diferente da atual.';
    return '';
}

/** Grava a senha nova do painel e, se pedir, troca a do root do Linux (SSH) com a mesma. */
function pw_apply(string $new, bool $root, bool $ssh = false): array
{
    if (($w = pw_weak($new)) !== '') return ['ok' => false, 'error' => $w];
    $rootMsg = null;
    $sshOn = null;
    if ($root) {
        if (shell_user() !== 'root') return ['ok' => false, 'error' => 'Para trocar a senha do root junto, o terminal do painel precisa estar como root (sudo lk › opção 33).'];
        if (str_contains($new, ':')) return ['ok' => false, 'error' => 'A senha do Linux não pode ter ":". Tire o ":" ou desmarque o root.'];
        $r = helper_exec('sys.php', ['action' => 'rootpass', 'pass' => $new, 'ssh' => $ssh], 60);
        if (empty($r['ok'])) return ['ok' => false, 'error' => 'Senha do root não foi trocada: ' . ($r['error'] ?? 'erro') . ' (a do painel também não).'];
        $sshOn = $r['ssh'] ?? null;
    }
    $f = pass_file();
    if (@file_put_contents($f . '.tmp', json_encode(['hash' => password_hash($new, PASSWORD_DEFAULT), 'since' => time()])) === false) {
        return ['ok' => false, 'error' => 'Não foi possível gravar a senha em ' . LKM_DATA . ($root ? ' (a senha do root JÁ foi trocada para a nova).' : '.')];
    }
    @chmod($f . '.tmp', 0600);
    @rename($f . '.tmp', $f);
    @unlink(LKM_DATA . '/must-change');
    lkm_log('[senha] senha do painel trocada' . ($root ? ' (e a do root do Linux)' : ''));
    tfa_alert('senha do painel trocada' . ($root ? ' (e a do root / SSH)' : '') . ' de ' . client_ip() . '. Se não foi você, entre no servidor e rode: sudo lk senha');
    return ['ok' => true, 'root' => $root, 'ssh' => $sshOn, 'sshset' => $ssh];
}

function pw_endpoint(): void
{
    $valid = hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($_SERVER['HTTP_X_LKM_TOKEN'] ?? ''));
    session_write_close();
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !$valid) {
        json_out(['ok' => false, 'error' => 'Sessão expirada. Recarregue a página.'], 403);
        return;
    }
    $act = (string)($_GET['pw'] ?? '');
    if ($act === 'status') {
        $d = json_decode((string)@file_get_contents(pass_file()), true);
        json_out(['ok' => true, 'user' => LKM_USER, 'since' => is_array($d) ? ($d['since'] ?? null) : null, 'root' => shell_user() === 'root', 'ips' => array_values(array_filter(explode(' ', trim(sh('hostname -I'))), fn($ip) => $ip !== '' && !str_contains($ip, ':')))]);
        return;
    }
    if ($act !== 'change') {
        json_out(['ok' => false, 'error' => 'Ação inválida'], 400);
        return;
    }
    $in = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($in)) $in = [];
    if (!password_verify((string)($in['cur'] ?? ''), pass_hash())) {
        sleep(2);
        login_record(false, client_ip());
        json_out(['ok' => false, 'error' => 'Senha atual errada.']);
        return;
    }
    json_out(pw_apply((string)($in['new'] ?? ''), !empty($in['root']), !empty($in['root']) && !empty($in['ssh'])));
}

function page_setpw(string $err): void
{
    $title = htmlspecialchars(LKM_TITLE);
    $host = htmlspecialchars((string)gethostname());
    $err = htmlspecialchars($err);
    $csrf = htmlspecialchars((string)$_SESSION['csrf']);
    $css = base_css();
    $pwa = pwa_head();
    $rootBox = shell_user() === 'root'
        ? '<label class="chk"><input type="checkbox" name="root" value="1" checked><span>Usar esta senha também na conta <b>root</b> do Linux e <b>ligar o SSH do root</b> (<code>ssh root@IP</code>)</span></label>'
        : '';
    echo <<<HTML
<!doctype html><html lang="pt-br"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$title} · Senha nova</title>{$pwa}<style>{$css}
.wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:16px}
form{width:100%;max-width:400px;background:var(--card);border:1px solid var(--line);border-radius:18px;padding:30px 28px;box-shadow:0 30px 80px #0008}
.logo{display:flex;align-items:center;gap:10px;font-weight:700;font-size:18px;margin-bottom:4px}
.logo i{width:30px;height:30px;border-radius:8px;background:url(icon.svg) center/cover;display:inline-block}
p{color:var(--mut);margin-bottom:16px;font-size:13px;line-height:1.5}
p b{color:var(--tx)}
input[type=password],input[type=text]{width:100%;padding:12px 14px;border-radius:10px;border:1px solid var(--line2);background:var(--bg2);color:var(--tx);font-size:15px;outline:none;margin-bottom:10px}
input:focus{border-color:var(--cpu);box-shadow:0 0 0 3px #5b9cff33}
.chk{display:flex;gap:9px;align-items:flex-start;font-size:13px;color:var(--tx);margin:6px 0 4px;line-height:1.45;cursor:pointer}
.chk input{margin-top:3px;accent-color:var(--pri)}
.meter{height:5px;border-radius:3px;background:var(--line);overflow:hidden;margin:-4px 0 6px}
.meter b{display:block;height:100%;width:0;background:var(--bad);transition:width .2s,background .2s}
.mtx{font-size:12px;color:var(--mut);min-height:16px;margin-bottom:6px}
.row{display:flex;gap:8px;justify-content:space-between;align-items:center;font-size:12.5px}
.row a{color:var(--cpu);cursor:pointer;text-decoration:none}
button{width:100%;margin-top:14px;padding:12px;border:0;border-radius:3px;background:var(--pri);color:#fff;font-weight:600;font-size:15px;cursor:pointer}
button:hover{filter:brightness(1.1)}
.err{color:var(--bad);font-size:13px;margin-top:12px;min-height:18px}
</style></head><body><div class="wrap"><form method="post" autocomplete="off">
<div class="logo"><i></i>{$title}</div>
<p>{$host} · primeiro acesso<br>Você entrou com a <b>senha temporária</b> do instalador. Crie a sua senha agora (usuário: <b>root</b>).</p>
<input type="hidden" name="csrf" value="{$csrf}">
<input type="password" name="new" id="n1" placeholder="Senha nova" autocomplete="new-password" autofocus required minlength="10">
<div class="meter"><b id="mb"></b></div><div class="mtx" id="mt">Mínimo 10 caracteres. Uma frase de 4 palavras é forte e fácil de lembrar.</div>
<input type="password" name="new2" id="n2" placeholder="Repita a senha nova" autocomplete="new-password" required>
<div class="row"><a id="show">Mostrar senha</a><a id="gen">Gerar uma forte</a></div>
{$rootBox}
<button type="submit">Salvar e entrar</button><div class="err">{$err}</div>
</form></div>
<script>
(function () {
  var n1 = document.getElementById('n1'), n2 = document.getElementById('n2'), mb = document.getElementById('mb'), mt = document.getElementById('mt');
  function score(p) {
    var pool = 0;
    if (/[a-z]/.test(p)) pool += 26;
    if (/[A-Z]/.test(p)) pool += 26;
    if (/[0-9]/.test(p)) pool += 10;
    if (/[^a-zA-Z0-9]/.test(p)) pool += 33;
    return Math.round(p.length * Math.log2(pool || 1));
  }
  n1.addEventListener('input', function () {
    var b = score(n1.value), pct = Math.min(100, b);
    mb.style.width = pct + '%';
    mb.style.background = b < 45 ? 'var(--bad)' : b < 70 ? 'var(--warn, #e6a23c)' : 'var(--ok)';
    mt.textContent = n1.value.length === 0 ? '' : '~' + b + ' bits · ' + (b < 45 ? 'fraca' : b < 70 ? 'média' : b < 90 ? 'forte' : 'muito forte');
  });
  document.getElementById('show').onclick = function () {
    var t = n1.type === 'password' ? 'text' : 'password';
    n1.type = t; n2.type = t;
    this.textContent = t === 'text' ? 'Esconder senha' : 'Mostrar senha';
  };
  document.getElementById('gen').onclick = function () {
    var abc = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789', out = '', r = new Uint32Array(20);
    crypto.getRandomValues(r);
    for (var i = 0; i < 20; i++) { out += abc.charAt(r[i] % abc.length); if (i % 5 === 4 && i < 19) out += '-'; }
    n1.value = out; n2.value = out; n1.type = 'text'; n2.type = 'text';
    document.getElementById('show').textContent = 'Esconder senha';
    n1.dispatchEvent(new Event('input'));
    mt.textContent += ' · anote antes de salvar!';
  };
})();
</script></body></html>
HTML;
}

function tfa_endpoint(): void
{
    $act = (string)($_GET['tfa'] ?? '');
    $valid = hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($_SERVER['HTTP_X_LKM_TOKEN'] ?? ''));
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !$valid) {
        session_write_close();
        json_out(['ok' => false, 'error' => 'Sessão expirada. Recarregue a página.'], 403);
        return;
    }
    $in = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($in)) $in = [];
    $d = tfa_get();
    switch ($act) {
        case 'status':
            session_write_close();
            json_out(['ok' => true, 'enabled' => tfa_on(), 'since' => $d['since'] ?? null, 'recovery' => count((array)($d['recovery'] ?? []))]);
            return;
        case 'start':
            if (tfa_on()) { session_write_close(); json_out(['ok' => false, 'error' => 'O 2FA já está ligado.']); return; }
            $secret = b32_enc(random_bytes(20));
            $_SESSION['tfa_pending'] = $secret;
            session_write_close();
            $label = rawurlencode('LK Monitor') . ':' . rawurlencode(strtolower(explode('.', (string)gethostname())[0]));
            json_out(['ok' => true, 'secret' => trim(chunk_split($secret, 4, ' ')),
                'uri' => 'otpauth://totp/' . $label . '?secret=' . $secret . '&issuer=' . rawurlencode('LK Monitor') . '&algorithm=SHA1&digits=6&period=30']);
            return;
        case 'enable':
            $secret = (string)($_SESSION['tfa_pending'] ?? '');
            if ($secret === '') { session_write_close(); json_out(['ok' => false, 'error' => 'Comece de novo: clique em Ativar 2FA.']); return; }
            $step = totp_match($secret, preg_replace('/\s+/', '', (string)($in['code'] ?? '')));
            if ($step === null) { session_write_close(); json_out(['ok' => false, 'error' => 'Código errado. Confira se o relógio do celular está certo e digite o código que aparece agora.']); return; }
            unset($_SESSION['tfa_pending']);
            session_write_close();
            [$plain, $hash] = tfa_new_recovery();
            tfa_put(['enabled' => true, 'secret' => $secret, 'since' => time(), 'last' => $step, 'recovery' => $hash]);
            lkm_log('[2fa] ligado');
            tfa_alert('login com 2 fatores LIGADO no painel.');
            json_out(['ok' => true, 'recovery' => $plain]);
            return;
        case 'disable':
        case 'recovery':
            session_write_close();
            if (!tfa_on()) { json_out(['ok' => false, 'error' => 'O 2FA não está ligado.']); return; }
            if (!tfa_check((string)($in['code'] ?? ''))) { sleep(2); json_out(['ok' => false, 'error' => 'Código errado. Use o código que aparece agora no app (ou um de recuperação).']); return; }
            if ($act === 'disable') {
                @unlink(tfa_file());
                lkm_log('[2fa] desligado pelo painel');
                tfa_alert('login com 2 fatores DESLIGADO no painel (de ' . client_ip() . '). Se não foi você, troque a senha agora.');
                json_out(['ok' => true]);
                return;
            }
            [$plain, $hash] = tfa_new_recovery();
            $d = tfa_get();
            $d['recovery'] = $hash;
            tfa_put($d);
            lkm_log('[2fa] códigos de recuperação novos');
            json_out(['ok' => true, 'recovery' => $plain]);
            return;
    }
    session_write_close();
    json_out(['ok' => false, 'error' => 'Ação inválida'], 400);
}

/* ------------------------------------------------------------------ aba Internet (Cloudflare Tunnel) */

function cf_endpoint(): void
{
    $act = (string)($_GET['cf'] ?? '');
    $valid = hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($_SERVER['HTTP_X_LKM_TOKEN'] ?? ''));
    session_write_close();
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !$valid) {
        json_out(['ok' => false, 'error' => 'Sessão expirada. Recarregue a página.'], 403);
        return;
    }
    if (!in_array($act, ['status', 'login', 'setup', 'route_add', 'route_del', 'toggle', 'quick_start', 'quick_stop', 'unblock'], true)) {
        json_out(['ok' => false, 'error' => 'Ação inválida'], 400);
        return;
    }
    $in = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($in)) $in = [];
    if ($act === 'unblock') {
        $ip = (string)($in['ip'] ?? '');
        login_fails(function ($d) use ($ip) { unset($d[$ip]); return $d; });
        lkm_log('[login] IP liberado: ' . $ip);
        json_out(['ok' => true]);
        return;
    }
    if (!is_file(LKM_LIB . '/cf.php')) {
        json_out(['ok' => false, 'error' => 'Atualize o LK Monitor (sudo lk, opção 33) para usar a aba Internet.']);
        return;
    }
    if ($act !== 'status' && shell_user() !== 'root') {
        json_out(['ok' => false, 'error' => 'A aba Internet precisa do terminal do LK Monitor como root. No servidor: sudo lk › opção 33 › instalar / atualizar e escolha root.']);
        return;
    }
    $in['action'] = $act;
    if ($act !== 'status') lkm_log('[internet] ' . $act . (isset($in['host']) ? ' ' . $in['host'] : '') . (isset($in['target']['type']) ? ' -> ' . $in['target']['type'] . ' ' . ($in['target']['name'] ?? $in['target']['port'] ?? '') : ''));
    @set_time_limit(0);
    $r = helper_exec('cf.php', $in, 120);
    if ($act === 'status') $r += login_report() + ['here' => (string)($_SERVER['HTTP_HOST'] ?? ''), 'via' => isset($_SERVER['HTTP_CF_RAY']) ? 'cloudflare' : 'rede'];
    json_out($r);
}

/* ------------------------------------------------------------------ coleta */

function take_sample(): array
{
    $cpu = [];
    foreach (explode("\n", rf('/proc/stat')) as $l) {
        if (!preg_match('/^(cpu\d*)\s+(.*)$/', $l, $m)) continue;
        $v = array_map('intval', preg_split('/\s+/', trim($m[2])));
        $cpu[$m[1]] = [array_sum(array_slice($v, 0, 8)), ($v[3] ?? 0) + ($v[4] ?? 0)];
    }
    $net = [];
    foreach (explode("\n", rf('/proc/net/dev')) as $l) {
        if (!preg_match('/^\s*([^:\s]+):\s*(.*)$/', $l, $m)) continue;
        $if = $m[1];
        if (skip_iface($if)) continue;
        $p = preg_split('/\s+/', trim($m[2]));
        $net[$if] = [(int)($p[0] ?? 0), (int)($p[8] ?? 0)];
    }
    $proc = [];
    foreach (glob('/proc/[0-9]*', GLOB_ONLYDIR) ?: [] as $d) {
        $s = rf("$d/stat");
        if ($s === '') continue;
        $a = strpos($s, '(');
        $b = strrpos($s, ')');
        if ($a === false || $b === false) continue;
        $f = explode(' ', substr($s, $b + 2));
        $proc[(int)basename($d)] = [(int)($f[11] ?? 0) + (int)($f[12] ?? 0), (int)($f[21] ?? 0), substr($s, $a + 1, $b - $a - 1)];
    }
    return ['t' => microtime(true), 'cpu' => $cpu, 'net' => $net, 'proc' => $proc];
}

function slim(array $s): array
{
    return ['t' => $s['t'], 'cpu' => $s['cpu'], 'net' => $s['net'], 'proc' => array_map(fn($p) => $p[0], $s['proc'])];
}

function users_map(): array
{
    $u = [];
    foreach (explode("\n", rf('/etc/passwd')) as $l) {
        $p = explode(':', $l);
        if (count($p) > 2) $u[(int)$p[2]] = $p[0];
    }
    return $u;
}

function battery(): ?array
{
    $bat = null;
    $ac = null;
    foreach (glob('/sys/class/power_supply/*') ?: [] as $p) {
        $type = trim(rf("$p/type"));
        if ($type === 'Battery' && $bat === null && trim(rf("$p/scope")) !== 'Device') {
            $bat = ['pct' => (int)trim(rf("$p/capacity")), 'status' => trim(rf("$p/status"))];
        } elseif ($type === 'Mains') {
            $ac = trim(rf("$p/online")) === '1';
        }
    }
    if ($bat) $bat['ac'] = $ac;
    return $bat;
}

function services(): array
{
    $c = ['apache2', 'nginx', 'mariadb', 'mysql', 'redis-server', 'supervisor', 'ssh', 'ufw', 'docker', 'fail2ban', 'cron', 'pm2-root', 'tailscaled'];
    foreach (glob('/etc/php/*/fpm', GLOB_ONLYDIR) ?: [] as $d) $c[] = 'php' . basename(dirname($d)) . '-fpm';
    $have = [];
    foreach ($c as $s) {
        foreach (['/usr/lib/systemd/system', '/lib/systemd/system', '/etc/systemd/system'] as $dir) {
            if (is_file("$dir/$s.service")) { $have[$s] = 1; break; }
        }
    }
    if (isset($have['mariadb'])) unset($have['mysql']);
    $names = array_keys($have);
    if (!$names) return [];
    $st = explode("\n", trim(sh('systemctl is-active ' . implode(' ', array_map('escapeshellarg', $names)))));
    $out = [];
    foreach ($names as $i => $n) $out[] = ['name' => $n, 'state' => ($st[$i] ?? '') !== '' ? $st[$i] : 'unknown'];
    return $out;
}

function collect_stats(): array
{
    $cur = take_sample();
    $prev = $_SESSION['prev'] ?? null;
    if (!$prev || $cur['t'] - $prev['t'] > 20) {
        usleep(400000);
        $prev = slim($cur);
        $cur = take_sample();
    }
    $_SESSION['prev'] = slim($cur);
    session_write_close();
    $dt = max(0.1, $cur['t'] - $prev['t']);

    // CPU
    $pct = [];
    foreach ($cur['cpu'] as $k => [$tot, $idle]) {
        $p = $prev['cpu'][$k] ?? null;
        $dTot = $p ? $tot - $p[0] : 0;
        $pct[$k] = $dTot > 0 ? round(max(0, min(100, 100 * (1 - ($idle - $p[1]) / $dTot))), 1) : 0.0;
    }
    $cpuTotal = $pct['cpu'] ?? 0.0;
    unset($pct['cpu']);
    $cores = array_values($pct);
    $ncpu = max(1, count($cores));

    // memória
    $mi = meminfo();
    $memTotal = $mi['MemTotal'] ?? 0;
    $memAvail = $mi['MemAvailable'] ?? 0;

    // rede
    $ifs = [];
    $rx = 0.0;
    $tx = 0.0;
    foreach ($cur['net'] as $if => [$r, $t]) {
        if ($r + $t === 0) continue;
        $p = $prev['net'][$if] ?? [$r, $t];
        $rr = max(0, ($r - $p[0]) / $dt);
        $tr = max(0, ($t - $p[1]) / $dt);
        $rx += $rr;
        $tx += $tr;
        $ifs[] = ['name' => $if, 'rx' => round($rr), 'tx' => round($tr), 'rx_total' => $r, 'tx_total' => $t];
    }

    // processos
    $users = users_map();
    $rows = [];
    foreach ($cur['proc'] as $pid => [$ticks, $rss, $comm]) {
        $pt = $prev['proc'][$pid] ?? null;
        $c = $pt === null ? 0.0 : max(0, ($ticks - $pt) / 100 / $dt * 100);
        $rows[] = [$pid, $comm, $c, $rss * 4096];
    }
    $fmt = function (array $r) use ($users, $memTotal): array {
        $uid = @fileowner('/proc/' . $r[0]);
        return [
            'pid' => $r[0], 'name' => $r[1], 'user' => $uid === false ? '?' : ($users[$uid] ?? (string)$uid),
            'cpu' => round($r[2], 1), 'mem' => $r[3], 'mem_pct' => $memTotal ? round($r[3] / $memTotal * 100, 1) : 0,
        ];
    };
    $byCpu = $rows;
    usort($byCpu, fn($a, $b) => $b[2] <=> $a[2] ?: $b[3] <=> $a[3]);
    $byMem = $rows;
    usort($byMem, fn($a, $b) => $b[3] <=> $a[3]);

    // info
    $os = 'Linux';
    if (preg_match('/^PRETTY_NAME="?([^"\n]+)"?/m', rf('/etc/os-release'), $m)) $os = $m[1];
    $ci = rf('/proc/cpuinfo');
    $model = preg_match('/^model name\s*:\s*(.+)$/m', $ci, $m) ? trim($m[1]) : php_uname('m');
    $mhz = preg_match_all('/^cpu MHz\s*:\s*([\d.]+)/m', $ci, $mm) ? round(array_sum($mm[1]) / count($mm[1])) : null;
    $load = array_map('floatval', array_slice(explode(' ', rf('/proc/loadavg')), 0, 3));
    $ips = array_values(array_filter(explode(' ', trim(sh('hostname -I'))), fn($ip) => $ip !== '' && !str_contains($ip, ':')));

    return [
        'time' => date('H:i:s'),
        'host' => gethostname(),
        'os' => $os,
        'kernel' => php_uname('r'),
        'uptime' => (int)(float)rf('/proc/uptime'),
        'ips' => $ips,
        'php' => PHP_VERSION,
        'cpu' => ['total' => $cpuTotal, 'cores' => $cores, 'count' => $ncpu, 'model' => $model, 'mhz' => $mhz, 'load' => $load],
        'mem' => [
            'total' => $memTotal, 'used' => $memTotal - $memAvail, 'avail' => $memAvail,
            'cached' => ($mi['Cached'] ?? 0) + ($mi['Buffers'] ?? 0),
            'swap_total' => $mi['SwapTotal'] ?? 0, 'swap_used' => ($mi['SwapTotal'] ?? 0) - ($mi['SwapFree'] ?? 0),
        ],
        'net' => ['rx' => round($rx), 'tx' => round($tx), 'ifs' => $ifs, 'conn' => tcp_established()],
        'disks' => disks(),
        'temp' => temps(),
        'battery' => battery(),
        'services' => services(),
        'procs' => [
            'count' => count($rows),
            'cpu' => array_map($fmt, array_slice($byCpu, 0, 8)),
            'mem' => array_map($fmt, array_slice($byMem, 0, 8)),
        ],
    ];
}

/* ------------------------------------------------------------------ terminal */

function shell_user(): string
{
    return defined('LKM_SHELL_USER') ? (string)LKM_SHELL_USER : '';
}

function user_home(string $u): string
{
    foreach (explode("\n", rf('/etc/passwd')) as $l) {
        $p = explode(':', $l);
        if (($p[0] ?? '') === $u && !empty($p[5])) return $p[5];
    }
    return $u === 'root' ? '/root' : '/';
}

function run_cmd_endpoint(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $u = shell_user();
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($_SERVER['HTTP_X_LKM_TOKEN'] ?? ''))) {
        http_response_code(403);
        echo '{"error":"token"}';
        return;
    }
    if ($u === '') {
        echo json_encode(['out' => "Terminal desativado na instalação do LK Monitor.\n", 'cwd' => '/', 'code' => 1]);
        return;
    }
    $in = json_decode((string)file_get_contents('php://input'), true);
    $cmd = trim((string)($in['cmd'] ?? ''));
    $home = user_home($u);
    $cwd = (string)($_SESSION['cwd'] ?? $home);
    session_write_close();
    if ($cmd === '') {
        echo json_encode(['out' => '', 'cwd' => $cwd, 'code' => 0]);
        return;
    }
    if (strlen($cmd) > 4000) {
        echo json_encode(['out' => "Comando muito longo.\n", 'cwd' => $cwd, 'code' => 1]);
        return;
    }
    @file_put_contents(LKM_DATA . '/cmd.log', date('Y-m-d H:i:s') . ' ' . client_ip() . " [$u] " . str_replace("\n", ' ', $cmd) . "\n", FILE_APPEND | LOCK_EX);

    $script = 'exec 2>&1; cd -- "$1" 2>/dev/null || cd ~; eval "$2"; __lkm_rc=$?; printf "\n__LKM_PWD__%s\n" "$PWD"; exit $__lkm_rc';
    $argv = ['/usr/bin/sudo', '-n', '-u', $u, '/usr/bin/env', 'HOME=' . $home, 'USER=' . $u,
        'PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:/snap/bin',
        'TERM=dumb', 'PAGER=cat', 'SYSTEMD_PAGER=', 'SYSTEMD_COLORS=0', 'COLUMNS=140', 'LANG=C.UTF-8',
        '/usr/bin/timeout', '-k', '3', (string)LKM_CMD_TIMEOUT, '/bin/bash', '-c', $script, 'lkm', $cwd, $cmd];
    $out = '';
    $code = 1;
    $proc = @proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        $out = "Não foi possível executar o comando (proc_open bloqueado?).\n";
    } else {
        $max = 300000;
        $cut = false;
        while (!feof($pipes[1])) {
            $chunk = fread($pipes[1], 65536);
            if ($chunk === false) break;
            if (strlen($out) < $max) $out .= $chunk; else $cut = true;
        }
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        if ($err !== '' && $err !== false) $out = $err . $out;
        if ($cut) $out .= "\n[saída cortada: muito grande]\n";
    }
    $newCwd = $cwd;
    $pos = strrpos($out, "\n__LKM_PWD__");
    if ($pos !== false) {
        $newCwd = trim(substr($out, $pos + 12));
        $out = substr($out, 0, $pos);
    }
    if ($code === 124 || $code === 137) $out .= "\n[tempo esgotado: " . LKM_CMD_TIMEOUT . "s]";
    if (str_contains($out, 'a password is required') || str_contains($out, 'password is required')) {
        $out .= "\n[sudo não configurado para o LK Monitor. Rode o instalador de novo.]";
    }
    $out = preg_replace('/\e\[[0-9;?]*[ -\/]*[@-~]|\e\][^\a]*\a|\r(?!\n)/', '', $out);
    if (!mb_check_encoding($out, 'UTF-8')) $out = mb_convert_encoding($out, 'UTF-8', 'UTF-8');

    session_start();
    $_SESSION['cwd'] = $newCwd;
    session_write_close();
    echo json_encode(['out' => $out, 'cwd' => $newCwd, 'code' => $code], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}

/* ------------------------------------------------------------------ terminal completo (xterm.js + serviço lkmonitor-term) */

/**
 * O navegador pede um ticket aqui (depois do login) e abre o WebSocket em term-ws?t=<ticket>.
 * O serviço lkmonitor-term (lib/term.py, usuário lkmonitor) lê e apaga o ticket: uso único, vale 60 s.
 */
function term_endpoint(): void
{
    $act = (string)($_GET['term'] ?? '');
    $valid = hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($_SERVER['HTTP_X_LKM_TOKEN'] ?? ''));
    session_write_close();
    if (!$valid) {
        json_out(['ok' => false, 'error' => 'Sessão expirada. Recarregue a página.'], 403);
        return;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        json_out(['ok' => false, 'error' => 'Método inválido'], 405);
        return;
    }
    if (shell_user() === '') {
        json_out(['ok' => false, 'error' => 'Terminal desativado. Rode o instalador de novo e escolha root ou seu usuário.', 'off' => true]);
        return;
    }
    $port = (int)LKM_TERM_PORT;
    if ($port <= 0) {
        json_out(['ok' => false, 'error' => 'O terminal completo não está instalado. Atualize o LK Monitor (sudo lk, opção 33).', 'off' => true]);
        return;
    }
    $dir = LKM_DATA . '/term';
    if ($act === 'list') {
        $d = json_decode(rf("$dir/sessions.json"), true);
        $list = [];
        foreach ((array)($d['sessions'] ?? []) as $s) {
            if (!is_array($s) || !preg_match('/^[a-f0-9]{32}$/', (string)($s['id'] ?? ''))) continue;
            $list[] = ['id' => $s['id'], 'created' => (int)($s['created'] ?? 0), 'attached' => !empty($s['attached'])];
        }
        json_out(['ok' => true, 'sessions' => $list, 'ttl' => (int)($d['ttl'] ?? 0)]);
        return;
    }
    if ($act !== 'ticket') {
        json_out(['ok' => false, 'error' => 'Ação inválida'], 400);
        return;
    }
    $fp = @fsockopen('127.0.0.1', $port, $en, $es, 1.5);
    if (!$fp) {
        json_out(['ok' => false, 'error' => 'O serviço do terminal está parado. No servidor rode: sudo systemctl restart lkmonitor-term', 'down' => true]);
        return;
    }
    fclose($fp);
    $in = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($in)) $in = [];
    $sid = (string)($in['sid'] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/', $sid)) $sid = '';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    foreach (glob("$dir/t-*.json") ?: [] as $f) {
        if ((int)@filemtime($f) < time() - 120) @unlink($f);
    }
    $t = bin2hex(random_bytes(16));
    $f = "$dir/t-$t.json";
    $data = ['exp' => time() + 60, 'sid' => $sid !== '' ? $sid : null, 'cols' => (int)($in['cols'] ?? 100), 'rows' => (int)($in['rows'] ?? 30)];
    if (@file_put_contents($f, json_encode($data), LOCK_EX) === false) {
        json_out(['ok' => false, 'error' => "Não foi possível preparar o terminal (sem permissão em $dir)."]);
        return;
    }
    @chmod($f, 0600);
    json_out(['ok' => true, 'ticket' => $t]);
}

/* ------------------------------------------------------------------ gerenciador de arquivos */

function json_out(array $d, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}

/** Executa o helper como o usuário configurado. Com $stream=true envia o stdout direto ao navegador. */
function fm_exec(array $req, bool $stream = false): ?array
{
    $argv = ['/usr/bin/sudo', '-n', '-u', shell_user(), LKM_PHP_CLI, '-d', 'memory_limit=1024M', '-d', 'display_errors=stderr', LKM_FM_HELPER];
    $proc = @proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) return ['ok' => false, 'error' => 'Não foi possível executar o gerenciador (proc_open bloqueado?).'];
    fwrite($pipes[0], json_encode($req, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    fclose($pipes[0]);
    if ($stream) {
        while (!feof($pipes[1])) {
            $chunk = fread($pipes[1], 262144);
            if ($chunk === false) break;
            if ($chunk === '') continue;
            echo $chunk;
            flush();
            if (connection_aborted()) break;
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        return null;
    }
    $out = stream_get_contents($pipes[1]);
    $err = trim((string)stream_get_contents($pipes[2]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    $d = json_decode((string)$out, true);
    if (is_array($d)) return $d;
    if (str_contains($err, 'password') || str_contains($err, 'sudo')) {
        return ['ok' => false, 'error' => 'Permissão do sudo não configurada para o painel. Rode o instalador de novo.'];
    }
    return ['ok' => false, 'error' => $err !== '' ? $err : 'Resposta inválida do gerenciador.'];
}

function fm_endpoint(): void
{
    $act = (string)($_GET['fm'] ?? '');
    $tok = (string)($_SERVER['HTTP_X_LKM_TOKEN'] ?? ($_GET['t'] ?? ''));
    $valid = hash_equals((string)($_SESSION['csrf'] ?? ''), $tok);
    session_write_close();
    if (!$valid) {
        json_out(['ok' => false, 'error' => 'Sessão expirada. Recarregue a página.'], 403);
        return;
    }
    if (shell_user() === '') {
        json_out(['ok' => false, 'error' => 'Gerenciador desativado. Rode o instalador e escolha root ou seu usuário.']);
        return;
    }
    @set_time_limit(0);

    if ($act === 'download' || $act === 'view') {
        fm_download($act === 'view');
        return;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        json_out(['ok' => false, 'error' => 'Método inválido'], 405);
        return;
    }
    if ($act === 'upload') {
        $f = $_FILES['file'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $code = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
            $msg = [
                UPLOAD_ERR_INI_SIZE => 'Arquivo maior que o limite do PHP (upload_max_filesize).',
                UPLOAD_ERR_FORM_SIZE => 'Arquivo maior que o limite permitido.',
                UPLOAD_ERR_PARTIAL => 'Envio interrompido. Tente de novo.',
                UPLOAD_ERR_NO_FILE => 'Nenhum arquivo recebido (pode ter passado do limite post_max_size).',
                UPLOAD_ERR_NO_TMP_DIR => 'Pasta temporária ausente no servidor.',
                UPLOAD_ERR_CANT_WRITE => 'Falha ao gravar o arquivo temporário (disco cheio?).',
            ][$code] ?? 'Falha no envio (código ' . $code . ').';
            json_out(['ok' => false, 'error' => $msg]);
            return;
        }
        @chmod($f['tmp_name'], 0644);
        $r = fm_exec(['action' => 'upload', 'tmp' => $f['tmp_name'], 'dest' => (string)($_POST['dest'] ?? ''),
            'name' => (string)($_POST['name'] ?? $f['name']), 'overwrite' => ($_POST['overwrite'] ?? '') === '1']);
        @unlink($f['tmp_name']);
        json_out($r ?? ['ok' => false]);
        return;
    }
    $allowed = ['list', 'stat', 'read', 'save', 'mkdir', 'newfile', 'rename', 'delete', 'copy', 'move', 'chmod', 'chown', 'zip', 'extract', 'search'];
    if (!in_array($act, $allowed, true)) {
        json_out(['ok' => false, 'error' => 'Ação inválida'], 400);
        return;
    }
    $in = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($in)) $in = [];
    $in['action'] = $act;
    if (in_array($act, ['save', 'delete', 'move', 'rename', 'chmod', 'chown', 'extract'], true)) {
        $what = $in['path'] ?? implode(', ', array_slice((array)($in['paths'] ?? []), 0, 5));
        @file_put_contents(LKM_DATA . '/cmd.log', date('Y-m-d H:i:s') . ' ' . client_ip() . ' [arquivos] ' . $act . ' ' . $what . "\n", FILE_APPEND | LOCK_EX);
    }
    json_out(fm_exec($in) ?? ['ok' => false]);
}

function fm_download(bool $inline): void
{
    $path = (string)($_GET['path'] ?? '');
    if (isset($_GET['paths'])) {
        $paths = json_decode((string)$_GET['paths'], true);
        if (!is_array($paths) || !$paths) {
            http_response_code(400);
            echo 'Nada selecionado';
            return;
        }
        $st = ['ok' => true, 'is_dir' => true];
    } else {
        $st = fm_exec(['action' => 'stat', 'path' => $path]);
    }
    if (empty($st['ok'])) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo $st['error'] ?? 'Arquivo não encontrado';
        return;
    }
    while (ob_get_level()) ob_end_clean();
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; media-src 'self'; style-src 'unsafe-inline'; sandbox");
    header('Cache-Control: private, no-store');
    if (!empty($st['is_dir'])) {
        $z = fm_exec(isset($paths) ? ['action' => 'zipdl', 'paths' => $paths, 'tmpdir' => LKM_DATA . '/tmp']
            : ['action' => 'zipdl', 'path' => $path, 'tmpdir' => LKM_DATA . '/tmp']);
        if (empty($z['ok']) || !is_file((string)$z['file'])) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo $z['error'] ?? 'Falha ao compactar a pasta';
            return;
        }
        header('Content-Type: application/zip');
        header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode((string)$z['name']));
        header('Content-Length: ' . filesize($z['file']));
        readfile($z['file']);
        @unlink($z['file']);
        return;
    }
    $mime = (string)($st['mime'] ?? 'application/octet-stream');
    $safe = preg_match('#^(image/(png|jpeg|gif|webp|svg\+xml|x-icon|bmp|avif)|video/(mp4|webm)|audio/(mpeg|ogg|wav)|application/pdf|text/plain)$#', $mime);
    header('Content-Type: ' . ($inline && $safe ? $mime : 'application/octet-stream'));
    header('Content-Disposition: ' . ($inline && $safe ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode((string)$st['name']));
    if (isset($st['size'])) header('Content-Length: ' . (int)$st['size']);
    fm_exec(['action' => 'cat', 'path' => $path], true);
}

/* ------------------------------------------------------------------ ações do sistema (serviços, processos, modo servidor, energia) */

function sys_endpoint(): void
{
    $act = (string)($_GET['sys'] ?? '');
    $valid = hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($_SERVER['HTTP_X_LKM_TOKEN'] ?? ''));
    session_write_close();
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !$valid) {
        json_out(['ok' => false, 'error' => 'Sessão expirada. Recarregue a página.'], 403);
        return;
    }
    if (!in_array($act, ['status', 'service', 'kill', 'mode', 'power'], true)) {
        json_out(['ok' => false, 'error' => 'Ação inválida'], 400);
        return;
    }
    if (!is_file(LKM_LIB . '/sys.php')) {
        json_out(['ok' => false, 'error' => 'Atualize o LK Monitor (sudo lk, opção 33) para usar estes botões.']);
        return;
    }
    if ($act !== 'status' && shell_user() !== 'root') {
        json_out(['ok' => false, 'error' => 'Essa ação precisa do terminal do LK Monitor como root. No servidor: sudo lk › opção 33 › instalar / atualizar e escolha root.']);
        return;
    }
    $in = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($in)) $in = [];
    $in['action'] = $act;
    if ($act !== 'status') {
        $what = ['service' => ($in['op'] ?? '') . ' ' . ($in['name'] ?? ''), 'kill' => ($in['sig'] ?? 'TERM') . ' ' . ($in['pid'] ?? '') . ' ' . ($in['name'] ?? ''),
            'mode' => ($in['to'] ?? '') . ' ' . ($in['when'] ?? ''), 'power' => (string)($in['op'] ?? '')][$act];
        lkm_log('[sistema] ' . $act . ' ' . $what);
    }
    @set_time_limit(0);
    json_out(helper_exec('sys.php', $in, $act === 'mode' ? 620 : 150));
}

/* ------------------------------------------------------------------ App Store */

function apps_endpoint(): void
{
    $act = (string)($_GET['apps'] ?? '');
    $valid = hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($_SERVER['HTTP_X_LKM_TOKEN'] ?? ''));
    session_write_close();
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !$valid) {
        json_out(['ok' => false, 'error' => 'Sessão expirada. Recarregue a página.'], 403);
        return;
    }
    if (!in_array($act, ['list', 'install', 'remove', 'svc'], true)) {
        json_out(['ok' => false, 'error' => 'Ação inválida'], 400);
        return;
    }
    if (!is_file(LKM_LIB . '/apps.php')) {
        json_out(['ok' => false, 'error' => 'Atualize o LK Monitor (sudo lk, opção 33) para usar a App Store.']);
        return;
    }
    if ($act !== 'list' && shell_user() !== 'root') {
        json_out(['ok' => false, 'error' => 'A App Store precisa do terminal do LK Monitor como root. No servidor: sudo lk › opção 33 › instalar / atualizar e escolha root.']);
        return;
    }
    $in = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($in)) $in = [];
    $in['action'] = $act;
    if ($act !== 'list') lkm_log('[apps] ' . $act . ' ' . ($in['id'] ?? '') . ($act === 'svc' ? ' ' . ($in['op'] ?? '') : ''));
    @set_time_limit(0);
    $r = helper_exec('apps.php', $in, $act === 'svc' ? 150 : 60);
    if ($act === 'list' && !empty($r['apps'])) {
        // fotos dos apps: appicons/<id>.svg|png|webp|jpg
        foreach ($r['apps'] as &$a) {
            foreach (['svg', 'png', 'webp', 'jpg'] as $ext) {
                $f = __DIR__ . '/appicons/' . $a['id'] . '.' . $ext;
                if (is_file($f)) { $a['icon'] = 'appicons/' . $a['id'] . '.' . $ext . '?v=' . filemtime($f); break; }
            }
        }
        unset($a);
    }
    json_out($r);
}

/* ------------------------------------------------------------------ sites */

function lkm_log(string $what): void
{
    @file_put_contents(LKM_DATA . '/cmd.log', date('Y-m-d H:i:s') . ' ' . client_ip() . ' ' . str_replace("\n", ' ', $what) . "\n", FILE_APPEND | LOCK_EX);
}

/** Roda um helper de LKM_LIB com JSON no stdin: via sudo como o usuário do painel ou, sem terminal, como lkmonitor (somente leitura). */
function helper_exec(string $file, array $req, int $timeout = 180): array
{
    $u = shell_user();
    $cmd = ['/usr/bin/timeout', '-k', '5', (string)$timeout, LKM_PHP_CLI, '-d', 'display_errors=stderr', LKM_LIB . '/' . $file];
    if ($u !== '') $cmd = array_merge(['/usr/bin/sudo', '-n', '-u', $u], $cmd);
    $proc = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) return ['ok' => false, 'error' => 'Não foi possível executar o helper (proc_open bloqueado?).'];
    fwrite($pipes[0], json_encode($req, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = trim((string)stream_get_contents($pipes[2]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    $d = json_decode((string)$out, true);
    if (is_array($d)) return $d;
    if ($code === 124 || $code === 137) return ['ok' => false, 'error' => 'A operação demorou demais e foi cancelada.'];
    if (str_contains($err, 'password') || str_contains($err, 'sudo')) {
        return ['ok' => false, 'error' => 'Permissão do sudo não configurada para o painel. Rode o instalador de novo.'];
    }
    return ['ok' => false, 'error' => $err !== '' ? $err : 'Resposta inválida do helper.'];
}

function sites_endpoint(): void
{
    $act = (string)($_GET['sites'] ?? '');
    $valid = hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($_SERVER['HTTP_X_LKM_TOKEN'] ?? ''));
    session_write_close();
    if (!$valid) {
        json_out(['ok' => false, 'error' => 'Sessão expirada. Recarregue a página.'], 403);
        return;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        json_out(['ok' => false, 'error' => 'Método inválido'], 405);
        return;
    }
    $read = ['list', 'size', 'logs', 'deploy_info', 'backups', 'backup_cfg'];
    $write = ['toggle', 'create', 'remove', 'deploy', 'backup', 'backup_delete', 'restore', 'backup_cfg_set'];
    if (!in_array($act, array_merge($read, $write), true)) {
        json_out(['ok' => false, 'error' => 'Ação inválida'], 400);
        return;
    }
    $in = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($in)) $in = [];
    $in['action'] = $act;
    if (in_array($act, $write, true)) {
        if (shell_user() !== 'root') {
            json_out(['ok' => false, 'error' => 'Sites em modo somente leitura: o painel não tem acesso root.']);
            return;
        }
        $name = (string)($in['name'] ?? $in['domain'] ?? '');
        $what = match ($act) {
            'toggle' => (!empty($in['on']) ? 'ligar ' : 'desligar ') . $name,
            'create' => 'criar ' . $name . ' (' . ($in['type'] ?? '') . (!empty($in['db']) ? ', com banco' : '') . ')',
            'remove' => 'remover ' . $name . (!empty($in['files']) ? ' e apagar os arquivos' : ''),
            'deploy' => 'deploy ' . $name . ' (' . implode(', ', array_keys(array_filter((array)($in['opts'] ?? [])))) . ')',
            'backup' => 'backup ' . $name,
            'backup_delete' => 'apagar backup ' . $name . '/' . ($in['file'] ?? ''),
            'restore' => 'restaurar ' . $name . ' a partir de ' . ($in['file'] ?? ''),
            'backup_cfg_set' => 'backup automático: ' . (!empty($in['cfg']['enabled']) ? 'ativo às ' . ($in['cfg']['time'] ?? '') . ', manter ' . ($in['cfg']['keep'] ?? '') . ', sites ' . implode(', ', (array)($in['cfg']['sites'] ?? [])) : 'desativado'),
        };
        lkm_log('[sites] ' . $what);
    }
    @set_time_limit(0);
    json_out(helper_exec('sites.php', $in));
}

/* ------------------------------------------------------------------ configurações (alertas e canais) */

function cfg_endpoint(): void
{
    $act = (string)($_GET['cfg'] ?? '');
    $valid = hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($_SERVER['HTTP_X_LKM_TOKEN'] ?? ''));
    session_write_close();
    require_once LKM_LIB . '/alerts.inc.php';
    if ($act === 'get') {
        json_out(['ok' => true, 'settings' => al_public(al_settings()), 'history' => al_history(), 'active' => al_active(), 'host' => (string)gethostname(),
            'services' => array_keys(al_services()), 'sites' => al_sites(), 'curl' => function_exists('curl_init'), 'gowa' => al_gowa_public()]);
        return;
    }
    if ($act === 'wa_status') {
        json_out(['ok' => true, 'gowa' => al_gowa_public(), 'st' => al_gowa_status()]);
        return;
    }
    if (!$valid) {
        json_out(['ok' => false, 'error' => 'Sessão expirada. Recarregue a página.'], 403);
        return;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        json_out(['ok' => false, 'error' => 'Método inválido'], 405);
        return;
    }
    $in = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($in)) $in = [];
    $new = al_merge((array)($in['settings'] ?? []), al_settings());
    if ($act === 'save') {
        if ($err = al_validate($new)) {
            json_out(['ok' => false, 'error' => $err]);
            return;
        }
        al_write('settings.json', $new);
        $on = array_keys(array_filter($new['rules'], fn($r) => $r['on']));
        lkm_log('[config] alertas salvos (regras: ' . (implode(', ', $on) ?: 'nenhuma') . '; telegram ' . ($new['telegram']['on'] ? 'ligado' : 'desligado') . '; whatsapp ' . ($new['whatsapp']['on'] ? 'ligado' : 'desligado') . ')');
        json_out(['ok' => true, 'settings' => al_public($new)]);
        return;
    }
    if ($act === 'test') {
        $ch = (string)($in['channel'] ?? '');
        if (!in_array($ch, ['telegram', 'whatsapp'], true)) {
            json_out(['ok' => false, 'error' => 'Canal inválido'], 400);
            return;
        }
        $new[$ch]['on'] = true;
        if ($err = al_validate($new)) {
            json_out(['ok' => false, 'error' => $err]);
            return;
        }
        lkm_log('[config] envio de teste: ' . $ch);
        $r = al_send('✅ [' . gethostname() . '] Teste do LK Monitor: este canal de alertas está funcionando.', $new, [$ch]);
        json_out($r[$ch] === true ? ['ok' => true] : ['ok' => false, 'error' => (string)$r[$ch]]);
        return;
    }
    if ($act === 'clear') {
        al_write('alerts.json', []);
        lkm_log('[config] histórico de alertas apagado');
        json_out(['ok' => true]);
        return;
    }
    json_out(['ok' => false, 'error' => 'Ação inválida'], 400);
}

/* ------------------------------------------------------------------ bancos de dados */

function db_endpoint(): void
{
    $act = (string)($_GET['db'] ?? '');
    $valid = hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($_SERVER['HTTP_X_LKM_TOKEN'] ?? ''));
    session_write_close();
    if (!$valid) {
        json_out(['ok' => false, 'error' => 'Sessão expirada. Recarregue a página.'], 403);
        return;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        json_out(['ok' => false, 'error' => 'Método inválido'], 405);
        return;
    }
    if (!in_array($act, ['overview', 'create', 'drop', 'password', 'tables', 'columns', 'rows', 'query', 'export'], true)) {
        json_out(['ok' => false, 'error' => 'Ação inválida'], 400);
        return;
    }
    if (shell_user() !== 'root') {
        json_out(['ok' => false, 'error' => 'A aba Bancos precisa de acesso root no painel.', 'noroot' => true]);
        return;
    }
    $in = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($in)) $in = [];
    $in['action'] = $act;
    $src = $in['src']['db'] ?? $in['src']['path'] ?? '';
    match ($act) {
        'create' => lkm_log('[bancos] criar ' . ($in['name'] ?? '')),
        'drop' => lkm_log('[bancos] apagar ' . ($in['name'] ?? '') . ' (com backup)' . (!empty($in['users']) ? ' e usuário(s) ' . implode(', ', (array)$in['users']) : '')),
        'password' => lkm_log('[bancos] trocar senha de ' . ($in['user'] ?? '') . (!empty($in['sites']) ? ' e atualizar .env de ' . implode(', ', (array)$in['sites']) : '')),
        'query' => !empty($in['write']) ? lkm_log('[bancos] alteração em ' . $src . ': ' . mb_substr(preg_replace('/\s+/', ' ', (string)($in['sql'] ?? '')), 0, 400)) : null,
        default => null,
    };
    @set_time_limit(0);
    $r = helper_exec('db.php', $in, $act === 'drop' ? 1900 : ($act === 'export' ? 180 : 90));
    if ($act !== 'export' || empty($r['ok'])) {
        json_out($r);
        return;
    }
    $name = preg_replace('/[^A-Za-z0-9_.-]+/', '_', (string)($in['table'] ?? '') ?: 'consulta') . '-' . date('Ymd-His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Cache-Control: no-store');
    header('X-LKM-More: ' . (!empty($r['more']) ? '1' : '0'));
    $o = fopen('php://output', 'wb');
    fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, $r['columns'], ';', '"', '');
    foreach ($r['rows'] as $row) fputcsv($o, array_map(fn($v) => $v === null ? '' : (string)$v, $row), ';', '"', '');
    fclose($o);
}

/* ------------------------------------------------------------------ tarefas em segundo plano (somente leitura) */

function job_read(string $id): ?array
{
    if (!preg_match('/^\d{8}-\d{6}-[a-f0-9]{6}$/', $id)) return null;
    $J = json_decode(rf(LKM_DATA . "/jobs/$id.json"), true);
    if (!is_array($J)) return null;
    // tarefa que parou sem terminar (servidor reiniciado, processo morto)
    if ((($J['state'] ?? '') === 'running' && !file_exists('/proc/' . (int)($J['pid'] ?? 0)))
        || (($J['state'] ?? '') === 'queued' && time() - (int)($J['created'] ?? 0) > 120)) {
        $J['state'] = 'interrupted';
    }
    unset($J['pid']);
    return $J;
}

function jobs_endpoint(): void
{
    $act = (string)$_GET['jobs'];
    if ($act === 'list') {
        $site = (string)($_GET['site'] ?? '');
        $type = (string)($_GET['type'] ?? '');
        $files = glob(LKM_DATA . '/jobs/*.json') ?: [];
        rsort($files);
        $out = [];
        foreach ($files as $f) {
            $J = job_read(basename($f, '.json'));
            if (!$J || ($site !== '' && ($J['site'] ?? '') !== $site) || ($type !== '' && $J['type'] !== $type)) continue;
            $out[] = $J;
            if (count($out) >= 15) break;
        }
        json_out(['ok' => true, 'jobs' => $out]);
        return;
    }
    $id = (string)($_GET['id'] ?? '');
    $J = job_read($id);
    if (!$J) {
        json_out(['ok' => false, 'error' => 'Tarefa não encontrada'], 404);
        return;
    }
    $f = LKM_DATA . "/jobs/$id.log";
    if ($act === 'log') {
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $J['type'] . '-' . $id . '.log"');
        header('Cache-Control: no-store');
        readfile($f);
        return;
    }
    $size = (int)@filesize($f);
    $from = max(0, min($size, (int)($_GET['from'] ?? 0)));
    $max = 262144;
    $chunk = '';
    if ($from < $size) {
        if ($size - $from > $max) $from = $size - $max;
        $h = @fopen($f, 'rb');
        if ($h) {
            fseek($h, $from);
            $chunk = (string)fread($h, $size - $from);
            fclose($h);
        }
    }
    json_out(['ok' => true, 'job' => $J, 'from' => $from, 'size' => $from + strlen($chunk), 'chunk' => $chunk]);
}

function ca_download(): void
{
    $f = LKM_DATA . '/ca/ca.crt';
    if (!is_readable($f)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Certificado não encontrado. Rode o instalador do LK Monitor (sudo lk, opção 33).';
        return;
    }
    header('Content-Type: application/x-x509-ca-cert');
    header('Content-Disposition: attachment; filename="lkmonitor-ca.crt"');
    header('Cache-Control: no-store');
    header('Content-Length: ' . filesize($f));
    readfile($f);
}

/* ------------------------------------------------------------------ páginas */

function base_css(): string
{
    return <<<'CSS'
:root{--bg:#eef0f3;--bg2:#f7f8fa;--card:#ffffff;--line:#e3e6ea;--line2:#d3d8de;--tx:#333a42;--mut:#6f7884;--dim:#9aa3ad;
--cpu:#2f8cdc;--mem:#8a63d2;--disk:#16a37a;--temp:#f08c00;--rx:#0ea5c6;--tx2:#e0559b;--ok:#20a53a;--warn:#e69a00;--bad:#e8483b;--pri:#20a53a;
--r:3px;color-scheme:light;--mono:ui-monospace,"JetBrains Mono","SFMono-Regular",Menlo,Consolas,monospace}
*{box-sizing:border-box;margin:0;padding:0}
html,body{background:var(--bg);color:var(--tx);font:14px/1.45 system-ui,-apple-system,"Segoe UI",Roboto,Ubuntu,sans-serif}
body{min-height:100vh}
.num{font-family:var(--mono);font-variant-numeric:tabular-nums}
CSS;
}

/** Tags do app instalável (PWA). O service worker só registra em contexto seguro (HTTPS ou localhost). */
function pwa_head(): string
{
    $title = htmlspecialchars(LKM_TITLE);
    return '<meta name="theme-color" content="#272c33"><link rel="manifest" href="manifest.webmanifest">'
        . '<link rel="icon" href="icon.svg" type="image/svg+xml"><link rel="apple-touch-icon" href="icon-192.png">'
        . '<meta name="mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-capable" content="yes">'
        . '<meta name="apple-mobile-web-app-status-bar-style" content="black"><meta name="apple-mobile-web-app-title" content="' . $title . '">'
        . "<script>if('serviceWorker' in navigator&&window.isSecureContext)addEventListener('load',()=>navigator.serviceWorker.register('sw.js').catch(()=>{}))</script>";
}

function page_login(string $err, bool $code = false): void
{
    $title = htmlspecialchars(LKM_TITLE);
    $host = htmlspecialchars((string)gethostname());
    $err = htmlspecialchars($err);
    $css = base_css();
    $pwa = pwa_head();
    $field = $code
        ? '<p style="margin:-10px 0 16px;color:var(--tx)">Digite o código de 6 dígitos do app de autenticação.</p>'
          . '<input class="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="11" placeholder="000000" autofocus required>'
          . '<button type="submit">Confirmar</button>'
          . '<div class="hint">Perdeu o celular? Digite um <b>código de recuperação</b> no lugar (tipo <code>abcde-fghij</code>).<br><a href="?logout=1">Voltar e usar outra senha</a></div>'
        : '<input type="text" name="user" placeholder="Usuário" value="' . htmlspecialchars(LKM_USER) . '" autocomplete="username" autocapitalize="none" spellcheck="false" required style="margin-bottom:10px">'
          . '<input type="password" name="pass" placeholder="Senha" autocomplete="current-password" autofocus required><button type="submit">Entrar</button>';
    echo <<<HTML
<!doctype html><html lang="pt-br"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$title} · Login</title>{$pwa}<style>{$css}
.wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:16px}
form{width:100%;max-width:360px;background:var(--card);border:1px solid var(--line);border-radius:18px;padding:32px 28px;box-shadow:0 30px 80px #0008}
.logo{display:flex;align-items:center;gap:10px;font-weight:700;font-size:18px;margin-bottom:4px}
.logo i{width:30px;height:30px;border-radius:8px;background:url(icon.svg) center/cover;display:inline-block}
p{color:var(--mut);margin-bottom:22px;font-size:13px}
input{width:100%;padding:12px 14px;border-radius:10px;border:1px solid var(--line2);background:var(--bg2);color:var(--tx);font-size:15px;outline:none}
input:focus{border-color:var(--cpu);box-shadow:0 0 0 3px #5b9cff33}
button{width:100%;margin-top:14px;padding:12px;border:0;border-radius:3px;background:var(--pri);color:#fff;font-weight:600;font-size:15px;cursor:pointer}
button:hover{filter:brightness(1.1)}
.err{color:var(--bad);font-size:13px;margin-top:12px;min-height:18px}
.code{font:600 24px var(--mono);letter-spacing:.35em;text-align:center}
.hint{font-size:12.5px;color:var(--mut);margin-top:14px;line-height:1.5}
.hint a{color:var(--cpu)}
</style></head><body><div class="wrap"><form method="post" autocomplete="off">
<div class="logo"><i></i>{$title}</div><p>{$host}</p>
{$field}<div class="err">{$err}</div>
</form></div></body></html>
HTML;
}

function page_dashboard(): void
{
    $title = htmlspecialchars(LKM_TITLE);
    $ver = LKM_VERSION;
    $u = shell_user();
    $pwdone = $_SESSION['pwdone'] ?? null;
    if ($pwdone) unset($_SESSION['pwdone']);
    $cfg = json_encode([
        'token' => $_SESSION['csrf'] ?? '', 'user' => $u, 'pwdone' => $pwdone, 'host' => (string)gethostname(),
        'cwd' => (string)($_SESSION['cwd'] ?? ($u !== '' ? user_home($u) : '/')),
        'https' => (int)LKM_HTTPS_PORT, 'ca' => is_readable(LKM_DATA . '/ca/ca.crt'), 'ver' => LKM_VERSION,
        'term' => (int)LKM_TERM_PORT > 0,
        'ips' => array_values(array_filter(explode(' ', trim(sh('hostname -I'))), fn($ip) => $ip !== '' && !str_contains($ip, ':') && !str_starts_with($ip, '172.17.') && !str_starts_with($ip, '192.168.122.'))),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
    $css = base_css();
    $pwa = pwa_head();
    echo <<<HTML
<!doctype html><html lang="pt-br"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$title}</title>{$pwa}<link rel="stylesheet" href="fm.css?v={$ver}"><link rel="stylesheet" href="app.css?v={$ver}"><style>{$css}
/* layout clássico: menu lateral escuro + topo branco + caixas brancas */
.app{display:grid;grid-template-columns:200px minmax(0,1fr);min-height:100vh}
.side{background:#272c33;color:#aab2bd;position:sticky;top:0;height:100vh;display:flex;flex-direction:column;overflow-y:auto;z-index:6}
.brand{display:flex;align-items:center;gap:10px;padding:14px 18px;background:#1f2329;color:#fff;font-weight:700;font-size:16px;flex:none}
.brand i{width:30px;height:30px;border-radius:7px;background:url(icon.svg) center/cover;box-shadow:0 0 0 1px #3a414b;flex:none}
.brand small{display:block;font-weight:400;font-size:11px;color:#8b95a1;margin-top:-1px}
.nav{display:flex;flex-direction:column;padding:8px 0;flex:1}
.nav a{display:flex;align-items:center;gap:11px;padding:10px 20px;color:#aab2bd;text-decoration:none;border-left:3px solid transparent;font-size:13.5px}
.nav a svg{width:16px;height:16px;flex:none;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
.nav a:hover{background:#30363e;color:#fff}
.nav a.on{background:#20a53a24;border-left-color:var(--ok);color:#fff}
.nav a:focus-visible{outline:2px solid var(--cpu);outline-offset:-2px}
.nav hr{border:0;border-top:1px solid #343a42;margin:8px 16px}
.side-ft{padding:12px 20px;font-size:11.5px;color:#6f7884;border-top:1px solid #343a42;word-break:break-all}
.col{min-width:0;display:flex;flex-direction:column}
.top{background:var(--card);border-bottom:1px solid var(--line);display:flex;align-items:center;gap:14px;padding:0 20px;height:52px;position:sticky;top:0;z-index:5}
.crumb{font-weight:600;font-size:15px;white-space:nowrap}
.host{color:var(--mut);font-size:12.5px;min-width:0;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.live,.btn{flex:none}
.sp{flex:1}
.live{display:flex;align-items:center;gap:6px;font-size:12px;color:var(--ok);background:#e9f6ec;border-radius:3px;padding:3px 9px}
.live b{width:7px;height:7px;border-radius:50%;background:var(--ok);box-shadow:0 0 0 0 #20a53aaa;animation:pl 2s infinite}
.live.off{color:var(--bad);background:#fdecea}.live.off b{background:var(--bad);animation:none}
@keyframes pl{70%{box-shadow:0 0 0 7px #20a53a00}100%{box-shadow:0 0 0 0 #20a53a00}}
.btn{color:var(--tx);text-decoration:none;font-size:12.5px;border:1px solid var(--line2);padding:5px 12px;border-radius:3px;background:#fff;cursor:pointer;font-family:inherit;display:inline-flex;align-items:center;gap:6px;line-height:1.45}
.btn:hover{color:var(--ok);border-color:var(--ok)}
.btn svg{width:15px;height:15px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
main{padding:16px 20px 28px;display:grid;gap:14px;align-content:start;min-width:0}
.g4{display:grid;gap:14px;grid-template-columns:repeat(auto-fit,minmax(230px,1fr))}
.g2{display:grid;gap:14px;grid-template-columns:repeat(auto-fit,minmax(min(100%,520px),1fr));align-items:start}
.g2b{display:grid;gap:14px;grid-template-columns:minmax(0,1.35fr) minmax(0,1fr);align-items:start}
.g3{display:grid;gap:14px;grid-template-columns:repeat(auto-fit,minmax(min(100%,360px),1fr));align-items:start}
.card{background:var(--card);border:1px solid var(--line);border-radius:3px;padding:0 16px 16px;min-width:0}
.ct{display:flex;align-items:center;justify-content:space-between;gap:10px;margin:0 -16px 14px;padding:0 16px;min-height:44px;border-bottom:1px solid var(--line)}
.ct h2{font-size:14px;font-weight:600;color:var(--tx)}
.ct span{font-size:12px;color:var(--mut)}
.ct a.lnk{font-size:12.5px;color:var(--cpu);text-decoration:none}.ct a.lnk:hover{text-decoration:underline}
.gauges{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:8px;padding:4px 0 2px}
.gg{display:flex;flex-direction:column;align-items:center;gap:5px;text-align:center;min-width:0}
.gg .lbl{font-size:12.5px;color:var(--mut)}.gg .lbl small{color:var(--dim)}
.gg .sub{font-size:12.5px;color:var(--tx);white-space:nowrap}
.gg .sub2{font-size:11.5px;color:var(--mut);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%}
.gg .ring{width:100px;height:100px}
.ov4{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));padding:0}
.ov{padding:16px;border-right:1px solid var(--line);display:flex;flex-direction:column;gap:2px;text-decoration:none;color:var(--tx)}
.ov:last-child{border-right:0}.ov:hover{background:var(--bg2)}
.ov span{color:var(--mut);font-size:12.5px}
.ov b{font-size:26px;font-weight:500;color:var(--cpu);font-variant-numeric:tabular-nums;line-height:1.3}
.soft{display:grid;grid-template-columns:repeat(auto-fill,minmax(100px,1fr));margin:-14px -16px -16px}
.sw2{display:flex;flex-direction:column;align-items:center;gap:7px;padding:14px 6px 12px;border-right:1px solid var(--line);border-bottom:1px solid var(--line);text-decoration:none;color:var(--tx);font-size:12.5px;position:relative;text-align:center}
.sw2:hover{background:var(--bg2)}
.sw2 img,.sw2 .ini{width:40px;height:40px;border-radius:7px;box-shadow:0 0 0 1px #0000000f}
.sw2 .ini{display:flex;align-items:center;justify-content:center;font-weight:700;color:#fff;font-size:15px}
.sw2 .dot{position:absolute;top:9px;right:11px;width:7px;height:7px;border-radius:50%;background:var(--ok)}
.sw2 .dot.off{background:var(--bad)}
.soft .empty{padding:20px 16px}
.gauge{display:flex;align-items:center;gap:18px}
.ring{position:relative;width:92px;height:92px;flex:none}
.ring svg{transform:rotate(-90deg);width:100%;height:100%}
.ring .bgc{stroke:#eceef1}
.ring .fg{transition:stroke-dashoffset .6s ease,stroke .3s}
.ring div{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:20px;font-weight:600;font-family:inherit}
.gi .big{font-size:22px;font-weight:700}
.gi .big.sm{font-size:17px;white-space:nowrap}
.gi .big.na{font-family:inherit;font-size:16px;color:var(--mut);font-weight:500}
.gi .sub{color:var(--mut);font-size:12.5px;margin-top:2px}
.chart{width:100%;height:190px;display:block;touch-action:pan-y}
.cc{position:relative}
.cmsg{position:absolute;left:18px;right:18px;top:60px;bottom:18px;display:none;align-items:center;justify-content:center;text-align:center;color:var(--dim);font-size:13px;pointer-events:none}
.cmsg.on{display:flex}
.ctip{position:absolute;z-index:4;pointer-events:none;background:#fffffff5;border:1px solid var(--line2);border-radius:3px;padding:7px 10px;font-size:12px;line-height:1.5;box-shadow:0 6px 20px #00000026;white-space:nowrap;display:none}
.ctip b{display:block;color:var(--mut);font-weight:500;font-size:11.5px;margin-bottom:2px}
.ctip i{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:6px}
.ctip small{color:var(--dim)}
.rbar{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.rbar h2{font-size:14px;font-weight:600;color:var(--tx)}
.rbar span{font-size:12px;color:var(--mut)}
.leg{display:flex;gap:16px;font-size:12.5px;color:var(--mut)}
.leg i{display:inline-block;width:10px;height:3px;border-radius:2px;margin-right:6px;vertical-align:middle}
.leg b{color:var(--tx);font-weight:600}
.cores{display:grid;grid-template-columns:repeat(auto-fill,minmax(64px,1fr));gap:8px}
#v-painel .cores{margin-top:14px}
.core{background:var(--bg2);border:1px solid var(--line);border-radius:3px;padding:8px;font-size:11px;color:var(--mut)}
.core .v{color:var(--tx);font-size:13px;font-weight:600;margin:2px 0 6px}
.bar{height:6px;background:#eceef1;border-radius:3px;overflow:hidden}
.bar i{display:block;height:100%;border-radius:3px;transition:width .5s ease,background .3s}
.row{margin-bottom:14px}.row:last-child{margin-bottom:0}
.rl{display:flex;justify-content:space-between;gap:10px;font-size:13px;margin-bottom:6px}
.rl small{color:var(--mut)}
table{width:100%;border-collapse:collapse;font-size:13px}
th{text-align:left;color:var(--mut);font-weight:500;font-size:12.5px;padding:8px;background:var(--bg2);border-bottom:1px solid var(--line)}
td{padding:8px;border-top:1px solid var(--line);white-space:nowrap}
tbody tr:first-child td{border-top:0}
tbody tr:hover td{background:#fafbfc}
td.n{max-width:180px;overflow:hidden;text-overflow:ellipsis}
th.r,td.r{text-align:right}
.tabs{display:flex;gap:2px;background:var(--bg2);border:1px solid var(--line);border-radius:3px;padding:2px}
.tabs button{background:none;border:0;color:var(--mut);font-size:12px;padding:4px 10px;border-radius:2px;cursor:pointer}
.tabs button:hover{color:var(--tx)}
.tabs button.on{background:var(--pri);color:#fff}
.svcs{display:flex;flex-wrap:wrap;gap:8px}
.svc{display:flex;align-items:center;gap:8px;background:var(--bg2);border:1px solid var(--line);border-radius:3px;padding:6px 12px;font-size:13px}
.svc b{width:8px;height:8px;border-radius:50%;background:var(--bad)}
.svc.on b{background:var(--ok)}
.svc small{color:var(--dim)}
.svc[data-svc]{cursor:pointer;font-family:inherit;color:var(--tx);transition:border-color .15s}
.svc[data-svc]:hover{border-color:var(--ok)}
.svc .dd{width:12px;height:12px;stroke:var(--dim);fill:none;stroke-width:2.2;stroke-linecap:round;stroke-linejoin:round;margin-right:-3px}
th.k,td.k{width:30px;padding-left:0;padding-right:0;text-align:center}
.pk{width:24px;height:24px;display:inline-flex;align-items:center;justify-content:center;border-radius:3px;border:1px solid transparent;background:none;color:var(--dim);cursor:pointer;opacity:.6}
.pk svg{width:14px;height:14px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round}
tr:hover .pk,.pk:focus-visible{opacity:1}
.pk:hover{color:var(--bad);border-color:#f3c0bb;background:#fdecea;opacity:1}
@media (hover:none){.pk{opacity:1}}
dl{display:grid;grid-template-columns:auto 1fr;gap:9px 16px;font-size:13px}
dt{color:var(--mut)}dd{text-align:right;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.kv{display:flex;justify-content:space-between;padding:7px 0;border-top:1px solid var(--line);font-size:13px}
.kv:first-child{border-top:0}
.kv span:first-child{color:var(--mut)}
.empty{color:var(--dim);font-size:13px}
.scroll{overflow-x:auto}
#v-painel .scroll{margin:-14px -16px -16px}
#v-painel .scroll th:first-child,#v-painel .scroll td:first-child{padding-left:16px}
#v-painel .scroll th:last-child,#v-painel .scroll td:last-child{padding-right:16px}
.term{background:#05070c;border:1px solid var(--line);border-radius:3px;padding:12px 14px;height:calc(100vh - 330px);min-height:280px;overflow:auto;font:13px/1.5 var(--mono);white-space:pre-wrap;word-break:break-word;color:#cfd7e6}
.term .ln{color:#7ee787}.term .ln b{color:#5b9cff;font-weight:600}.term .er{color:#ff7b72}.term .dm{color:var(--dim)}
.tin{display:flex;align-items:center;gap:8px;margin-top:10px;background:#05070c;border:1px solid var(--line2);border-radius:3px;padding:0 12px}
.tin:focus-within{border-color:#5b9cff;box-shadow:0 0 0 3px #5b9cff22}
.tin .pr{font:13px var(--mono);color:#7ee787;white-space:nowrap;max-width:45%;overflow:hidden;text-overflow:ellipsis}
.tin input{flex:1;min-width:0;background:none;border:0;outline:none;color:var(--tx);font:13px var(--mono);padding:11px 0}
.tin button{background:var(--line2);border:0;color:var(--tx);border-radius:3px;padding:5px 12px;font-size:12px;cursor:pointer}
.tin button:disabled{opacity:.5;cursor:wait}
.chips{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px}
.chips button{background:var(--bg2);border:1px solid var(--line);color:var(--mut);font:12px var(--mono);padding:4px 10px;border-radius:3px;cursor:pointer}
.chips button:hover{color:var(--tx);border-color:var(--line2)}
.hint{color:var(--mut);font-size:12px;margin-top:8px}
.ubadge{font-size:12px;padding:3px 9px;border-radius:3px;background:var(--bg2);border:1px solid var(--line)}
.ubadge.root{color:#b67400;border-color:#f5cf8c;background:#fff6e5}
.view{display:none}.view.on{display:grid}
main.wide{padding:16px 20px}
@media (max-width:1100px){.btn .lbl{display:none}.gauges{grid-template-columns:repeat(3,minmax(0,1fr));row-gap:16px}.g2b{grid-template-columns:minmax(0,1fr)}}
@media (max-width:760px){
.app{grid-template-columns:minmax(0,1fr)}
.side{position:sticky;top:0;height:auto;flex-direction:row;align-items:stretch;overflow:visible}
.brand{padding:10px 12px;font-size:14px}.brand small{display:none}.brand i{width:26px;height:26px}
.nav{flex-direction:row;overflow-x:auto;padding:0;scrollbar-width:none}.nav::-webkit-scrollbar{display:none}
.nav a{border-left:0;border-bottom:3px solid transparent;padding:12px 11px 9px;white-space:nowrap}
.nav a.on{border-bottom-color:var(--ok)}
.nav a span{display:none}.nav a.on span{display:inline}
.nav hr,.side-ft{display:none}
.top{position:static;height:auto;padding:10px 16px;flex-wrap:wrap;row-gap:4px}
.host{flex-basis:100%;order:3}
main{padding:12px 16px 24px}main.wide{padding:8px}
.gauges{grid-template-columns:repeat(2,minmax(0,1fr))}
.ov4{grid-template-columns:repeat(2,minmax(0,1fr))}.ov:nth-child(2){border-right:0}.ov:nth-child(-n+2){border-bottom:1px solid var(--line)}
.live span{display:none}
}
</style></head><body>
<div class="app">
<aside class="side"><div class="brand"><i></i><div>{$title}<small>v{$ver}</small></div></div>
<nav class="nav" id="nav"><a href="#painel" data-v="painel"><svg viewBox="0 0 24 24"><path d="M3 12l9-8 9 8"/><path d="M5 10v10h14V10"/></svg><span>Painel</span></a><a href="#sites" data-v="sites"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></svg><span>Sites</span></a><a href="#bancos" data-v="bancos"><svg viewBox="0 0 24 24"><ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/></svg><span>Bancos</span></a><a href="#arquivos" data-v="arquivos"><svg viewBox="0 0 24 24"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg><span>Arquivos</span></a><a href="#terminal" data-v="terminal"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="m7 9 3 3-3 3M13 15h4"/></svg><span>Terminal</span></a><a href="#internet" data-v="internet"><svg viewBox="0 0 24 24"><path d="M17.5 19a4.5 4.5 0 1 0-1.4-8.8A6 6 0 0 0 4.6 12.5 3.5 3.5 0 0 0 6 19z"/></svg><span>Internet</span></a><a href="#apps" data-v="apps"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg><span>App Store</span></a><hr><a href="#config" data-v="config"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1"/></svg><span>Config</span></a><a href="?logout=1"><svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg><span>Sair</span></a></nav>
<div class="side-ft" id="sideFt"></div></aside>
<div class="col">
<header class="top"><div class="crumb" id="crumb">Painel</div><div class="host" id="host">carregando...</div><div class="live" id="live"><b></b><span id="upd">conectando</span></div><button class="btn" id="pwaBtn" type="button" title="Instalar no celular"><svg viewBox="0 0 24 24"><rect x="6" y="2" width="12" height="20" rx="2.5"/><path d="M11 18h2"/></svg><span class="lbl">Instalar no celular</span></button></header>
<main class="view" id="v-painel">
<div class="card"><div class="ct"><h2>Status</h2><span id="batt"></span></div>
 <div class="gauges">
  <div class="gg"><span class="lbl">Carga</span><div class="ring" id="rLoad"></div><span class="sub" id="loadTxt">–</span><span class="sub2 num" id="cpuLoad">–</span></div>
  <div class="gg"><span class="lbl">CPU <small id="cpuMhz"></small></span><div class="ring" id="rCpu"></div><span class="sub" id="cpuCount"></span><span class="sub2">load 1 · 5 · 15 min</span></div>
  <div class="gg"><span class="lbl">Memória</span><div class="ring" id="rMem"></div><span class="sub"><span id="memUsed">–</span> / <span id="memTot"></span></span><span class="sub2" id="memAvail"></span><span id="swap" hidden></span></div>
  <div class="gg"><span class="lbl">Disco / <small id="diskFs"></small></span><div class="ring" id="rDisk"></div><span class="sub" id="diskUsed">–</span><span class="sub2" id="diskFree"></span></div>
  <div class="gg"><span class="lbl">Temperatura</span><div class="ring" id="rTemp"></div><span class="sub" id="tempV">–</span><span class="sub2" id="uptime"></span></div>
 </div></div>
<div class="card ov4"><a class="ov" href="#sites"><span>Sites</span><b id="ovSites">–</b></a><a class="ov" href="#bancos"><span>Bancos de dados</span><b id="ovDbs">–</b></a><a class="ov" href="#apps"><span>Apps instalados</span><b id="ovApps">–</b></a><a class="ov" href="#painel" id="ovConnA"><span>Conexões TCP</span><b id="ovConn">–</b></a></div>
<section class="g2b">
  <div class="card"><div class="ct"><h2>Software</h2><a class="lnk" href="#apps">App Store ›</a></div><div class="soft" id="soft"><div class="empty">Carregando…</div></div></div>
  <div class="card"><div class="ct"><h2>Serviços</h2><span id="svcInfo"></span></div><div class="svcs" id="svcs"></div></div>
</section>
<section class="rbar"><h2>Gráficos</h2><div class="tabs" id="rng"><button class="on" data-r="live">Ao vivo</button><button data-r="1h">1 h</button><button data-r="24h">24 h</button><button data-r="7d">7 dias</button></div><span id="rngInfo"></span></section>
<section class="g3">
  <div class="card cc"><div class="ct"><h2>CPU e memória</h2><div class="leg"><span><i style="background:var(--cpu)"></i>CPU <b class="num" id="lCpu">0%</b></span><span><i style="background:var(--mem)"></i>RAM <b class="num" id="lMem">0%</b></span></div></div><canvas class="chart" id="cCpu"></canvas><div class="cmsg" id="mCpu"></div></div>
  <div class="card cc"><div class="ct"><h2>Tráfego</h2><div class="leg"><span><i style="background:var(--rx)"></i>↓ <b class="num" id="lRx">0</b></span><span><i style="background:var(--tx2)"></i>↑ <b class="num" id="lTx">0</b></span></div></div><canvas class="chart" id="cNet"></canvas><div class="cmsg" id="mNet"></div></div>
  <div class="card cc"><div class="ct"><h2>Temperatura</h2><div class="leg"><span><i style="background:var(--temp)"></i>CPU <b class="num" id="lTemp">–</b></span></div></div><canvas class="chart" id="cTemp"></canvas><div class="cmsg" id="mTemp"></div></div>
</section>
<section class="g2b">
  <div class="card"><div class="ct"><h2>Processos</h2><div class="tabs" id="ptabs"><button class="on" data-t="cpu">CPU</button><button data-t="mem">Memória</button></div></div>
    <div class="scroll"><table><thead><tr><th>Processo</th><th>Usuário</th><th class="r">CPU</th><th class="r">Memória</th><th class="k"></th></tr></thead><tbody id="procs"></tbody></table></div></div>
  <div class="card"><div class="ct"><h2>Sistema</h2><span id="sysMode"></span></div><dl id="info"></dl><div class="cores" id="cores"></div><span id="coreInfo" hidden></span><div class="sysact" id="sysAct"></div></div>
</section>
<section class="g3">
  <div class="card"><div class="ct"><h2>Discos</h2></div><div id="disks"></div></div>
  <div class="card"><div class="ct"><h2>Interfaces de rede</h2><span id="conn"></span></div><div class="scroll"><table><thead><tr><th>Interface</th><th class="r">↓ agora</th><th class="r">↑ agora</th><th class="r">↓ total</th><th class="r">↑ total</th></tr></thead><tbody id="ifs"></tbody></table></div></div>
  <div class="card"><div class="ct"><h2>Sensores</h2></div><div id="sensors"></div></div>
</section>
</main>
<main class="view wide" id="v-terminal">
<div id="xt"></div>
<section id="tSimple"><div class="card" id="term"><div class="ct"><h2>Terminal <small class="tmode">modo simples</small></h2><span class="ubadge" id="tUser"></span><button class="abtn sm" id="tFull" type="button" hidden>Terminal completo</button></div>
  <div class="chips" id="chips"></div>
  <div class="term" id="tOut"></div>
  <form class="tin" id="tForm"><span class="pr" id="tPr"></span><input id="tIn" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="digite um comando e Enter"><button id="tBtn" type="submit">Executar</button></form>
  <div class="hint">Comandos interativos (nano, top, htop, o menu do lk) não funcionam aqui. Use as versões diretas: <code>top -bn1 | head -20</code>, <code>lk status</code>. ↑/↓ histórico · Ctrl+L limpa · limite de 30s por comando.</div>
</div></section>
</main>
<main class="view wide" id="v-arquivos"><div id="fm"></div></main>
<main class="view" id="v-sites"><div id="sites"></div></main>
<main class="view" id="v-bancos"><div id="bancos"></div></main>
<main class="view" id="v-internet"><div id="internet"></div></main>
<main class="view" id="v-apps"><div id="apps"></div></main>
<main class="view" id="v-config"><div id="config"></div></main>
</div></div>
<script>
const N=90,H={t:[],cpu:[],mem:[],rx:[],tx:[],temp:[]};let D=null,tab='cpu',timer=null,busy=false,RNG='live',HD=null,htimer=null;
const \$=id=>document.getElementById(id);
const esc=s=>String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
function fb(b,d=1){const u=['B','KB','MB','GB','TB'];let i=0;b=+b||0;while(b>=1024&&i<u.length-1){b/=1024;i++}return(i?b.toFixed(b>=100?0:d):Math.round(b))+' '+u[i]}
const fr=b=>fb(b)+'/s';
function fup(s){const d=Math.floor(s/86400),h=Math.floor(s%86400/3600),m=Math.floor(s%3600/60);return(d?d+'d ':'')+(d||h?h+'h ':'')+m+'min'}
function lvl(p,w=75,b=90){return p>=b?'var(--bad)':p>=w?'var(--warn)':null}
function push(a,v){a.push(v);if(a.length>N)a.shift()}
function ring(id,pct,color,label){
  const el=\$(id),r=40,c=2*Math.PI*r,p=Math.max(0,Math.min(100,pct||0));
  if(!el.firstChild)el.innerHTML='<svg width="92" height="92" viewBox="0 0 92 92"><circle class="bgc" cx="46" cy="46" r="'+r+'" fill="none" stroke-width="8"/><circle class="fg" cx="46" cy="46" r="'+r+'" fill="none" stroke-width="8" stroke-linecap="round" stroke-dasharray="'+c+'" stroke-dashoffset="'+c+'"/></svg><div class="num"></div>';
  if(/--(cpu|mem|disk)/.test(color))color='var(--ok)';
  const fg=el.querySelector('.fg');fg.style.strokeDashoffset=c*(1-p/100);fg.style.stroke=color;
  el.querySelector('div').textContent=label;
}
/* gráficos: eixo de tempo, faixa mínimo–máximo e dica ao passar o mouse */
const COL={cpu:'#2f8cdc',mem:'#8a63d2',rx:'#0ea5c6',tx:'#e0559b',temp:'#f08c00'};
const pct=v=>Math.round(v)+'%',degc=v=>(Math.round(v*10)/10)+' °C',p2=n=>String(n).padStart(2,'0');
const hm=t=>{const d=new Date(t);return p2(d.getHours())+':'+p2(d.getMinutes())};
const hms=t=>hm(t)+':'+p2(new Date(t).getSeconds());
const dm=t=>{const d=new Date(t);return p2(d.getDate())+'/'+p2(d.getMonth()+1)};
const TICKS=[15e3,3e4,6e4,12e4,3e5,6e5,9e5,18e5,36e5,72e5,108e5,216e5,432e5,864e5];
function chart(cv,o){
  const dpr=window.devicePixelRatio||1,w=cv.clientWidth,h=cv.clientHeight;if(!w)return;
  if(cv.width!==Math.round(w*dpr)||cv.height!==Math.round(h*dpr)){cv.width=Math.round(w*dpr);cv.height=Math.round(h*dpr)}
  const x=cv.getContext('2d');x.setTransform(dpr,0,0,dpr,0,0);x.clearRect(0,0,w,h);
  const top=8,bot=h-18;let m=o.max;
  if(m==null){m=1024;o.s.forEach(s=>(s.hi||s.d).forEach(v=>{if(v!=null&&v>m)m=v}));m*=1.2}
  const span=Math.max(1,o.t1-o.t0),X=t=>(t-o.t0)/span*w,Y=v=>bot-(Math.min(Math.max(v,0),m)/m)*(bot-top);
  o.X=X;
  x.strokeStyle='#eceef1';x.lineWidth=1;x.fillStyle='#9aa3ad';x.font='11px ui-monospace,monospace';
  for(let i=0;i<=3;i++){const y=Math.round(top+(bot-top)*i/3)+.5;x.beginPath();x.moveTo(0,y);x.lineTo(w,y);x.stroke();if(i<3)x.fillText(o.fmt(m*(1-i/3)),4,y+13)}
  const maxT=Math.max(2,Math.floor(w/70)),st=TICKS.find(v=>span/v<=maxT)||864e5,tz=new Date().getTimezoneOffset()*6e4;
  x.textAlign='center';
  for(let t=Math.ceil((o.t0-tz)/st)*st+tz;t<=o.t1;t+=st){const px=X(t);if(px<18||px>w-18)continue;x.fillText(st>=864e5?dm(t):st<6e4?hms(t):hm(t),px,h-4)}
  x.textAlign='left';
  o.s.forEach(s=>{
    const segs=[];let cur=[];
    s.d.forEach((v,i)=>{if(v==null||(i&&o.t[i]-o.t[i-1]>o.gap)){if(cur.length)segs.push(cur);cur=[]}if(v!=null)cur.push(i)});
    if(cur.length)segs.push(cur);
    segs.forEach(g=>{
      if(o.band&&s.lo){
        x.beginPath();g.forEach((i,k)=>{const a=X(o.t[i]),b=Y(s.hi[i]);k?x.lineTo(a,b):x.moveTo(a,b)});
        for(let k=g.length-1;k>=0;k--)x.lineTo(X(o.t[g[k]]),Y(s.lo[g[k]]));
        x.closePath();x.fillStyle=s.c+'2e';x.fill();
      }else if(g.length>1){
        x.beginPath();g.forEach((i,k)=>{const a=X(o.t[i]),b=Y(s.d[i]);k?x.lineTo(a,b):x.moveTo(a,b)});
        const gr=x.createLinearGradient(0,top,0,bot);gr.addColorStop(0,s.c+'55');gr.addColorStop(1,s.c+'00');
        x.lineTo(X(o.t[g[g.length-1]]),bot);x.lineTo(X(o.t[g[0]]),bot);x.closePath();x.fillStyle=gr;x.fill();
      }
      if(g.length===1){x.beginPath();x.arc(X(o.t[g[0]]),Y(s.d[g[0]]),2,0,7);x.fillStyle=s.c;x.fill();return}
      x.beginPath();g.forEach((i,k)=>{const a=X(o.t[i]),b=Y(s.d[i]);k?x.lineTo(a,b):x.moveTo(a,b)});
      x.strokeStyle=s.c;x.lineWidth=2;x.lineJoin='round';x.stroke();
    });
  });
  if(o.hi!=null){
    const px=Math.round(X(o.t[o.hi]))+.5;x.strokeStyle='#9aa3ad';x.lineWidth=1;x.setLineDash([3,3]);x.beginPath();x.moveTo(px,top);x.lineTo(px,bot);x.stroke();x.setLineDash([]);
    o.s.forEach(s=>{const v=s.d[o.hi];if(v==null)return;x.beginPath();x.arc(px,Y(v),4,0,7);x.fillStyle=s.c;x.fill();x.strokeStyle='#ffffff';x.lineWidth=2;x.stroke()});
  }
}
function tipLabel(o,t){
  if(!o.step)return hms(t);
  const a=t-o.step/2,b=t+o.step/2;
  if(o.step<=6e4)return(o.t1-o.t0>864e5?dm(a)+' ':'')+hm(a);
  return(o.t1-o.t0>864e5?dm(a)+' ':'')+hm(a)+' – '+hm(b);
}
function paint(cv,o){
  cv._o=o;o.hi=null;
  if(cv._hx!=null&&o.t.length){
    const w=cv.clientWidth,span=Math.max(1,o.t1-o.t0);let best=-1,bd=1e9;
    o.t.forEach((t,i)=>{if(o.s.every(s=>s.d[i]==null))return;const d=Math.abs((t-o.t0)/span*w-cv._hx);if(d<bd){bd=d;best=i}});
    if(best>=0&&bd<=48)o.hi=best;
  }
  chart(cv,o);
  const tip=cv._tip;if(!tip)return;
  if(o.hi==null){tip.style.display='none';return}
  const i=o.hi,t=o.t[i];
  tip.innerHTML='<b>'+tipLabel(o,t)+'</b>'+o.s.map(s=>{const v=s.d[i];if(v==null)return'';return'<div><i style="background:'+s.c+'"></i>'+esc(s.n)+' <span class="num">'+o.fmt(v)+'</span>'+(o.band&&s.lo&&s.lo[i]!=null?' <small class="num">('+o.fmt(s.lo[i])+' a '+o.fmt(s.hi[i])+')</small>':'')+'</div>'}).join('');
  tip.style.display='block';
  const px=cv.offsetLeft+o.X(t),tw=tip.offsetWidth,cw=cv.parentElement.clientWidth;
  tip.style.left=Math.max(6,px+14+tw>cw-6?px-14-tw:px+14)+'px';tip.style.top=(cv.offsetTop+6)+'px';
}
function tipInit(cv){
  const tip=document.createElement('div');tip.className='ctip';cv.parentElement.appendChild(tip);cv._tip=tip;
  const mv=e=>{const r=cv.getBoundingClientRect();cv._hx=e.clientX-r.left;if(cv._o)paint(cv,cv._o)};
  cv.addEventListener('pointermove',mv);cv.addEventListener('pointerdown',mv);
  cv.addEventListener('pointerleave',()=>{cv._hx=null;if(cv._o)paint(cv,cv._o)});
}
['cCpu','cNet','cTemp'].forEach(id=>tipInit(\$(id)));
function cmsg(id,t){const m=\$(id);m.textContent=t||'';m.classList.toggle('on',!!t)}
const tmax=a=>{let m=0;a.forEach(v=>{if(v!=null&&v>m)m=v});return Math.min(150,Math.max(80,Math.ceil((m+10)/10)*10))};
function draw(){
  if(RNG==='live'){
    if(!D)return;
    const t1=H.t.length?H.t[H.t.length-1]:Date.now(),b={t:H.t,t0:t1-(N-1)*2000,t1,gap:1e4};
    paint(\$('cCpu'),Object.assign({s:[{n:'RAM',c:COL.mem,d:H.mem},{n:'CPU',c:COL.cpu,d:H.cpu}],max:100,fmt:pct},b));
    paint(\$('cNet'),Object.assign({s:[{n:'Download',c:COL.rx,d:H.rx},{n:'Upload',c:COL.tx,d:H.tx}],max:null,fmt:fr},b));
    paint(\$('cTemp'),Object.assign({s:[{n:'CPU',c:COL.temp,d:H.temp}],max:tmax(H.temp),fmt:degc},b));
    cmsg('mCpu');cmsg('mNet');cmsg('mTemp',D.temp.cpu==null?'Nenhum sensor de temperatura encontrado':'');
    return;
  }
  const d=HD;
  if(!d){const e={t:[],t0:Date.now()-36e5,t1:Date.now(),s:[],fmt:pct,max:100};['cCpu','cNet','cTemp'].forEach(id=>paint(\$(id),e));return}
  const S=d.s,b={t:d.t.map(v=>v*1000),t0:d.from*1000,t1:d.to*1000,gap:d.step*2500,step:d.step*1000,band:d.range!=='1h'};
  const ser=(k,n,c)=>({n,c,d:S[k].a,lo:S[k].lo,hi:S[k].hi});
  paint(\$('cCpu'),Object.assign({s:[ser('mem','RAM',COL.mem),ser('cpu','CPU',COL.cpu)],max:100,fmt:pct},b));
  paint(\$('cNet'),Object.assign({s:[ser('rx','Download',COL.rx),ser('tx','Upload',COL.tx)],max:null,fmt:fr},b));
  paint(\$('cTemp'),Object.assign({s:[ser('temp','CPU',COL.temp)],max:tmax(S.temp.hi),fmt:degc},b));
  const none=d.t.length?'':'Sem dados neste período';
  cmsg('mCpu',none);cmsg('mNet',none);cmsg('mTemp',none||(S.temp.a.every(v=>v==null)?'Nenhum sensor de temperatura encontrado':''));
}
async function loadHist(){
  const r=RNG;if(r==='live')return;
  try{
    const res=await fetch('?hist='+r,{cache:'no-store',credentials:'same-origin'});
    if(res.status===401){location.reload();return}
    const d=await res.json();if(RNG!==r)return;
    if(!d.ok){
      HD=null;draw();
      const m=d.error==='sqlite'?'Histórico indisponível: falta a extensão SQLite do PHP. Rode o instalador do LK Monitor de novo (sudo lk, opção 33).':'Ainda não há histórico. O coletor grava um ponto por minuto: volte daqui a pouco.';
      ['mCpu','mNet','mTemp'].forEach(id=>cmsg(id,m));\$('rngInfo').textContent='';return;
    }
    HD=d;draw();
    \$('rngInfo').textContent=(d.step>=1800?'média de 30 min':d.step>=300?'média de 5 min':'um ponto por minuto')+(d.range!=='1h'?' · faixa = mínimo e máximo':'')+(d.last?' · última coleta '+hm(d.last*1000):'');
  }catch(e){\$('rngInfo').textContent='sem conexão'}
}
function setRange(r){
  if(!['live','1h','24h','7d'].includes(r))r='live';
  RNG=r;HD=null;clearInterval(htimer);htimer=null;\$('rngInfo').textContent=r==='live'?'atualiza a cada 2 s':'carregando…';
  document.querySelectorAll('#rng button').forEach(b=>b.classList.toggle('on',b.dataset.r===r));
  try{localStorage.setItem('lkm.range',r)}catch(e){}
  draw();
  if(r!=='live'&&view==='painel'&&!document.hidden){loadHist();htimer=setInterval(loadHist,60000)}
}
\$('rng').onclick=e=>{const b=e.target.closest('button');if(b)setRange(b.dataset.r)};
function procs(){
  if(!D)return;
  \$('procs').innerHTML=D.procs[tab].map(p=>'<tr><td class="n" title="PID '+p.pid+'">'+esc(p.name)+'</td><td>'+esc(p.user)+'</td><td class="r num">'+p.cpu.toFixed(1)+'%</td><td class="r num">'+fb(p.mem)+'</td><td class="k"><button type="button" class="pk" data-pid="'+p.pid+'" data-name="'+esc(p.name)+'" data-user="'+esc(p.user)+'" title="Encerrar '+esc(p.name)+' (PID '+p.pid+')"><svg viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg></button></td></tr>').join('');
}
function render(d){
  D=d;const c=d.cpu,m=d.mem,memPct=m.total?m.used/m.total*100:0;
  push(H.t,Date.now());push(H.cpu,c.total);push(H.mem,memPct);push(H.rx,d.net.rx);push(H.tx,d.net.tx);push(H.temp,d.temp.cpu);
  \$('host').textContent=d.host+' · '+(d.ips.join(', ')||'sem IP')+' · '+d.os;
  ring('rCpu',c.total,lvl(c.total)||'var(--cpu)',Math.round(c.total)+'%');
  \$('cpuLoad').textContent=c.load.map(v=>v.toFixed(2)).join(' · ');
  \$('cpuCount').textContent=c.count+' núcleos';
  const lp=Math.min(100,(c.load[0]||0)/c.count*100);ring('rLoad',lp,lp>=90?'var(--bad)':lp>=70?'var(--warn)':'var(--ok)',Math.round(lp)+'%');\$('loadTxt').textContent=lp<50?'Rodando liso':lp<80?'Carga média':'Sobrecarregado';
  \$('ovConn').textContent=d.net.conn;\$('sideFt').innerHTML=esc(d.host)+'<br>'+esc(location.host);
  \$('cpuMhz').textContent=c.mhz?(c.mhz/1000).toFixed(2)+' GHz':'';
  ring('rMem',memPct,lvl(memPct,80,92)||'var(--mem)',Math.round(memPct)+'%');
  \$('memUsed').textContent=fb(m.used);\$('memTot').textContent=fb(m.total);
  \$('memAvail').textContent=fb(m.avail)+' livre · '+fb(m.cached)+' cache';
  \$('swap').textContent=m.swap_total?'swap '+fb(m.swap_used)+' de '+fb(m.swap_total):'sem swap';
  const root=d.disks.find(x=>x.mnt==='/')||d.disks[0];
  if(root){const p=root.used/root.total*100;ring('rDisk',p,lvl(p,80,92)||'var(--disk)',Math.round(p)+'%');\$('diskUsed').textContent=fb(root.used);\$('diskFree').textContent=fb(root.total-root.used)+' livre de '+fb(root.total);\$('diskFs').textContent=root.fs}
  const t=d.temp.cpu;
  if(t!=null){ring('rTemp',t,t>=85?'var(--bad)':t>=70?'var(--warn)':'var(--temp)',Math.round(t)+'°');\$('tempV').textContent=t.toFixed(1)+' °C';\$('tempV').classList.remove('na')}
  else{ring('rTemp',0,'var(--temp)','–');\$('tempV').textContent='sem sensor';\$('tempV').classList.add('na')}
  \$('uptime').textContent='ligado há '+fup(d.uptime);
  const b=d.battery;\$('batt').textContent=b?'🔋 '+b.pct+'%'+(b.ac||b.status==='Charging'?' ⚡':''):'';
  \$('lCpu').textContent=Math.round(c.total)+'%';\$('lMem').textContent=Math.round(memPct)+'%';
  \$('lRx').textContent=fr(d.net.rx);\$('lTx').textContent=fr(d.net.tx);\$('lTemp').textContent=t!=null?t.toFixed(1)+' °C':'–';
  \$('cores').innerHTML=c.cores.map((v,i)=>'<div class="core">núcleo '+i+'<div class="v num">'+Math.round(v)+'%</div><div class="bar"><i style="width:'+v+'%;background:'+(lvl(v)||'var(--ok)')+'"></i></div></div>').join('');
  \$('coreInfo').textContent=c.model;
  const on=d.services.filter(s=>s.state==='active').length;
  \$('svcInfo').textContent=on+' de '+d.services.length+' ativos';
  \$('svcs').innerHTML=d.services.length?d.services.map(s=>'<button type="button" class="svc'+(s.state==='active'?' on':'')+'" data-svc="'+esc(s.name)+'" data-st="'+esc(s.state)+'" title="Iniciar, parar ou reiniciar"><b></b>'+esc(s.name)+(s.state!=='active'?' <small>'+esc(s.state)+'</small>':'')+'<svg class="dd" viewBox="0 0 24 24"><path d="m7 10 5 5 5-5"/></svg></button>').join(''):'<div class="empty">Nenhum serviço conhecido</div>';
  procs();
  \$('disks').innerHTML=d.disks.length?d.disks.map(x=>{const p=x.used/x.total*100;return'<div class="row"><div class="rl"><span>'+esc(x.mnt)+' <small>'+esc(x.dev)+' · '+esc(x.fs)+'</small></span><span class="num">'+fb(x.used)+' / '+fb(x.total)+'</span></div><div class="bar"><i style="width:'+p+'%;background:'+(lvl(p,80,92)||'var(--disk)')+'"></i></div></div>'}).join(''):'<div class="empty">Nenhum disco</div>';
  const info=[['Hostname',d.host],['Sistema',d.os],['Kernel',d.kernel],['CPU',c.model],['Ligado há',fup(d.uptime)],['IPs',d.ips.join(', ')],['PHP',d.php],['Processos',d.procs.count],['Conexões TCP',d.net.conn]];
  \$('info').innerHTML=info.map(r=>'<dt>'+r[0]+'</dt><dd title="'+esc(r[1])+'">'+esc(r[1])+'</dd>').join('');
  \$('conn').textContent=d.net.conn+' conexões TCP';
  \$('ifs').innerHTML=d.net.ifs.map(i=>'<tr><td>'+esc(i.name)+'</td><td class="r num">'+fr(i.rx)+'</td><td class="r num">'+fr(i.tx)+'</td><td class="r num">'+fb(i.rx_total)+'</td><td class="r num">'+fb(i.tx_total)+'</td></tr>').join('')||'<tr><td colspan="5" class="empty">Nenhuma interface</td></tr>';
  let s=d.temp.list.map(t=>'<div class="kv"><span>'+esc(t.label)+' <small style="color:var(--dim)">'+esc(t.chip)+'</small></span><span class="num" style="color:'+(t.c>=85?'var(--bad)':t.c>=70?'var(--warn)':'var(--tx)')+'">'+t.c.toFixed(1)+' °C</span></div>').join('');
  if(b)s='<div class="kv"><span>Bateria</span><span class="num">'+b.pct+'% · '+esc(b.status)+'</span></div>'+s;
  \$('sensors').innerHTML=s||'<div class="empty">Nenhum sensor disponível</div>';
  draw();
}
async function tick(){
  if(busy)return;busy=true;
  try{
    const r=await fetch('?api=1',{cache:'no-store',credentials:'same-origin'});
    if(r.status===401){location.reload();return}
    render(await r.json());
    \$('live').classList.remove('off');\$('upd').textContent='ao vivo · '+D.time;
  }catch(e){\$('live').classList.add('off');\$('upd').textContent='sem conexão'}
  finally{busy=false}
}
let view='painel';
function start(){if(!timer&&view==='painel'&&!document.hidden){tick();timer=setInterval(tick,2000);if(RNG!=='live'&&!htimer){loadHist();htimer=setInterval(loadHist,60000)}}}
function stop(){clearInterval(timer);timer=null;clearInterval(htimer);htimer=null}
document.addEventListener('visibilitychange',()=>document.hidden?stop():start());
function route(){
  const v=(location.hash||'#painel').slice(1);view=['painel','sites','bancos','arquivos','terminal','internet','apps','config'].includes(v)?v:'painel';
  document.querySelectorAll('.view').forEach(m=>m.classList.toggle('on',m.id==='v-'+view));
  document.querySelectorAll('#nav a').forEach(a=>a.classList.toggle('on',a.dataset.v===view));
  \$('crumb').textContent=({painel:'Painel',sites:'Sites',bancos:'Bancos de dados',arquivos:'Arquivos',terminal:'Terminal',internet:'Internet',apps:'App Store',config:'Configurações'})[view];
  if(view==='painel'){start();requestAnimationFrame(draw)}else stop();
  if(view==='terminal'){if(window.TERM)window.TERM.show();else setTimeout(()=>\$('tIn')&&\$('tIn').focus(),50)}
  else if(window.TERM)window.TERM.hide();
  if(view==='arquivos'&&window.FM)window.FM.show();
  if(view==='sites'&&window.SITES)window.SITES.show();
  if(view==='bancos'&&window.DB)window.DB.show();
  if(view==='config'&&window.CONF)window.CONF.show();
  if(view==='apps'&&window.APPS)window.APPS.show();
  if(view==='internet'&&window.CF)window.CF.show();
  if(!D&&view!=='painel')tick();
}
window.addEventListener('hashchange',route);
document.querySelectorAll('#ptabs button').forEach(b=>b.onclick=()=>{document.querySelectorAll('#ptabs button').forEach(x=>x.classList.toggle('on',x===b));tab=b.dataset.t;procs()});
window.addEventListener('resize',draw);
const CFG={$cfg};
let cwd=CFG.cwd,hist=[],hp=0,running=false;
const tOut=\$('tOut'),tIn=\$('tIn');
function tPrompt(){const home=CFG.user==='root'?'/root':'/home/'+CFG.user;let p=cwd===home?'~':cwd.startsWith(home+'/')?'~'+cwd.slice(home.length):cwd;return CFG.user+'@'+CFG.host+':'+p+(CFG.user==='root'?'#':'\$')}
function tAdd(html){tOut.insertAdjacentHTML('beforeend',html);tOut.scrollTop=tOut.scrollHeight}
function tUpd(){\$('tPr').textContent=tPrompt()}
/* tRun: usado também pelo gerenciador de arquivos (Abrir no terminal). Vai para o terminal completo quando ele está em uso. */
window.tRun=cmd=>window.TERM&&window.TERM.active()?window.TERM.run(cmd):tRunSimple(cmd);
async function tRunSimple(cmd){
  cmd=cmd.trim();if(running)return;
  if(cmd==='clear'||cmd==='cls'){tOut.innerHTML='';return}
  tAdd('<div class="ln"><b>'+esc(tPrompt())+'</b> '+esc(cmd)+'</div>');
  if(!cmd)return;
  if(hist[hist.length-1]!==cmd)hist.push(cmd);hp=hist.length;
  running=true;\$('tBtn').disabled=true;\$('tBtn').textContent='...';
  try{
    const r=await fetch('?cmd=1',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-LKM-Token':CFG.token},body:JSON.stringify({cmd})});
    if(r.status===401){location.reload();return}
    if(r.status===403){tAdd('<div class="er">Sessão expirada. Recarregue a página.</div>');return}
    const d=await r.json();
    if(d.out)tAdd('<div>'+esc(d.out.replace(/\\n+$/,''))+'</div>');
    if(d.code)tAdd('<div class="er dm">[código '+d.code+']</div>');
    cwd=d.cwd||cwd;tUpd();
  }catch(e){tAdd('<div class="er">Falha de conexão com o painel.</div>')}
  finally{running=false;\$('tBtn').disabled=false;\$('tBtn').textContent='Executar';tIn.focus()}
}
if(!CFG.user){\$('tUser').textContent='desativado';\$('tForm').style.display='none';\$('chips').style.display='none';tAdd('<div class="dm">Terminal desativado. Rode o instalador de novo e escolha root ou seu usuário.</div>')}
else{
  \$('tUser').textContent=CFG.user==='root'?'root · acesso total':CFG.user;if(CFG.user==='root')\$('tUser').classList.add('root');
  ['df -h','free -h','uptime','ip -br a','lk status','lk sites','systemctl --failed','top -bn1 | head -20','journalctl -n 30 --no-pager'].forEach(c=>{const b=document.createElement('button');b.type='button';b.textContent=c;b.onclick=()=>tRunSimple(c);\$('chips').appendChild(b)});
  tAdd('<div class="dm">Terminal do LK Monitor · comandos rodam como '+esc(CFG.user)+' em '+esc(CFG.host)+'</div>');
  tUpd();
  \$('tForm').onsubmit=e=>{e.preventDefault();const c=tIn.value;tIn.value='';tRunSimple(c)};
  tIn.addEventListener('keydown',e=>{
    if(e.key==='ArrowUp'){if(hp>0){hp--;tIn.value=hist[hp]}e.preventDefault()}
    else if(e.key==='ArrowDown'){if(hp<hist.length-1){hp++;tIn.value=hist[hp]}else{hp=hist.length;tIn.value=''}e.preventDefault()}
    else if(e.key==='l'&&e.ctrlKey){tOut.innerHTML='';e.preventDefault()}
  });
  tOut.addEventListener('click',()=>{if(!getSelection().toString())tIn.focus()});
}
route();
setRange((()=>{try{return localStorage.getItem('lkm.range')}catch(e){return null}})()||'live');
/* ---------- app no celular (PWA) */
let BIP=null;
window.addEventListener('beforeinstallprompt',e=>{e.preventDefault();BIP=e});
if(matchMedia('(display-mode: standalone)').matches||navigator.standalone)\$('pwaBtn').style.display='none';
\$('pwaBtn').onclick=async()=>{
  const U=window.UI;if(!U)return;
  const sec=location.protocol==='https:',ok=CFG.https&&CFG.ca;
  const urls=ok?(CFG.ips.length?CFG.ips:[location.hostname]).map(ip=>'https://'+ip+':'+CFG.https+'/'):[];
  const ua=navigator.userAgent,plat=/iPhone|iPad|iPod/.test(ua)?'ios':/Android/.test(ua)?'android':'win';
  const step=(n,t,body)=>'<div class="pw-step"><b>'+n+'</b><div><div class="pw-t">'+t+'</div>'+body+'</div></div>';
  const add='<div class="pw-tabs tabs"><button data-p="android">Android</button><button data-p="ios">iPhone</button><button data-p="win">Windows</button></div>';
  const home='<div class="pw-p" data-p="android">No Chrome, toque no menu <b>⋮</b> e em <b>Instalar app</b> (ou <b>Adicionar à tela inicial</b>).</div>'+
    '<div class="pw-p" data-p="ios">No Safari, toque em <b>Compartilhar</b> e em <b>Adicionar à Tela de Início</b>.</div>'+
    '<div class="pw-p" data-p="win">No Chrome ou Edge, clique no ícone de instalar na barra de endereço (ou menu <b>⋮</b> › <b>Instalar</b>).</div>';
  const certHow='<div class="pw-p" data-p="android">Abra o arquivo baixado ou vá em <b>Configurações › Segurança › Mais configurações de segurança › Criptografia e credenciais › Instalar um certificado › Certificado de CA</b> e escolha <b>lkmonitor-ca.crt</b>.</div>'+
    '<div class="pw-p" data-p="ios">Baixe pelo <b>Safari</b> e toque em <b>Permitir</b>. Vá em <b>Ajustes › Perfil Baixado › Instalar</b>. Depois em <b>Ajustes › Geral › Sobre › Ajustes de Confiança de Certificados</b>, ative <b>LK Monitor CA</b>.</div>'+
    '<div class="pw-p" data-p="win">Dê dois cliques no arquivo › <b>Instalar Certificado</b> › <b>Usuário Atual</b> › <b>Colocar todos os certificados no repositório a seguir</b> › <b>Autoridades de Certificação Raiz Confiáveis</b> › <b>Concluir</b>. Feche e abra o navegador.</div>';
  const links='<div class="pw-urls">'+urls.map(u=>'<div><a href="'+esc(u)+'" target="_blank" rel="noopener">'+esc(u)+'</a><button type="button" class="abtn sm" data-copy="'+esc(u)+'">Copiar</button></div>').join('')+'</div>';
  let h;
  if(!ok)h='<p>O endereço seguro (HTTPS) do painel ainda não está ativo neste servidor. Rode <b>sudo lk</b> › opção <b>33</b> › <b>1</b> (instalar / atualizar) e volte aqui.</p><p class="pw-note">Enquanto isso o painel continua funcionando normalmente por este endereço.</p>';
  else if(sec)h='<p>Você já está no endereço seguro do painel. Falta só colocar o atalho na tela inicial:</p>'+add+home;
  else h='<p>Para virar um app no celular, o painel precisa ser aberto pelo endereço seguro (HTTPS). Como o certificado é do próprio servidor, instale antes o certificado dele:</p>'+
    step(1,'Baixe o certificado','<a class="abtn pri" href="?ca=1" download="lkmonitor-ca.crt">Baixar certificado (.crt)</a><div class="pw-note">Gerado só para este servidor e aceito apenas em endereços da rede local.</div>')+
    step(2,'Instale no aparelho',add+certHow)+
    step(3,'Abra o endereço seguro',links)+
    step(4,'Adicione à tela inicial',home)+
    '<p class="pw-note">Sem o certificado o painel continua funcionando normalmente por este endereço (HTTP).</p>';
  const btns=[{label:'Fechar',value:null}];
  if(BIP)btns.push({label:'Instalar app',value:'inst',kind:'pri'});
  const r=await U.dialog({title:'Instalar no celular',icon:'phone',wide:true,html:'<div class="pw">'+h+'</div>',buttons:btns,onOpen(ov){
    const pick=p=>{ov.querySelectorAll('.pw-tabs button').forEach(b=>b.classList.toggle('on',b.dataset.p===p));ov.querySelectorAll('.pw-p').forEach(d=>d.hidden=d.dataset.p!==p)};
    ov.querySelectorAll('.pw-tabs button').forEach(b=>b.onclick=()=>pick(b.dataset.p));pick(plat);
    ov.querySelectorAll('[data-copy]').forEach(b=>b.onclick=()=>U.copyText(b.dataset.copy).then(()=>U.toast('Endereço copiado')));
  }});
  if(r==='inst'&&BIP){BIP.prompt();BIP=null}
};
</script><script src="fm.js?v={$ver}"></script><script src="term.js?v={$ver}"></script><script src="sites.js?v={$ver}"></script><script src="db.js?v={$ver}"></script><script src="config.js?v={$ver}"></script><script src="sys.js?v={$ver}"></script><script src="apps.js?v={$ver}"></script><script src="cf.js?v={$ver}"></script></body></html>
HTML;
}
