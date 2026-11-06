<?php
/*
 * LK Monitor - alertas: regras, avaliação (pelo coletor, a cada minuto), canais (Telegram e webhook de WhatsApp),
 * estado e histórico. Configuração em LKM_DATA/settings.json (0600, dono lkmonitor).
 */
declare(strict_types=1);

const AL_DEFAULTS = [
    'repeat' => 30,
    'rules' => [
        'temp' => ['on' => true, 'limit' => 80, 'minutes' => 3],
        'disk' => ['on' => true, 'limit' => 90],
        'ram' => ['on' => false, 'limit' => 90, 'minutes' => 5],
        'service' => ['on' => true],
        'site' => ['on' => true],
        'ssh' => ['on' => true, 'limit' => 20],
    ],
    'telegram' => ['on' => false, 'token' => '', 'chat' => ''],
    // mode: '' (automático), 'gowa' (WhatsApp deste servidor, via gowa.json) ou 'webhook' (gateway próprio)
    'whatsapp' => ['on' => false, 'mode' => '', 'numbers' => '', 'url' => '', 'method' => 'POST', 'headers' => '', 'body' => '{"phone": "{phone}", "message": "{message}"}', 'phone' => ''],
];
const AL_HIST_MAX = 100;

function al_dir(): string
{
    return defined('LKM_DATA') ? LKM_DATA : (getenv('LKM_DATA') ?: '/var/lib/lkmonitor');
}

function al_read(string $name, array $def = []): array
{
    $d = json_decode((string)@file_get_contents(al_dir() . "/$name"), true);
    return is_array($d) ? $d : $def;
}

function al_write(string $name, array $d): void
{
    $f = al_dir() . "/$name";
    $tmp = $f . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false) return;
    @chmod($tmp, 0600);
    @rename($tmp, $f);
}

/** Configuração atual (com os padrões para o que faltar). */
function al_settings(): array
{
    $s = al_read('settings.json');
    $out = AL_DEFAULTS;
    $out['repeat'] = (int)($s['repeat'] ?? $out['repeat']);
    foreach (AL_DEFAULTS['rules'] as $k => $r) $out['rules'][$k] = array_merge($r, array_intersect_key((array)($s['rules'][$k] ?? []), $r));
    foreach (['telegram', 'whatsapp'] as $c) $out[$c] = array_merge(AL_DEFAULTS[$c], array_intersect_key((array)($s[$c] ?? []), AL_DEFAULTS[$c]));
    return $out;
}

function al_mask(string $v): string
{
    $v = trim($v);
    if ($v === '') return '';
    return '••••' . (strlen($v) > 8 ? substr($v, -4) : '');
}

/** Cabeçalhos com os valores mascarados (podem ter tokens). */
function al_mask_headers(string $h): string
{
    $o = [];
    foreach (preg_split('/\r?\n/', trim($h)) as $l) {
        if (trim($l) === '') continue;
        $p = strpos($l, ':');
        $o[] = $p === false ? al_mask($l) : substr($l, 0, $p) . ': ' . al_mask(substr($l, $p + 1));
    }
    return implode("\n", $o);
}

/** Versão para o navegador: segredos nunca voltam, só mascarados. */
function al_public(array $s): array
{
    $s['telegram']['token_set'] = $s['telegram']['token'] !== '';
    $s['telegram']['token'] = al_mask($s['telegram']['token']);
    $s['whatsapp']['headers'] = al_mask_headers($s['whatsapp']['headers']);
    $s['whatsapp']['mode_eff'] = al_wa_mode($s['whatsapp']);
    return $s;
}

/* ------------------------------------------------------------------ WhatsApp deste servidor (GOWA) */

