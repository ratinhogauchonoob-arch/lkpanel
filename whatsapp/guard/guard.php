<?php
/*
 * Guardião LK — vigia a temperatura da CPU e aceita comandos pelo WhatsApp.
 *
 *  - Temperatura >= WARN_TEMP por WARN_MIN minutos: avisa no WhatsApp com um código
 *    para REINICIAR / DESLIGAR. Repete a cada REPEAT_MIN minutos enquanto continuar quente.
 *  - Temperatura >= CRIT_TEMP: avisa e desliga sozinho depois de CRIT_MIN minutos
 *    (se AUTO_OFF=1), a menos que alguém responda CANCELAR <código>.
 *  - Comandos aceitos só dos números em NUMBERS: STATUS, REINICIAR, DESLIGAR, CANCELAR, AJUDA.
 *
 * Mensagens chegam pelo hook.php da página do WhatsApp, que grava em /var/lib/lk-guard/inbox.
 * Roda como root (systemd: lk-guard.service).
 */
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);

define('CONF', getenv('LKG_CONF') ?: '/etc/lk-guard.conf');
define('GOWA_ENV', getenv('LKG_GOWA_ENV') ?: '/etc/gowa/gowa.env');
define('DIR', getenv('LKG_DIR') ?: '/var/lib/lk-guard');
define('INBOX', DIR . '/inbox');
define('STATE', DIR . '/state.json');
define('DRY', getenv('LKG_DRY') === '1');                   // testes: não reinicia/desliga de verdade
define('TEMP_FILE', getenv('LKG_TEMP_FILE') ?: '');           // testes: lê a temperatura deste arquivo
define('TEMP_EVERY', max(1, (int)(getenv('LKG_TEMP_EVERY') ?: 10)));
define('DEVICE', 'lk');

function logm(string $m): void
{
    fwrite(STDERR, '[' . date('H:i:s') . "] $m\n");
}

/** Lê arquivos KEY=valor (ignora linhas vazias e comentários com # ou ;). */
function read_kv(string $file): array
{
    $out = [];
    foreach (@file($file, FILE_IGNORE_NEW_LINES) ?: [] as $l) {
        $l = trim($l);
        if ($l === '' || $l[0] === '#' || $l[0] === ';' || !str_contains($l, '=')) continue;
        [$k, $v] = explode('=', $l, 2);
        $out[trim($k)] = trim(trim($v), "\"'");
    }
    return $out;
}

function conf(): array
{
    $d = ['NUMBERS' => '', 'WARN_TEMP' => 90, 'WARN_MIN' => 1, 'CRIT_TEMP' => 97, 'CRIT_MIN' => 2, 'AUTO_OFF' => 1, 'REPEAT_MIN' => 15, 'BOT' => 1];
    $c = read_kv(CONF);
    foreach ($d as $k => $v) {
        if (!isset($c[$k]) || trim((string)$c[$k], "\"' ") === '') continue;
        $val = trim((string)$c[$k], "\"' ");
        $d[$k] = is_int($v) ? (int)$val : $val;
    }
    // LK-BOT-PATCH2: "Repetir o mesmo alerta a cada N min" do LK Monitor vale também aqui
    $lkm = json_decode((string)@file_get_contents('/var/lib/lkmonitor/settings.json'), true);
    if (is_array($lkm) && (int)($lkm['repeat'] ?? 0) >= 1) $d['REPEAT_MIN'] = (int)$lkm['repeat'];
    $nums = [];
    foreach (explode(',', (string)$d['NUMBERS']) as $n) {
        $n = preg_replace('/\D+/', '', $n);
        if (strlen($n) >= 8 && strlen($n) <= 15) $nums[] = $n;
    }
    $d['nums'] = array_values(array_unique($nums));
    return $d;
}

function gowa(): array
{
    $e = read_kv(GOWA_ENV);
    return ['port' => trim((string)($e['APP_PORT'] ?? '')), 'user' => trim((string)($e['GOWA_USER'] ?? '')), 'pass' => trim((string)($e['GOWA_PASS'] ?? ''))];
}

function gowa_call(string $method, string $path, ?array $body = null, int $timeout = 20): array
{
    $g = gowa();
    if ($g['port'] === '') return [0, null];
    $ch = curl_init('http://127.0.0.1:' . $g['port'] . $path);
    $h = ['X-Device-Id: ' . DEVICE, 'Accept: application/json'];
    if ($body !== null) {
        $h[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h,
        CURLOPT_USERPWD => $g['user'] . ':' . $g['pass'], CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 5]);
    $r = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    return [$r === false ? 0 : $code, $r === false ? null : json_decode((string)$r, true)];
}

