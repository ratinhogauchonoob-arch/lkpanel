<?php
/*
 * LK Monitor - helper do gerenciador de arquivos.
 * Executado via sudo pelo painel (somente CLI). Recebe um JSON no stdin
 * e devolve um JSON no stdout (ou os bytes do arquivo na ação "cat").
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

const PROTECTED_PATHS = [
    '/', '/bin', '/boot', '/dev', '/etc', '/home', '/lib', '/lib32', '/lib64', '/libx32', '/lost+found', '/media', '/mnt',
    '/opt', '/proc', '/root', '/run', '/sbin', '/snap', '/srv', '/sys', '/tmp', '/usr', '/var',
    '/etc/apache2', '/etc/php', '/etc/ssh', '/etc/systemd', '/etc/sudoers.d', '/usr/bin', '/usr/sbin', '/usr/lib',
    '/usr/local', '/usr/share', '/var/www', '/var/lib', '/var/log', '/var/cache', '/var/spool',
];

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

function is_root(): bool
{
    static $r = null;
    if ($r === null) $r = function_exists('posix_geteuid') ? posix_geteuid() === 0 : trim((string)shell_exec('id -u')) === '0';
    return $r;
}

function norm($p): string
{
    $p = (string)$p;
    if ($p === '' || $p[0] !== '/' || str_contains($p, "\0")) fail('Caminho inválido');
    $parts = [];
    foreach (explode('/', $p) as $s) {
        if ($s === '' || $s === '.') continue;
        if ($s === '..') {
            array_pop($parts);
            continue;
        }
        $parts[] = $s;
    }
    return '/' . implode('/', $parts);
}

function okname($n): string
{
    $n = trim((string)$n);
    if ($n === '' || $n === '.' || $n === '..' || str_contains($n, '/') || str_contains($n, "\0") || strlen($n) > 255) {
        fail('Nome inválido: ' . $n);
    }
    return $n;
}

function pjoin(string $d, string $n): string
{
    return rtrim($d, '/') . '/' . $n;
}

function guard(string $p): void
{
    if (in_array($p, PROTECTED_PATHS, true)) fail("Pasta do sistema protegida: $p");
}

function paths_of(array $req): array
{
    $list = $req['paths'] ?? [];
    if (!is_array($list) || !$list) fail('Nenhum item selecionado');
    return array_map('norm', $list);
}

function exists_l(string $p): bool
{
    return file_exists($p) || is_link($p);
}

function uname_of(int $uid): string
{
    static $c = [];
    if (!isset($c[$uid])) {
        $c[$uid] = (string)$uid;
        if (function_exists('posix_getpwuid') && ($u = posix_getpwuid($uid))) $c[$uid] = $u['name'];
        else foreach (file('/etc/passwd') ?: [] as $l) {
            $x = explode(':', $l);
            if ((int)($x[2] ?? -1) === $uid) { $c[$uid] = $x[0]; break; }
        }
    }
    return $c[$uid];
}

function gname_of(int $gid): string
{
    static $c = [];
    if (!isset($c[$gid])) {
        $c[$gid] = (string)$gid;
        if (function_exists('posix_getgrgid') && ($g = posix_getgrgid($gid))) $c[$gid] = $g['name'];
        else foreach (file('/etc/group') ?: [] as $l) {
            $x = explode(':', $l);
            if ((int)($x[2] ?? -1) === $gid) { $c[$gid] = $x[0]; break; }
        }
    }
    return $c[$gid];
}

function entry(string $f, ?string $name = null): array
{
    $name ??= basename($f);
    $l = @lstat($f);
    if (!$l) return ['name' => $name, 'path' => $f, 'type' => 'file', 'size' => null, 'mtime' => 0, 'perm' => '0000', 'owner' => '?', 'group' => '?', 'link' => null, 'broken' => true];
    $isLink = ($l['mode'] & 0170000) === 0120000;
    $st = $isLink ? (@stat($f) ?: $l) : $l;
    $type = ($st['mode'] & 0170000) === 0040000 ? 'dir' : 'file';
    return [
        'name' => $name, 'path' => $f, 'type' => $type,
        'size' => $type === 'dir' ? null : $st['size'], 'mtime' => $st['mtime'],
        'perm' => sprintf('%04o', $st['mode'] & 07777), 'owner' => uname_of($st['uid']), 'group' => gname_of($st['gid']),
        'link' => $isLink ? (string)@readlink($f) : null, 'broken' => $isLink && !@stat($f),
        'exec' => $type === 'file' && ($st['mode'] & 0111) !== 0,
    ];
}

function owner_like(string $target, string $ref): void
{
    if (!is_root()) return;
    $st = @stat($ref);
    if (!$st || ($st['uid'] === 0 && $st['gid'] === 0)) return;
    chown_r($target, $st['uid'], $st['gid']);
}

function chown_r(string $p, $uid, $gid): void
{
    @lchown($p, $uid);
    @lchgrp($p, $gid);
    if (is_dir($p) && !is_link($p)) {
        foreach (scandir($p) ?: [] as $n) {
            if ($n !== '.' && $n !== '..') chown_r(pjoin($p, $n), $uid, $gid);
        }
    }
}

function rm_rf(string $p): void
{
    if (is_link($p) || !is_dir($p)) {
        if (!@unlink($p)) fail('Não foi possível excluir: ' . $p);
        return;
    }
    foreach (scandir($p) ?: [] as $n) {
        if ($n !== '.' && $n !== '..') rm_rf(pjoin($p, $n));
    }
    if (!@rmdir($p)) fail('Não foi possível excluir a pasta: ' . $p);
}

function keep_meta(string $s, string $d): void
{
    $st = @lstat($s);
    if (!$st) return;
    if (!is_link($d)) {
        @chmod($d, $st['mode'] & 07777);
        @touch($d, $st['mtime']);
    }
    if (is_root()) {
        @lchown($d, $st['uid']);
        @lchgrp($d, $st['gid']);
    }
}

function cp_r(string $s, string $d): void
{
    if (is_link($s)) {
        if (!@symlink((string)readlink($s), $d)) fail('Falha ao copiar o link: ' . $s);
        keep_meta($s, $d);
        return;
    }
    if (is_dir($s)) {
        if (!@mkdir($d, 0755)) fail('Falha ao criar a pasta: ' . $d);
        foreach (scandir($s) ?: [] as $n) {
            if ($n !== '.' && $n !== '..') cp_r(pjoin($s, $n), pjoin($d, $n));
        }
        keep_meta($s, $d);
        return;
    }
    if (!@copy($s, $d)) fail('Falha ao copiar: ' . $s);
    keep_meta($s, $d);
}

function uniq(string $dir, string $name, bool $copy = true): string
{
    $t = pjoin($dir, $name);
    if (!exists_l($t)) return $t;
    $dot = strpos($name, '.', 1);
    $base = $dot === false ? $name : substr($name, 0, $dot);
    $ext = $dot === false ? '' : substr($name, $dot);
    for ($i = $copy ? 1 : 2; $i < 1000; $i++) {
        $suffix = $copy ? ' (cópia' . ($i > 1 ? " $i" : '') . ')' : " ($i)";
        $t = pjoin($dir, $base . $suffix . $ext);
        if (!exists_l($t)) return $t;
    }
    fail('Não foi possível gerar um nome livre');
}

function inside(string $child, string $parent): bool
{
    return $child === $parent || str_starts_with($child, rtrim($parent, '/') . '/');
}

function mime_of(string $f): string
{
    $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
    $map = [
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp',
        'svg' => 'image/svg+xml', 'ico' => 'image/x-icon', 'bmp' => 'image/bmp', 'avif' => 'image/avif', 'pdf' => 'application/pdf',
        'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mp3' => 'audio/mpeg', 'ogg' => 'audio/ogg', 'wav' => 'audio/wav',
        'txt' => 'text/plain', 'log' => 'text/plain',
    ];
    if (isset($map[$ext])) return $map[$ext];
    if (function_exists('mime_content_type') && is_file($f)) return (string)(@mime_content_type($f) ?: 'application/octet-stream');
    return 'application/octet-stream';
}

function is_utf8(string $s): bool
{
    return function_exists('mb_check_encoding') ? mb_check_encoding($s, 'UTF-8') : (bool)preg_match('//u', $s);
}

function latin1_to_utf8(string $s): string
{
    return function_exists('mb_convert_encoding') ? mb_convert_encoding($s, 'UTF-8', 'ISO-8859-1') : utf8_encode($s);
}

function utf8_to_latin1(string $s): string
{
    return function_exists('mb_convert_encoding') ? mb_convert_encoding($s, 'ISO-8859-1', 'UTF-8') : utf8_decode($s);
}

function list_new(array $before, string $dir): array
{
    $now = array_diff(scandir($dir) ?: [], ['.', '..']);
    return array_values(array_diff($now, $before));
}

/* ------------------------------------------------------------------ ações */