/** Configuração do serviço de WhatsApp local, criada pelo install-whatsapp.sh (LKM_DATA/gowa.json). */
function al_gowa(): ?array
{
    $g = al_read('gowa.json');
    $url = rtrim(trim((string)($g['url'] ?? '')), '/');
    if ($url === '' || !preg_match('#^https?://[^\s/]+(/[^\s]*)?$#i', $url)) return null;
    $dev = (string)($g['device'] ?? 'lk');
    if (!preg_match('/^[A-Za-z0-9_.@:-]{1,64}$/', $dev)) $dev = 'lk';
    $port = (string)($g['page_port'] ?? '');
    return ['url' => $url, 'user' => (string)($g['user'] ?? ''), 'pass' => (string)($g['pass'] ?? ''), 'device' => $dev,
        'page_port' => preg_match('/^\d{1,5}$/', $port) ? $port : ''];
}

/** O que o navegador pode saber do serviço local: nunca usuário nem senha. */
function al_gowa_public(): ?array
{
    $g = al_gowa();
    return $g ? ['device' => $g['device'], 'page_port' => $g['page_port']] : null;
}

/** Modo efetivo: quem já tinha webhook configurado continua nele; sem gowa.json, sempre webhook. */
function al_wa_mode(array $w): string
{
    if (!al_gowa()) return 'webhook';
    $m = (string)($w['mode'] ?? '');
    if ($m === 'gowa' || $m === 'webhook') return $m;
    return trim((string)($w['url'] ?? '')) !== '' ? 'webhook' : 'gowa';
}

/** Números de destino: aceita +, espaços, traços e parênteses; devolve [válidos, inválidos]. */
function al_numbers(string $s): array
{
    $ok = [];
    $bad = [];
    foreach (preg_split('/[,;\n]+/', $s) as $n) {
        $raw = trim($n);
        if ($raw === '') continue;
        $d = preg_replace('/[\s+().-]/', '', $raw);
        if (preg_match('/^\d{10,15}$/', $d)) $ok[] = $d;
        else $bad[] = $raw;
    }
    return [array_values(array_unique($ok)), $bad];
}

function al_gowa_headers(array $g): array
{
    $h = ['Accept: application/json', 'X-Device-Id: ' . $g['device']];
    if ($g['user'] !== '' || $g['pass'] !== '') $h[] = 'Authorization: Basic ' . base64_encode($g['user'] . ':' . $g['pass']);
    return $h;
}

/** Situação do número conectado: ok (o serviço respondeu), logged (true/false/null) e error em português. */
function al_gowa_status(): array
{
    $g = al_gowa();
    if (!$g) return ['ok' => false, 'logged' => null, 'error' => 'O WhatsApp deste servidor não está instalado.'];
    [$code, $body, $err] = al_http('GET', $g['url'] . '/devices/' . rawurlencode($g['device']) . '/status', al_gowa_headers($g), null, 6);
    if ($code === 0) return ['ok' => false, 'logged' => null, 'error' => al_gowa_down($err)];
    if ($code === 401 || $code === 403) return ['ok' => false, 'logged' => null, 'error' => 'O serviço do WhatsApp recusou o usuário/senha (confira o gowa.json).'];
    $d = json_decode($body, true);
    if ($code === 404) return ['ok' => true, 'logged' => false, 'error' => 'Aparelho "' . $g['device'] . '" não encontrado no serviço do WhatsApp.'];
    if ($code >= 500 || !is_array($d)) return ['ok' => false, 'logged' => null, 'error' => 'O serviço do WhatsApp respondeu com erro (HTTP ' . $code . ').'];
    $r = (array)($d['results'] ?? []);
    return ['ok' => true, 'logged' => !empty($r['is_logged_in']), 'connected' => !empty($r['is_connected']), 'error' => null];
}

function al_gowa_down(string $err): string
{
    if (stripos($err, 'timed out') !== false || stripos($err, 'timeout') !== false) return 'O serviço do WhatsApp não respondeu a tempo (travado ou sobrecarregado).';
    return 'O serviço do WhatsApp deste servidor está parado (sem resposta). Inicie-o de novo e tente outra vez.';
}

