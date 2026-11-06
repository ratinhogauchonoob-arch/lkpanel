#!/usr/bin/env python3
"""
LK Monitor - terminal web (PTY + WebSocket).

Roda como o usuário lkmonitor (serviço lkmonitor-term), escutando só em 127.0.0.1. O Apache encaminha
/term-ws do painel para cá. Cada conexão precisa de um ticket de uso único criado pelo painel (index.php,
depois do login) em LKM_DATA/term/t-<ticket>.json, válido por 60 s.

Cada aba do terminal é uma sessão: um shell de login aberto com "sudo -n -u <usuário do terminal> -i",
a mesma permissão do terminal antigo. A sessão continua viva por LKM_TERM_TTL segundos (padrão 30 min)
depois que o navegador fecha ou cai, e volta com a tela como estava ao reconectar.

Protocolo (cliente -> servidor, texto): "i<texto digitado>", "r<colunas>,<linhas>", "k" (fechar a sessão).
Servidor -> cliente: binário = saída do terminal; texto = JSON de controle (hello, exit, kicked, gone, error).
"""
import asyncio
import base64
import fcntl
import hashlib
import json
import os
import pty
import re
import secrets
import signal
import struct
import sys
import termios
import time
from urllib.parse import parse_qs, urlsplit

DATA = os.environ.get('LKM_DATA', '/var/lib/lkmonitor')
TDIR = os.path.join(DATA, 'term')
CONFIG = os.environ.get('LKM_CONFIG', '/var/www/lkmonitor/config.php')
CMDLOG = os.path.join(DATA, 'cmd.log')
TTL = max(60, int(os.environ.get('LKM_TERM_TTL', '1800') or 1800))
TEST_SHELL = os.environ.get('LKM_TERM_TEST_SHELL', '')  # só para testes: abre este shell em vez do sudo
MAX_SESSIONS = 8
BUF_MAX = 512 * 1024          # tela guardada para quando o navegador reconectar
MAX_MSG = 1024 * 1024         # maior mensagem aceita do navegador
PAUSE_AT = 2 * 1024 * 1024    # navegador lento: para de ler o terminal até esvaziar
GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11'
PATH = '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'

SESSIONS = {}


def log(msg):
    sys.stderr.write(time.strftime('[%H:%M:%S] ') + msg + '\n')
    sys.stderr.flush()


def cmdlog(ip, msg):
    try:
        with open(CMDLOG, 'a', encoding='utf-8') as f:
            f.write('%s %s [terminal] %s\n' % (time.strftime('%Y-%m-%d %H:%M:%S'), ip or '?', msg))
    except OSError:
        pass


def shell_user():
    """Usuário do terminal, lido do config.php do painel (o mesmo do terminal simples)."""
    try:
        with open(CONFIG, encoding='utf-8') as f:
            m = re.search(r"define\('LKM_SHELL_USER', '([^']*)'\)", f.read())
    except OSError:
        return ''
    u = m.group(1) if m else ''
    return u if re.fullmatch(r'[a-z_][a-z0-9_.-]{0,31}\$?', u) else ''


def save_state():
    """Lista das sessões para o painel (index.php ?term=list)."""
    now = time.time()
    lst = [{'id': s.sid, 'created': int(s.created), 'last': int(s.last), 'attached': s.client is not None,
            'idle': 0 if s.client else int(now - s.detached)} for s in SESSIONS.values()]
    tmp = os.path.join(TDIR, '.sessions.%d.tmp' % os.getpid())
    try:
        with open(tmp, 'w') as f:
            json.dump({'pid': os.getpid(), 'ttl': TTL, 'sessions': lst}, f)
        os.chmod(tmp, 0o600)
        os.replace(tmp, os.path.join(TDIR, 'sessions.json'))
    except OSError:
        pass


def take_ticket(t):
    """Lê e apaga o ticket (uso único). Devolve o conteúdo ou None."""
    if not re.fullmatch(r'[a-f0-9]{32}', t or ''):
        return None
    p = os.path.join(TDIR, 't-%s.json' % t)
    try:
        with open(p) as f:
            d = json.load(f)
    except (OSError, ValueError):
        return None
    finally:
        try:
            os.unlink(p)
        except OSError:
            pass
    if not isinstance(d, dict) or float(d.get('exp', 0)) < time.time():
        return None
    return d


def clamp(v, lo, hi, dflt):
    try:
        return max(lo, min(hi, int(v)))
    except (TypeError, ValueError):
        return dflt


# --------------------------------------------------------------------------- WebSocket


