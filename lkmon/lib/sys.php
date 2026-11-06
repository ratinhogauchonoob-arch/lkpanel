<?php
/*
 * LK Monitor - helper das ações do sistema (aba Painel): serviços, processos, modo servidor e energia.
 * Executado pelo painel via sudo como o usuário do terminal (precisa ser root para agir).
 * O painel roda no Apache + php-fpm: reiniciar esses dois (ou desligar/reiniciar o PC) é agendado
 * com systemd-run para alguns segundos depois, assim a resposta chega ao navegador antes.
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

const LK_BIN = '/usr/local/bin/lk';
const SVC_CANDIDATES = ['apache2', 'nginx', 'mariadb', 'mysql', 'redis-server', 'supervisor', 'ssh', 'ufw', 'docker', 'fail2ban', 'cron', 'pm2-root', 'tailscaled'];

function out(array $d = []): never
{
    echo json_encode(['ok' => true] + $d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit(0);
}

function fail(string $m): never
{
    echo json_encode(['ok' => false, 'error' => $m], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit(0);
}

/** Roda um comando (argv, sem shell). Retorna [código, saída sem cores]. */
function run(array $argv, int $timeout = 60): array
{
    $argv = array_merge(['/usr/bin/timeout', '-k', '5', (string)$timeout], $argv);
    $p = @proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes,
        null, ['PATH' => getenv('PATH'), 'LANG' => 'C.UTF-8', 'TERM' => 'dumb', 'SYSTEMD_COLORS' => '0']);
    if (!is_resource($p)) return [1, 'Não foi possível executar ' . $argv[4]];
    $o = (string)stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $code = proc_close($p);
    $o = preg_replace('/\e\[[0-9;?]*[ -\/]*[@-~]|\r(?!\n)/', '', $o);
    return [$code, trim($o)];
}

/** Agenda um comando para daqui a $sec segundos, fora do Apache (sobrevive ao reinício dele). */
function later(array $argv, int $sec = 3): void
{
    $unit = 'lkm-acao-' . bin2hex(random_bytes(4));
    [$c, $o] = run(array_merge(['systemd-run', '--quiet', '--collect', '--unit=' . $unit, '--on-active=' . $sec, '--timer-property=AccuracySec=100ms'], $argv), 20);
    if ($c !== 0) fail('Falha ao agendar: ' . ($o ?: 'systemd-run'));
}

function need_root(): void
{
    if (function_exists('posix_geteuid') ? posix_geteuid() !== 0 : trim((string)shell_exec('id -u')) !== '0') {
        fail('Essa ação precisa do terminal do LK Monitor como root. No servidor: sudo lk › opção 33 › instalar / atualizar e escolha root.');
    }
}

function unit_exists(string $s): bool
{
    foreach (['/usr/lib/systemd/system', '/lib/systemd/system', '/etc/systemd/system'] as $dir) {
        if (is_file("$dir/$s.service")) return true;
    }
    return false;
}

/** Mesma lista do card "Serviços" do painel. */
function known_service(string $s): bool
{
    if (!preg_match('/^[a-z0-9][a-z0-9.@_-]{0,60}$/', $s)) return false;
    $ok = in_array($s, SVC_CANDIDATES, true) || (bool)preg_match('/^php\d+\.\d+-fpm$/', $s);
    return $ok && unit_exists($s);
}

/** Serviços que mantêm o próprio painel no ar. */
function is_panel_service(string $s): bool
{
    return $s === 'apache2' || (bool)preg_match('/^php\d+\.\d+-fpm$/', $s);
}

function mode_info(): array
{
    [, $def] = run(['systemctl', 'get-default'], 10);
    [$c] = run(['systemctl', 'is-active', '--quiet', 'graphical.target'], 10);
    return [
        'default' => $def,
        'server' => $def === 'multi-user.target',
        'desktop_now' => $c === 0,
        'has_desktop' => unit_exists('display-manager') || is_link('/etc/systemd/system/display-manager.service'),
        'lk' => is_executable(LK_BIN),
        'root' => function_exists('posix_geteuid') ? posix_geteuid() === 0 : trim((string)shell_exec('id -u')) === '0',
    ];
}