function send(string $num, string $text): bool
{
    // LK-BOT-PATCH: BOT=0 em /etc/lk-guard.conf deixa o bot mudo (o desligamento automático continua)
    if ((int)(conf()['BOT'] ?? 1) !== 1) {
        logm('Bot desligado (BOT=0), mensagem não enviada: ' . str_replace("\n", ' | ', $text));
        return false;
    }
    [$code, $d] = gowa_call('POST', '/send/message', ['phone' => $num . '@s.whatsapp.net', 'message' => $text]);
    if ($code < 200 || $code >= 300) {
        logm("Falha ao enviar para $num (HTTP $code): " . (is_array($d) ? (string)($d['message'] ?? '') : 'sem resposta'));
        return false;
    }
    return true;
}

function whatsapp_ready(): bool
{
    [$code, $d] = gowa_call('GET', '/devices/' . DEVICE . '/status', null, 8);
    return $code === 200 && !empty($d['results']['is_logged_in']) && !empty($d['results']['is_connected']);
}

/**
 * O WhatsApp às vezes identifica o remetente só pelo LID (um código interno), sem o número.
 * Descobre a qual número autorizado o LID pertence, consultando o GOWA, e guarda o resultado.
 */
function lid_to_number(string $lid, array $nums): string
{
    if ($lid === '') return '';
    $file = DIR . '/lids.json';
    $map = json_decode((string)@file_get_contents($file), true);
    if (!is_array($map)) $map = [];
    if (isset($map[$lid]) && in_array($map[$lid], $nums, true)) return $map[$lid];

    $found = '';
    // 1) LID -> número
    [$code, $d] = gowa_call('GET', '/user/info?phone=' . rawurlencode($lid . '@lid'), null, 15);
    if ($code === 200) $found = (string)preg_replace('/\D+/', '', (string)($d['results']['resolved_phone'] ?? ''));
    // 2) número -> LID, para cada número autorizado (a 1ª consulta faz o GOWA aprender a ligação)
    if ($found === '') {
        foreach ($nums as $n) {
            for ($try = 0; $try < 2 && $found === ''; $try++) {
                [$code, $d] = gowa_call('GET', '/user/info?phone=' . rawurlencode($n . '@s.whatsapp.net'), null, 15);
                $rl = $code === 200 ? (string)preg_replace('/[:@].*$/', '', (string)($d['results']['resolved_lid'] ?? '')) : '';
                if ($rl !== '') {
                    $map[$rl] = $n;
                    if ($rl === $lid) $found = $n;
                    break;
                }
            }
            if ($found !== '') break;
        }
    }
    if ($found !== '') {
        $map[$lid] = $found;
        logm("LID $lid identificado como +$found");
    }
    @file_put_contents($file, json_encode($map));
    @chmod($file, 0600);
    return $found;
}

function cpu_temp(): ?float
{
    if (TEMP_FILE !== '') {
        $v = trim((string)@file_get_contents(TEMP_FILE));
        return $v === '' ? null : (float)$v;
    }
    $best = null;
    foreach (glob('/sys/class/hwmon/hwmon*') ?: [] as $h) {
        $name = trim((string)@file_get_contents("$h/name"));
        if (!in_array($name, ['coretemp', 'k10temp', 'zenpower', 'cpu_thermal', 'soc_thermal'], true)) continue;
        foreach (glob("$h/temp*_input") ?: [] as $f) {
            $v = (int)@file_get_contents($f);
            if ($v > 0 && ($best === null || $v / 1000 > $best)) $best = $v / 1000;
        }
    }
    if ($best !== null) return $best;
    foreach (glob('/sys/class/thermal/thermal_zone*') ?: [] as $z) {
        if (!preg_match('/x86_pkg_temp|cpu|soc|acpitz/i', trim((string)@file_get_contents("$z/type")))) continue;
        $v = (int)@file_get_contents("$z/temp");
        if ($v > 0 && ($best === null || $v / 1000 > $best)) $best = $v / 1000;
    }
    return $best;
}

function fmt_dur(int $s): string
{
    $d = intdiv($s, 86400);
    $h = intdiv($s % 86400, 3600);
    $m = intdiv($s % 3600, 60);
    return ($d ? "{$d}d " : '') . ($d || $h ? "{$h}h " : '') . "{$m}min";
}

function host(): string
{
    return (string)gethostname();
}