$req = json_decode((string)stream_get_contents(STDIN), true);
if (!is_array($req)) fail('Requisição inválida');
$action = (string)($req['action'] ?? '');

switch ($action) {

    case 'list': {
        $p = norm($req['path'] ?? '/');
        if (!is_dir($p)) fail('Pasta não encontrada: ' . $p);
        $dh = @opendir($p);
        if (!$dh) fail('Sem permissão para abrir: ' . $p);
        $items = [];
        $trunc = false;
        while (($n = readdir($dh)) !== false) {
            if ($n === '.' || $n === '..') continue;
            if (count($items) >= 10000) { $trunc = true; break; }
            $items[] = entry(pjoin($p, $n), $n);
        }
        closedir($dh);
        out(['path' => $p, 'items' => $items, 'truncated' => $trunc, 'writable' => is_writable($p),
            'free' => @disk_free_space($p) ?: 0, 'total' => @disk_total_space($p) ?: 0]);
    }

    case 'stat': {
        $p = norm($req['path'] ?? '');
        if (!exists_l($p)) fail('Arquivo não encontrado: ' . $p);
        $e = entry($p);
        $e['is_dir'] = $e['type'] === 'dir';
        $e['mime'] = $e['is_dir'] ? 'inode/directory' : mime_of($p);
        $e['readable'] = is_readable($p);
        $e['atime'] = @fileatime($p) ?: 0;
        if (!empty($req['du']) && $e['is_dir']) {
            $du = trim((string)shell_exec('timeout 25 du -sb --apparent-size ' . escapeshellarg($p) . ' 2>/dev/null'));
            $e['size'] = $du !== '' ? (int)$du : null;
            $cnt = trim((string)shell_exec('timeout 25 find ' . escapeshellarg($p) . ' -mindepth 1 2>/dev/null | wc -l'));
            $e['count'] = (int)$cnt;
        }
        out($e);
    }

    case 'cat': {
        $p = norm($req['path'] ?? '');
        if (!is_file($p) || !is_readable($p)) exit(2);
        $h = fopen($p, 'rb');
        if (!$h) exit(2);
        $o = fopen('php://stdout', 'wb');
        stream_copy_to_stream($h, $o);
        exit(0);
    }

    case 'read': {
        $p = norm($req['path'] ?? '');
        if (!is_file($p)) fail('Arquivo não encontrado: ' . $p);
        if (filesize($p) > 5 * 1024 * 1024) fail('Arquivo muito grande para editar no navegador (máximo 5 MB).');
        $c = @file_get_contents($p);
        if ($c === false) fail('Sem permissão de leitura: ' . $p);
        if (str_contains(substr($c, 0, 8192), "\0")) fail('Este arquivo é binário e não pode ser editado como texto.');
        $enc = 'utf8';
        if (!is_utf8($c)) {
            $c = latin1_to_utf8($c);
            $enc = 'latin1';
        }
        clearstatcache();
        out(['content' => $c, 'enc' => $enc, 'mtime' => filemtime($p), 'size' => filesize($p), 'writable' => is_writable($p)]);
    }

    case 'save': {
        $p = norm($req['path'] ?? '');
        $c = (string)($req['content'] ?? '');
        if (($req['enc'] ?? '') === 'latin1') $c = utf8_to_latin1($c);
        $dir = dirname($p);
        if (!is_dir($dir)) fail('Pasta não encontrada: ' . $dir);
        if (is_dir($p)) fail('O destino é uma pasta');
        clearstatcache();
        if (is_file($p) && isset($req['mtime']) && empty($req['force']) && filemtime($p) !== (int)$req['mtime']) {
            fail('CONFLICT');
        }
        if (is_link($p) || (is_file($p) && @stat($p)['nlink'] > 1)) {
            if (@file_put_contents($p, $c) === false) fail('Sem permissão para salvar: ' . $p);
        } else {
            $st = is_file($p) ? @stat($p) : null;
            $tmp = pjoin($dir, '.lkm-' . bin2hex(random_bytes(4)) . '.tmp');
            if (@file_put_contents($tmp, $c) === false) fail('Sem permissão para salvar em ' . $dir);
            if ($st) {
                @chmod($tmp, $st['mode'] & 07777);
                if (is_root()) { @chown($tmp, $st['uid']); @chgrp($tmp, $st['gid']); }
            } else {
                @chmod($tmp, 0644);
            }
            if (!@rename($tmp, $p)) {
                @unlink($tmp);
                fail('Falha ao salvar: ' . $p);
            }
            if (!$st) owner_like($p, $dir);
        }
        clearstatcache();
        out(['mtime' => filemtime($p), 'size' => filesize($p)]);
    }

    case 'mkdir':
    case 'newfile': {
        $dir = norm($req['path'] ?? '');
        $name = okname($req['name'] ?? '');
        if (!is_dir($dir)) fail('Pasta não encontrada: ' . $dir);
        $t = pjoin($dir, $name);
        if (exists_l($t)) fail("Já existe um item chamado \"$name\"");
        if ($action === 'mkdir') {
            if (!@mkdir($t, 0755)) fail('Sem permissão para criar a pasta em ' . $dir);
        } else {
            if (@file_put_contents($t, '') === false) fail('Sem permissão para criar o arquivo em ' . $dir);
            @chmod($t, 0644);
        }
        owner_like($t, $dir);
        out(['path' => $t]);
    }

    case 'rename': {
        $s = norm($req['path'] ?? '');
        guard($s);
        if (!exists_l($s)) fail('Item não encontrado: ' . $s);
        $name = okname($req['name'] ?? '');
        $t = pjoin(dirname($s), $name);
        if ($t === $s) out(['path' => $t]);
        if (exists_l($t) && strcasecmp($t, $s) !== 0) fail("Já existe um item chamado \"$name\"");
        if (!@rename($s, $t)) fail('Não foi possível renomear');
        out(['path' => $t]);
    }

    case 'delete': {
        $n = 0;
        foreach (paths_of($req) as $p) {
            guard($p);
            if (!exists_l($p)) continue;
            rm_rf($p);
            $n++;
        }
        out(['count' => $n]);
    }

    case 'copy':
    case 'move': {
        $dest = norm($req['dest'] ?? '');
        if (!is_dir($dest)) fail('Pasta de destino não encontrada: ' . $dest);
        $done = [];
        foreach (paths_of($req) as $s) {
            if (!exists_l($s)) fail('Item não encontrado: ' . $s);
            if (is_dir($s) && !is_link($s) && inside($dest, $s)) fail('Não é possível colocar uma pasta dentro dela mesma.');
            if ($action === 'move') {
                guard($s);
                if (dirname($s) === $dest) continue;
                $t = uniq($dest, basename($s), false);
                if (!@rename($s, $t)) {
                    cp_r($s, $t);
                    rm_rf($s);
                }
            } else {
                $t = uniq($dest, basename($s));
                cp_r($s, $t);
            }
            $done[] = $t;
        }
        out(['paths' => $done]);
    }

    case 'chmod': {
        $mode = (string)($req['mode'] ?? '');
        $dmode = (string)($req['dmode'] ?? $mode);
        if (!preg_match('/^[0-7]{3,4}$/', $mode) || !preg_match('/^[0-7]{3,4}$/', $dmode)) fail('Permissão inválida');
        $rec = !empty($req['recursive']);
        $m = octdec($mode);
        $dm = octdec($dmode);
        $err = 0;
        $apply = function (string $p, bool $top) use (&$apply, $m, $dm, $rec, &$err): void {
            if (is_link($p)) return;
            $isDir = is_dir($p);
            if (!@chmod($p, ($isDir && ($rec || !$top)) ? $dm : $m)) $err++;
            if ($rec && $isDir) {
                foreach (scandir($p) ?: [] as $n) {
                    if ($n !== '.' && $n !== '..') $apply(pjoin($p, $n), false);
                }
            }
        };
        foreach (paths_of($req) as $p) {
            if ($rec) guard($p);
            if (!exists_l($p)) fail('Item não encontrado: ' . $p);
            if (is_dir($p) && !$rec) { if (!@chmod($p, $m)) $err++; continue; }
            $apply($p, true);
        }
        if ($err) fail("Permissão alterada com $err erro(s). Verifique se você tem acesso.");
        out();
    }

    case 'chown': {
        if (!is_root()) fail('Somente o root pode mudar o dono dos arquivos.');
        $u = trim((string)($req['owner'] ?? ''));
        $g = trim((string)($req['group'] ?? ''));
        $re = '/^[A-Za-z0-9_][A-Za-z0-9_.-]*\$?$/';
        if (($u !== '' && !preg_match($re, $u)) || ($g !== '' && !preg_match($re, $g))) fail('Dono ou grupo inválido');
        if ($u !== '' && !ctype_digit($u) && function_exists('posix_getpwnam') && !posix_getpwnam($u)) fail("Usuário \"$u\" não existe");
        if ($g !== '' && !ctype_digit($g) && function_exists('posix_getgrnam') && !posix_getgrnam($g)) fail("Grupo \"$g\" não existe");
        $rec = !empty($req['recursive']);
        $uid = $u === '' ? null : (ctype_digit($u) ? (int)$u : $u);
        $gid = $g === '' ? null : (ctype_digit($g) ? (int)$g : $g);
        $apply = function (string $p) use (&$apply, $uid, $gid, $rec): void {
            if ($uid !== null) @lchown($p, $uid);
            if ($gid !== null) @lchgrp($p, $gid);
            if ($rec && is_dir($p) && !is_link($p)) {
                foreach (scandir($p) ?: [] as $n) {
                    if ($n !== '.' && $n !== '..') $apply(pjoin($p, $n));
                }
            }
        };
        foreach (paths_of($req) as $p) {
            if ($rec) guard($p);
            if (!exists_l($p)) fail('Item não encontrado: ' . $p);
            $apply($p);
        }
        out();
    }

    case 'upload': {
        $tmp = (string)($req['tmp'] ?? '');
        $dest = norm($req['dest'] ?? '');
        $name = okname($req['name'] ?? '');
        if ($tmp === '' || !is_file($tmp)) fail('Arquivo enviado não encontrado');
        if (!is_dir($dest)) fail('Pasta de destino não encontrada: ' . $dest);
        $t = pjoin($dest, $name);
        if (exists_l($t)) {
            if (!empty($req['overwrite'])) {
                if (is_dir($t) && !is_link($t)) fail("Já existe uma pasta chamada \"$name\"");
            } else {
                $t = uniq($dest, $name, false);
            }
        }
        $st = is_file($t) ? @stat($t) : null;
        if (!@copy($tmp, $t)) fail('Sem permissão para gravar em ' . $dest);
        if ($st) {
            @chmod($t, $st['mode'] & 07777);
            if (is_root()) { @chown($t, $st['uid']); @chgrp($t, $st['gid']); }
        } else {
            @chmod($t, 0644);
            owner_like($t, $dest);
        }
        out(['path' => $t]);
    }

    case 'zip': {
        if (!class_exists('ZipArchive')) fail('A extensão zip do PHP não está instalada (apt install php-zip).');
        $dest = norm($req['dest'] ?? '');
        $name = okname($req['name'] ?? 'arquivo.zip');
        if (!preg_match('/\.zip$/i', $name)) $name .= '.zip';
        if (!is_dir($dest)) fail('Pasta não encontrada: ' . $dest);
        $t = pjoin($dest, $name);
        if (exists_l($t)) fail("Já existe um arquivo chamado \"$name\"");
        $z = new ZipArchive();
        if ($z->open($t, ZipArchive::CREATE) !== true) fail('Sem permissão para criar o zip em ' . $dest);
        foreach (paths_of($req) as $s) {
            if (!exists_l($s)) continue;
            $base = basename($s);
            if (is_dir($s) && !is_link($s)) {
                $z->addEmptyDir($base);
                $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($s, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
                foreach ($it as $f) {
                    $rel = $base . '/' . substr($f->getPathname(), strlen($s) + 1);
                    if ($f->isDir() && !$f->isLink()) $z->addEmptyDir($rel);
                    elseif ($f->isFile() && $f->isReadable()) $z->addFile($f->getPathname(), $rel);
                }
            } elseif (is_file($s)) {
                $z->addFile($s, $base);
            }
        }
        if (!$z->close()) fail('Falha ao gravar o arquivo zip');
        owner_like($t, $dest);
        out(['path' => $t]);
    }

    case 'zipdl': {
        if (!class_exists('ZipArchive')) fail('A extensão zip do PHP não está instalada (apt install php-zip).');
        $tmpdir = norm($req['tmpdir'] ?? '/tmp');
        $list = isset($req['paths']) ? paths_of($req) : [norm($req['path'] ?? '')];
        $t = pjoin($tmpdir, 'dl-' . bin2hex(random_bytes(6)) . '.zip');
        $z = new ZipArchive();
        if ($z->open($t, ZipArchive::CREATE) !== true) fail('Falha ao preparar o download');
        foreach ($list as $s) {
            if (!exists_l($s)) continue;
            $base = basename($s) ?: 'raiz';
            if (is_file($s)) {
                $z->addFile($s, $base);
                continue;
            }
            if (!is_dir($s)) continue;
            $z->addEmptyDir($base);
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($s, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
            foreach ($it as $f) {
                $rel = $base . '/' . substr($f->getPathname(), strlen(rtrim($s, '/')) + 1);
                if ($f->isDir() && !$f->isLink()) $z->addEmptyDir($rel);
                elseif ($f->isFile() && $f->isReadable()) $z->addFile($f->getPathname(), $rel);
            }
        }
        if ($z->numFiles === 0) { $z->close(); @unlink($t); fail('Nada para baixar'); }
        if (!$z->close()) fail('Falha ao preparar o download');
        @chmod($t, 0644);
        $name = count($list) === 1 ? (basename($list[0]) ?: 'raiz') . '.zip' : 'arquivos.zip';
        out(['file' => $t, 'name' => $name]);
    }

    case 'extract': {
        $s = norm($req['path'] ?? '');
        if (!is_file($s)) fail('Arquivo não encontrado: ' . $s);
        $dir = dirname($s);
        $lower = strtolower($s);
        $isZip = str_ends_with($lower, '.zip');
        $isTar = (bool)preg_match('/\.(tar|tar\.gz|tgz|tar\.bz2|tbz2|tar\.xz|txz)$/', $lower);
        if (!$isZip && !$isTar) fail('Formato não suportado (use .zip, .tar, .tar.gz, .tgz, .tar.bz2, .tar.xz)');
        if (!empty($req['here'])) {
            $target = $dir;
        } else {
            $stem = preg_replace('/\.(zip|tar|tar\.gz|tgz|tar\.bz2|tbz2|tar\.xz|txz)$/i', '', basename($s));
            $target = uniq($dir, $stem !== '' ? $stem : 'extraido', false);
            if (!@mkdir($target, 0755)) fail('Sem permissão para criar a pasta em ' . $dir);
        }
        $before = array_diff(scandir($target) ?: [], ['.', '..']);
        if ($isZip) {
            if (!class_exists('ZipArchive')) fail('A extensão zip do PHP não está instalada (apt install php-zip).');
            $z = new ZipArchive();
            if ($z->open($s) !== true) fail('Arquivo zip inválido ou corrompido');
            for ($i = 0; $i < $z->numFiles; $i++) {
                $n = (string)$z->getNameIndex($i);
                if (str_starts_with($n, '/') || preg_match('#(^|/)\.\.(/|$)#', $n)) { $z->close(); fail('Zip com caminho inseguro: ' . $n); }
            }
            if (!$z->extractTo($target)) { $z->close(); fail('Falha ao extrair'); }
            $z->close();
        } else {
            $cmd = 'tar -xf ' . escapeshellarg($s) . ' -C ' . escapeshellarg($target) . ' --no-same-owner 2>&1';
            exec($cmd, $o, $rc);
            if ($rc !== 0) fail('Falha ao extrair: ' . implode(' ', array_slice($o, 0, 3)));
        }
        if (empty($req['here'])) owner_like($target, $dir);
        else foreach (list_new($before, $target) as $n) owner_like(pjoin($target, $n), $target);
        out(['path' => $target]);
    }

    case 'search': {
        $root = norm($req['path'] ?? '/');
        $q = trim((string)($req['q'] ?? ''));
        if ($q === '') fail('Digite o que procurar');
        if (!is_dir($root)) fail('Pasta não encontrada');
        $skip = ['/proc', '/sys', '/dev', '/run', '/snap'];
        $res = [];
        $limit = 300;
        $deadline = microtime(true) + 12;
        $stack = [[$root, 0]];
        while ($stack && count($res) < $limit && microtime(true) < $deadline) {
            [$d, $depth] = array_pop($stack);
            $dh = @opendir($d);
            if (!$dh) continue;
            while (($n = readdir($dh)) !== false) {
                if ($n === '.' || $n === '..') continue;
                $f = pjoin($d, $n);
                if (stripos($n, $q) !== false) {
                    $res[] = entry($f, $n);
                    if (count($res) >= $limit) break;
                }
                if ($depth < 12 && is_dir($f) && !is_link($f) && !in_array($f, $skip, true)) $stack[] = [$f, $depth + 1];
            }
            closedir($dh);
        }
        out(['items' => $res, 'limited' => count($res) >= $limit || microtime(true) >= $deadline]);
    }

    default:
        fail('Ação desconhecida: ' . $action);
}