/* ------------------------------------------------------------------ */

$req = json_decode((string)stream_get_contents(STDIN), true);
if (!is_array($req)) fail('Pedido inválido');
$act = (string)($req['action'] ?? '');

switch ($act) {
    case 'status':
        out(['mode' => mode_info()]);

    case 'service':
        need_root();
        $name = (string)($req['name'] ?? '');
        $op = (string)($req['op'] ?? '');
        if (!in_array($op, ['start', 'stop', 'restart'], true)) fail('Ação inválida');
        if (!known_service($name)) fail('Serviço desconhecido: ' . $name);
        if (is_panel_service($name)) {
            if ($op === 'stop') fail($name . ' mantém o LK Monitor no ar. Parar pelo painel deixaria o painel fora do ar: use Reiniciar, ou pare pelo terminal do servidor.');
            later(['/bin/systemctl', $op, $name], 2);
            out(['msg' => $name . ': reiniciando em 2 segundos. O painel pode piscar.', 'delayed' => true]);
        }
        [$c, $o] = run(['systemctl', $op, $name], 120);
        if ($c !== 0) fail('Falha ao executar ' . $op . ' em ' . $name . ($o !== '' ? ': ' . $o : ''));
        [, $st] = run(['systemctl', 'is-active', $name], 10);
        $verb = ['start' => 'iniciado', 'stop' => 'parado', 'restart' => 'reiniciado'][$op];
        out(['msg' => $name . ' ' . $verb, 'state' => $st]);

    case 'kill':
        need_root();
        $pid = (int)($req['pid'] ?? 0);
        $sig = ($req['sig'] ?? '') === 'KILL' ? 'KILL' : 'TERM';
        if ($pid <= 1) fail('Processo inválido');
        $stat = @file_get_contents("/proc/$pid/stat");
        if ($stat === false) fail('O processo já terminou.');
        $a = strpos($stat, '(');
        $b = strrpos($stat, ')');
        $comm = substr($stat, $a + 1, $b - $a - 1);
        $ppid = (int)(explode(' ', substr($stat, $b + 2))[1] ?? 0);
        if (isset($req['name']) && (string)$req['name'] !== $comm) fail('O PID ' . $pid . ' agora é outro processo (' . $comm . '). Nada foi feito.');
        if ($ppid === 2 || $pid === 2) fail('Processos do kernel não podem ser encerrados.');
        for ($p = getmypid(); $p > 1;) {   // nunca o próprio painel (este helper, sudo, php-fpm…)
            if ($p === $pid) fail('Esse processo faz parte do próprio LK Monitor.');
            $s = @file_get_contents("/proc/$p/stat");
            if ($s === false) break;
            $p = (int)(explode(' ', substr($s, strrpos($s, ')') + 2))[1] ?? 0);
        }
        [$c, $o] = run(['/bin/kill', '-s', $sig, (string)$pid], 10);
        if ($c !== 0) fail('Não foi possível encerrar ' . $comm . ($o !== '' ? ': ' . $o : ''));
        out(['msg' => ($sig === 'KILL' ? 'Forçado o fim de ' : 'Pedido para encerrar ') . $comm . ' (PID ' . $pid . ')']);

    case 'mode':
        need_root();
        $to = (string)($req['to'] ?? '');
        $when = (string)($req['when'] ?? 'boot');
        if (!in_array($to, ['server', 'desktop'], true) || !in_array($when, ['agora', 'reiniciar', 'boot'], true)) fail('Pedido inválido');
        if (!is_executable(LK_BIN)) fail('Comando lk não encontrado em ' . LK_BIN . '. Rode o install-lk.sh de novo.');
        // configura tudo (GRUB, tampa, suspensão, thermald, perfil de energia) sem trocar a tela agora
        [$c, $o] = run([LK_BIN, 'servidor', $to === 'server' ? 'on' : 'off', 'boot'], 600);
        if ($c !== 0) fail("O lk terminou com erro:\n" . $o);
        $msg = $to === 'server' ? 'Modo servidor ativado' : 'Modo normal (com tela) ativado';
        if ($when === 'agora') {
            later(['/bin/systemctl', 'isolate', $to === 'server' ? 'multi-user.target' : 'graphical.target'], 2);
            $msg .= $to === 'server' ? '. Desligando a área de trabalho…' : '. Ligando a área de trabalho…';
        } elseif ($when === 'reiniciar') {
            later(['/bin/systemctl', 'reboot'], 3);
            $msg .= '. Reiniciando em 3 segundos…';
        } else {
            $msg .= '. Vale a partir do próximo boot.';
        }
        out(['msg' => $msg, 'log' => $o, 'mode' => mode_info()]);

    case 'rootpass':
        // troca a senha do root do Linux (SSH e login local). A senha chega pelo stdin e vai direto para o chpasswd (nunca na linha de comando nem no log).
        need_root();
        $pw = (string)($req['pass'] ?? '');
        if (strlen($pw) < 10 || strlen($pw) > 200 || preg_match('/[\x00-\x1f:]/', $pw)) fail('Senha inválida para o Linux (10 a 200 caracteres, sem ":").');
        $p = @proc_open(['/usr/sbin/chpasswd'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($p)) fail('Não foi possível rodar o chpasswd.');
        fwrite($pipes[0], 'root:' . $pw . "\n");
        fclose($pipes[0]);
        $e = trim((string)stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($p) !== 0) fail('O Linux recusou a senha: ' . ($e ?: 'chpasswd'));
        $sshd = is_executable('/usr/sbin/sshd') ? '/usr/sbin/sshd' : '';
        $on = null;
        if ($sshd && !empty($req['ssh'])) {
            // liga o login do root com senha (mesmo arquivo da opção "Permitir login root com senha" do lk ssh)
            $f = '/etc/ssh/sshd_config.d/10-root.conf';
            $old = is_file($f) ? (string)file_get_contents($f) : null;
            @mkdir('/etc/ssh/sshd_config.d', 0755, true);
            file_put_contents($f, "# LkPanel: root entra no SSH com senha\nPermitRootLogin yes\nPasswordAuthentication yes\n");
            chmod($f, 0644);
            [$c, $e] = run([$sshd, '-t'], 10);
            if ($c !== 0) {
                if ($old === null) @unlink($f); else file_put_contents($f, $old);
                fail('Senha do root trocada, mas a configuração do SSH ficou inválida e foi desfeita: ' . $e);
            }
            foreach (['ssh', 'sshd'] as $u) if (unit_exists($u)) { run(['systemctl', 'enable', '--now', $u], 30); run(['systemctl', 'try-reload-or-restart', $u], 30); break; }
        }
        // o SSH aceita o root com senha?
        $t = '';
        if ($sshd) { [$c, $t] = run([$sshd, '-T'], 10); if ($c !== 0) $t = ''; }
        $prl = preg_match('/^permitrootlogin\s+(\S+)/m', $t, $m) ? $m[1] : '';
        $pa = preg_match('/^passwordauthentication\s+(\S+)/m', $t, $m) ? $m[1] : '';
        out(['msg' => 'Senha do root trocada', 'ssh' => $t === '' ? null : ($prl === 'yes' && $pa === 'yes'), 'ssh_installed' => $sshd !== '']);

    case 'power':
        need_root();
        $op = (string)($req['op'] ?? '');
        if (!in_array($op, ['reboot', 'poweroff'], true)) fail('Ação inválida');
        later(['/bin/systemctl', $op], 3);
        out(['msg' => $op === 'reboot' ? 'Reiniciando em 3 segundos…' : 'Desligando em 3 segundos…']);
}
fail('Ação inválida');
