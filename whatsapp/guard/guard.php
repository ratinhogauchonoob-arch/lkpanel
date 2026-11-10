<?php
/*
 * Guardião LK — vigia a temperatura da CPU e aceita comandos pelo WhatsApp.
 *
 *  - Temperatura >= WARN_TEMP por WARN_MIN minutos: avisa no WhatsApp com um código
 *    para REINICIAR / DESLIGAR. Repete a cada REPEAT_MIN minutos enquanto continuar quente.
 *  - Temperatura >= CRIT_TEMP: avisa e desliga sozinho depois de CRIT_MIN minutos
 *    (se AUTO_OFF=1), a menos que alguém responda CANCELAR <código>.
 *  - Comandos aceitos só dos números em NUMBERS: STATUS, REINICIAR, DESLIGAR, CANCELAR, AJUDA, IDIOMA
 *    (também em inglês e espanhol: RESTART/SHUTDOWN/CANCEL/HELP/LANGUAGE, APAGAR/AYUDA).
 *  - Responde em LANG (en, pt ou es; padrão en). Trocar: IDIOMA PT / LANGUAGE EN / IDIOMA ES.
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
    $d = ['NUMBERS' => '', 'WARN_TEMP' => 90, 'WARN_MIN' => 1, 'CRIT_TEMP' => 97, 'CRIT_MIN' => 2, 'AUTO_OFF' => 1, 'REPEAT_MIN' => 15, 'BOT' => 1, 'LANG' => 'en'];
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
    $d['LANG'] = lang_code((string)$d['LANG']) ?: 'en';
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


/* ===== Idiomas do bot (LK-BOT-I18N, 2.4.1) =====
   LANG=en|pt|es em /etc/lk-guard.conf (padrão: en). Troca pelo WhatsApp: IDIOMA PT / LANGUAGE EN / IDIOMA ES.
   Os comandos funcionam nos 3 idiomas, seja qual for o idioma escolhido. */
function lang_code(string $s): string
{
    $s = norm_text($s);
    $map = ['pt' => 'pt', 'br' => 'pt', 'portugues' => 'pt', 'portuguese' => 'pt', 'ptbr' => 'pt',
            'en' => 'en', 'us' => 'en', 'eng' => 'en', 'english' => 'en', 'ingles' => 'en',
            'es' => 'es', 'pa' => 'es', 'esp' => 'es', 'espanol' => 'es', 'spanish' => 'es', 'castellano' => 'es'];
    return $map[str_replace(' ', '', $s)] ?? '';
}

function L(): string
{
    static $cache = null, $at = 0;
    if (!empty($GLOBALS['__lk_lang_force'])) { $cache = $GLOBALS['__lk_lang_force']; $at = time(); unset($GLOBALS['__lk_lang_force']); }
    if ($cache === null || time() - $at > 5) { $cache = conf()['LANG']; $at = time(); }
    return $cache;
}

/* palavras dos comandos mostradas nas mensagens, no idioma escolhido */
function W(string $k): string
{
    $w = ['pt' => ['status' => 'STATUS', 'reboot' => 'REINICIAR', 'off' => 'DESLIGAR', 'cancel' => 'CANCELAR', 'help' => 'AJUDA', 'lang' => 'IDIOMA'],
          'en' => ['status' => 'STATUS', 'reboot' => 'RESTART', 'off' => 'SHUTDOWN', 'cancel' => 'CANCEL', 'help' => 'HELP', 'lang' => 'LANGUAGE'],
          'es' => ['status' => 'STATUS', 'reboot' => 'REINICIAR', 'off' => 'APAGAR', 'cancel' => 'CANCELAR', 'help' => 'AYUDA', 'lang' => 'IDIOMA']];
    return $w[L()][$k] ?? $w['en'][$k];
}

