<?php
/*
 * LK Monitor - helper da aba Bancos (MySQL/MariaDB e SQLite).
 * Executado pelo painel via sudo como root. MySQL: root pelo socket. SQLite: arquivos dentro de /var/www,
 * abertos com o usuário dono do arquivo (para não criar -wal/-journal de root).
 * Consultas são somente leitura por padrão; INSERT/UPDATE/DELETE só com "permitir alterações".
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

const WWW = '/var/www';
const SYS_DBS = ['mysql', 'information_schema', 'performance_schema', 'sys'];
const SYS_USERS = ['root', 'mysql', 'mariadb.sys', 'debian-sys-maint', 'mysql.sys', 'mysql.session', 'mysql.infoschema', ''];
const LK_DBFILE = '/etc/lk/databases.txt';
const DROP_BACKUPS = '/var/backups/lkmonitor/_bancos';
const PAGE = 50;
const MAX_ROWS = 1000;
const MAX_EXPORT = 20000;
const MAX_BYTES = 8 * 1024 * 1024;

function out(array $d = []): never
{
    echo json_encode(['ok' => true] + $d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit(0);
}

function fail(string $m, array $extra = []): never
{
    echo json_encode(['ok' => false, 'error' => $m] + $extra, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit(0);
}

function run(array $argv, ?string $stdin = null): array
{
    $p = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
    if (!is_resource($p)) return [127, 'falha ao executar ' . $argv[0]];
    if ($stdin !== null) fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    $o = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    return [proc_close($p), trim((string)$o)];
}

function gen_pass(int $n = 20): string
{
    $a = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $s = '';
    for ($i = 0; $i < $n; $i++) $s .= $a[random_int(0, strlen($a) - 1)];
    return $s;
}

/* ------------------------------------------------------------------ MySQL */

function mysql_socket(): ?string
{
    foreach (['/run/mysqld/mysqld.sock', '/var/run/mysqld/mysqld.sock', '/tmp/mysql.sock', (string)ini_get('pdo_mysql.default_socket')] as $s) {
        if ($s !== '' && file_exists($s)) return $s;
    }
    return null;
}

function my(?string $db = null): PDO
{
    static $c = [];
    $k = $db ?? '';
    if (isset($c[$k])) return $c[$k];
    if (!extension_loaded('pdo_mysql')) fail('A extensão MySQL do PHP não está instalada (php-mysql). Rode o instalador do LK Monitor de novo.');
    $sock = mysql_socket();
    if (!$sock) fail('MySQL/MariaDB não encontrado ou parado (socket não existe).');
    try {
        $pdo = new PDO('mysql:unix_socket=' . $sock . ';charset=utf8mb4' . ($db !== null ? ';dbname=' . $db : ''), 'root', '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5,
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => false, PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_NUM,
        ]);
    } catch (Throwable $e) {
        fail('Não foi possível entrar no MySQL como root pelo socket: ' . $e->getMessage());
    }
    // limite de tempo por consulta (MariaDB / MySQL)
    try { $pdo->exec('SET SESSION max_statement_time=30'); } catch (Throwable $e) {
        try { $pdo->exec('SET SESSION MAX_EXECUTION_TIME=30000'); } catch (Throwable $e2) { }
    }
    return $c[$k] = $pdo;
}

function q1(PDO $p, string $sql, array $a = []): array
{
    $st = $p->prepare($sql);
    $st->execute($a);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function my_dbs(): array
{
    return array_values(array_diff(my()->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN), SYS_DBS));
}

function db_name($n): string
{
    $n = (string)$n;
    if (!preg_match('/^[A-Za-z0-9_$-]{1,64}$/', $n) || in_array(strtolower($n), SYS_DBS, true)) fail('Nome de banco inválido');
    if (!in_array($n, my_dbs(), true)) fail("Banco não encontrado: $n");
    return $n;
}