/** Traduz a resposta de erro do envio pelo serviço local. */
function al_gowa_err(int $code, string $body, string $err, string $num): string
{
    if ($code === 0) return al_gowa_down($err);
    if ($code === 401 || $code === 403) return 'o serviço do WhatsApp recusou o usuário/senha (confira o gowa.json)';
    $d = json_decode($body, true);
    $m = is_array($d) ? trim((string)($d['message'] ?? $d['error'] ?? '')) : trim(strip_tags($body));
    $low = strtolower($m . ' ' . (is_array($d) ? (string)($d['code'] ?? '') : ''));
    if (preg_match('/not (logged|login|connected)|logged ?out|unauthorized device|login first|scan|qr|session|disconnect/', $low)) {
        return 'número desconectado: abra a página do WhatsApp e leia o QR code';
    }
    if (preg_match('/not (registered|on whatsapp|exist)|invalid (phone|jid|number)|is not a whatsapp/', $low)) return "o número $num não tem WhatsApp ou é inválido";
    if (preg_match('/device.*not found|unknown device|device id/', $low)) return 'aparelho não encontrado no serviço do WhatsApp (confira o "device" do gowa.json)';
    return 'erro do serviço do WhatsApp (HTTP ' . $code . ')' . ($m !== '' ? ': ' . mb_substr($m, 0, 160) : '');
}

