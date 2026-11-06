<?php
/* WhatsApp · LK — página para conectar o GOWA e enviar mensagens e mídia */
declare(strict_types=1);
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
ini_set('display_errors', '0');
require __DIR__ . '/config.php';
$tz = trim((string)@file_get_contents('/etc/timezone'));
if ($tz === '' && is_link('/etc/localtime')) $tz = (string)preg_replace('#^.*/zoneinfo/#', '', (string)readlink('/etc/localtime'));
if ($tz !== '') @date_default_timezone_set($tz);

/* Tipos de mídia: endpoint do GOWA, campo do formulário, limite (MB) e formatos aceitos */
const MEDIA = [
    'image' => ['path' => '/send/image', 'field' => 'image', 'max' => 20, 'label' => 'Imagem', 'fmt' => 'JPG ou PNG',
        'mimes' => ['image/jpeg', 'image/png'], 'ext' => [], 'cap' => true, 'opts' => ['view_once', 'compress']],
    'video' => ['path' => '/send/video', 'field' => 'video', 'max' => 99, 'label' => 'Vídeo', 'fmt' => 'MP4, MKV ou AVI',
        'mimes' => ['video/mp4', 'video/x-matroska', 'video/avi', 'video/x-msvideo'],
        'ext' => ['mp4' => 'video/mp4', 'mkv' => 'video/x-matroska', 'avi' => 'video/x-msvideo'], 'cap' => true, 'opts' => ['view_once', 'compress', 'gif_playback']],
    'audio' => ['path' => '/send/audio', 'field' => 'audio', 'max' => 50, 'label' => 'Áudio', 'fmt' => 'MP3, M4A, OGG, WAV, AAC, FLAC ou AMR',
        'mimes' => ['audio/aac', 'audio/amr', 'audio/flac', 'audio/m4a', 'audio/mp3', 'audio/mpeg', 'audio/ogg', 'audio/wav', 'audio/x-wav', 'audio/wave', 'audio/x-ms-wma'],
        'ext' => ['mp3' => 'audio/mpeg', 'm4a' => 'audio/m4a', 'aac' => 'audio/aac', 'ogg' => 'audio/ogg', 'oga' => 'audio/ogg', 'opus' => 'audio/ogg',
            'wav' => 'audio/wav', 'flac' => 'audio/flac', 'amr' => 'audio/amr', 'wma' => 'audio/x-ms-wma'], 'cap' => false, 'opts' => ['ptt']],
    'file' => ['path' => '/send/file', 'field' => 'file', 'max' => 50, 'label' => 'Arquivo', 'fmt' => 'qualquer formato',
        'mimes' => null, 'ext' => [], 'cap' => true, 'opts' => []],
    'sticker' => ['path' => '/send/sticker', 'field' => 'sticker', 'max' => 20, 'label' => 'Figurinha', 'fmt' => 'JPG, PNG, WebP ou GIF',
        'mimes' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], 'ext' => [], 'cap' => false, 'opts' => []],
];
const KIND_LABEL = ['text' => 'Texto', 'image' => 'Imagem', 'video' => 'Vídeo', 'audio' => 'Áudio', 'file' => 'Arquivo', 'sticker' => 'Figurinha',
    'location' => 'Local', 'contact' => 'Contato', 'link' => 'Link', 'poll' => 'Enquete'];

session_name('lkwhats');
session_set_cookie_params(['lifetime' => 0, 'httponly' => true, 'samesite' => 'Strict']);
session_start();
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

if (isset($_GET['sair'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: ./');
    exit;
}

if (empty($_SESSION['ok'])) {
    $err = '';
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['senha'])) {
        if (password_verify((string)$_POST['senha'], WA_PASS_HASH)) {
            session_regenerate_id(true);
            $_SESSION['ok'] = 1;
            header('Location: ./');
            exit;
        }
        sleep(2);
        $err = 'Senha incorreta';
    }
    if (isset($_GET['a'])) out(['ok' => false, 'error' => 'Sessão expirada. Recarregue a página.'], 401);
    page_login($err);
    exit;
}
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

if (isset($_GET['a'])) {
    $tok = (string)($_SERVER['HTTP_X_TOKEN'] ?? '');
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !hash_equals($_SESSION['csrf'], $tok)) {
        out(['ok' => false, 'error' => 'Sessão expirada. Recarregue a página.'], 403);
    }
    if (str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'multipart/form-data')) {
        if (!$_POST && !$_FILES && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            out(['ok' => false, 'error' => 'Arquivo grande demais para esta página (máximo ' . ini_mb('post_max_size') . ' MB).']);
        }
        $in = $_POST;
    } else {
        $in = json_decode((string)file_get_contents('php://input'), true);
    }
    session_write_close_later();
    action((string)$_GET['a'], is_array($in) ? $in : []);
}
page_app();

/* ------------------------------------------------------------------ utilidades */

function out(array $d, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function fail(string $msg): never
{
    out(['ok' => false, 'error' => $msg]);
}

/** Não segura a sessão durante envios longos (o histórico é gravado reabrindo a sessão). */
function session_write_close_later(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
}

function ini_mb(string $key): int
{
    $v = trim((string)ini_get($key));
    $n = (float)$v;
    $u = strtoupper(substr($v, -1));
    $b = match ($u) { 'G' => $n * 1073741824, 'M' => $n * 1048576, 'K' => $n * 1024, default => $n };
    return (int)floor($b / 1048576);
}

/** Chama a API do GOWA. Retorna [status HTTP, corpo decodificado (array) ou bruto (string)]. */
function gw(string $method, string $path, array $query = [], ?array $body = null, bool $raw = false, ?array $form = null, int $timeout = 40): array
{
    $url = rtrim(WA_GOWA_URL, '/') . $path . ($query ? '?' . http_build_query($query) : '');
    $ch = curl_init($url);
    $headers = ['Accept: application/json', 'X-Device-Id: ' . WA_DEVICE, 'Expect:'];
    if ($form !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $form);
    } elseif ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERPWD => WA_GOWA_USER . ':' . WA_GOWA_PASS,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => $timeout,
    ]);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $errno = curl_errno($ch);
    if ($errno === CURLE_OPERATION_TIMEDOUT) fail('O envio demorou demais. Tente um arquivo menor ou verifique a internet do servidor.');
    if ($errno || $res === false) fail('O serviço do WhatsApp não está respondendo. No servidor, rode: systemctl restart gowa');
    if ($raw) return [$code, (string)$res];
    $d = json_decode((string)$res, true);
    return [$code, is_array($d) ? $d : ['message' => trim((string)$res)]];
}

/** Traduz as mensagens de erro mais comuns do GOWA. */
function gw_error(array $d, string $fallback): string
{
    $m = trim((string)($d['message'] ?? ''));
    if ($m === '') return $fallback;
    $map = [
        '/not log|logged out|not connected|no active session/i' => 'O número não está conectado. Conecte de novo na coluna ao lado.',
        '/ffmpeg not installed/i' => 'Falta o ffmpeg no servidor. Rode de novo: sudo bash /root/install-whatsapp.sh',
        '/max (file|video|image) (upload|download|size)|too large|exceeds/i' => 'Arquivo grande demais para o WhatsApp.',
        '/(image|video|audio|sticker).* (is )?not allowed/i' => 'Formato não aceito para este tipo de envio.',
        '/must be a valid url|invalid url/i' => 'Link inválido.',
        '/(failed|error).*(download|fetch)|download.*(failed|error)|status code [45]\d\d/i' => 'Não foi possível baixar o arquivo do link. Confira se o link abre direto o arquivo.',
    ];
    foreach ($map as $re => $pt) {
        if (preg_match($re, $m)) return $pt;
    }
    return $m;
}