function status_text(): string
{
    $t = cpu_temp();
    $la = explode(' ', trim((string)@file_get_contents('/proc/loadavg')));
    $cores = max(1, preg_match_all('/^processor\s*:/m', (string)@file_get_contents('/proc/cpuinfo')));
    $mi = [];
    foreach (explode("\n", (string)@file_get_contents('/proc/meminfo')) as $l) {
        if (preg_match('/^(\w+):\s+(\d+)/', $l, $m)) $mi[$m[1]] = (int)$m[2];
    }
    $tot = ($mi['MemTotal'] ?? 0) / 1048576;
    $used = $tot - ($mi['MemAvailable'] ?? 0) / 1048576;
    $dt = @disk_total_space('/') ?: 0;
    $df = @disk_free_space('/') ?: 0;
    $up = (int)(float)@file_get_contents('/proc/uptime');
    return "📊 *" . host() . "*\n"
        . "🌡️ CPU: " . ($t !== null ? round($t) . ' °C' : 'sem sensor') . "\n"
        . "⚙️ Carga: " . ($la[0] ?? '?') . " ($cores núcleos)\n"
        . "🧠 RAM: " . number_format($used, 1, ',', '') . " de " . number_format($tot, 1, ',', '') . " GB\n"
        . "💾 Disco: " . ($dt > 0 ? round(100 * ($dt - $df) / $dt) : '?') . "% usado\n"
        . "⏱️ Ligado há " . fmt_dur($up);
}

function help_text(): string
{
    return "🤖 *" . host() . "* — comandos:\n"
        . "*STATUS* – temperatura, CPU, RAM e disco\n"
        . "*REINICIAR* – reinicia o servidor (pede confirmação)\n"
        . "*DESLIGAR* – desliga o servidor (pede confirmação)\n"
        . "*CANCELAR <código>* – cancela o desligamento automático";
}

