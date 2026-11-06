<?php
/* Recebe as mensagens do GOWA (webhook) e entrega ao Guardião LK. Só aceita 127.0.0.1 (Apache) e assinatura válida. */
declare(strict_types=1);
error_reporting(0);
header('Content-Type: application/json');
require __DIR__ . '/config.php';

function done(int $code = 200): never
{
    http_response_code($code);
    echo $code < 300 ? '{"ok":true}' : '{"ok":false}';
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !defined('WA_HOOK_SECRET') || WA_HOOK_SECRET === '') done(403);
$raw = (string)file_get_contents('php://input');
if (strlen($raw) > 262144) done(413);
$sig = (string)($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
if (!hash_equals('sha256=' . hash_hmac('sha256', $raw, WA_HOOK_SECRET), $sig)) done(401);

$d = json_decode($raw, true);
if (!is_array($d) || ($d['event'] ?? '') !== 'message') done();
$p = is_array($d['payload'] ?? null) ? $d['payload'] : [];
$dir = '/var/lib/lk-guard/inbox';
$chat = (string)($p['chat_id'] ?? '');
$from = (string)($p['from'] ?? '');
$body = trim((string)($p['body'] ?? ''));
$dev = (string)preg_replace('/[:@].*$/', '', (string)($d['device_id'] ?? ''));

// diagnóstico: última mensagem recebida (sem o texto)
if (is_dir($dir)) @file_put_contents($dir . '/.ultima', date('c') . " chat=$chat from=$from me=" . (empty($p['is_from_me']) ? 0 : 1) . " device=$dev len=" . mb_strlen($body) . "\n");

if (str_ends_with($chat, '@g.us')) done();                       // grupos: ignora
if ($body === '' || mb_strlen($body) > 200) done();

if (!empty($p['is_from_me'])) {
    // Mensagem enviada pelo próprio número do servidor. Só vale na conversa "Mensagens para mim"
    // (quando o servidor usa o número do dono) e só se for um comando curto — assim os avisos
    // que o próprio Guardião manda nunca viram comando.
    $chatNum = (string)preg_replace('/[:@].*$/', '', $chat);
    if ($dev === '' || $chatNum !== $dev || !str_ends_with($chat, '@s.whatsapp.net')) done();
    if (mb_strlen($body) > 40 || !preg_match('/^(status|temp|temperatura|info|ajuda|menu|comandos|help|\?|reinicia|reiniciar|reboot|desliga|desligar|poweroff|shutdown|cancelar)(\s|$)/iu', $body)) done();
    $from = $dev . '@s.whatsapp.net';
} else {
    if (!str_ends_with($from, '@s.whatsapp.net') && str_ends_with($chat, '@s.whatsapp.net')) $from = $chat;
    if (!str_ends_with($from, '@s.whatsapp.net')) {
        // remetente só com LID (o WhatsApp ainda não informou o número): o Guardião descobre qual é
        $lid = (string)($p['from_lid'] ?? '');
        if (!str_ends_with($lid, '@lid')) $lid = str_ends_with($from, '@lid') ? $from : (str_ends_with($chat, '@lid') ? $chat : '');
        if ($lid === '') done();
        $from = 'lid:' . preg_replace('/[:@].*$/', '', $lid);
    }
}

if (!is_dir($dir) || count(glob($dir . '/*.json') ?: []) > 50) done();
$fromOut = str_starts_with($from, 'lid:') ? $from : preg_replace('/[:@].*$/', '', $from);
@file_put_contents($dir . '/' . sprintf('%.4f', microtime(true)) . '-' . bin2hex(random_bytes(4)) . '.json',
    json_encode(['from' => $fromOut, 'text' => $body, 'at' => time()], JSON_UNESCAPED_UNICODE), LOCK_EX);
done();