function call(string $path, array $body): array
{
    [$code, $d] = gw('POST', $path, [], $body);
    if ($code >= 400) fail(gw_error($d, 'Falha ao enviar'));
    return $d;
}

/** Garante que o device existe no GOWA (cria se faltar). Devolve os dados dele. */
function ensure_device(): array
{
    [$code, $d] = gw('GET', '/devices');
    if ($code >= 400) fail(gw_error($d, 'Falha ao listar os aparelhos do serviço'));
    foreach ((array)($d['results'] ?? []) as $dev) {
        if (is_array($dev) && ($dev['id'] ?? '') === WA_DEVICE) return $dev;
    }
    [$code, $d] = gw('POST', '/devices', [], ['device_id' => WA_DEVICE]);
    if ($code >= 400) fail(gw_error($d, 'Falha ao preparar o aparelho no serviço'));
    return (array)($d['results'] ?? []);
}

function jid_of(string $to): string
{
    $to = trim($to);
    if ($to === '') fail('Digite o número de destino');
    if (str_contains($to, '@')) {
        if (!preg_match('/^[0-9A-Za-z._:-]+@(s\.whatsapp\.net|g\.us|lid|newsletter)$/', $to)) fail('Destino inválido');
        return $to;
    }
    $d = preg_replace('/\D+/', '', $to);
    if (strlen($d) < 8 || strlen($d) > 15) fail('Número inválido. Use o código do país + número, só dígitos (ex.: 50761234567).');
    return $d . '@s.whatsapp.net';
}

function valid_url(string $u): string
{
    $u = str_replace(' ', '%20', trim($u));
    if ($u === '') fail('Cole o link (URL)');
    if (!preg_match('#^https?://#i', $u) || !filter_var($u, FILTER_VALIDATE_URL)) fail('Link inválido. Use um endereço que comece com http:// ou https://');
    if (strlen($u) > 2000) fail('Link longo demais');
    return $u;
}

function text_in(array $in, string $key, int $max, string $name, bool $required = false): string
{
    $v = trim((string)($in[$key] ?? ''));
    if ($required && $v === '') fail('Preencha: ' . $name);
    if (mb_strlen($v) > $max) fail($name . ' muito longo (máximo ' . $max . ' caracteres)');
    return $v;
}

/** Lê coordenadas de "lat, lng" ou de um link do Google Maps. */
function coords(string $s): array
{
    $s = trim(rawurldecode($s));
    $num = '(-?\d{1,3}(?:\.\d+)?)';
    if (preg_match('/!3d' . $num . '!4d' . $num . '/', $s, $m)
        || preg_match('/(?<![\d.])' . $num . '\s*,\s*' . $num . '(?![\d.])/', $s, $m)) {
        if (abs((float)$m[1]) <= 90 && abs((float)$m[2]) <= 180) return [$m[1], $m[2]];
    }
    fail('Coordenadas inválidas. Exemplo: 8.9824, -79.5199 (no Google Maps, clique com o botão direito no local e copie os números).');
}

/** Descobre o tipo do arquivo enviado e confere se o GOWA aceita para este tipo de envio. */
function pick_mime(array $K, string $tmp, string $name, string $browser): ?string
{
    $fi = '';
    if (function_exists('finfo_open')) {
        $h = finfo_open(FILEINFO_MIME_TYPE);
        $fi = $h ? (string)finfo_file($h, $tmp) : '';
    }
    if ($fi === 'image/jpg') $fi = 'image/jpeg';
    if ($K['mimes'] === null) {
        if ($fi === '' || $fi === 'application/octet-stream') $fi = $browser !== '' ? $browser : 'application/octet-stream';
        return preg_match('#^[\w.+-]+/[\w.+-]+$#', $fi) ? $fi : 'application/octet-stream';
    }
    if (in_array($fi, $K['mimes'], true)) return $fi;
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $generic = $fi === '' || $fi === 'application/octet-stream' || str_starts_with($fi, 'audio/') || str_starts_with($fi, 'video/');
    return $generic ? ($K['ext'][$ext] ?? null) : null;
}

function add_hist(string $jid, string $kind, string $summary): array
{
    $item = ['to' => (string)preg_replace('/@s\.whatsapp\.net$/', '', $jid), 'kind' => $kind, 'msg' => mb_substr($summary, 0, 140), 'at' => date('H:i')];
    @session_start();
    $_SESSION['hist'] = array_slice(array_merge([$item], (array)($_SESSION['hist'] ?? [])), 0, 30);
    session_write_close();
    return $item;
}

/* ------------------------------------------------------------------ ações */