function t(string $k, array $v = []): string
{
    static $T = [
     'pt' => [
      'nosensor' => 'sem sensor', 'cpu' => 'CPU', 'load' => 'Carga', 'cores' => 'núcleos', 'ram' => 'RAM', 'of' => 'de',
      'disk' => 'Disco', 'used' => 'usado', 'up' => 'Ligado há',
      'help_h' => 'comandos', 'help_status' => 'temperatura, CPU, RAM e disco', 'help_reboot' => 'reinicia o servidor (pede confirmação)',
      'help_off' => 'desliga o servidor (pede confirmação)', 'help_cancel' => 'cancela o desligamento automático', 'code' => 'código',
      'help_lang' => 'muda o idioma do bot',
      'back' => '✅ *{h}* ligado de novo (reiniciou há {d}).',
      'crit' => "🛑 *{h}* chegou a *{t}*!\n\nVou *{OFF}* em {m} min para proteger o notebook, se não baixar.\nResponda *{CANCEL} {c}* para impedir, ou *{OFF} {c}* para desligar agora.",
      'crit_ok' => '👍 *{h}*: temperatura baixou para *{t}*. Desligamento automático cancelado.',
      'warn' => "🔥 *{h}* está em *{t}* (limite {l} °C).\n\nResponda com:\n*{REBOOT} {c}* – reinicia o servidor\n*{OFF} {c}* – desliga o servidor\n*{STATUS}* – mostra como ele está\n\nO código vale 30 min.",
      'normal' => '✅ *{h}*: temperatura voltou ao normal (*{t}*).',
      'autooff' => "⏻ *{h}* continua em *{t}*: desligando agora para proteger o notebook.\nPara ligar de novo, use o botão de energia.",
      'no_auto' => 'Não há desligamento automático agendado.',
      'bad_cancel' => 'Código inválido. Responda *{CANCEL}* seguido do código do último aviso.',
      'cancelled' => '🟡 Desligamento automático de *{h}* cancelado por +{f}. Volto a vigiar em 30 min.',
      'confirm' => "Para confirmar, responda:\n*{w} {c}*\n\n{x}\nO código vale 5 min.",
      'confirm_reboot' => 'O servidor e os sites ficam fora do ar por cerca de 1 minuto.',
      'confirm_off' => '⚠️ Para ligar de novo, só pelo botão de energia do notebook.',
      'bad_code' => 'Código inválido ou vencido. Mande *{w}* para receber um novo.',
      'rebooting' => '🔄 Reiniciando *{h}* a pedido de +{f}. Aviso quando ele voltar.',
      'off_now' => "⏻ Desligando *{h}* a pedido de +{f}. Para ligar de novo, use o botão de energia.",
      'test' => '✅ Guardião de *{h}* funcionando.',
      'lang_ok' => '🌎 Pronto! Agora eu falo *português*.',
      'lang_ask' => "🌎 Idioma atual: *português*.\nMande *IDIOMA PT*, *IDIOMA ES* ou *LANGUAGE EN*.",
     ],
     'en' => [
      'nosensor' => 'no sensor', 'cpu' => 'CPU', 'load' => 'Load', 'cores' => 'cores', 'ram' => 'RAM', 'of' => 'of',
      'disk' => 'Disk', 'used' => 'used', 'up' => 'Up for',
      'help_h' => 'commands', 'help_status' => 'temperature, CPU, RAM and disk', 'help_reboot' => 'restarts the server (asks to confirm)',
      'help_off' => 'shuts the server down (asks to confirm)', 'help_cancel' => 'cancels the automatic shutdown', 'code' => 'code',
      'help_lang' => 'changes the bot language',
      'back' => '✅ *{h}* is back on (restarted {d} ago).',
      'crit' => "🛑 *{h}* reached *{t}*!\n\nI'll *{OFF}* in {m} min to protect the laptop if it doesn't cool down.\nReply *{CANCEL} {c}* to stop it, or *{OFF} {c}* to shut down now.",
      'crit_ok' => '👍 *{h}*: temperature dropped to *{t}*. Automatic shutdown cancelled.',
      'warn' => "🔥 *{h}* is at *{t}* (limit {l} °C).\n\nReply with:\n*{REBOOT} {c}* – restarts the server\n*{OFF} {c}* – shuts the server down\n*{STATUS}* – shows how it's doing\n\nThe code is valid for 30 min.",
      'normal' => '✅ *{h}*: temperature is back to normal (*{t}*).',
      'autooff' => "⏻ *{h}* is still at *{t}*: shutting down now to protect the laptop.\nTo turn it back on, use the power button.",
      'no_auto' => 'No automatic shutdown is scheduled.',
      'bad_cancel' => 'Invalid code. Reply *{CANCEL}* followed by the code from the last alert.',
      'cancelled' => '🟡 Automatic shutdown of *{h}* cancelled by +{f}. I\'ll keep watching again in 30 min.',
      'confirm' => "To confirm, reply:\n*{w} {c}*\n\n{x}\nThe code is valid for 5 min.",
      'confirm_reboot' => 'The server and the sites will be offline for about 1 minute.',
      'confirm_off' => '⚠️ To turn it back on, you need the laptop\'s power button.',
      'bad_code' => 'Invalid or expired code. Send *{w}* to get a new one.',
      'rebooting' => '🔄 Restarting *{h}* as requested by +{f}. I\'ll let you know when it\'s back.',
      'off_now' => "⏻ Shutting down *{h}* as requested by +{f}. To turn it back on, use the power button.",
      'test' => '✅ Guardian of *{h}* is working.',
      'lang_ok' => '🌎 Done! I now speak *English*.',
      'lang_ask' => "🌎 Current language: *English*.\nSend *LANGUAGE EN*, *IDIOMA ES* or *IDIOMA PT*.",
     ],
     'es' => [
      'nosensor' => 'sin sensor', 'cpu' => 'CPU', 'load' => 'Carga', 'cores' => 'núcleos', 'ram' => 'RAM', 'of' => 'de',
      'disk' => 'Disco', 'used' => 'usado', 'up' => 'Encendido hace',
      'help_h' => 'comandos', 'help_status' => 'temperatura, CPU, RAM y disco', 'help_reboot' => 'reinicia el servidor (pide confirmación)',
      'help_off' => 'apaga el servidor (pide confirmación)', 'help_cancel' => 'cancela el apagado automático', 'code' => 'código',
      'help_lang' => 'cambia el idioma del bot',
      'back' => '✅ *{h}* encendido de nuevo (se reinició hace {d}).',
      'crit' => "🛑 ¡*{h}* llegó a *{t}*!\n\nVoy a *{OFF}* en {m} min para proteger la laptop si no baja.\nResponde *{CANCEL} {c}* para impedirlo, o *{OFF} {c}* para apagar ahora.",
      'crit_ok' => '👍 *{h}*: la temperatura bajó a *{t}*. Apagado automático cancelado.',
      'warn' => "🔥 *{h}* está en *{t}* (límite {l} °C).\n\nResponde con:\n*{REBOOT} {c}* – reinicia el servidor\n*{OFF} {c}* – apaga el servidor\n*{STATUS}* – muestra cómo está\n\nEl código vale 30 min.",
      'normal' => '✅ *{h}*: la temperatura volvió a la normalidad (*{t}*).',
      'autooff' => "⏻ *{h}* sigue en *{t}*: apagando ahora para proteger la laptop.\nPara encenderlo de nuevo, usa el botón de encendido.",
      'no_auto' => 'No hay apagado automático programado.',
      'bad_cancel' => 'Código inválido. Responde *{CANCEL}* seguido del código del último aviso.',
      'cancelled' => '🟡 Apagado automático de *{h}* cancelado por +{f}. Vuelvo a vigilar en 30 min.',
      'confirm' => "Para confirmar, responde:\n*{w} {c}*\n\n{x}\nEl código vale 5 min.",
      'confirm_reboot' => 'El servidor y los sitios quedan fuera de línea por cerca de 1 minuto.',
      'confirm_off' => '⚠️ Para encenderlo de nuevo, solo con el botón de encendido de la laptop.',
      'bad_code' => 'Código inválido o vencido. Manda *{w}* para recibir uno nuevo.',
      'rebooting' => '🔄 Reiniciando *{h}* a pedido de +{f}. Te aviso cuando vuelva.',
      'off_now' => "⏻ Apagando *{h}* a pedido de +{f}. Para encenderlo de nuevo, usa el botón de encendido.",
      'test' => '✅ Guardián de *{h}* funcionando.',
      'lang_ok' => '🌎 ¡Listo! Ahora hablo *español*.',
      'lang_ask' => "🌎 Idioma actual: *español*.\nManda *IDIOMA ES*, *IDIOMA PT* o *LANGUAGE EN*.",
     ]];
    $s = $T[L()][$k] ?? $T['en'][$k] ?? $k;
    $v += ['REBOOT' => W('reboot'), 'OFF' => W('off'), 'CANCEL' => W('cancel'), 'STATUS' => W('status')];
    foreach ($v as $kk => $vv) $s = str_replace('{' . $kk . '}', (string)$vv, $s);
    return $s;
}