class Client:
    def __init__(self, reader, writer, ip):
        self.r = reader
        self.w = writer
        self.ip = ip
        self.closed = False

    def frame(self, op, payload=b''):
        if self.closed:
            return
        n = len(payload)
        if n < 126:
            hdr = struct.pack('!BB', 0x80 | op, n)
        elif n < 65536:
            hdr = struct.pack('!BBH', 0x80 | op, 126, n)
        else:
            hdr = struct.pack('!BBQ', 0x80 | op, 127, n)
        try:
            self.w.write(hdr + payload)
        except (ConnectionError, RuntimeError):
            self.closed = True

    def send_bin(self, b):
        self.frame(0x2, bytes(b))

    def send_json(self, o):
        self.frame(0x1, json.dumps(o, ensure_ascii=False).encode('utf-8'))

    def backlog(self):
        tr = self.w.transport
        return tr.get_write_buffer_size() if tr and not tr.is_closing() else 0

    async def drain(self):
        try:
            await self.w.drain()
        except (ConnectionError, RuntimeError):
            self.closed = True

    def close(self, code=1000):
        if self.closed:
            return
        self.frame(0x8, struct.pack('!H', code))
        self.closed = True
        try:
            self.w.close()
        except (ConnectionError, RuntimeError):
            pass

    async def recv(self):
        """Próxima mensagem completa: (opcode, bytes). None quando a conexão fecha."""
        parts = []
        first_op = None
        while True:
            try:
                b1, b2 = await self.r.readexactly(2)
                n = b2 & 0x7F
                if n == 126:
                    n = struct.unpack('!H', await self.r.readexactly(2))[0]
                elif n == 127:
                    n = struct.unpack('!Q', await self.r.readexactly(8))[0]
                if n > MAX_MSG or not (b2 & 0x80):          # cliente sempre manda mascarado
                    self.close(1009 if n > MAX_MSG else 1002)
                    return None
                mask = await self.r.readexactly(4)
                data = await self.r.readexactly(n)
            except (asyncio.IncompleteReadError, ConnectionError, OSError):
                return None
            if n:
                data = bytes(a ^ mask[i & 3] for i, a in enumerate(data)) if n < 64 else _unmask(data, mask)
            op = b1 & 0x0F
            fin = b1 & 0x80
            if op == 0x8:
                self.close()
                return None
            if op == 0x9:
                self.frame(0xA, data)
                continue
            if op == 0xA:
                continue
            if op in (0x1, 0x2):
                first_op = op
                parts = [data]
            elif op == 0x0 and first_op is not None:
                parts.append(data)
                if sum(len(p) for p in parts) > MAX_MSG:
                    self.close(1009)
                    return None
            else:
                self.close(1002)
                return None
            if fin:
                return first_op, b''.join(parts)