function action(string $a, array $in): never
{
    $dev = '/devices/' . rawurlencode(WA_DEVICE);
    switch ($a) {
        case 'status':
            $info = ensure_device();
            [$code, $d] = gw('GET', $dev . '/status');
            if ($code >= 400) fail(gw_error($d, 'Falha ao ler o status'));
            $r = (array)($d['results'] ?? []);
            $jid = (string)($info['jid'] ?? '');
            if ($jid === '' && !empty($info['phone_number'])) $jid = (string)$info['phone_number'];
            out(['ok' => true, 'logged' => !empty($r['is_logged_in']), 'connected' => !empty($r['is_connected']),
                'name' => (string)($info['display_name'] ?? ''), 'phone' => $jid !== '' ? preg_replace('/[:@].*$/', '', $jid) : '']);

        case 'qr':
            ensure_device();
            [$code, $d] = gw('GET', $dev . '/login');
            if ($code >= 400) fail(gw_error($d, 'Não foi possível gerar o QR code'));
            $link = (string)($d['results']['qr_link'] ?? '');
            $p = parse_url($link, PHP_URL_PATH);
            if (!$p) fail('Resposta sem QR code');
            [$ic, $img] = gw('GET', $p, [], null, true);
            if ($ic >= 400 || $img === '') fail('Não foi possível baixar a imagem do QR code');
            out(['ok' => true, 'img' => 'data:image/png;base64,' . base64_encode($img), 'duration' => (int)($d['results']['qr_duration'] ?? 30)]);

        case 'pair':
            ensure_device();
            $phone = preg_replace('/\D+/', '', (string)($in['phone'] ?? ''));
            if (strlen($phone) < 8 || strlen($phone) > 15) fail('Digite o número com código do país, só dígitos (ex.: 50761234567).');
            [$code, $d] = gw('POST', $dev . '/login/code', ['phone' => $phone]);
            if ($code >= 400) fail(gw_error($d, 'Não foi possível gerar o código'));
            $pc = (string)($d['results']['pair_code'] ?? '');
            if ($pc === '') fail('O serviço não devolveu o código. Tente de novo.');
            out(['ok' => true, 'code' => $pc]);

        case 'send':
            $type = (string)($in['type'] ?? 'text');
            $jid = jid_of((string)($in['to'] ?? ''));
            switch ($type) {
                case 'text':
                    $msg = text_in($in, 'message', 4000, 'Mensagem', true);
                    call('/send/message', ['phone' => $jid, 'message' => $msg]);
                    $sum = $msg;
                    break;
                case 'location':
                    [$lat, $lng] = coords((string)($in['coords'] ?? ''));
                    call('/send/location', ['phone' => $jid, 'latitude' => $lat, 'longitude' => $lng]);
                    $sum = $lat . ', ' . $lng;
                    break;
                case 'contact':
                    $name = text_in($in, 'name', 100, 'Nome do contato', true);
                    $cp = preg_replace('/\D+/', '', (string)($in['phone'] ?? ''));
                    if (strlen($cp) < 6 || strlen($cp) > 15) fail('Telefone do contato inválido. Use código do país + número (ex.: 50761234567).');
                    call('/send/contact', ['phone' => $jid, 'contact_name' => $name, 'contact_phone' => $cp]);
                    $sum = $name . ' · +' . $cp;
                    break;
                case 'link':
                    $url = valid_url((string)($in['url'] ?? ''));
                    $cap = text_in($in, 'caption', 3000, 'Texto');
                    call('/send/link', ['phone' => $jid, 'link' => $url, 'caption' => $cap]);
                    $sum = $url;
                    break;
                case 'poll':
                    $q = text_in($in, 'question', 255, 'Pergunta', true);
                    $opts = [];
                    foreach ((array)($in['options'] ?? []) as $o) {
                        $o = trim((string)$o);
                        if ($o === '') continue;
                        if (mb_strlen($o) > 100) fail('Opção muito longa (máximo 100 caracteres)');
                        if (in_array(mb_strtolower($o), array_map('mb_strtolower', $opts), true)) fail('Opção repetida: ' . $o);
                        $opts[] = $o;
                    }
                    if (count($opts) < 2) fail('A enquete precisa de pelo menos 2 opções (uma por linha)');
                    if (count($opts) > 12) fail('Máximo de 12 opções');
                    $max = max(1, min(count($opts), (int)($in['max'] ?? 1)));
                    call('/send/poll', ['phone' => $jid, 'question' => $q, 'options' => $opts, 'max_answer' => $max]);
                    $sum = $q;
                    break;
                default:
                    out(['ok' => false, 'error' => 'Tipo de envio inválido'], 400);
            }
            out(['ok' => true, 'item' => add_hist($jid, $type, $sum)]);

        case 'media':
            @set_time_limit(360);
            $kind = (string)($in['kind'] ?? '');
            $K = MEDIA[$kind] ?? null;
            if (!$K) out(['ok' => false, 'error' => 'Tipo de envio inválido'], 400);
            $jid = jid_of((string)($in['to'] ?? ''));
            $form = ['phone' => $jid];
            if (($in['src'] ?? '') === 'url') {
                $u = valid_url((string)($in['url'] ?? ''));
                $form[$K['field'] . '_url'] = $u;
                $name = rawurldecode(basename((string)parse_url($u, PHP_URL_PATH)));
                if ($name === '' || $name === '/') $name = (string)parse_url($u, PHP_URL_HOST);
            } else {
                $f = $_FILES['file'] ?? null;
                $err = is_array($f) ? (int)($f['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
                if ($err === UPLOAD_ERR_NO_FILE) fail('Escolha um arquivo');
                if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) fail('Arquivo grande demais para esta página (máximo ' . ini_mb('upload_max_filesize') . ' MB).');
                if ($err !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) fail('Falha ao receber o arquivo. Tente de novo.');
                $size = (int)$f['size'];
                if ($size <= 0) fail('O arquivo está vazio');
                if ($size > $K['max'] * 1000000) fail($K['label'] . ' grande demais: o limite é ' . $K['max'] . ' MB.');
                $name = basename(str_replace('\\', '/', (string)$f['name']));
                if ($name === '' || $name === '.') $name = 'arquivo';
                $mime = pick_mime($K, (string)$f['tmp_name'], $name, (string)($f['type'] ?? ''));
                if ($mime === null) {
                    $tip = $kind === 'image' ? ' WebP ou GIF: envie como Figurinha ou como Arquivo.' : ($kind === 'video' ? ' Outros formatos: envie como Arquivo.' : '');
                    fail('Formato não aceito para ' . mb_strtolower($K['label']) . '. Use ' . $K['fmt'] . '.' . $tip);
                }
                $form[$K['field']] = new CURLFile((string)$f['tmp_name'], $mime, $name);
            }
            $cap = '';
            if ($K['cap']) {
                $cap = text_in($in, 'caption', 3000, 'Legenda');
                if ($cap !== '') $form['caption'] = $cap;
            }
            foreach ($K['opts'] as $o) {
                $v = (string)($in[$o] ?? '');
                $form[$o] = ($v === '1' || $v === 'true' || $v === 'on') ? 'true' : 'false';
            }
            [$code, $d] = gw('POST', $K['path'], [], null, false, $form, 330);
            if ($code >= 400) fail(gw_error($d, 'Falha ao enviar'));
            out(['ok' => true, 'item' => add_hist($jid, $kind, $name . ($cap !== '' ? ' · ' . $cap : ''))]);

        case 'hist':
            out(['ok' => true, 'items' => (array)($_SESSION['hist'] ?? [])]);

        case 'logout':
            [$code, $d] = gw('POST', $dev . '/logout');
            if ($code >= 400) fail(gw_error($d, 'Falha ao desconectar'));
            out(['ok' => true]);

        case 'reconnect':
            [$code, $d] = gw('POST', $dev . '/reconnect');
            if ($code >= 400) fail(gw_error($d, 'Falha ao reconectar'));
            out(['ok' => true]);
    }
    out(['ok' => false, 'error' => 'Ação inválida'], 400);
}

/* ------------------------------------------------------------------ páginas */

function css(): string
{
    return <<<'CSS'
:root{--bg:#0a0e17;--bg2:#0f1522;--card:#121a2a;--line:#1e2940;--line2:#283654;--tx:#e6ebf5;--mut:#8592ad;--dim:#56627d;
--wa:#25d366;--wa2:#1da851;--cpu:#5b9cff;--bad:#ef4444;--warn:#f5b301;--r:14px;--mono:ui-monospace,"JetBrains Mono",Menlo,Consolas,monospace}
*{box-sizing:border-box;margin:0;padding:0}
html,body{background:var(--bg);color:var(--tx);font:14px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,Ubuntu,sans-serif}
body{min-height:100vh;background-image:radial-gradient(900px 420px at 0 -10%,#12382a55,transparent),radial-gradient(900px 400px at 100% 0,#16244a44,transparent)}
button,input,textarea{font:inherit;color:inherit}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;height:40px;padding:0 16px;border-radius:10px;border:1px solid var(--line2);background:var(--bg2);color:var(--tx);font-weight:500;cursor:pointer;white-space:nowrap}
.btn:hover:not(:disabled){border-color:var(--mut)}
.btn:disabled{opacity:.5;cursor:default}
.btn.pri{background:var(--wa);border-color:var(--wa);color:#04210f;font-weight:600}
.btn.pri:hover:not(:disabled){background:var(--wa2);border-color:var(--wa2)}
.btn.danger:hover:not(:disabled){border-color:var(--bad);color:#ff9a92}
.btn.sm{height:32px;padding:0 12px;font-size:13px}
.in{width:100%;background:var(--bg2);border:1px solid var(--line2);border-radius:10px;padding:10px 12px;outline:none;font-size:15px}
.in:focus{border-color:var(--wa);box-shadow:0 0 0 3px #25d36622}
textarea.in{min-height:120px;resize:vertical;line-height:1.5}
textarea.in.sm{min-height:72px}
label{display:block;font-size:12.5px;color:var(--mut);margin:14px 0 6px}
CSS;
}

function logo(): string
{
    return '<svg viewBox="0 0 32 32" width="26" height="26" aria-hidden="true"><path fill="#25d366" d="M16 3a13 13 0 0 0-11.2 19.6L3 29l6.6-1.7A13 13 0 1 0 16 3z"/><path fill="#fff" d="M22.6 18.9c-.4-.2-2.1-1-2.4-1.1-.3-.1-.6-.2-.8.2l-1.1 1.4c-.2.2-.4.3-.8.1a9.4 9.4 0 0 1-4.7-4.1c-.4-.6.4-.6 1-2 .1-.2 0-.5 0-.7l-1.1-2.6c-.3-.7-.6-.6-.8-.6h-.7c-.2 0-.6.1-.9.4a3.8 3.8 0 0 0-1.2 2.8 6.6 6.6 0 0 0 1.4 3.5c.2.2 2.4 3.7 5.8 5.1 2.2.9 3 1 4.1.8.7-.1 2.1-.9 2.4-1.7.3-.8.3-1.5.2-1.7-.1-.1-.3-.2-.7-.4z"/></svg>';
}

function icon(string $n): string
{
    static $p = [
        'text' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
        'image' => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/>',
        'video' => '<path d="M23 7l-7 5 7 5V7z"/><rect x="1" y="5" width="15" height="14" rx="2"/>',
        'audio' => '<path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2M12 19v4M8 23h8"/>',
        'file' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8"/>',
        'sticker' => '<circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2M9 9h.01M15 9h.01"/>',
        'location' => '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>',
        'contact' => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'link' => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
        'poll' => '<path d="M18 20V10M12 20V4M6 20v-6"/>',
        'upload' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/>',
        'x' => '<path d="M18 6L6 18M6 6l12 12"/>',
    ];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($p[$n] ?? '') . '</svg>';
}

function page_login(string $err): void
{
    $css = css();
    $logo = logo();
    $err = htmlspecialchars($err);
    echo <<<HTML
<!doctype html><html lang="pt-br"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>WhatsApp · Entrar</title><style>{$css}
.wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:16px}
form{width:100%;max-width:360px;background:var(--card);border:1px solid var(--line);border-radius:18px;padding:30px 26px;box-shadow:0 30px 80px #0008}
h1{display:flex;align-items:center;gap:10px;font-size:18px;margin-bottom:4px}
p{color:var(--mut);font-size:13px;margin-bottom:18px}
.btn{width:100%;margin-top:14px}
.err{color:#ff8a80;font-size:13px;margin-top:12px;min-height:18px}
</style></head><body><div class="wrap"><form method="post" autocomplete="off">
<h1>{$logo}WhatsApp</h1><p>Painel de envio do servidor</p>
<input class="in" type="password" name="senha" placeholder="Senha" autofocus required>
<button class="btn pri" type="submit">Entrar</button><div class="err">{$err}</div>
</form></div></body></html>
HTML;
}

function page_app(): void
{
    $css = css();
    $logo = logo();
    $tok = $_SESSION['csrf'];
    $host = htmlspecialchars((string)gethostname());
    $chips = '';
    foreach (KIND_LABEL as $k => $l) {
        $chips .= '<button type="button" data-k="' . $k . '"' . ($k === 'text' ? ' class="on"' : '') . '>' . icon($k) . '<span>' . $l . '</span></button>';
    }
    $iUp = icon('upload');
    $iX = icon('x');
    $upMax = min(ini_mb('upload_max_filesize'), ini_mb('post_max_size'));
    $js = js();
    echo <<<HTML
<!doctype html><html lang="pt-br"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#0a0e17"><title>WhatsApp · {$host}</title><style>{$css}
header{position:sticky;top:0;z-index:5;background:#0a0e17cc;backdrop-filter:blur(12px);border-bottom:1px solid var(--line)}
.hd{max-width:1100px;margin:0 auto;padding:12px 20px;display:flex;align-items:center;gap:14px}
.brand{display:flex;align-items:center;gap:10px;font-weight:700;font-size:16px}
.brand small{color:var(--dim);font-weight:500;font-size:12.5px}
.sp{flex:1}
.pill{display:inline-flex;align-items:center;gap:8px;font-size:12.5px;padding:5px 12px;border-radius:999px;border:1px solid var(--line2);color:var(--mut);white-space:nowrap}
.pill i{width:8px;height:8px;border-radius:50%;background:var(--dim)}
.pill.on{color:#8ff0b5;border-color:#1f5a3a}.pill.on i{background:var(--wa);box-shadow:0 0 8px #25d36699}
.pill.off{color:#ffb4ab;border-color:#5a2a2a}.pill.off i{background:var(--bad)}
.out{color:var(--mut);text-decoration:none;font-size:13px;border:1px solid var(--line2);padding:6px 12px;border-radius:8px}
.out:hover{color:var(--tx);border-color:var(--mut)}
main{max-width:1100px;margin:0 auto;padding:20px;display:grid;grid-template-columns:minmax(0,.9fr) minmax(0,1.25fr);gap:16px;align-items:start}
.card{background:var(--card);border:1px solid var(--line);border-radius:var(--r);padding:20px;min-width:0}
.card h2{font-size:12px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--mut);margin-bottom:14px;display:flex;align-items:center;gap:8px}
.tabs{display:flex;gap:4px;background:var(--bg2);border:1px solid var(--line);border-radius:10px;padding:4px;margin-bottom:16px}
.tabs button{flex:1;background:none;border:0;color:var(--mut);padding:8px;border-radius:7px;cursor:pointer;font-weight:500}
.tabs button.on{background:var(--line2);color:var(--tx)}
.qrbox{display:flex;flex-direction:column;align-items:center;gap:12px;text-align:center}
.qr{width:264px;height:264px;max-width:100%;background:#fff;border-radius:14px;padding:12px;display:flex;align-items:center;justify-content:center;position:relative;overflow:hidden}
.qr img{width:100%;height:100%;image-rendering:pixelated}
.qr .ph{color:#475569;font-size:13px;padding:20px}
.qr.exp img{filter:blur(4px) grayscale(1);opacity:.35}
.qr .ov{position:absolute;inset:0;display:none;align-items:center;justify-content:center}
.qr.exp .ov{display:flex}
.timer{font:12.5px var(--mono);color:var(--dim)}
.steps{list-style:none;counter-reset:s;text-align:left;color:var(--mut);font-size:13.5px;display:grid;gap:6px;margin-top:4px;width:100%}
.steps li{counter-increment:s;position:relative;padding-left:30px;min-height:22px}
.steps li::before{content:counter(s);position:absolute;left:0;top:1px;width:20px;height:20px;border-radius:50%;background:var(--bg2);border:1px solid var(--line2);color:var(--tx);font-size:11.5px;display:flex;align-items:center;justify-content:center;margin-top:1px}
.steps b{color:var(--tx);font-weight:600}
.row{display:flex;gap:8px}
.row .in{flex:1}
.pc{margin:18px 0 6px;text-align:center}
.pc .code{font:700 34px/1.1 var(--mono);letter-spacing:.12em;color:#8ff0b5;background:var(--bg2);border:1px dashed #1f5a3a;border-radius:12px;padding:16px 10px;user-select:all}
.me{display:flex;align-items:center;gap:14px;padding:4px 0 16px}
.av{width:52px;height:52px;border-radius:50%;background:#12382a;display:flex;align-items:center;justify-content:center;flex:none}
.me b{font-size:16px;display:block}
.me span{color:var(--mut);font:13px var(--mono)}
.acts{display:flex;gap:8px;flex-wrap:wrap}
.note{color:var(--dim);font-size:12.5px;margin-top:14px;line-height:1.5}
.hint{display:block;color:var(--dim);font-size:12px;margin-top:6px;line-height:1.45}
.count{text-align:right;color:var(--dim);font-size:12px;margin-top:4px}
.lock{display:none;align-items:center;gap:10px;color:var(--mut);background:var(--bg2);border:1px solid var(--line);border-radius:10px;padding:12px 14px;font-size:13.5px;margin-bottom:6px}
.disabled .lock{display:flex}
.disabled .form{opacity:.45;pointer-events:none}
.types{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:6px}
.types button{display:flex;flex-direction:column;align-items:center;gap:5px;padding:10px 4px 8px;background:var(--bg2);border:1px solid var(--line);border-radius:10px;color:var(--mut);cursor:pointer;font-size:12px;font-weight:500;min-width:0}
.types button span{max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.types button svg{width:18px;height:18px}
.types button:hover{border-color:var(--line2);color:var(--tx)}
.types button.on{border-color:var(--wa);color:var(--tx);background:#12382a66}
.types button.on svg{color:var(--wa)}
.seg{display:inline-flex;background:var(--bg2);border:1px solid var(--line);border-radius:10px;padding:3px;margin:16px 0 10px}
.seg button{background:none;border:0;color:var(--mut);padding:6px 14px;border-radius:7px;cursor:pointer;font-weight:500;font-size:13px}
.seg button.on{background:var(--line2);color:var(--tx)}
.drop{border:1.5px dashed var(--line2);border-radius:12px;padding:18px;cursor:pointer;background:var(--bg2);transition:border-color .15s,background .15s}
.drop:hover,.drop.over{border-color:var(--wa);background:#12382a33}
.dz-empty{display:flex;flex-direction:column;align-items:center;gap:6px;color:var(--mut);text-align:center;font-size:13.5px}
.dz-empty svg{width:26px;height:26px;color:var(--dim)}
.dz-empty b{color:var(--tx);font-weight:600}
.dz-empty small{color:var(--dim);font-size:12px}
.dz-file{display:flex;align-items:center;gap:12px;cursor:default}
.pv{width:64px;height:64px;border-radius:8px;background:#0a0e17;border:1px solid var(--line);flex:none;display:flex;align-items:center;justify-content:center;overflow:hidden;color:var(--mut);font:600 11px var(--mono);text-transform:uppercase}
.pv img,.pv video{width:100%;height:100%;object-fit:cover}
.fi{min-width:0;flex:1}
.fi b{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:500}
.fi span{color:var(--dim);font-size:12.5px}
.fi audio{display:block;width:100%;height:34px;margin-top:6px}
.x{width:32px;height:32px;flex:none;border-radius:8px;border:1px solid var(--line2);background:none;color:var(--mut);cursor:pointer;display:flex;align-items:center;justify-content:center}
.x:hover{color:#ff9a92;border-color:var(--bad)}
.x svg{width:16px;height:16px}
.opts{display:flex;flex-wrap:wrap;gap:8px 20px;margin-top:14px}
.opts:empty{display:none}
.opts label{display:flex;align-items:center;gap:8px;margin:0;color:var(--tx);font-size:13.5px;cursor:pointer}
.opts input{accent-color:var(--wa);width:16px;height:16px}
.two{display:grid;grid-template-columns:1fr 1fr;gap:0 12px}
.sendrow{display:flex;justify-content:flex-end;align-items:center;gap:12px;margin-top:16px}
.prog{color:var(--mut);font-size:13px;margin-right:auto}
.hist{margin-top:20px;border-top:1px solid var(--line);padding-top:14px}
.hist h3{font-size:12px;color:var(--dim);font-weight:600;text-transform:uppercase;letter-spacing:.08em;margin-bottom:8px}
.hi{display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid #182134;font-size:13.5px}
.hi:last-child{border-bottom:0}
.hi .t{color:var(--dim);font:12px var(--mono);flex:none}
.hi .to{color:#8ff0b5;font:12.5px var(--mono);flex:none}
.hi .k{font-size:11px;padding:1px 7px;border-radius:6px;background:var(--bg2);border:1px solid var(--line2);color:var(--mut);flex:none}
.hi .m{color:var(--mut);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0}
.empty{color:var(--dim);font-size:13px}
.toast{position:fixed;left:50%;bottom:22px;transform:translateX(-50%);background:#1a2338;border:1px solid var(--line2);padding:10px 16px;border-radius:10px;font-size:13.5px;box-shadow:0 12px 40px #0009;z-index:50;max-width:min(560px,92vw);opacity:0;transition:opacity .2s;pointer-events:none}
.toast.show{opacity:1}
.toast.er{border-color:#ef444466;background:#2a1418;color:#ffd0cc}
.spin{width:18px;height:18px;border:2px solid #0000;border-top-color:currentColor;border-right-color:currentColor;border-radius:50%;animation:sp .7s linear infinite}
@keyframes sp{to{transform:rotate(360deg)}}
[hidden]{display:none!important}
@media (max-width:900px){main{grid-template-columns:1fr;padding:12px}.hd{padding:10px 14px}.brand small{display:none}}
@media (max-width:480px){.card{padding:16px}.types{gap:4px}.types button{font-size:10.5px;padding:8px 2px 6px}.two{grid-template-columns:1fr}.hi .k{display:none}}
</style></head><body>
<header><div class="hd"><div class="brand">{$logo}WhatsApp <small>{$host}</small></div><div class="sp"></div>
<span class="pill" id="pill"><i></i><span>verificando…</span></span><a class="out" href="?sair=1">Sair</a></div></header>
<main>
<section class="card" id="conn">
  <h2>Conexão</h2>
  <div id="vLoading" class="empty">Verificando a conexão…</div>
  <div id="vLogged" hidden>
    <div class="me"><div class="av">{$logo}</div><div><b id="meName">Conectado</b><span id="mePhone"></span></div></div>
    <div class="acts"><button class="btn sm" id="bRec">Reconectar</button><button class="btn sm danger" id="bOut">Desconectar este número</button></div>
    <p class="note">O número fica conectado mesmo depois de reiniciar o servidor. Para trocar de número, desconecte e conecte outro.</p>
  </div>
  <div id="vLogin" hidden>
    <div class="tabs"><button class="on" data-t="qr">QR code</button><button data-t="code">Código de pareamento</button></div>
    <div id="tQr" class="qrbox">
      <div class="qr" id="qr"><div class="ph">Clique em <b>Gerar QR code</b></div><div class="ov"><button class="btn sm pri" id="bQr2">Gerar novo QR</button></div></div>
      <div class="timer" id="timer"></div>
      <button class="btn pri" id="bQr">Gerar QR code</button>
      <ol class="steps"><li>No celular, abra o <b>WhatsApp</b></li><li>Toque em <b>⋮</b> ou <b>Configurações</b> › <b>Aparelhos conectados</b></li><li>Toque em <b>Conectar um aparelho</b> e aponte para o QR code</li></ol>
    </div>
    <div id="tCode" hidden>
      <label for="pcPhone">Número do WhatsApp que vai conectar</label>
      <div class="row"><input class="in" id="pcPhone" inputmode="numeric" placeholder="50761234567" autocomplete="off"><button class="btn pri" id="bPc">Gerar código</button></div>
      <div class="pc" id="pcBox" hidden><div class="code" id="pcCode"></div><button class="btn sm" id="bPcCopy" style="margin-top:10px">Copiar código</button></div>
      <ol class="steps" style="margin-top:14px"><li>No celular, abra o <b>WhatsApp</b> › <b>Aparelhos conectados</b></li><li>Toque em <b>Conectar um aparelho</b></li><li>Toque em <b>Conectar com número de telefone</b> e digite o código</li></ol>
      <p class="note">Use o código do país + DDD + número, só dígitos. O código vale por poucos minutos.</p>
    </div>
  </div>
</section>
<section class="card disabled" id="send">
  <h2>Enviar</h2>
  <div class="lock">Conecte um número ao lado para poder enviar.</div>
  <div class="form">
    <label for="to" style="margin-top:0">Para</label>
    <input class="in" id="to" inputmode="tel" placeholder="50761234567" autocomplete="off">
    <label>O que enviar</label>
    <div class="types" id="types">{$chips}</div>

    <div class="pane" id="p-text">
      <label for="msg">Mensagem</label>
      <textarea class="in" id="msg" placeholder="Escreva a mensagem…" maxlength="4000"></textarea>
      <div class="count" id="count">0 / 4000</div>
    </div>

    <div class="pane" id="p-media" hidden>
      <div class="seg" id="srcSeg"><button type="button" data-s="file" class="on">Arquivo do PC</button><button type="button" data-s="url">Link (URL)</button></div>
      <div id="srcFile">
        <input type="file" id="file" hidden>
        <div class="drop" id="drop">
          <div class="dz-empty" id="dzEmpty">{$iUp}<div><b>Escolha um arquivo</b> ou arraste aqui</div><small id="fmt"></small></div>
          <div class="dz-file" id="dzFile" hidden><div class="pv" id="pv"></div><div class="fi"><b id="fname"></b><span id="fsize"></span><div id="fextra"></div></div><button type="button" class="x" id="fclear" title="Remover">{$iX}</button></div>
        </div>
      </div>
      <div id="srcUrl" hidden>
        <input class="in" id="murl" placeholder="https://" autocomplete="off" spellcheck="false">
        <small class="hint" id="urlHint"></small>
      </div>
      <div id="capWrap"><label for="cap">Legenda <span style="color:var(--dim)">(opcional)</span></label><textarea class="in sm" id="cap" maxlength="3000" placeholder="Texto que vai junto"></textarea></div>
      <div class="opts" id="opts"></div>
    </div>

    <div class="pane" id="p-location" hidden>
      <label for="coords">Coordenadas ou link do Google Maps</label>
      <input class="in" id="coords" placeholder="8.9824, -79.5199" autocomplete="off" spellcheck="false">
      <small class="hint">No Google Maps, clique com o botão direito no local e clique nos números para copiar.</small>
    </div>

    <div class="pane" id="p-contact" hidden>
      <div class="two">
        <div><label for="cName">Nome do contato</label><input class="in" id="cName" placeholder="Maria Souza" autocomplete="off"></div>
        <div><label for="cPhone">Telefone do contato</label><input class="in" id="cPhone" inputmode="tel" placeholder="50761234567" autocomplete="off"></div>
      </div>
      <small class="hint">Vai como cartão de contato, com botão para conversar ou salvar.</small>
    </div>

    <div class="pane" id="p-link" hidden>
      <label for="lUrl">Link</label>
      <input class="in" id="lUrl" placeholder="https://" autocomplete="off" spellcheck="false">
      <label for="lCap">Texto <span style="color:var(--dim)">(opcional)</span></label>
      <textarea class="in sm" id="lCap" maxlength="3000" placeholder="Olha isso…"></textarea>
    </div>

    <div class="pane" id="p-poll" hidden>
      <label for="pQ">Pergunta</label>
      <input class="in" id="pQ" maxlength="255" placeholder="Qual o melhor dia para a reunião?" autocomplete="off">
      <label for="pOpts">Opções <span style="color:var(--dim)">(uma por linha, de 2 a 12)</span></label>
      <textarea class="in sm" id="pOpts" placeholder="Segunda&#10;Quarta&#10;Sexta" style="min-height:96px"></textarea>
      <label for="pMax">Quantas opções cada pessoa pode marcar</label>
      <input class="in" id="pMax" type="number" min="1" max="12" value="1" style="max-width:120px">
    </div>

    <div class="sendrow"><span class="prog" id="prog"></span><button class="btn pri" id="bSend">Enviar</button></div>
    <div class="hist"><h3>Enviadas nesta sessão</h3><div id="hist"><div class="empty">Nada enviado ainda.</div></div></div>
  </div>
  <p class="note">Ctrl+Enter envia. Para grupos, cole o ID completo do grupo (termina em <b>@g.us</b>). Upload pela página: até {$upMax} MB. Você também pode colar uma imagem (Ctrl+V).</p>
</section>
</main>
<div class="toast" id="toast"></div>
<script>window.WA_TOKEN='{$tok}';</script>
<script>{$js}</script>
</body></html>
HTML;
}

function js(): string
{
    return <<<'JS'
const TOKEN=window.WA_TOKEN;
const $=id=>document.getElementById(id);
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
let state={logged:null},qrTimer=null,qrLeft=0,qrAuto=0,statusTimer=null,tab='qr';

function toast(m,er){const t=$('toast');t.textContent=m;t.className='toast show'+(er?' er':'');clearTimeout(t._h);t._h=setTimeout(()=>t.className='toast',er?6500:3000)}
async function api(a,body){
  let r;try{r=await fetch('?a='+a,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-Token':TOKEN},body:JSON.stringify(body||{})})}
  catch(e){throw new Error('Sem conexão com o servidor')}
  if(r.status===401){location.reload();throw new Error('Sessão expirada')}
  let d;try{d=await r.json()}catch(e){throw new Error('Resposta inválida do servidor')}
  if(!d.ok)throw new Error(d.error||'Erro');return d;
}
function upload(a,fd,onp){
  return new Promise((res,rej)=>{
    const x=new XMLHttpRequest();
    x.open('POST','?a='+a);x.setRequestHeader('X-Token',TOKEN);x.responseType='json';x.timeout=600000;
    x.upload.onprogress=e=>{if(e.lengthComputable)onp(Math.round(e.loaded*100/e.total))};
    x.upload.onload=()=>onp(100);
    x.onload=()=>{
      if(x.status===401){location.reload();return rej(new Error('Sessão expirada'))}
      const d=x.response;
      if(!d)return rej(new Error(x.status===413?'Arquivo grande demais para o servidor':'Resposta inválida do servidor'));
      d.ok?res(d):rej(new Error(d.error||'Erro'));
    };
    x.onerror=()=>rej(new Error('Sem conexão com o servidor'));
    x.ontimeout=()=>rej(new Error('O envio demorou demais'));
    x.send(fd);
  });
}
function busy(b,on,label){if(on){b._l=b.innerHTML;b.disabled=true;b.innerHTML='<span class="spin"></span>'+(label||'')}else{b.disabled=false;if(b._l)b.innerHTML=b._l}}
function setPill(cls,txt){const p=$('pill');p.className='pill '+cls;p.querySelector('span').textContent=txt}
function fmtSize(n){return n<1024?n+' B':n<1048576?(n/1024).toFixed(0)+' KB':(n/1048576).toFixed(n<10485760?1:0)+' MB'}

/* ---------------- conexão */
async function refresh(){
  try{
    const d=await api('status');
    const was=state.logged;state=d;
    $('vLoading').hidden=true;
    $('vLogged').hidden=!d.logged;$('vLogin').hidden=d.logged;
    $('send').classList.toggle('disabled',!d.logged);
    if(d.logged){
      setPill(d.connected?'on':'off',d.connected?'conectado':'conectado, sem rede');
      $('meName').textContent=d.name||'WhatsApp conectado';$('mePhone').textContent=d.phone?'+'+d.phone:'';
      if(was===false){stopQr();$('pcBox').hidden=true;toast('Número conectado!')}
    }else setPill('off','desconectado');
  }catch(e){$('vLoading').hidden=false;$('vLoading').textContent=e.message;setPill('off','serviço parado')}
  schedule();
}
function schedule(){clearTimeout(statusTimer);const waiting=!state.logged&&(qrLeft>0||!$('pcBox').hidden);statusTimer=setTimeout(refresh,waiting?3000:15000)}
function stopQr(){clearInterval(qrTimer);qrTimer=null;qrLeft=0;$('timer').textContent=''}
async function genQr(auto){
  const b=$('bQr');busy(b,true,' Gerando…');
  try{
    const d=await api('qr');
    $('qr').classList.remove('exp');
    $('qr').innerHTML='<img alt="QR code" src="'+d.img+'"><div class="ov"><button class="btn sm pri" id="bQr2">Gerar novo QR</button></div>';
    $('bQr2').onclick=()=>{qrAuto=0;genQr()};
    qrLeft=d.duration||30;if(!auto)qrAuto=0;
    clearInterval(qrTimer);
    qrTimer=setInterval(()=>{
      qrLeft--;$('timer').textContent=qrLeft>0?'expira em '+qrLeft+'s':'';
      if(qrLeft<=0){clearInterval(qrTimer);qrTimer=null;
        if(state.logged)return;
        if(qrAuto<4){qrAuto++;genQr(true)}else{$('qr').classList.add('exp');$('timer').textContent='QR code expirado'}}
    },1000);
    schedule();
  }catch(e){toast(e.message,true)}
  finally{busy(b,false)}
}
$('bQr').onclick=()=>{qrAuto=0;genQr()};
document.querySelectorAll('.tabs button').forEach(b=>b.onclick=()=>{
  tab=b.dataset.t;document.querySelectorAll('.tabs button').forEach(x=>x.classList.toggle('on',x===b));
  $('tQr').hidden=tab!=='qr';$('tCode').hidden=tab!=='code';
  if(tab==='code'){stopQr();setTimeout(()=>$('pcPhone').focus(),30)}
});
$('bPc').onclick=async()=>{
  const b=$('bPc');busy(b,true,' Gerando…');
  try{const d=await api('pair',{phone:$('pcPhone').value});$('pcCode').textContent=d.code;$('pcBox').hidden=false;schedule()}
  catch(e){toast(e.message,true)}finally{busy(b,false)}
};
$('pcPhone').addEventListener('keydown',e=>{if(e.key==='Enter')$('bPc').click()});
$('bPcCopy').onclick=()=>{const t=$('pcCode').textContent;(navigator.clipboard&&window.isSecureContext?navigator.clipboard.writeText(t):Promise.reject()).then(()=>toast('Código copiado'),()=>{const r=document.createRange();r.selectNodeContents($('pcCode'));getSelection().removeAllRanges();getSelection().addRange(r);toast('Código selecionado: copie com Ctrl+C')})};
$('bOut').onclick=async()=>{
  if(!confirm('Desconectar este número do servidor?'))return;
  const b=$('bOut');busy(b,true);
  try{await api('logout');toast('Número desconectado');state.logged=false;$('pcBox').hidden=true;$('qr').innerHTML='<div class="ph">Clique em <b>Gerar QR code</b></div>';await refresh()}
  catch(e){toast(e.message,true)}finally{busy(b,false)}
};
$('bRec').onclick=async()=>{const b=$('bRec');busy(b,true);try{await api('reconnect');toast('Reconectando…');setTimeout(refresh,2500)}catch(e){toast(e.message,true)}finally{busy(b,false)}};

/* ---------------- envio */
const KINDS={
  text:{label:'Texto'},
  image:{label:'Imagem',media:1,max:20,accept:'image/jpeg,image/png',fmt:'JPG ou PNG · até 20 MB',cap:1,url:'https://site.com/foto.jpg',
    opts:[['view_once','Ver uma vez'],['compress','Comprimir']]},
  video:{label:'Vídeo',media:1,max:99,accept:'video/mp4,video/x-matroska,video/x-msvideo,.mp4,.mkv,.avi',fmt:'MP4, MKV ou AVI · até 100 MB',cap:1,url:'https://site.com/video.mp4',
    opts:[['view_once','Ver uma vez'],['compress','Comprimir'],['gif_playback','Tocar como GIF (sem som, em loop)']]},
  audio:{label:'Áudio',media:1,max:50,accept:'audio/*,.mp3,.m4a,.ogg,.opus,.wav,.aac,.flac,.amr',fmt:'MP3, M4A, OGG, WAV, AAC, FLAC · até 50 MB',url:'https://site.com/audio.mp3',
    opts:[['ptt','Enviar como mensagem de voz (com a bolinha do microfone)']]},
  file:{label:'Arquivo',media:1,max:50,accept:'',fmt:'PDF, Word, Excel, ZIP ou qualquer outro · até 50 MB',cap:1,url:'https://site.com/documento.pdf',opts:[]},
  sticker:{label:'Figurinha',media:1,max:20,accept:'image/jpeg,image/png,image/webp,image/gif',fmt:'JPG, PNG, WebP ou GIF · vira figurinha automaticamente',url:'https://site.com/figura.png',opts:[]},
  location:{label:'Local'},contact:{label:'Contato'},link:{label:'Link'},poll:{label:'Enquete'}
};
const PREF_KEY='lkwa.prefs';
let kind='text',src='file',picked=null,pvUrl=null;

function setKind(k){
  if(!KINDS[k])k='text';
  kind=k;const K=KINDS[k];
  document.querySelectorAll('#types button').forEach(b=>b.classList.toggle('on',b.dataset.k===k));
  document.querySelectorAll('.pane').forEach(p=>p.hidden=true);
  $(K.media?'p-media':'p-'+k).hidden=false;
  if(K.media){
    $('fmt').textContent=K.fmt;
    $('file').accept=K.accept;
    $('murl').placeholder=K.url;
    $('urlHint').textContent='O servidor baixa o arquivo deste link e envia como '+K.label.toLowerCase()+'. O link precisa abrir direto o arquivo.';
    $('capWrap').hidden=!K.cap;
    $('opts').innerHTML=K.opts.map(o=>'<label><input type="checkbox" name="'+o[0]+'">'+esc(o[1])+'</label>').join('');
    if(picked&&picked.size>K.max*1e6){toast(K.label+': o limite é '+K.max+' MB',true);clearFile()}
  }
  $('bSend').textContent=k==='text'?'Enviar':'Enviar '+K.label.toLowerCase();
  savePrefs();
}
function setSrc(s){
  src=s;document.querySelectorAll('#srcSeg button').forEach(b=>b.classList.toggle('on',b.dataset.s===s));
  $('srcFile').hidden=s!=='file';$('srcUrl').hidden=s!=='url';
  if(s==='url')setTimeout(()=>$('murl').focus(),30);
  savePrefs();
}
function savePrefs(){try{localStorage.setItem(PREF_KEY,JSON.stringify({kind,src,to:$('to').value}))}catch(e){}}
function loadPrefs(){let p={};try{p=JSON.parse(localStorage.getItem(PREF_KEY)||'{}')||{}}catch(e){}if(p.to)$('to').value=p.to;setSrc(p.src==='url'?'url':'file');setKind(p.kind||'text')}

function clearFile(){
  picked=null;$('file').value='';
  if(pvUrl){URL.revokeObjectURL(pvUrl);pvUrl=null}
  $('dzEmpty').hidden=false;$('dzFile').hidden=true;$('pv').innerHTML='';$('fextra').innerHTML='';
}
function setFile(f){
  if(!f)return;
  const K=KINDS[kind];
  if(K.media&&f.size>K.max*1e6){toast(K.label+' grande demais: o limite é '+K.max+' MB',true);return}
  clearFile();picked=f;
  $('dzEmpty').hidden=true;$('dzFile').hidden=false;
  $('fname').textContent=f.name||'arquivo';$('fsize').textContent=fmtSize(f.size)+(f.type?' · '+f.type:'');
  const ext=((f.name||'').split('.').pop()||'').slice(0,4);
  const t=f.type||'';
  if(t.startsWith('image/')||t.startsWith('video/')||t.startsWith('audio/'))pvUrl=URL.createObjectURL(f);
  if(t.startsWith('image/'))$('pv').innerHTML='<img alt="" src="'+pvUrl+'">';
  else if(t.startsWith('video/'))$('pv').innerHTML='<video muted playsinline preload="metadata" src="'+pvUrl+'"></video>';
  else $('pv').textContent=ext||'arq';
  if(t.startsWith('audio/'))$('fextra').innerHTML='<audio controls preload="none" src="'+pvUrl+'"></audio>';
}
document.querySelectorAll('#types button').forEach(b=>b.onclick=()=>setKind(b.dataset.k));
document.querySelectorAll('#srcSeg button').forEach(b=>b.onclick=()=>setSrc(b.dataset.s));
$('drop').onclick=e=>{if(e.target.closest('#fclear')||e.target.closest('audio')||e.target.closest('#dzFile'))return;$('file').click()};
$('fclear').onclick=e=>{e.stopPropagation();clearFile()};
$('file').onchange=()=>setFile($('file').files[0]);
['dragenter','dragover'].forEach(ev=>$('drop').addEventListener(ev,e=>{e.preventDefault();$('drop').classList.add('over')}));
['dragleave','drop'].forEach(ev=>$('drop').addEventListener(ev,e=>{e.preventDefault();$('drop').classList.remove('over')}));
$('drop').addEventListener('drop',e=>{const f=e.dataTransfer&&e.dataTransfer.files[0];if(f)setFile(f)});
document.addEventListener('paste',e=>{
  const f=e.clipboardData&&e.clipboardData.files&&e.clipboardData.files[0];
  if(!f||$('send').classList.contains('disabled'))return;
  e.preventDefault();
  if(!KINDS[kind].media)setKind(f.type.startsWith('image/')?'image':f.type.startsWith('video/')?'video':f.type.startsWith('audio/')?'audio':'file');
  setSrc('file');setFile(f);toast('Arquivo colado: '+(f.name||f.type));
});
$('to').addEventListener('change',savePrefs);

function resetFields(k){
  if(k==='text'){$('msg').value='';$('count').textContent='0 / 4000';$('msg').focus()}
  else if(KINDS[k].media){clearFile();$('murl').value='';$('cap').value=''}
  else if(k==='location')$('coords').value='';
  else if(k==='contact'){$('cName').value='';$('cPhone').value=''}
  else if(k==='link'){$('lUrl').value='';$('lCap').value=''}
  else if(k==='poll'){$('pQ').value='';$('pOpts').value='';$('pMax').value=1}
}
let sending=false;
async function send(){
  if(sending)return;
  const to=$('to').value.trim();
  if(!to){toast('Digite o número de destino',true);$('to').focus();return}
  const k=kind,K=KINDS[k],b=$('bSend');
  let d;
  try{
    sending=true;
    if(K.media){
      const fd=new FormData();
      fd.append('kind',k);fd.append('to',to);fd.append('src',src);
      if(src==='url'){const u=$('murl').value.trim();if(!u){$('murl').focus();throw new Error('Cole o link do arquivo')}fd.append('url',u)}
      else{if(!picked)throw new Error('Escolha um arquivo');fd.append('file',picked,picked.name||'arquivo')}
      if(K.cap)fd.append('caption',$('cap').value);
      document.querySelectorAll('#opts input').forEach(i=>fd.append(i.name,i.checked?'1':'0'));
      busy(b,true,' Enviando…');
      d=await upload('media',fd,p=>{$('prog').textContent=src==='url'?'Baixando e enviando…':(p<100?'Enviando '+p+'%':'Enviando para o WhatsApp…')});
    }else{
      const body={type:k,to};
      if(k==='text')body.message=$('msg').value;
      else if(k==='location')body.coords=$('coords').value;
      else if(k==='contact'){body.name=$('cName').value;body.phone=$('cPhone').value}
      else if(k==='link'){body.url=$('lUrl').value;body.caption=$('lCap').value}
      else if(k==='poll'){body.question=$('pQ').value;body.options=$('pOpts').value.split('\n');body.max=+$('pMax').value||1}
      busy(b,true,' Enviando…');
      d=await api('send',body);
    }
    hist.unshift(d.item);hist=hist.slice(0,30);renderHist(hist);
    resetFields(k);savePrefs();
    const fem={text:1,image:1,sticker:1,location:1,poll:1}[k],what={text:'Mensagem',location:'Localização'}[k]||K.label;
    toast(what+(fem?' enviada':' enviado')+' para '+(d.item.to.includes('@')?d.item.to:'+'+d.item.to));
  }catch(e){toast(e.message,true)}
  finally{sending=false;busy(b,false);$('prog').textContent=''}
}
$('bSend').onclick=send;
$('msg').addEventListener('input',()=>$('count').textContent=$('msg').value.length+' / 4000');
$('send').addEventListener('keydown',e=>{if(e.key==='Enter'&&(e.ctrlKey||e.metaKey)){e.preventDefault();send()}});
['coords','cPhone','lUrl','murl'].forEach(id=>$(id).addEventListener('keydown',e=>{if(e.key==='Enter'&&!e.ctrlKey){e.preventDefault();send()}}));

let hist=[];
function renderHist(items){
  $('hist').innerHTML=items.length?items.map(i=>{
    const k=i.kind||'text',lab=(KINDS[k]||KINDS.text).label;
    return '<div class="hi"><span class="t">'+esc(i.at)+'</span><span class="to">'+esc(i.to.includes('@')?i.to:'+'+i.to)+'</span><span class="k">'+esc(lab)+'</span><span class="m" title="'+esc(i.msg)+'">'+esc(i.msg)+'</span></div>';
  }).join(''):'<div class="empty">Nada enviado ainda.</div>';
}
loadPrefs();
api('hist').then(d=>{hist=d.items;renderHist(hist)}).catch(()=>{});
document.addEventListener('visibilitychange',()=>{if(!document.hidden)refresh()});
refresh();
JS;
}
