#!/usr/bin/env python3
"""Gera dist/install-lk.sh a partir dos fontes (lk/ e lkmon/)."""
import base64, os, re, sys
R = os.path.dirname(os.path.abspath(__file__))
def rd(p): return open(os.path.join(R, p), encoding='utf-8', newline='').read()
def inc(m):
    kind, rel = m.group(1), m.group(2)
    p = os.path.join(R, 'lkmon', rel)
    if kind == 'FILE':
        s = rd('lkmon/' + rel)
        if not s.endswith('\n'): sys.exit('ERRO: ' + rel + ' precisa terminar com quebra de linha')
        return s[:-1]
    return base64.encodebytes(open(p, 'rb').read()).decode().rstrip('\n')
mon = rd('lkmon/install-lk-monitor.sh')
def incdir(m):
    rel, dest = m.group(1), m.group(2)
    d = os.path.join(R, 'lkmon', rel)
    out = ['rm -rf "%s"; mkdir -p "%s"' % (dest, dest)]
    for i, f in enumerate(sorted(os.listdir(d))):
        if f.startswith('.') or not os.path.isfile(os.path.join(d, f)): continue
        if not re.fullmatch(r'[a-z0-9_-]+\.(svg|png|webp|jpg)', f): sys.exit('ERRO: nome inválido em ' + rel + ': ' + f)
        mark = 'LKM_EOF_DIR_%d' % i
        out.append("base64 -d > \"%s/%s\" <<'%s'" % (dest, f, mark))
        out.append(base64.encodebytes(open(os.path.join(d, f), 'rb').read()).decode().rstrip('\n'))
        out.append(mark)
    out.append('chmod 755 "%s"; chmod 644 "%s"/* 2>/dev/null || true' % (dest, dest))
    return '\n'.join(out)
mon = re.sub(r'^@@DIR (\S+) (\S+)@@$', incdir, mon, flags=re.M)
mon = re.sub(r'^@@(FILE|BASE64) ([^@]+)@@$', inc, mon, flags=re.M)
# nenhum arquivo pode conter o marcador que fecha o próprio heredoc
for mark in re.findall(r"<<'(LKM_[A-Z0-9_]+)'", mon):
    if mon.count('\n' + mark + '\n') != 1: sys.exit('ERRO: marcador ' + mark + ' aparece mais de uma vez')
lk = rd('lk/lk')
for mark in ('LK_SCRIPT_EOF', 'LK_MONITOR_INSTALLER_EOF'):
    if ('\n' + mark + '\n') in lk or ('\n' + mark + '\n') in mon: sys.exit('ERRO: marcador ' + mark + ' dentro de um fonte')
# WhatsApp + bot: header.sh + panel/index.php + footer.sh (hook.php e guard.php embutidos), como o build.sh antigo do whatsapp-guardiao
for f, m in (('whatsapp/panel/index.php', 'LK_WA_PAGE_EOF'), ('whatsapp/guard/hook.php', 'LK_WA_HOOK_EOF'), ('whatsapp/guard/guard.php', 'LK_GUARD_EOF')):
    if ('\n' + m + '\n') in '\n' + rd(f): sys.exit('ERRO: ' + f + ' contém o marcador ' + m)
waf = rd('whatsapp/footer.sh').replace('@@HOOK_PHP@@\n', rd('whatsapp/guard/hook.php')).replace('@@GUARD_PHP@@\n', rd('whatsapp/guard/guard.php'))
wa = rd('whatsapp/header.sh') + rd('whatsapp/panel/index.php') + waf
if '\nLK_WHATSAPP_INSTALLER_EOF\n' in '\n' + wa: sys.exit('ERRO: marcador LK_WHATSAPP_INSTALLER_EOF dentro do instalador do WhatsApp')
os.makedirs(os.path.join(R, 'dist'), exist_ok=True)
open(os.path.join(R, 'dist/install-whatsapp.sh'), 'w', encoding='utf-8', newline='').write(wa)
foot = rd('lk/footer.sh').replace('@@WHATSAPP_INSTALLER@@\n', wa if wa.endswith('\n') else wa + '\n')
out = rd('lk/header.sh') + lk + rd('lk/middle.sh') + mon + foot
os.makedirs(os.path.join(R, 'dist'), exist_ok=True)
open(os.path.join(R, 'dist/install-lk.sh'), 'w', encoding='utf-8', newline='').write(out)
os.chmod(os.path.join(R, 'dist/install-lk.sh'), 0o755)
print('dist/install-lk.sh gerado (%d bytes)' % len(out.encode()))
# atualizar-lk.sh: o mesmo instalador, mas só atualiza um servidor que já tem o LK e o painel (não instala nada novo)
anchor = 'SHARE=/usr/local/share/lk\n'
assert out.count(anchor) >= 1
guard = anchor + """
# ---- MODO SÓ ATUALIZAR (atualizar-lk.sh) ----
# Não instala nada novo (LAMP, SSH, WhatsApp), não mexe em senhas, portas, sites, bancos nem no SSH.
LK_NO_SETUP=1
if [[ "${1:-}" == "uninstall" ]]; then echo "${R}Este arquivo só atualiza. Para remover use o install-lk.sh.${N}"; exit 1; fi
if [[ ! -f /var/www/lkmonitor/config.php ]] || [[ ! -e /usr/bin/lk && ! -e /usr/local/bin/lk ]]; then
  echo "${R}O LK e o painel não estão instalados aqui. Este arquivo só atualiza; para instalar use o install-lk.sh.${N}"; exit 1
fi
LK_UPD_BK=/root/lk-backups/antes-da-atualizacao-$(date +%F-%H%M%S)
mkdir -p "$LK_UPD_BK" && chmod 700 "$LK_UPD_BK"
cp -a "$(command -v lk || echo /usr/bin/lk)" /var/www/lkmonitor/config.php "$LK_UPD_BK"/ 2>/dev/null
[[ -f /var/lib/lkmonitor/pass.json ]] && cp -a /var/lib/lkmonitor/pass.json "$LK_UPD_BK"/
echo "${C}[..]${N} Só atualizando o LK e o painel (backup do lk e do config em $LK_UPD_BK)"
"""
upd = out.replace(anchor, guard, 1)
open(os.path.join(R, 'dist/atualizar-lk.sh'), 'w', encoding='utf-8', newline='').write(upd)
os.chmod(os.path.join(R, 'dist/atualizar-lk.sh'), 0o755)
print('dist/atualizar-lk.sh gerado')