/** Envia pelo serviço local para cada número. true se todos receberam; senão, a mensagem de erro. */
function al_send_gowa(array $w, string $text): string|bool
{
    $g = al_gowa();
    if (!$g) return 'WhatsApp: o WhatsApp deste servidor não está instalado.';
    [$nums] = al_numbers((string)$w['numbers']);
    if (!$nums) return 'WhatsApp: informe pelo menos um número de destino.';
    $fail = [];
    foreach ($nums as $n) {
        [$code, $body, $err] = al_http('POST', $g['url'] . '/send/message', array_merge(al_gowa_headers($g), ['Content-Type: application/json']),
            json_encode(['phone' => $n . '@s.whatsapp.net', 'message' => $text], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 15);
        $d = json_decode($body, true);
        $okCode = !is_array($d) || !isset($d['code']) || in_array(strtoupper((string)$d['code']), ['SUCCESS', '200', 'OK'], true);
        if ($code >= 200 && $code < 300 && $okCode) continue;
        $e = al_gowa_err($code, $body, $err, $n);
        // sem detalhe claro: confere se o número está conectado
        if ($code !== 0 && !str_starts_with($e, 'número desconectado') && ($st = al_gowa_status()) && $st['ok'] && $st['logged'] === false) {
            $e = 'número desconectado: abra a página do WhatsApp e leia o QR code';
        }
        if ($code === 0 || str_starts_with($e, 'número desconectado') || str_contains($e, 'usuário/senha')) return 'WhatsApp: ' . $e;
        $fail[] = (count($nums) > 1 ? "$n: " : '') . $e;
    }
    return $fail ? 'WhatsApp: ' . implode('; ', $fail) : true;
}

/** Junta o que veio da tela com o salvo: segredo vazio ou ainda mascarado mantém o valor antigo. */
function al_merge(array $in, array $old): array
{
    $n = $old;
    $n['repeat'] = max(5, min(1440, (int)($in['repeat'] ?? $old['repeat'])));
    foreach (AL_DEFAULTS['rules'] as $k => $r) {
        if (!isset($in['rules'][$k])) continue;
        $x = (array)$in['rules'][$k];
        $n['rules'][$k]['on'] = !empty($x['on']);
        if (isset($r['limit'])) $n['rules'][$k]['limit'] = max(1, min($k === 'ssh' ? 100000 : ($k === 'temp' ? 150 : 100), (int)($x['limit'] ?? $old['rules'][$k]['limit'])));
        if (isset($r['minutes'])) $n['rules'][$k]['minutes'] = max(1, min(120, (int)($x['minutes'] ?? $old['rules'][$k]['minutes'])));
    }
    $t = (array)($in['telegram'] ?? []);
    $n['telegram']['on'] = array_key_exists('on', $t) ? !empty($t['on']) : $old['telegram']['on'];
    $n['telegram']['chat'] = trim((string)($t['chat'] ?? $old['telegram']['chat']));
    $tok = trim((string)($t['token'] ?? ''));
    if ($tok !== '' && !str_starts_with($tok, '••••')) $n['telegram']['token'] = $tok;
    if (!empty($t['clear_token'])) $n['telegram']['token'] = '';
    $w = (array)($in['whatsapp'] ?? []);
    $n['whatsapp']['on'] = array_key_exists('on', $w) ? !empty($w['on']) : $old['whatsapp']['on'];
    if (in_array($w['mode'] ?? null, ['gowa', 'webhook'], true)) $n['whatsapp']['mode'] = $w['mode'];
    if (array_key_exists('numbers', $w)) {
        [$ok, $bad] = al_numbers((string)$w['numbers']);
        $n['whatsapp']['numbers'] = $bad ? trim((string)$w['numbers']) : implode(', ', $ok);
    }
    foreach (['url', 'body', 'phone'] as $k) $n['whatsapp'][$k] = trim((string)($w[$k] ?? $old['whatsapp'][$k]));
    $n['whatsapp']['method'] = in_array(strtoupper((string)($w['method'] ?? $old['whatsapp']['method'])), ['POST', 'GET', 'PUT'], true) ? strtoupper((string)($w['method'] ?? $old['whatsapp']['method'])) : 'POST';
    $h = trim((string)($w['headers'] ?? ''));
    if (array_key_exists('headers', $w) && $h !== al_mask_headers($old['whatsapp']['headers'])) {
        // linha mascarada que não mudou mantém o valor antigo daquele cabeçalho
        $oldH = [];
        foreach (preg_split('/\r?\n/', $old['whatsapp']['headers']) as $l) if (($p = strpos($l, ':')) !== false) $oldH[strtolower(trim(substr($l, 0, $p)))] = $l;
        $lines = [];
        foreach (preg_split('/\r?\n/', $h) as $l) {
            if (trim($l) === '') continue;
            $p = strpos($l, ':');
            $name = $p === false ? '' : strtolower(trim(substr($l, 0, $p)));
            $lines[] = $p !== false && str_starts_with(trim(substr($l, $p + 1)), '••••') && isset($oldH[$name]) ? $oldH[$name] : $l;
        }
        $n['whatsapp']['headers'] = implode("\n", $lines);
    }
    return $n;
}

function al_validate(array $s): ?string
{
    if ($s['telegram']['on'] && ($s['telegram']['token'] === '' || $s['telegram']['chat'] === '')) return 'Telegram: informe o token do bot e o chat id.';
    if ($s['telegram']['token'] !== '' && !preg_match('/^\d+:[A-Za-z0-9_-]{20,}$/', $s['telegram']['token'])) return 'Telegram: o token do bot parece inválido (formato 123456:ABC...).';
    $w = $s['whatsapp'];
    if (al_wa_mode($w) === 'gowa') {
        [$ok, $bad] = al_numbers($w['numbers']);
        if ($bad) return 'WhatsApp: número inválido: "' . $bad[0] . '". Use só dígitos com código do país e DDD (ex.: 5511999998888).';
        if ($w['on'] && !$ok) return 'WhatsApp: informe pelo menos um número de destino.';
        return null;
    }
    if ($w['url'] !== '' && !preg_match('#^https?://[^\s]+$#i', $w['url'])) return 'WhatsApp: a URL precisa começar com http:// ou https://';
    if ($w['on'] && ($w['url'] === '' || $w['phone'] === '')) return 'WhatsApp: informe a URL do gateway e o número de destino.';
    foreach (preg_split('/\r?\n/', trim($w['headers'])) as $l) if (trim($l) !== '' && !preg_match('/^[A-Za-z0-9-]+\s*:/', $l)) return 'WhatsApp: cabeçalho inválido: "' . $l . '" (use Nome: valor)';
    if ($w['method'] !== 'GET' && $w['body'] !== '' && str_starts_with(ltrim($w['body']), '{')) {
        $probe = str_replace(['{phone}', '{message}'], ['x', 'y'], $w['body']);
        if (json_decode($probe) === null) return 'WhatsApp: o corpo não é um JSON válido.';
    }
    return null;
}

/* ------------------------------------------------------------------ envio */

function al_http(string $method, string $url, array $headers, ?string $body, int $timeout = 10): array
{
    if (function_exists('curl_init')) {
        $c = curl_init($url);
        curl_setopt_array($c, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_HTTPHEADER => $headers, CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => 'LK-Monitor']);
        if ($body !== null && $method !== 'GET') curl_setopt($c, CURLOPT_POSTFIELDS, $body);
        $out = curl_exec($c);
        $code = (int)curl_getinfo($c, CURLINFO_RESPONSE_CODE);
        $err = curl_error($c);
        curl_close($c);
        return [$code, is_string($out) ? $out : '', $err];
    }
    $ctx = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", array_merge($headers, ['User-Agent: LK-Monitor'])), 'content' => $method !== 'GET' ? (string)$body : '',
        'timeout' => $timeout, 'ignore_errors' => true, 'follow_location' => 0]]);
    $out = @file_get_contents($url, false, $ctx);
    $code = 0;
    foreach ($http_response_header ?? [] as $h) if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $code = (int)$m[1];
    return [$code, is_string($out) ? $out : '', $out === false && !$code ? 'sem resposta' : ''];
}