function my_users(): array
{
    $u = [];
    foreach (q1(my(), 'SELECT User, Host FROM mysql.user') as $r) {
        if (in_array($r['User'], SYS_USERS, true)) continue;
        $u[$r['User'] . '@' . $r['Host']] = ['user' => $r['User'], 'host' => $r['Host'], 'dbs' => []];
    }
    foreach (q1(my(), 'SELECT User, Host, Db FROM mysql.db') as $r) {
        $k = $r['User'] . '@' . $r['Host'];
        if (isset($u[$k])) $u[$k]['dbs'][] = str_replace('\\_', '_', $r['Db']);
    }
    return array_values($u);
}

function qi(string $n, string $kind): string
{
    return $kind === 'mysql' ? '`' . str_replace('`', '``', $n) . '`' : '"' . str_replace('"', '""', $n) . '"';
}

/* ------------------------------------------------------------------ sites (.env) */

function env_read(string $f): array
{
    $e = [];
    foreach (@file($f, FILE_IGNORE_NEW_LINES) ?: [] as $l) {
        if (preg_match('/^\s*([A-Z0-9_]+)\s*=\s*(.*?)\s*$/', $l, $m)) $e[$m[1]] = trim($m[2], "\"'");
    }
    return $e;
}

/** Sites com .env: qual conexão, banco e usuário cada um usa. */
function sites_env(): array
{
    $out = [];
    foreach (glob(WWW . '/*/.env') ?: [] as $f) {
        $root = dirname($f);
        $e = env_read($f);
        $conn = $e['DB_CONNECTION'] ?? '';
        $db = $e['DB_DATABASE'] ?? '';
        if ($conn === 'sqlite') {
            $db = $db === '' ? "$root/database/database.sqlite" : (str_starts_with($db, '/') ? $db : "$root/$db");
        }
        $out[] = ['site' => basename($root), 'root' => $root, 'conn' => $conn, 'db' => $db, 'user' => $e['DB_USERNAME'] ?? ''];
    }
    return $out;
}

/* ------------------------------------------------------------------ SQLite */

function is_sqlite(string $f): bool
{
    $h = @fopen($f, 'rb');
    if (!$h) return false;
    $s = (string)fread($h, 16);
    fclose($h);
    return $s === "SQLite format 3\0";
}

function sqlite_files(): array
{
    [, $o] = run(['timeout', '15', 'find', WWW, '-maxdepth', '6', '(', '-name', 'node_modules', '-o', '-name', 'vendor', '-o', '-name', '.git', ')', '-prune', '-o',
        '-type', 'f', '(', '-name', '*.sqlite', '-o', '-name', '*.sqlite3', '-o', '-name', '*.db', ')', '-size', '-4G', '-print']);
    $list = [];
    foreach (array_filter(explode("\n", $o)) as $f) {
        if (!str_starts_with($f, WWW . '/') || !is_sqlite($f)) continue;
        $list[] = $f;
        if (count($list) >= 200) break;
    }
    sort($list);
    return $list;
}

function sqlite_path($p): string
{
    $p = (string)$p;
    $r = realpath($p);
    if ($r === false || !str_starts_with($r, WWW . '/') || !is_file($r) || !is_sqlite($r)) fail('Arquivo SQLite inválido (precisa estar dentro de ' . WWW . ').');
    return $r;
}

/** Abre o SQLite como o dono do arquivo; leitura em modo somente leitura. */
function lite(string $f, bool $write): PDO
{
    $st = stat($f);
    if (function_exists('posix_geteuid') && posix_geteuid() === 0 && $st['uid'] !== 0) {
        @posix_setegid($st['gid']);
        @posix_seteuid($st['uid']);
    }
    $opt = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_NUM];
    if (!$write && defined('PDO::SQLITE_ATTR_OPEN_FLAGS')) $opt[PDO::SQLITE_ATTR_OPEN_FLAGS] = PDO::SQLITE_OPEN_READONLY;
    try {
        return new PDO('sqlite:' . $f, null, null, $opt);
    } catch (Throwable $e) {
        fail('Não foi possível abrir o SQLite: ' . $e->getMessage());
    }
}