function norm_text(string $s): string
{
    $s = mb_strtolower(trim($s));
    $s = strtr($s, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ü' => 'u', 'ç' => 'c']);
    $s = (string)preg_replace('/[^a-z0-9 ]+/', ' ', $s);
    return trim((string)preg_replace('/\s+/', ' ', $s));
}

final class Guard
{
    private ?int $warnSince = null;
    private bool $alerted = false;
    private int $lastAlert = 0;
    private int $lastCritMsg = 0;      // LK-BOT-PATCH: anti-spam do aviso crítico
    private bool $critNotified = false;
    private ?int $autoOffAt = null;
    private int $noAutoOffUntil = 0;
    private ?array $code = null;      // ['code' => '1234', 'until' => ts]
    private ?float $temp = null;
    private int $lastTempCheck = 0;

    public function run(): void
    {
        if (!is_dir(INBOX)) @mkdir(INBOX, 0730, true);
        logm('Guardião iniciado em ' . host() . (DRY ? ' (modo teste)' : ''));
        $this->afterBoot();
        while (true) {
            $c = conf();
            $this->inbox($c);
            if (time() - $this->lastTempCheck >= TEMP_EVERY) {
                $this->lastTempCheck = time();
                $this->checkTemp($c);
            }
            if ($this->autoOffAt !== null && time() >= $this->autoOffAt) $this->autoOff($c);
            sleep(getenv('LKG_TICK') !== false ? max(1, (int)getenv('LKG_TICK')) : 2);
        }
    }

    /* Avisa que o servidor voltou depois de um REINICIAR pedido pelo WhatsApp. */
    private function afterBoot(): void
    {
        $st = json_decode((string)@file_get_contents(STATE), true);
        if (!is_array($st) || empty($st['rebooting'])) return;
        @unlink(STATE);
        if (time() - (int)$st['rebooting'] > 3600) return;
        $c = conf();
        for ($i = 0; $i < 60 && !whatsapp_ready(); $i++) sleep(3);
        $up = (int)(float)@file_get_contents('/proc/uptime');
        foreach ($c['nums'] as $n) send($n, "✅ *" . host() . "* ligado de novo (reiniciou há " . fmt_dur($up) . ").");
    }

    private function newCode(int $minutes): string
    {
        if ($this->code !== null && $this->code['until'] - time() >= 120) {
            $this->code['until'] = max($this->code['until'], time() + $minutes * 60);
            return $this->code['code'];
        }
        $this->code = ['code' => (string)random_int(1000, 9999), 'until' => time() + $minutes * 60];
        return $this->code['code'];
    }

    private function codeOk(string $given): bool
    {
        return $this->code !== null && time() <= $this->code['until'] && hash_equals($this->code['code'], $given);
    }

    private function broadcast(array $c, string $text): void
    {
        if (!$c['nums']) {
            logm('Nenhum número configurado em ' . CONF . ': aviso não enviado: ' . str_replace("\n", ' | ', $text));
            return;
        }
        foreach ($c['nums'] as $n) send($n, $text);
    }

    /* LK-BOT-PATCH: aviso crítico no máximo 1x a cada REPEAT_MIN minutos (a temperatura
       subindo e descendo em volta do limite mandava dezenas de mensagens). */
    private function critMsgOk(array $c, int $now): bool
    {
        if ($now - $this->lastCritMsg < max(1, (int)$c['REPEAT_MIN']) * 60) {
            logm('Aviso crítico suprimido (anti-spam)');
            return false;
        }
        $this->lastCritMsg = $now;
        $this->critNotified = true;
        return true;
    }

    private function checkTemp(array $c): void
    {
        $t = cpu_temp();
        if ($t === null) return;
        $this->temp = $t;
        $now = time();
        $tt = round($t) . ' °C';

        // crítico: desliga sozinho (vem antes do aviso para não mandar duas mensagens)
        if ((int)$c['AUTO_OFF'] === 1 && $t >= $c['CRIT_TEMP']) {
            if ($this->autoOffAt === null && $now >= $this->noAutoOffUntil) {
                $code = $this->newCode(30);
                $this->autoOffAt = $now + max(0, (int)$c['CRIT_MIN']) * 60;
                $this->alerted = true;
                $this->lastAlert = $now;
                $this->warnSince ??= $now;
                logm("Temperatura crítica: $tt — desligamento automático agendado");
                if ($this->critMsgOk($c, $now)) $this->broadcast($c, "🛑 *" . host() . "* chegou a *$tt*!\n\n"
                    . "Vou *DESLIGAR* em {$c['CRIT_MIN']} min para proteger o notebook, se não baixar.\n"
                    . "Responda *CANCELAR $code* para impedir, ou *DESLIGAR $code* para desligar agora.");
            }
        } elseif ($this->autoOffAt !== null && $t <= $c['CRIT_TEMP'] - 3) {
            $this->autoOffAt = null;
            logm("Temperatura baixou: $tt — desligamento automático cancelado");
            if ($this->critNotified) { $this->critNotified = false; $this->broadcast($c, "👍 *" . host() . "*: temperatura baixou para *$tt*. Desligamento automático cancelado."); }
        }

        // aviso
        if ($t >= $c['WARN_TEMP']) {
            $this->warnSince ??= $now;
            $due = !$this->alerted ? ($now - $this->warnSince >= $c['WARN_MIN'] * 60 && $now - $this->lastAlert >= $c['REPEAT_MIN'] * 60) : $now - $this->lastAlert >= $c['REPEAT_MIN'] * 60;
            if ($due && $this->autoOffAt === null) {
                $code = $this->newCode(30);
                $this->alerted = true;
                $this->lastAlert = $now;
                logm("Aviso de temperatura: $tt");
                $this->broadcast($c, "🔥 *" . host() . "* está em *$tt* (limite {$c['WARN_TEMP']} °C).\n\n"
                    . "Responda com:\n*REINICIAR $code* – reinicia o servidor\n*DESLIGAR $code* – desliga o servidor\n*STATUS* – mostra como ele está\n\n"
                    . "O código vale 30 min.");
            }
        } elseif ($t <= $c['WARN_TEMP'] - 5) {
            $this->warnSince = null;
            if ($this->alerted) {
                $this->alerted = false;
                logm("Temperatura normal: $tt");
                $this->broadcast($c, "✅ *" . host() . "*: temperatura voltou ao normal (*$tt*).");
            }
        }
    }

    private function autoOff(array $c): void
    {
        $this->autoOffAt = null;
        $this->noAutoOffUntil = time() + 600;     // se o poweroff falhar, não repete antes de 10 min
        $tt = $this->temp !== null ? round($this->temp) . ' °C' : '?';
        logm("Desligando por temperatura ($tt)");
        $this->broadcast($c, "⏻ *" . host() . "* continua em *$tt*: desligando agora para proteger o notebook.\nPara ligar de novo, use o botão de energia.");
        $this->power(false);
    }

    private function power(bool $reboot): void
    {
        if ($reboot) @file_put_contents(STATE, json_encode(['rebooting' => time()]));
        if (DRY) {
            logm('[TESTE] ' . ($reboot ? 'systemctl reboot' : 'systemctl poweroff'));
            return;
        }
        sleep(2);
        exec($reboot ? '/bin/systemctl reboot' : '/bin/systemctl poweroff');
    }

    private function inbox(array $c): void
    {
        $files = glob(INBOX . '/*.json') ?: [];
        sort($files);
        foreach ($files as $f) {
            $ok = is_file($f) && !is_link($f) && filesize($f) <= 8192;
            $raw = $ok ? (string)@file_get_contents($f) : '';
            @unlink($f);
            $m = json_decode($raw, true);
            if (!is_array($m)) continue;
            if (time() - (int)($m['at'] ?? 0) > 300) continue;       // mensagem velha: ignora
            $rawFrom = (string)($m['from'] ?? '');
            $text = (string)($m['text'] ?? '');
            if (str_starts_with($rawFrom, 'lid:')) {
                $lid = (string)preg_replace('/\D+/', '', substr($rawFrom, 4));
                $from = lid_to_number($lid, $c['nums']);
                if ($from === '') {
                    logm("Mensagem de LID $lid sem número conhecido ignorada");
                    continue;
                }
            } else {
                $from = (string)preg_replace('/\D+/', '', $rawFrom);
            }
            if (!in_array($from, $c['nums'], true)) {
                logm("Mensagem de número não autorizado ignorada: $from");
                continue;
            }
            $this->command($c, $from, $text);
        }
    }

    private function command(array $c, string $from, string $text): void
    {
        $raw = trim($text);
        $n = norm_text($raw);
        $parts = explode(' ', $n);
        $w = $parts[0] ?? '';
        $arg = $parts[1] ?? '';
        if ($raw === '?' || in_array($w, ['ajuda', 'menu', 'comandos', 'help'], true)) {
            send($from, help_text());
            return;
        }
        if (in_array($w, ['status', 'temp', 'temperatura', 'info'], true)) {
            send($from, status_text());
            return;
        }
        if (in_array($w, ['reiniciar', 'reboot', 'reinicia'], true)) {
            $this->power_cmd($c, $from, true, $arg);
            return;
        }
        if (in_array($w, ['desligar', 'desliga', 'poweroff', 'shutdown'], true)) {
            $this->power_cmd($c, $from, false, $arg);
            return;
        }
        if ($w === 'cancelar') {
            if ($this->autoOffAt === null) {
                send($from, "Não há desligamento automático agendado.");
            } elseif (!$this->codeOk($arg)) {
                send($from, "Código inválido. Responda *CANCELAR* seguido do código do último aviso.");
            } else {
                $this->autoOffAt = null;
                $this->noAutoOffUntil = time() + 1800;
                logm("Desligamento automático cancelado por +$from");
                $this->broadcast($c, "🟡 Desligamento automático de *" . host() . "* cancelado por +$from. Volto a vigiar em 30 min.");
            }
            return;
        }
        // qualquer outra mensagem: não responde (o número pode estar só conversando)
    }

    private function power_cmd(array $c, string $from, bool $reboot, string $arg): void
    {
        $word = $reboot ? 'REINICIAR' : 'DESLIGAR';
        if ($arg === '') {
            $code = $this->newCode(5);
            send($from, "Para confirmar, responda:\n*$word $code*\n\n" . ($reboot
                    ? "O servidor e os sites ficam fora do ar por cerca de 1 minuto."
                    : "⚠️ Para ligar de novo, só pelo botão de energia do notebook.") . "\nO código vale 5 min.");
            return;
        }
        if (!$this->codeOk($arg)) {
            send($from, "Código inválido ou vencido. Mande *$word* para receber um novo.");
            return;
        }
        $this->code = null;
        $this->autoOffAt = null;
        logm(($reboot ? 'Reiniciando' : 'Desligando') . " a pedido de +$from");
        $this->broadcast($c, $reboot
            ? "🔄 Reiniciando *" . host() . "* a pedido de +$from. Aviso quando ele voltar."
            : "⏻ Desligando *" . host() . "* a pedido de +$from. Para ligar de novo, use o botão de energia.");
        $this->power($reboot);
    }
}

if (PHP_SAPI !== 'cli') exit(1);
if (in_array($argv[1] ?? '', ['status', '--status'], true)) {
    echo status_text(), "\n";
    exit(0);
}
if (in_array($argv[1] ?? '', ['teste', '--test'], true)) {
    $c = conf();
    if (!$c['nums']) {
        fwrite(STDERR, "Nenhum número configurado em " . CONF . "\n");
        exit(1);
    }
    $ok = true;
    foreach ($c['nums'] as $n) $ok = send($n, "✅ Guardião de *" . host() . "* funcionando.\n\n" . help_text()) && $ok;
    echo $ok ? "Mensagem de teste enviada para: " . implode(', ', $c['nums']) . "\n" : "Falha ao enviar (veja acima)\n";
    exit($ok ? 0 : 1);
}
(new Guard())->run();