/* grava LANG=xx em /etc/lk-guard.conf (mantém o resto do arquivo) */
function set_lang(string $code): bool
{
    $lines = @file(CONF, FILE_IGNORE_NEW_LINES);
    if ($lines === false) return false;
    $done = false;
    foreach ($lines as $i => $l) if (preg_match('/^\s*LANG\s*=/', $l)) { $lines[$i] = "LANG=$code"; $done = true; }
    if (!$done) { $lines[] = '# Idioma do bot: en, pt ou es (WhatsApp: IDIOMA PT / LANGUAGE EN / IDIOMA ES)'; $lines[] = "LANG=$code"; }
    $ok = @file_put_contents(CONF, implode("\n", $lines) . "\n") !== false;
    @chmod(CONF, 0600);
    return $ok;
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
    $dec = L() === 'en' ? '.' : ',';
    return "📊 *" . host() . "*\n"
        . "🌡️ " . t('cpu') . ": " . ($t !== null ? round($t) . ' °C' : t('nosensor')) . "\n"
        . "⚙️ " . t('load') . ": " . ($la[0] ?? '?') . " ($cores " . t('cores') . ")\n"
        . "🧠 " . t('ram') . ": " . number_format($used, 1, $dec, '') . " " . t('of') . " " . number_format($tot, 1, $dec, '') . " GB\n"
        . "💾 " . t('disk') . ": " . ($dt > 0 ? round(100 * ($dt - $df) / $dt) : '?') . "% " . t('used') . "\n"
        . "⏱️ " . t('up') . " " . fmt_dur($up);
}