/* ------------------------------------------------------------------ fonte (banco aberto no visualizador) */

function src(array $req, bool $write = false): array
{
    $s = $req['src'] ?? [];
    if (($s['type'] ?? '') === 'mysql') {
        $db = db_name($s['db'] ?? '');
        return [my($db), 'mysql', $db];
    }
    if (($s['type'] ?? '') === 'sqlite') {
        $f = sqlite_path($s['path'] ?? '');
        return [lite($f, $write), 'sqlite', $f];
    }
    fail('Banco não informado');
}

function tables(PDO $p, string $kind, string $db): array
{
    $out = [];
    if ($kind === 'mysql') {
        foreach (q1($p, 'SELECT TABLE_NAME n, TABLE_TYPE t, TABLE_ROWS r, DATA_LENGTH + INDEX_LENGTH s FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME', [$db]) as $r) {
            $view = $r['t'] === 'VIEW';
            $rows = $r['r'] === null ? null : (int)$r['r'];
            $exact = false;
            if (!$view && ($rows === null || $rows < 50000)) {
                try { $rows = (int)$p->query('SELECT COUNT(*) FROM ' . qi($r['n'], $kind))->fetchColumn(); $exact = true; } catch (Throwable $e) { }
            }
            $out[] = ['name' => $r['n'], 'view' => $view, 'rows' => $rows, 'exact' => $exact, 'size' => $r['s'] === null ? null : (int)$r['s']];
        }
    } else {
        foreach ($p->query("SELECT name, type FROM sqlite_master WHERE type IN ('table','view') AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll() as [$n, $t]) {
            $rows = null;
            try { $rows = (int)$p->query('SELECT COUNT(*) FROM ' . qi($n, $kind))->fetchColumn(); } catch (Throwable $e) { }
            $out[] = ['name' => $n, 'view' => $t === 'view', 'rows' => $rows, 'exact' => true, 'size' => null];
        }
    }
    return $out;
}

function table_name(PDO $p, string $kind, string $db, $t): string
{
    $t = (string)$t;
    foreach (tables_light($p, $kind, $db) as $n) if ($n === $t) return $t;
    fail("Tabela não encontrada: $t");
}

function tables_light(PDO $p, string $kind, string $db): array
{
    return $kind === 'mysql'
        ? array_column(q1($p, 'SELECT TABLE_NAME n FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?', [$db]), 'n')
        : $p->query("SELECT name FROM sqlite_master WHERE type IN ('table','view')")->fetchAll(PDO::FETCH_COLUMN);
}

function columns(PDO $p, string $kind, string $db, string $t): array
{
    if ($kind === 'mysql') {
        return array_map(fn($r) => ['name' => $r['n'], 'type' => $r['t'], 'null' => $r['nl'] === 'YES', 'key' => $r['k'], 'default' => $r['d'], 'extra' => $r['e']],
            q1($p, 'SELECT COLUMN_NAME n, COLUMN_TYPE t, IS_NULLABLE nl, COLUMN_KEY k, COLUMN_DEFAULT d, EXTRA e FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$db, $t]));
    }
    $out = [];
    foreach ($p->query('PRAGMA table_info(' . qi($t, $kind) . ')')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = ['name' => $r['name'], 'type' => $r['type'], 'null' => !$r['notnull'], 'key' => $r['pk'] ? 'PRI' : '', 'default' => $r['dflt_value'], 'extra' => ''];
    }
    return $out;
}

/** Converte um valor para mostrar/exportar: binário vira marcador; texto longo é cortado (só na tela). */
function cell($v, int $cut)
{
    if ($v === null || is_int($v) || is_float($v)) return $v;
    $v = (string)$v;
    if (!mb_check_encoding($v, 'UTF-8')) return $cut ? '[binário ' . strlen($v) . ' bytes]' : '0x' . bin2hex($v);
    if ($cut && mb_strlen($v) > $cut) return mb_substr($v, 0, $cut) . '…';
    return $v;
}

function fetch_rows(PDOStatement $st, int $max, int $cut): array
{
    $cols = [];
    for ($i = 0; $i < $st->columnCount(); $i++) $cols[] = (string)($st->getColumnMeta($i)['name'] ?? "col$i");
    $rows = [];
    $bytes = 0;
    $more = false;
    while (($r = $st->fetch(PDO::FETCH_NUM)) !== false) {
        if (count($rows) >= $max || $bytes > MAX_BYTES) { $more = true; break; }
        $r = array_map(fn($v) => cell($v, $cut), $r);
        $bytes += strlen(json_encode($r, JSON_INVALID_UTF8_SUBSTITUTE) ?: '');
        $rows[] = $r;
    }
    $st->closeCursor();
    return [$cols, $rows, $more];
}

/** Remove comentários e textos entre aspas, para analisar o comando com segurança. */
function sql_skeleton(string $sql): string
{
    return (string)preg_replace(['/--[^\n]*|#[^\n]*|\/\*.*?\*\//s', "/'(?:[^'\\\\]|\\\\.|'')*'/s", '/"(?:[^"\\\\]|\\\\.|"")*"/s', '/`(?:[^`]|``)*`/s'], [' ', "''", '""', '``'], $sql);
}

function sql_check(string $sql, string $kind, bool $write): array
{
    $sk = trim(sql_skeleton($sql));
    $sk = rtrim($sk, "; \n\t\r");
    if ($sk === '') fail('Digite uma consulta.');
    if (str_contains($sk, ';')) fail('Execute um comando por vez (sem ";" no meio).');
    $verb = strtoupper((string)preg_split('/[\s(]+/', $sk)[0]);
    $read = $kind === 'mysql' ? ['SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN', 'WITH'] : ['SELECT', 'PRAGMA', 'EXPLAIN', 'WITH'];
    $wr = ['INSERT', 'UPDATE', 'DELETE', 'REPLACE'];
    if (preg_match('/\bINTO\s+(OUTFILE|DUMPFILE)\b|\bLOAD_FILE\s*\(|\bload_extension\s*\(/i', $sk)) fail('Este comando não é permitido no painel.');
    if ($verb === 'PRAGMA' && str_contains($sk, '=')) fail('PRAGMA que altera configuração não é permitido.');
    if (in_array($verb, $read, true)) return [$verb, false];
    if (in_array($verb, $wr, true)) {
        if (!$write) fail('Consulta em modo somente leitura. Ative "Permitir alterações" para rodar ' . $verb . '.', ['needs_write' => true]);
        return [$verb, true];
    }
    fail('Comando não permitido: ' . $verb . '. Use SELECT, SHOW, DESCRIBE, EXPLAIN' . ($kind === 'sqlite' ? ', PRAGMA' : '') . ' e, com alterações liberadas, INSERT, UPDATE ou DELETE.');
}

/* ------------------------------------------------------------------ ações */

$req = json_decode((string)stream_get_contents(STDIN), true);
if (!is_array($req)) fail('Requisição inválida');
if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) fail('A aba Bancos precisa de acesso root no painel.');
$action = (string)($req['action'] ?? '');

switch ($action) {

    case 'overview': {
        $env = sites_env();
        $mysql = ['ok' => false, 'error' => null, 'version' => null];
        $dbs = [];
        $users = [];
        if (!mysql_socket()) {
            $mysql['error'] = 'MySQL/MariaDB não encontrado ou parado.';
        } elseif (!extension_loaded('pdo_mysql')) {
            $mysql['error'] = 'A extensão MySQL do PHP (php-mysql) não está instalada.';
        } else {
            try {
                $mysql['version'] = (string)my()->query('SELECT VERSION()')->fetchColumn();
                $mysql['ok'] = true;
                $users = my_users();
                $stats = [];
                foreach (q1(my(), 'SELECT TABLE_SCHEMA d, COUNT(*) n, SUM(DATA_LENGTH + INDEX_LENGTH) s FROM information_schema.TABLES GROUP BY TABLE_SCHEMA') as $r) $stats[$r['d']] = $r;
                foreach (my_dbs() as $d) {
                    $dbs[] = [
                        'name' => $d, 'tables' => (int)($stats[$d]['n'] ?? 0), 'size' => (int)($stats[$d]['s'] ?? 0),
                        'users' => array_values(array_map(fn($u) => $u['user'], array_filter($users, fn($u) => in_array($d, $u['dbs'], true)))),
                        'only' => array_values(array_map(fn($u) => $u['user'], array_filter($users, fn($u) => $u['dbs'] === [$d] && $u['host'] === 'localhost'))),
                        'sites' => array_values(array_map(fn($e) => $e['site'], array_filter($env, fn($e) => $e['conn'] !== 'sqlite' && $e['db'] === $d))),
                    ];
                }
            } catch (Throwable $e) {
                $mysql['error'] = $e->getMessage();
            }
        }
        $lite = [];
        foreach (sqlite_files() as $f) {
            $lite[] = ['path' => $f, 'size' => filesize($f), 'mtime' => filemtime($f), 'site' => explode('/', substr($f, strlen(WWW) + 1))[0],
                'used' => (bool)array_filter($env, fn($e) => $e['conn'] === 'sqlite' && realpath($e['db']) === $f)];
        }
        foreach ($users as &$u) $u['sites'] = array_values(array_map(fn($e) => $e['site'], array_filter($env, fn($e) => $e['user'] === $u['user'] && $e['conn'] !== 'sqlite')));
        unset($u);
        out(['mysql' => $mysql, 'dbs' => $dbs, 'users' => $users, 'sqlite' => $lite]);
    }

    case 'create': {
        $name = (string)($req['name'] ?? '');
        $user = (string)($req['user'] ?? '') ?: substr($name, 0, 32);
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $name) || in_array(strtolower($name), SYS_DBS, true)) fail('Nome do banco inválido: use letras, números e _ (até 64).');
        if (!preg_match('/^[A-Za-z0-9_]{1,32}$/', $user) || in_array($user, SYS_USERS, true)) fail('Usuário inválido: use letras, números e _ (até 32).');
        if (in_array($name, my()->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN), true)) fail("Já existe um banco chamado $name.");
        if (array_filter(my_users(), fn($u) => $u['user'] === $user)) fail("O usuário $user já existe. Escolha outro nome (ou use Trocar senha).");
        $pass = gen_pass();
        $p = my();
        try {
            $p->exec('CREATE DATABASE ' . qi($name, 'mysql') . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $p->exec('CREATE USER ' . $p->quote($user) . "@'localhost' IDENTIFIED BY " . $p->quote($pass));
            $p->exec('GRANT ALL PRIVILEGES ON ' . qi($name, 'mysql') . '.* TO ' . $p->quote($user) . "@'localhost'");
            $p->exec('FLUSH PRIVILEGES');
        } catch (Throwable $e) {
            fail('Falha ao criar: ' . $e->getMessage());
        }
        @mkdir('/etc/lk', 0700, true);
        @file_put_contents(LK_DBFILE, date('Y-m-d H:i:s') . " | db=$name | user=$user | pass=$pass | host=localhost\n", FILE_APPEND);
        @chmod(LK_DBFILE, 0600);
        out(['db' => ['name' => $name, 'user' => $user, 'pass' => $pass, 'host' => 'localhost']]);
    }

    case 'drop': {
        $name = db_name($req['name'] ?? '');
        if ((string)($req['confirm'] ?? '') !== $name) fail('Confirmação não confere: digite o nome do banco exatamente.');
        if (!is_dir(DROP_BACKUPS)) mkdir(DROP_BACKUPS, 0700, true);
        @chmod(dirname(DROP_BACKUPS), 0700);
        $f = DROP_BACKUPS . '/' . $name . '-' . date('Ymd-His') . '.sql.gz';
        [$rc, $o] = run(['/bin/bash', '-o', 'pipefail', '-c', 'timeout 1800 mysqldump --single-transaction --routines --triggers --events --databases ' . escapeshellarg($name) . ' | gzip > ' . escapeshellarg($f)]);
        if ($rc !== 0 || !is_file($f) || filesize($f) < 20) {
            @unlink($f);
            fail('O backup antes de apagar falhou; nada foi apagado.', ['detail' => $o]);
        }
        @chmod($f, 0600);
        $dropUsers = array_values(array_intersect((array)($req['users'] ?? []), array_map(fn($u) => $u['user'], array_filter(my_users(), fn($u) => $u['dbs'] === [$name] && $u['host'] === 'localhost'))));
        try {
            my()->exec('DROP DATABASE ' . qi($name, 'mysql'));
            foreach ($dropUsers as $u) my()->exec('DROP USER ' . my()->quote($u) . "@'localhost'");
            my()->exec('FLUSH PRIVILEGES');
        } catch (Throwable $e) {
            fail('Falha ao apagar: ' . $e->getMessage(), ['backup' => $f]);
        }
        out(['backup' => $f, 'size' => filesize($f), 'users' => $dropUsers]);
    }

    case 'password': {
        $user = (string)($req['user'] ?? '');
        $match = array_values(array_filter(my_users(), fn($u) => $u['user'] === $user && $u['host'] === 'localhost'));
        if (!$match) fail("Usuário não encontrado: $user");
        $pass = (string)($req['pass'] ?? '');
        if ($pass === '') $pass = gen_pass();
        elseif (strlen($pass) < 8 || strlen($pass) > 64 || preg_match('/[\s\'"\\\\`$]/', $pass)) fail('Senha inválida: use de 8 a 64 caracteres, sem espaços, aspas, barra invertida, crase ou $.');
        try {
            my()->exec('ALTER USER ' . my()->quote($user) . "@'localhost' IDENTIFIED BY " . my()->quote($pass));
            my()->exec('FLUSH PRIVILEGES');
        } catch (Throwable $e) {
            fail('Falha ao trocar a senha: ' . $e->getMessage());
        }
        $updated = [];
        $want = (array)($req['sites'] ?? []);
        foreach (sites_env() as $e) {
            if ($e['user'] !== $user || !in_array($e['site'], $want, true)) continue;
            $f = $e['root'] . '/.env';
            $val = preg_match('/^[A-Za-z0-9_]+$/', $pass) ? $pass : '"' . $pass . '"';
            $c = (string)file_get_contents($f);
            $c = preg_match('/^DB_PASSWORD=.*$/m', $c) ? preg_replace('/^DB_PASSWORD=.*$/m', 'DB_PASSWORD=' . str_replace(['\\', '$'], ['\\\\', '\\$'], $val), $c) : rtrim($c) . "\nDB_PASSWORD=$val\n";
            $st = stat($f);
            if (file_put_contents($f, $c) === false) continue;
            @chown($f, $st['uid']);
            @chgrp($f, $st['gid']);
            @chmod($f, $st['mode'] & 07777);
            if (is_file($e['root'] . '/artisan')) run(['/usr/sbin/runuser', '-u', 'www-data', '--', 'timeout', '60', PHP_BINARY, $e['root'] . '/artisan', 'config:clear']);
            $updated[] = $e['site'];
        }
        out(['user' => $user, 'pass' => $pass, 'updated' => $updated]);
    }

    case 'tables': {
        [$p, $kind, $db] = src($req);
        out(['tables' => tables($p, $kind, $db)]);
    }

    case 'columns': {
        [$p, $kind, $db] = src($req);
        $t = table_name($p, $kind, $db, $req['table'] ?? '');
        out(['columns' => columns($p, $kind, $db, $t)]);
    }

    case 'rows': {
        [$p, $kind, $db] = src($req);
        $t = table_name($p, $kind, $db, $req['table'] ?? '');
        $cols = columns($p, $kind, $db, $t);
        $page = max(1, (int)($req['page'] ?? 1));
        $q = trim((string)($req['q'] ?? ''));
        $where = '';
        $args = [];
        if ($q !== '') {
            $parts = [];
            foreach ($cols as $i => $c) {
                $parts[] = 'CAST(' . qi($c['name'], $kind) . ' AS ' . ($kind === 'mysql' ? 'CHAR' : 'TEXT') . ") LIKE ?";
                $args[] = '%' . $q . '%';
            }
            $where = ' WHERE ' . implode(' OR ', $parts);
        }
        $order = '';
        $sort = (string)($req['sort'] ?? '');
        if ($sort !== '' && in_array($sort, array_column($cols, 'name'), true)) $order = ' ORDER BY ' . qi($sort, $kind) . (!empty($req['desc']) ? ' DESC' : ' ASC');
        try {
            if ($kind === 'mysql') $p->exec('START TRANSACTION READ ONLY');
            $st = $p->prepare('SELECT COUNT(*) FROM ' . qi($t, $kind) . $where);
            $st->execute($args);
            $total = (int)$st->fetchColumn();
            $st = $p->prepare('SELECT * FROM ' . qi($t, $kind) . $where . $order . ' LIMIT ' . PAGE . ' OFFSET ' . (($page - 1) * PAGE));
            $st->execute($args);
            [$names, $rows] = fetch_rows($st, PAGE, 500);
            if ($kind === 'mysql') $p->exec('ROLLBACK');
        } catch (Throwable $e) {
            fail('Erro ao ler a tabela: ' . $e->getMessage());
        }
        out(['columns' => $names, 'rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => max(1, (int)ceil($total / PAGE)), 'per' => PAGE]);
    }

    case 'query':
    case 'export': {
        $sql = trim((string)($req['sql'] ?? ''));
        $table = (string)($req['table'] ?? '');
        $wantWrite = $action === 'query' && !empty($req['write']);
        [$p, $kind, $db] = src($req, $wantWrite);
        if ($action === 'export' && $table !== '') $sql = 'SELECT * FROM ' . qi(table_name($p, $kind, $db, $table), $kind);
        if (strlen($sql) > 100000) fail('Consulta muito grande.');
        [$verb, $isWrite] = sql_check($sql, $kind, $wantWrite);
        if ($action === 'export' && $isWrite) fail('Só é possível exportar o resultado de consultas.');
        $t0 = microtime(true);
        try {
            if (!$isWrite) {
                if ($kind === 'mysql') $p->exec('START TRANSACTION READ ONLY');
                $st = $p->query(rtrim($sql, "; \n\t\r"));
                [$cols, $rows, $more] = fetch_rows($st, $action === 'export' ? MAX_EXPORT : MAX_ROWS, $action === 'export' ? 0 : 2000);
                if ($kind === 'mysql') $p->exec('ROLLBACK');
                out(['columns' => $cols, 'rows' => $rows, 'more' => $more, 'ms' => (int)round((microtime(true) - $t0) * 1000), 'verb' => $verb]);
            }
            $n = $p->exec(rtrim($sql, "; \n\t\r"));
            out(['affected' => (int)$n, 'ms' => (int)round((microtime(true) - $t0) * 1000), 'verb' => $verb, 'columns' => [], 'rows' => []]);
        } catch (Throwable $e) {
            $m = $e->getMessage();
            if (stripos($m, 'max_statement_time') !== false || stripos($m, 'maximum statement execution time') !== false) $m = 'A consulta passou do limite de 30 segundos e foi cancelada.';
            if (stripos($m, 'READ ONLY transaction') !== false || stripos($m, 'readonly database') !== false) $m = 'Consulta em modo somente leitura: este comando tenta alterar dados.';
            fail($m);
        }
    }

    default:
        fail('Ação desconhecida: ' . $action);
}