def _unmask(data, mask):
    n = len(data)
    m = (mask * (n // 4 + 1))[:n]
    return (int.from_bytes(data, 'big') ^ int.from_bytes(m, 'big')).to_bytes(n, 'big')


# --------------------------------------------------------------------------- sessões


class Session:
    def __init__(self, user, cols, rows):
        self.sid = secrets.token_hex(16)
        self.user = user
        self.created = self.last = self.detached = time.time()
        self.buf = bytearray()
        self.client = None
        self.alive = True
        self.reading = False
        self.wq = bytearray()
        self.code = None
        loop = asyncio.get_running_loop()
        pid, fd = pty.fork()
        if pid == 0:                                       # filho: vira o shell
            try:
                fcntl.ioctl(0, termios.TIOCSWINSZ, struct.pack('HHHH', rows, cols, 0, 0))
                env = {'TERM': 'xterm-256color', 'COLORTERM': 'truecolor', 'PATH': PATH,
                       'LANG': os.environ.get('LANG') or 'C.UTF-8'}
                if TEST_SHELL:
                    env['HOME'] = os.environ.get('HOME', '/')
                    os.execve(TEST_SHELL, [TEST_SHELL, '-l'], env)
                os.execve('/usr/bin/sudo', ['sudo', '-n', '-u', user, '-i'], env)
            except Exception:
                pass
            os._exit(127)
        self.pid = pid
        self.fd = fd
        os.set_blocking(fd, False)
        self.loop = loop
        self._read_on()

    def _read_on(self):
        if self.alive and not self.reading:
            self.loop.add_reader(self.fd, self.on_read)
            self.reading = True

    def _read_off(self):
        if self.reading:
            self.loop.remove_reader(self.fd)
            self.reading = False

    def on_read(self):
        try:
            data = os.read(self.fd, 65536)
        except (BlockingIOError, InterruptedError):
            return
        except OSError:
            data = b''
        if not data:
            self.ended()
            return
        self.last = time.time()
        self.buf += data
        if len(self.buf) > BUF_MAX:
            cut = len(self.buf) - BUF_MAX
            nl = self.buf.find(b'\n', cut, cut + 8192)        # corta numa quebra de linha
            del self.buf[:nl + 1 if nl >= 0 else cut]
        c = self.client
        if c:
            c.send_bin(data)
            if c.backlog() > PAUSE_AT:
                self._read_off()
                asyncio.ensure_future(self._resume(c))

    async def _resume(self, c):
        try:
            await c.drain()
        finally:
            self._read_on()

    def write(self, data):
        if not self.alive or not data:
            return
        self.last = time.time()
        self.wq += data
        self._flush()

    def _flush(self):
        try:
            while self.wq:
                n = os.write(self.fd, self.wq[:65536])
                del self.wq[:n]
        except (BlockingIOError, InterruptedError):
            self.loop.add_writer(self.fd, self._flush_ready)
            return
        except OSError:
            self.wq.clear()
        self.loop.remove_writer(self.fd)

    def _flush_ready(self):
        self.loop.remove_writer(self.fd)
        self._flush()

    def resize(self, cols, rows):
        if self.alive:
            try:
                fcntl.ioctl(self.fd, termios.TIOCSWINSZ, struct.pack('HHHH', rows, cols, 0, 0))
            except OSError:
                pass

    def attach(self, c, cols, rows):
        old = self.client
        if old is not None and old is not c:
            old.send_json({'t': 'kicked'})
            old.close(4001)
        self.client = c
        self.resize(cols, rows)
        c.send_json({'t': 'hello', 'sid': self.sid, 'user': self.user, 'ttl': TTL})
        for i in range(0, len(self.buf), 65536):
            c.send_bin(self.buf[i:i + 65536])
        save_state()

    def detach(self, c):
        if self.client is c:
            self.client = None
            self.detached = time.time()
            save_state()

    def reap(self):
        if self.code is not None:
            return True
        try:
            pid, st = os.waitpid(self.pid, os.WNOHANG)
        except ChildProcessError:
            self.code = -1
            return True
        if pid == 0:
            return False
        self.code = os.waitstatus_to_exitcode(st) if hasattr(os, 'waitstatus_to_exitcode') else (st >> 8)
        return True

    def ended(self):
        if not self.alive:
            return
        self.alive = False
        self._read_off()
        try:
            self.loop.remove_writer(self.fd)
        except (ValueError, OSError):
            pass
        try:
            os.close(self.fd)
        except OSError:
            pass
        for _ in range(20):
            if self.reap():
                break
            time.sleep(0.01)
        c = self.client
        if c:
            c.send_json({'t': 'exit', 'code': self.code})
            c.close()
        SESSIONS.pop(self.sid, None)
        save_state()
        cmdlog(c.ip if c else '', 'sessão %s encerrada' % self.sid[:6])

    def kill(self):
        if not self.alive:
            return
        for sig in (signal.SIGHUP, signal.SIGCONT):
            try:
                os.kill(self.pid, sig)
            except OSError:
                pass

        def force():
            if self.alive and not self.reap():
                try:
                    os.kill(self.pid, signal.SIGKILL)
                except OSError:
                    pass
            if self.alive and self.reap():
                self.ended()
        self.loop.call_later(3, force)


# --------------------------------------------------------------------------- conexões


def http_error(writer, code, text):
    body = text.encode('utf-8')
    try:
        writer.write(('HTTP/1.1 %d %s\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Length: %d\r\n'
                      'Connection: close\r\n\r\n' % (code, {400: 'Bad Request', 403: 'Forbidden'}.get(code, 'Error'), len(body))).encode() + body)
        writer.close()
    except (ConnectionError, RuntimeError):
        pass


async def pinger(c):
    while not c.closed:
        await asyncio.sleep(25)
        c.frame(0x9, b'lk')


async def handle(reader, writer):
    try:
        head = await asyncio.wait_for(reader.readuntil(b'\r\n\r\n'), 10)
    except (asyncio.IncompleteReadError, asyncio.LimitOverrunError, asyncio.TimeoutError, ConnectionError, OSError):
        writer.close()
        return
    lines = head.decode('latin-1').split('\r\n')
    try:
        method, target, _ = lines[0].split(' ', 2)
    except ValueError:
        return http_error(writer, 400, 'requisição inválida')
    hd = {}
    for ln in lines[1:]:
        if ':' in ln:
            k, v = ln.split(':', 1)
            hd[k.strip().lower()] = v.strip()
    u = urlsplit(target)
    key = hd.get('sec-websocket-key', '')
    if method != 'GET' or not u.path.endswith('/term-ws') or 'websocket' not in hd.get('upgrade', '').lower() or not key:
        return http_error(writer, 400, 'use WebSocket')
    # a página precisa ser do próprio painel (o Apache informa o host original em X-Forwarded-Host)
    origin = hd.get('origin', '')
    host = (hd.get('x-forwarded-host') or hd.get('host') or '').split(',')[0].strip().lower()
    if origin and host and urlsplit(origin).netloc.lower() != host:
        return http_error(writer, 403, 'origem não permitida')
    tk = take_ticket(parse_qs(u.query).get('t', [''])[0])
    if not tk:
        return http_error(writer, 403, 'ticket inválido ou vencido')
    peer = writer.get_extra_info('peername')
    ip = (hd.get('x-forwarded-for') or (peer[0] if peer else '?')).split(',')[0].strip()
    accept = base64.b64encode(hashlib.sha1((key + GUID).encode()).digest()).decode()
    writer.write(('HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n'
                  'Sec-WebSocket-Accept: %s\r\n\r\n' % accept).encode())
    c = Client(reader, writer, ip)
    cols = clamp(tk.get('cols'), 10, 500, 100)
    rows = clamp(tk.get('rows'), 4, 300, 30)

    sid = str(tk.get('sid') or '')
    if sid:
        s = SESSIONS.get(sid)
        if not s or not s.alive:
            c.send_json({'t': 'gone'})
            c.close()
            return
    else:
        user = '' if TEST_SHELL else shell_user()
        if not user and not TEST_SHELL:
            c.send_json({'t': 'error', 'msg': 'Terminal desativado na instalação do LK Monitor.'})
            c.close()
            return
        if len(SESSIONS) >= MAX_SESSIONS:
            c.send_json({'t': 'error', 'msg': 'Limite de %d terminais abertos. Feche algum antes de abrir outro.' % MAX_SESSIONS})
            c.close()
            return
        try:
            s = Session(user or os.environ.get('USER', 'teste'), cols, rows)
        except OSError as e:
            c.send_json({'t': 'error', 'msg': 'Não foi possível abrir o terminal: %s' % e})
            c.close()
            return
        SESSIONS[s.sid] = s
        cmdlog(ip, 'sessão %s aberta (%s)' % (s.sid[:6], s.user))
    s.attach(c, cols, rows)
    ping = asyncio.ensure_future(pinger(c))
    try:
        while True:
            m = await c.recv()
            if m is None:
                break
            op, data = m
            if op == 0x2:
                s.write(data)
                continue
            if not data:
                continue
            k = data[:1]
            if k == b'i':
                s.write(data[1:])
            elif k == b'r':
                try:
                    a, b = data[1:].decode().split(',')
                    s.resize(clamp(a, 10, 500, 100), clamp(b, 4, 300, 30))
                except ValueError:
                    pass
            elif k == b'k':
                s.kill()
            if not s.alive:
                break
    finally:
        ping.cancel()
        s.detach(c)
        c.close()


async def janitor():
    """Fecha sessões esquecidas e recolhe processos que terminaram."""
    while True:
        await asyncio.sleep(15)
        now = time.time()
        for s in list(SESSIONS.values()):
            if s.client is None and now - s.detached > TTL:
                log('sessão %s sem uso há %d min: fechando' % (s.sid[:6], TTL // 60))
                s.kill()
            elif s.reap():
                s.ended()
        try:
            while True:
                pid, _ = os.waitpid(-1, os.WNOHANG)
                if pid == 0:
                    break
        except ChildProcessError:
            pass
        # tickets que ninguém usou
        try:
            for n in os.listdir(TDIR):
                p = os.path.join(TDIR, n)
                if n.startswith('t-') and now - os.path.getmtime(p) > 300:
                    os.unlink(p)
        except OSError:
            pass


async def main():
    port = 7681
    args = sys.argv[1:]
    if '--port' in args:
        port = int(args[args.index('--port') + 1])
    os.makedirs(TDIR, mode=0o700, exist_ok=True)
    save_state()
    srv = await asyncio.start_server(handle, '127.0.0.1', port, limit=65536)
    log('terminal do LK Monitor escutando em 127.0.0.1:%d (sessões ficam %d min sem uso)' % (port, TTL // 60))
    loop = asyncio.get_running_loop()
    stop = loop.create_future()
    for sig in (signal.SIGTERM, signal.SIGINT):
        loop.add_signal_handler(sig, lambda: stop.done() or stop.set_result(True))
    asyncio.ensure_future(janitor())
    async with srv:
        await stop
    for s in list(SESSIONS.values()):
        s.kill()
    SESSIONS.clear()
    save_state()


if __name__ == '__main__':
    try:
        asyncio.run(main())
    except KeyboardInterrupt:
        pass