function help_text(): string
{
    return "🤖 *" . host() . "* — " . t('help_h') . ":\n"
        . "*" . W('status') . "* – " . t('help_status') . "\n"
        . "*" . W('reboot') . "* – " . t('help_reboot') . "\n"
        . "*" . W('off') . "* – " . t('help_off') . "\n"
        . "*" . W('cancel') . " <" . t('code') . ">* – " . t('help_cancel') . "\n"
        . "*" . W('lang') . " PT / ES / EN* – " . t('help_lang');
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
        foreach ($c['nums'] as $n) send($n, t('back', ['h' => host(), 'd' => fmt_dur($up)]));
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
                if ($this->critMsgOk($c, $now)) $this->broadcast($c, t('crit', ['h' => host(), 't' => $tt, 'm' => $c['CRIT_MIN'], 'c' => $code]));
            }
        } elseif ($this->autoOffAt !== null && $t <= $c['CRIT_TEMP'] - 3) {
            $this->autoOffAt = null;
            logm("Temperatura baixou: $tt — desligamento automático cancelado");
            if ($this->critNotified) { $this->critNotified = false; $this->broadcast($c, t('crit_ok', ['h' => host(), 't' => $tt])); }
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
                $this->broadcast($c, t('warn', ['h' => host(), 't' => $tt, 'l' => $c['WARN_TEMP'], 'c' => $code]));
            }
        } elseif ($t <= $c['WARN_TEMP'] - 5) {
            $this->warnSince = null;
            if ($this->alerted) {
                $this->alerted = false;
                logm("Temperatura normal: $tt");
                $this->broadcast($c, t('normal', ['h' => host(), 't' => $tt]));
            }
        }
    }

    private function autoOff(array $c): void
    {
        $this->autoOffAt = null;
        $this->noAutoOffUntil = time() + 600;     // se o poweroff falhar, não repete antes de 10 min
        $tt = $this->temp !== null ? round($this->temp) . ' °C' : '?';
        logm("Desligando por temperatura ($tt)");
        $this->broadcast($c, t('autooff', ['h' => host(), 't' => $tt]));
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
        if (in_array($w, ['idioma', 'language', 'lang', 'lingua', 'linguagem'], true)) {
            $code = lang_code(implode(' ', array_slice($parts, 1)));
            if ($code === '') { send($from, t('lang_ask')); return; }
            if (!set_lang($code)) { logm('Não consegui gravar LANG em ' . CONF); return; }
            logm("Idioma do bot: $code (pedido de +$from)");
            usleep(10000);
            $GLOBALS['__lk_lang_force'] = $code;
            send($from, t('lang_ok') . "\n\n" . help_text());
            return;
        }
        if ($raw === '?' || in_array($w, ['ajuda', 'ayuda', 'menu', 'comandos', 'help', 'commands'], true)) {
            send($from, help_text());
            return;
        }
        if (in_array($w, ['status', 'temp', 'temperatura', 'temperature', 'info', 'estado'], true)) {
            send($from, status_text());
            return;
        }
        if (in_array($w, ['reiniciar', 'reboot', 'reinicia', 'restart', 'reinicio'], true)) {
            $this->power_cmd($c, $from, true, $arg);
            return;
        }
        if (in_array($w, ['desligar', 'desliga', 'poweroff', 'shutdown', 'apagar', 'apaga', 'off'], true)) {
            $this->power_cmd($c, $from, false, $arg);
            return;
        }
        if (in_array($w, ['cancelar', 'cancel', 'cancela'], true)) {
            if ($this->autoOffAt === null) {
                send($from, t('no_auto'));
            } elseif (!$this->codeOk($arg)) {
                send($from, t('bad_cancel'));
            } else {
                $this->autoOffAt = null;
                $this->noAutoOffUntil = time() + 1800;
                logm("Desligamento automático cancelado por +$from");
                $this->broadcast($c, t('cancelled', ['h' => host(), 'f' => $from]));
            }
            return;
        }
        // qualquer outra mensagem: não responde (o número pode estar só conversando)
    }

    private function power_cmd(array $c, string $from, bool $reboot, string $arg): void
    {
        $word = $reboot ? W('reboot') : W('off');
        if ($arg === '') {
            $code = $this->newCode(5);
            send($from, t('confirm', ['w' => $word, 'c' => $code, 'x' => t($reboot ? 'confirm_reboot' : 'confirm_off')]));
            return;
        }
        if (!$this->codeOk($arg)) {
            send($from, t('bad_code', ['w' => $word]));
            return;
        }
        $this->code = null;
        $this->autoOffAt = null;
        logm(($reboot ? 'Reiniciando' : 'Desligando') . " a pedido de +$from");
        $this->broadcast($c, $reboot
            ? t('rebooting', ['h' => host(), 'f' => $from])
            : t('off_now', ['h' => host(), 'f' => $from]));
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
    foreach ($c['nums'] as $n) $ok = send($n, t('test', ['h' => host()]) . "\n\n" . help_text()) && $ok;
    echo $ok ? "Mensagem de teste enviada para: " . implode(', ', $c['nums']) . "\n" : "Falha ao enviar (veja acima)\n";
    exit($ok ? 0 : 1);
}
(new Guard())->run();