function al_send_telegram(array $t, string $text): string|bool
{
    [$code, $body, $err] = al_http('POST', 'https://api.telegram.org/bot' . $t['token'] . '/sendMessage', ['Content-Type: application/json'],
        json_encode(['chat_id' => $t['chat'], 'text' => $text, 'disable_web_page_preview' => true], JSON_UNESCAPED_UNICODE));
    if ($code === 200) return true;
    $d = json_decode($body, true);
    return 'Telegram' . ($code ? " $code" : '') . ': ' . (is_array($d) && isset($d['description']) ? $d['description'] : ($err ?: 'falha no envio'));
}

function al_send_whatsapp(array $w, string $text): string|bool
{
    if (al_wa_mode($w) === 'gowa') return al_send_gowa($w, $text);
    $isJson = str_starts_with(ltrim($w['body']), '{') || str_starts_with(ltrim($w['body']), '[');
    $esc = fn(string $v) => $isJson ? substr(json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 1, -1) : $v;
    $url = str_replace(['{phone}', '{message}'], [rawurlencode($w['phone']), rawurlencode($text)], $w['url']);
    $headers = [];
    $hasCt = false;
    foreach (preg_split('/\r?\n/', trim($w['headers'])) as $l) {
        if (trim($l) === '') continue;
        $headers[] = trim($l);
        if (stripos(ltrim($l), 'content-type:') === 0) $hasCt = true;
    }
    $body = null;
    if ($w['method'] !== 'GET') {
        $body = str_replace(['{phone}', '{message}'], [$esc($w['phone']), $esc($text)], $w['body']);
        if (!$hasCt) $headers[] = 'Content-Type: ' . ($isJson ? 'application/json' : 'application/x-www-form-urlencoded');
    }
    [$code, $resp, $err] = al_http($w['method'], $url, $headers, $body);
    if ($code >= 200 && $code < 300) return true;
    return 'WhatsApp' . ($code ? " $code" : '') . ': ' . ($err ?: mb_substr(trim(strip_tags($resp)), 0, 160) ?: 'falha no envio');
}

/** Envia para os canais ativos (ou só os pedidos). Devolve canal => true | mensagem de erro. */
function al_send(string $text, array $s, ?array $only = null): array
{
    $r = [];
    foreach (['telegram', 'whatsapp'] as $c) {
        if ($only !== null ? !in_array($c, $only, true) : empty($s[$c]['on'])) continue;
        try {
            $r[$c] = $c === 'telegram' ? al_send_telegram($s[$c], $text) : al_send_whatsapp($s[$c], $text);
        } catch (Throwable $e) {
            $r[$c] = $e->getMessage();
        }
    }
    return $r;
}

/* ------------------------------------------------------------------ verificações */

function al_services(): array
{
    $c = ['apache2', 'mariadb', 'mysql', 'ssh', 'sshd', 'supervisor'];
    foreach (glob('/etc/php/*/fpm', GLOB_ONLYDIR) ?: [] as $d) $c[] = 'php' . basename(dirname($d)) . '-fpm';
    $have = [];
    foreach ($c as $s) {
        foreach (['/usr/lib/systemd/system', '/lib/systemd/system', '/etc/systemd/system'] as $dir) {
            if (is_file("$dir/$s.service") && !is_link("$dir/$s.service")) { $have[$s] = 1; break; }
        }
    }
    if (isset($have['mariadb'])) unset($have['mysql']);
    if (isset($have['ssh'])) unset($have['sshd']);
    $names = array_keys($have);
    if (!$names) return [];
    $st = explode("\n", trim((string)shell_exec('systemctl is-active ' . implode(' ', array_map('escapeshellarg', $names)) . ' 2>/dev/null')));
    $out = [];
    foreach ($names as $i => $n) $out[$n] = $st[$i] ?? 'unknown';
    return $out;
}

/** Domínios dos sites ligados no Apache (porta 80). */
function al_sites(): array
{
    $out = [];
    foreach (glob('/etc/apache2/sites-enabled/*.conf') ?: [] as $f) {
        $n = basename($f, '.conf');
        if (in_array($n, ['000-default', 'default-ssl', 'lkmonitor'], true) || str_ends_with($n, '-le-ssl')) continue;
        if (preg_match('/^\s*ServerName\s+(\S+)/mi', (string)@file_get_contents($f), $m)) $out[] = strtolower($m[1]);
    }
    return array_values(array_unique($out));
}

/** Testa os sites em paralelo em 127.0.0.1 com o Host de cada um: domínio => código HTTP (0 = sem resposta). */
function al_check_sites(array $doms): array
{
    $r = [];
    if (!$doms) return $r;
    if (function_exists('curl_multi_init')) {
        $mh = curl_multi_init();
        $hs = [];
        foreach ($doms as $d) {
            $c = curl_init('http://127.0.0.1/');
            curl_setopt_array($c, [CURLOPT_HTTPHEADER => ["Host: $d"], CURLOPT_RETURNTRANSFER => true, CURLOPT_NOBODY => false, CURLOPT_TIMEOUT => 8,
                CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => 'LK-Monitor (verificação)', CURLOPT_RANGE => '0-1023']);
            curl_multi_add_handle($mh, $c);
            $hs[$d] = $c;
        }
        do {
            $st = curl_multi_exec($mh, $running);
            if ($running) curl_multi_select($mh, 1.0);
        } while ($running && $st === CURLM_OK);
        foreach ($hs as $d => $c) {
            $r[$d] = (int)curl_getinfo($c, CURLINFO_RESPONSE_CODE);
            curl_multi_remove_handle($mh, $c);
            curl_close($c);
        }
        curl_multi_close($mh);
        return $r;
    }
    foreach ($doms as $d) {
        [$code] = al_http('GET', 'http://127.0.0.1/', ["Host: $d"], null, 5);
        $r[$d] = $code;
    }
    return $r;
}

/** Falhas de login no SSH nos últimos 10 minutos (journal; se não der, /var/log/auth.log). */
function al_ssh_failures(): ?int
{
    $re = '/Failed (password|publickey|keyboard-interactive\S*|none) for /';
    exec("journalctl -q --no-pager -o cat --since '-10min' SYSLOG_IDENTIFIER=sshd SYSLOG_IDENTIFIER=sshd-session 2>/dev/null", $lines, $rc);
    if ($rc === 0) return preg_match_all($re, implode("
", $lines));
    $f = '/var/log/auth.log';
    if (!is_readable($f)) return null;
    $h = fopen($f, 'rb');
    $size = filesize($f);
    fseek($h, max(0, $size - 2 * 1024 * 1024));
    $lines = explode("\n", (string)fread($h, 2 * 1024 * 1024));
    fclose($h);
    $since = time() - 600;
    $n = 0;
    foreach ($lines as $l) {
        if (!preg_match($re, $l)) continue;
        $t = preg_match('/^(\d{4}-\d\d-\d\dT[\d:.]+[+-]\d\d:?\d\d)/', $l, $m) ? strtotime($m[1]) : strtotime(substr($l, 0, 15));
        if ($t && $t >= $since) $n++;
    }
    return $n;
}

/* ------------------------------------------------------------------ avaliação (coletor) */

function al_history(int $n = AL_HIST_MAX): array
{
    return array_slice(al_read('alerts.json'), 0, $n);
}

function al_log(array $ev): void
{
    $h = al_read('alerts.json');
    array_unshift($h, $ev);
    al_write('alerts.json', array_slice($h, 0, AL_HIST_MAX));
}

/**
 * Avalia as regras. $m = métricas atuais do coletor; $db = SQLite do histórico (para "por Y minutos").
 * Dispara quando a condição começa, repete a cada N minutos enquanto continuar e avisa quando volta ao normal.
 */
function al_eval(array $m, ?PDO $db): array
{
    $s = al_settings();
    $R = $s['rules'];
    $state = al_read('alerts.state.json');
    $now = time();
    $host = gethostname();
    $checks = []; // chave => [ativo?, mensagem de alerta, mensagem de normal, precisa de N falhas seguidas]

    $window = function (string $expr, int $min) use ($db, $now): ?array {
        if (!$db) return null;
        try {
            $st = $db->query("SELECT $expr FROM m WHERE ts > " . ($now - $min * 60 - 20) . ' ORDER BY ts');
            return array_map(fn($v) => $v === null ? null : (float)$v, $st->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) { return null; }
    };

    if ($R['temp']['on'] && $m['temp'] !== null) {
        $l = $R['temp']['limit'];
        $mn = $R['temp']['minutes'];
        $v = array_filter($window('temp', $mn) ?? [], fn($x) => $x !== null);
        $hot = count($v) >= max(1, $mn - 1) && min($v) > $l;
        $was = !empty($state['temp']['active']);
        $checks['temp'] = [$hot || ($was && $m['temp'] > $l - 3), "🔥 Temperatura da CPU em {$m['temp']} °C (acima de $l °C há $mn min)", "✅ Temperatura da CPU normalizou: {$m['temp']} °C", 1];
    }
    if ($R['ram']['on'] && $m['mem_pct'] !== null) {
        $l = $R['ram']['limit'];
        $mn = $R['ram']['minutes'];
        $v = array_filter($window('CASE WHEN mem_total > 0 THEN mem * 100.0 / mem_total END', $mn) ?? [], fn($x) => $x !== null);
        $high = count($v) >= max(1, $mn - 1) && min($v) > $l;
        $was = !empty($state['ram']['active']);
        $p = round($m['mem_pct']);
        $checks['ram'] = [$high || ($was && $m['mem_pct'] > $l - 2), "🧠 Memória RAM em $p% há $mn min (limite $l%)", "✅ Memória RAM normalizou: $p%", 1];
    }
    if ($R['disk']['on']) {
        $l = $R['disk']['limit'];
        foreach ($m['disks'] as $d) {
            if (!$d['total']) continue;
            $p = round($d['used'] / $d['total'] * 100);
            $k = 'disk:' . $d['mnt'];
            $was = !empty($state[$k]['active']);
            $checks[$k] = [$p > $l || ($was && $p > $l - 2), "💾 Disco {$d['mnt']} com $p% de uso (limite $l%)", "✅ Disco {$d['mnt']} voltou para $p%", 1];
        }
    }
    if ($R['service']['on']) {
        foreach (al_services() as $n => $st) {
            $checks['svc:' . $n] = [$st !== 'active', "🛑 Serviço $n parado ($st)", "✅ Serviço $n voltou a rodar", 2];
        }
    }
    if ($R['site']['on']) {
        foreach (al_check_sites(al_sites()) as $d => $code) {
            $bad = $code === 0 || $code >= 500;
            $checks['site:' . $d] = [$bad, "🌐 Site $d fora do ar (" . ($code ? "HTTP $code" : 'sem resposta') . ')', "✅ Site $d voltou (HTTP $code)", 2];
        }
    }
    if ($R['ssh']['on']) {
        $n = al_ssh_failures();
        if ($n !== null) {
            $l = $R['ssh']['limit'];
            $checks['ssh'] = [$n > $l, "🔐 $n falhas de login no SSH nos últimos 10 min (limite $l)", "✅ Falhas de login no SSH voltaram ao normal ($n em 10 min)", 1];
        }
    }

    $events = [];
    $newState = [];
    foreach ($checks as $k => [$bad, $msgA, $msgN, $need]) {
        $st = $state[$k] ?? ['active' => false, 'fails' => 0];
        $st['fails'] = $bad ? (int)($st['fails'] ?? 0) + 1 : 0;
        if ($bad && $st['fails'] >= $need) {
            if (empty($st['active'])) {
                $st = ['active' => true, 'since' => $now, 'sent' => $now, 'fails' => $st['fails']];
                $events[] = ['kind' => 'alert', 'key' => $k, 'msg' => $msgA];
            } elseif ($now - (int)($st['sent'] ?? 0) >= $s['repeat'] * 60 - 30) {
                $st['sent'] = $now;
                $events[] = ['kind' => 'repeat', 'key' => $k, 'msg' => '⏰ Continua: ' . $msgA . ' · desde ' . date('H:i', (int)$st['since'])];
            }
        } elseif (!$bad && !empty($st['active'])) {
            $events[] = ['kind' => 'ok', 'key' => $k, 'msg' => $msgN . ' · durou ' . al_dur($now - (int)$st['since'])];
            $st = ['active' => false, 'fails' => 0];
        }
        $newState[$k] = $st;
    }
    // regra desligada ou item que sumiu: esquece sem avisar
    al_write('alerts.state.json', $newState);

    foreach ($events as $ev) {
        $text = '[' . $host . '] ' . $ev['msg'];
        $sent = al_send($text, $s);
        al_log(['t' => $now, 'kind' => $ev['kind'], 'key' => $ev['key'], 'msg' => $ev['msg'], 'sent' => $sent]);
    }
    return $events;
}

function al_dur(int $s): string
{
    if ($s < 3600) return max(1, intdiv($s, 60)) . ' min';
    if ($s < 86400) return intdiv($s, 3600) . ' h ' . intdiv($s % 3600, 60) . ' min';
    return intdiv($s, 86400) . ' d ' . intdiv($s % 86400, 3600) . ' h';
}

/** Situação atual para a tela (alertas ativos). */
function al_active(): array
{
    $out = [];
    foreach (al_read('alerts.state.json') as $k => $st) if (!empty($st['active'])) $out[] = ['key' => $k, 'since' => $st['since'] ?? null];
    return $out;
}
