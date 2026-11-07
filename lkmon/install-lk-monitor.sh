#!/usr/bin/env bash
# =====================================================================
#  LK Monitor - painel web de uso do PC/servidor + mini terminal
#  Uso:
#    sudo bash install-lk-monitor.sh            instala / atualiza (com perguntas)
#    sudo bash install-lk-monitor.sh atualizar  atualiza mantendo porta, senha e usuário (sem perguntas)
#    sudo bash install-lk-monitor.sh senha      troca a senha
#    sudo bash install-lk-monitor.sh uninstall  remove
# =====================================================================
[[ $EUID -ne 0 ]] && exec sudo bash "$(readlink -f "$0")" "$@"

G=$'\e[32m'; R=$'\e[31m'; C=$'\e[36m'; Y=$'\e[33m'; N=$'\e[0m'
DIR=/var/www/lkmonitor
DATA=/var/lib/lkmonitor
CADIR=$DATA/ca
LKUSER=lkmonitor
SITE=/etc/apache2/sites-available/lkmonitor.conf
SUDOERS=/etc/sudoers.d/lkmonitor
LIBDIR=/usr/local/lib/lkmonitor
UNITS="lkmonitor-collect.timer lkmonitor-collect.service lkmonitor-backup.timer lkmonitor-backup.service lkmonitor-term.service"
ok()   { echo "${G}[OK]${N} $*"; }
info() { echo "${C}[..]${N} $*"; }
warn() { echo "${Y}[!]${N} $*"; }
die()  { echo "${R}[ERRO]${N} $*"; exit 1; }

cfg_get() { [[ -f $DIR/config.php ]] && grep -oP "define\('$1', '\K[^']*" "$DIR/config.php" | head -n1; }

# Senha temporária: o painel obriga a trocar no primeiro login (e pode trocar a do root junto)
gen_temp() { local a; a=$(tr -dc 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789' </dev/urandom 2>/dev/null | head -c 12); echo "${a:0:4}-${a:4:4}-${a:8:4}"; }

ask_pass() {
  local p1 p2
  TEMP_PASS=""
  if [[ ${LKM_AUTO:-0} == 1 ]]; then p1=$(gen_temp); TEMP_PASS=$p1
  else
    while true; do
      read -rsp "Senha do painel (Enter = gerar uma temporária e trocar no 1º login): " p1; echo
      if [[ -z $p1 ]]; then p1=$(gen_temp); TEMP_PASS=$p1; break; fi
      read -rsp "Repita a senha: " p2; echo
      [[ ${#p1} -ge 4 ]] || { warn "Use pelo menos 4 caracteres."; continue; }
      [[ $p1 == "$p2" ]] || { warn "As senhas não conferem."; continue; }
      break
    done
  fi
  PASS_HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' -- "$p1")
  [[ $PASS_HASH == \$* ]] || die "Falha ao gerar a senha (PHP CLI instalado?)"
  NEW_PASS=1
}

# Senha nova pelo instalador: apaga a senha trocada pelo painel e, se for temporária, marca "trocar no 1º login"
apply_pass_flags() {
  [[ ${NEW_PASS:-0} == 1 ]] || return 0
  rm -f "$DATA/pass.json" "$DATA/must-change"
  if [[ -n ${TEMP_PASS:-} ]]; then
    mkdir -p "$DATA"; date +%s > "$DATA/must-change"
    id "$LKUSER" >/dev/null 2>&1 && chown "$LKUSER:$LKUSER" "$DATA/must-change"
    chmod 600 "$DATA/must-change"
  fi
}

show_temp() {
  [[ -n ${TEMP_PASS:-} ]] || return 0
  echo "${Y}+-------------------------------------------------+${N}"
  echo "${Y}|${N}  Login do painel:   usuário ${G}root${N}"
  echo "${Y}|${N}  Senha temporária:  ${G}$TEMP_PASS${N}"
  echo "${Y}|${N}  No 1º login o painel pede uma senha nova"
  echo "${Y}|${N}  (e liga a conta root no SSH com ela)."
  echo "${Y}+-------------------------------------------------+${N}"
}

write_config() {
  local grp=www-data
  id "$LKUSER" >/dev/null 2>&1 && grp=$LKUSER
  local cli=/usr/bin/php
  [[ -n ${PHPV:-} && -x /usr/bin/php$PHPV ]] && cli=/usr/bin/php$PHPV
  printf "<?php\ndefine('LKM_PASS_HASH', '%s');\ndefine('LKM_USER', 'root');\ndefine('LKM_TITLE', 'LK Monitor');\ndefine('LKM_SHELL_USER', '%s');\ndefine('LKM_DATA', '%s');\ndefine('LKM_CMD_TIMEOUT', 30);\ndefine('LKM_PHP_CLI', '%s');\ndefine('LKM_FM_HELPER', '%s');\ndefine('LKM_HTTPS_PORT', '%s');\ndefine('LKM_TERM_PORT', '%s');\n" \
    "$PASS_HASH" "$SHELL_USER" "$DATA" "$cli" "$LIBDIR/fm.php" "${HTTPS_PORT:-0}" "${TERM_PORT:-0}" > "$DIR/config.php"
  chown root:"$grp" "$DIR/config.php"; chmod 640 "$DIR/config.php"
}

port_busy() { # port_busy <porta> -> verdadeiro se outra coisa usa a porta
  ss -ltnH "sport = :$1" 2>/dev/null | grep -q . && return 0
  grep -qsE "^\s*Listen\s+(\S+:)?$1(\s|$)" /etc/apache2/ports.conf && return 0
  return 1
}

case "${1:-}" in
  uninstall)
    systemctl disable --now lkmonitor-collect.timer lkmonitor-backup.timer lkmonitor-term.service >/dev/null 2>&1
    for u in $UNITS; do rm -f "/etc/systemd/system/$u"; done
    systemctl daemon-reload 2>/dev/null
    a2dissite lkmonitor >/dev/null 2>&1
    rm -f "$SITE" "$SUDOERS"
    for f in /etc/php/*/fpm/pool.d/lkmonitor.conf; do
      [[ -f $f ]] || continue
      v=$(echo "$f" | cut -d/ -f4); rm -f "$f"; systemctl restart "php$v-fpm" 2>/dev/null
    done
    rm -rf "$DIR" "$DATA" "$LIBDIR" /var/cache/lkmonitor
    id "$LKUSER" >/dev/null 2>&1 && userdel "$LKUSER" 2>/dev/null
    systemctl reload apache2 2>/dev/null
    ok "LK Monitor removido (painel, terminal, coletor de métricas, certificados, tarefas e histórico)"
    [[ -d /var/backups/lkmonitor ]] && info "Backups mantidos em /var/backups/lkmonitor (apague manualmente se quiser)"
    exit 0;;
  senha|password)
    [[ -f $DIR/config.php ]] || die "LK Monitor não está instalado"
    SHELL_USER=$(cfg_get LKM_SHELL_USER)
    HTTPS_PORT=$(cfg_get LKM_HTTPS_PORT)
    TERM_PORT=$(cfg_get LKM_TERM_PORT)
    PHPV=$(cfg_get LKM_PHP_CLI | grep -oE '[0-9]+\.[0-9]+$')
    ask_pass; write_config; apply_pass_flags; ok "Senha alterada"; show_temp; exit 0;;
  atualizar|update)
    MODE=update;;
  ""|instalar|install)
    MODE=install;;
  *) die "Opção desconhecida: $1 (use: atualizar, senha ou uninstall)";;
esac

[[ -d /etc/apache2 ]] || die "Apache não encontrado. Instale o LAMP antes (lk -> opção 1)."
command -v php >/dev/null || die "PHP CLI não encontrado. Instale o LAMP antes (lk -> opção 1)."
PHPV=""
for v in $(ls /etc/php 2>/dev/null | grep -E '^[0-9]+\.[0-9]+$' | sort -V); do [[ -d /etc/php/$v/fpm/pool.d ]] && PHPV=$v; done
[[ -n $PHPV ]] || die "PHP-FPM não encontrado. Instale o LAMP antes (lk -> opção 1)."

# ---------------------------------------------------------------- perguntas
OLDPORT=""; OLDHTTPS=""
[[ -f $SITE ]] && OLDPORT=$(grep -m1 -oE '^Listen [0-9]+' "$SITE" | awk '{print $2}')
[[ -f $SITE ]] && OLDHTTPS=$(grep -m1 -oE '^Listen [0-9]+ https' "$SITE" | awk '{print $2}')
[[ -f $DIR/config.php ]] && grep -q "LKM_HTTPS_PORT" "$DIR/config.php" && [[ -z $OLDHTTPS ]] && OLDHTTPS=$(cfg_get LKM_HTTPS_PORT)

pick_https() { # escolhe uma porta HTTPS livre a partir de 8443
  local p
  for p in 8443 8444 8445 8446 8447 8448 8449 9443; do
    [[ $p == "$PORT" ]] && continue
    port_busy "$p" || { echo "$p"; return 0; }
  done
  echo 0
}

if [[ $MODE == update ]]; then
  [[ -f $DIR/config.php && -n $OLDPORT ]] || die "LK Monitor não está instalado. Use: sudo bash $0"
  PORT=$OLDPORT
  REQ=$(grep -m1 -oE 'Require (all granted|ip [0-9a-fA-F:./ ]+)' "$SITE")
  [[ -n $REQ ]] || REQ="Require ip 127.0.0.1 ::1 10.0.0.0/8 172.16.0.0/12 192.168.0.0/16 100.64.0.0/10"
  SHELL_USER=$(cfg_get LKM_SHELL_USER)
  PASS_HASH=$(cfg_get LKM_PASS_HASH)
  [[ -n $PASS_HASH ]] || die "Não foi possível ler a senha atual do painel em $DIR/config.php"
  if [[ -n $OLDHTTPS ]]; then HTTPS_PORT=$OLDHTTPS; else HTTPS_PORT=$(pick_https); fi
  info "Atualizando o LK Monitor (porta $PORT$([[ $HTTPS_PORT != 0 ]] && echo ", HTTPS $HTTPS_PORT"), terminal: ${SHELL_USER:-desativado})"
elif [[ ${LKM_AUTO:-0} == 1 ]]; then
  # instalação automática (lk setup): tudo no padrão, senha temporária
  PORT=${OLDPORT:-8088}
  [[ -z $OLDPORT ]] && port_busy "$PORT" && die "A porta $PORT já está em uso."
  HTTPS_PORT=${OLDHTTPS:-$(pick_https)}
  REQ="Require ip 127.0.0.1 ::1 10.0.0.0/8 172.16.0.0/12 192.168.0.0/16 100.64.0.0/10"
  SHELL_USER=root
  ask_pass
  info "Instalação automática: porta $PORT, HTTPS $HTTPS_PORT, só rede local / Tailscale, terminal como root"
else
  read -rp "Porta do painel [${OLDPORT:-8088}]: " PORT
  PORT=${PORT:-${OLDPORT:-8088}}
  [[ $PORT =~ ^[0-9]+$ ]] && ((PORT >= 1024 && PORT <= 65535)) || die "Porta inválida (use 1024 a 65535)"
  if [[ $PORT != "$OLDPORT" && $PORT != "$OLDHTTPS" ]] && port_busy "$PORT"; then
    die "A porta $PORT já está em uso. Escolha outra."
  fi

  DEF_HTTPS=${OLDHTTPS:-$(pick_https)}
  read -rp "Porta HTTPS do painel, para o app no celular (0 = sem HTTPS) [$DEF_HTTPS]: " HTTPS_PORT
  HTTPS_PORT=${HTTPS_PORT:-$DEF_HTTPS}
  [[ $HTTPS_PORT =~ ^[0-9]+$ ]] || die "Porta HTTPS inválida"
  if [[ $HTTPS_PORT != 0 ]]; then
    ((HTTPS_PORT >= 1024 && HTTPS_PORT <= 65535)) || die "Porta HTTPS inválida (use 1024 a 65535, ou 0)"
    [[ $HTTPS_PORT == "$PORT" ]] && die "A porta HTTPS precisa ser diferente da porta do painel."
    if [[ $HTTPS_PORT != "$OLDHTTPS" && $HTTPS_PORT != "$OLDPORT" ]] && port_busy "$HTTPS_PORT"; then
      die "A porta $HTTPS_PORT já está em uso. Escolha outra."
    fi
  fi

  read -rp "Liberar acesso só pela rede local (recomendado)? [S/n]: " LAN
  if [[ $LAN =~ ^[nN]$ ]]; then REQ="Require all granted"
  else REQ="Require ip 127.0.0.1 ::1 10.0.0.0/8 172.16.0.0/12 192.168.0.0/16 100.64.0.0/10"; fi

  NORMAL_USER=${SUDO_USER:-}
  [[ -z $NORMAL_USER || $NORMAL_USER == root ]] && NORMAL_USER=$(id -nu 1000 2>/dev/null)
  echo "Terminal (mini CMD) do painel - os comandos rodam como:"
  echo "  (1) root - controle total, igual ao SSH como root (necessário para gerenciar sites)"
  [[ -n $NORMAL_USER ]] && echo "  (2) $NORMAL_USER - seu usuário, sem permissão de administrador"
  echo "  (3) desativar o terminal"
  read -rp "Escolha [1]: " T
  case "${T:-1}" in
    2) [[ -n $NORMAL_USER ]] || die "Opção inválida"; SHELL_USER=$NORMAL_USER;;
    3) SHELL_USER="";;
    *) SHELL_USER=root;;
  esac

  if [[ -f $DIR/config.php ]] && { read -rp "Manter a senha atual do painel? [S/n]: " K; [[ ! $K =~ ^[nN]$ ]]; }; then
    PASS_HASH=$(cfg_get LKM_PASS_HASH)
    [[ -n $PASS_HASH ]] || ask_pass
  else
    ask_pass
  fi
fi

# ---------------------------------------------------------------- terminal completo: porta interna (só 127.0.0.1)
TERM_PORT=$(cfg_get LKM_TERM_PORT)
if ! [[ $TERM_PORT =~ ^[0-9]+$ ]] || ((TERM_PORT < 1024)); then
  TERM_PORT=0
  for p in $(seq 7681 7699); do
    [[ $p == "$PORT" || $p == "$HTTPS_PORT" ]] && continue
    port_busy "$p" || { TERM_PORT=$p; break; }
  done
  [[ $TERM_PORT == 0 ]] && warn "Nenhuma porta livre entre 7681 e 7699: o terminal completo fica desativado (o modo simples continua)."
fi

# ---------------------------------------------------------------- usuário e arquivos
id "$LKUSER" >/dev/null 2>&1 || useradd --system --home-dir "$DATA" --no-create-home --shell /usr/sbin/nologin "$LKUSER" \
  || die "Falha ao criar o usuário $LKUSER"
mkdir -p "$DATA/sessions" "$DATA/tmp" "$DIR" "$LIBDIR"
touch "$DATA/cmd.log"
# tudo em $DATA é do lkmonitor, menos os certificados (chaves só do root) e as tarefas (gravadas pelo root, lidas pelo painel)
find "$DATA" \( -path "$CADIR" -o -path "$DATA/jobs" -o -path "$DATA/backup.json" \) -prune -o -exec chown "$LKUSER:$LKUSER" {} +
[[ -f $DATA/backup.json ]] && { chown root:"$LKUSER" "$DATA/backup.json"; chmod 640 "$DATA/backup.json"; }
[[ -f $DATA/settings.json ]] && chmod 600 "$DATA/settings.json"
mkdir -p /var/backups/lkmonitor; chown root:root /var/backups/lkmonitor; chmod 700 /var/backups/lkmonitor
mkdir -p "$DATA/jobs"; chown root:"$LKUSER" "$DATA/jobs"; chmod 750 "$DATA/jobs"
mkdir -p /var/cache/lkmonitor; chmod 755 /var/cache/lkmonitor
apply_pass_flags
chmod 751 "$DATA"; chmod 700 "$DATA/sessions"; chmod 1733 "$DATA/tmp"; chmod 640 "$DATA/cmd.log"
find "$DATA/tmp" -type f -mmin +120 -delete 2>/dev/null

write_config
info "Instalando arquivos em $DIR..."
cat > "$DIR/index.php" <<'LKM_EOF_INDEX'
@@FILE www/index.php@@
LKM_EOF_INDEX
cat > "$DIR/fm.js" <<'LKM_EOF_FMJS'
@@FILE www/fm.js@@
LKM_EOF_FMJS
cat > "$DIR/fm.css" <<'LKM_EOF_FMCSS'
@@FILE www/fm.css@@
LKM_EOF_FMCSS
cat > "$DIR/app.css" <<'LKM_EOF_APPCSS'
@@FILE www/app.css@@
LKM_EOF_APPCSS
cat > "$DIR/sites.js" <<'LKM_EOF_SITESJS'
@@FILE www/sites.js@@
LKM_EOF_SITESJS
cat > "$DIR/db.js" <<'LKM_EOF_DBJS'
@@FILE www/db.js@@
LKM_EOF_DBJS
cat > "$DIR/config.js" <<'LKM_EOF_CONFJS'
@@FILE www/config.js@@
LKM_EOF_CONFJS
cat > "$DIR/term.js" <<'LKM_EOF_TERMJS'
@@FILE www/term.js@@
LKM_EOF_TERMJS
cat > "$DIR/sys.js" <<'LKM_EOF_SYSJS'
@@FILE www/sys.js@@
LKM_EOF_SYSJS
cat > "$DIR/apps.js" <<'LKM_EOF_APPSJS'
@@FILE www/apps.js@@
LKM_EOF_APPSJS
@@DIR www/appicons $DIR/appicons@@
cat > "$DIR/i18n.js" <<'LKM_EOF_I18NJS'
@@FILE www/i18n.js@@
LKM_EOF_I18NJS
cat > "$DIR/cf.js" <<'LKM_EOF_CFJS'
@@FILE www/cf.js@@
LKM_EOF_CFJS
cat > "$DIR/sw.js" <<'LKM_EOF_SW'
@@FILE www/sw.js@@
LKM_EOF_SW
cat > "$DIR/manifest.webmanifest" <<'LKM_EOF_MANIFEST'
@@FILE www/manifest.webmanifest@@
LKM_EOF_MANIFEST
cat > "$DIR/offline.html" <<'LKM_EOF_OFFLINE'
@@FILE www/offline.html@@
LKM_EOF_OFFLINE
cat > "$DIR/icon.svg" <<'LKM_EOF_ICON'
@@FILE www/icon.svg@@
LKM_EOF_ICON
base64 -d > "$DIR/icon-192.png" <<'LKM_EOF_PNG192'
@@BASE64 www/icon-192.png@@
LKM_EOF_PNG192
base64 -d > "$DIR/icon-512.png" <<'LKM_EOF_PNG512'
@@BASE64 www/icon-512.png@@
LKM_EOF_PNG512
base64 -d > "$DIR/icon-maskable-512.png" <<'LKM_EOF_PNGMASK'
@@BASE64 www/icon-maskable-512.png@@
LKM_EOF_PNGMASK
cat > "$LIBDIR/fm.php" <<'LKM_EOF_HELPER'
@@FILE lib/fm.php@@
LKM_EOF_HELPER
cat > "$LIBDIR/lib.php" <<'LKM_EOF_LIB'
@@FILE lib/lib.php@@
LKM_EOF_LIB
cat > "$LIBDIR/collect.php" <<'LKM_EOF_COLLECT'
@@FILE lib/collect.php@@
LKM_EOF_COLLECT
cat > "$LIBDIR/sites.php" <<'LKM_EOF_SITES'
@@FILE lib/sites.php@@
LKM_EOF_SITES
cat > "$LIBDIR/db.php" <<'LKM_EOF_DB'
@@FILE lib/db.php@@
LKM_EOF_DB
cat > "$LIBDIR/jobs.inc.php" <<'LKM_EOF_JOBSINC'
@@FILE lib/jobs.inc.php@@
LKM_EOF_JOBSINC
cat > "$LIBDIR/job.php" <<'LKM_EOF_JOB'
@@FILE lib/job.php@@
LKM_EOF_JOB
cat > "$LIBDIR/alerts.inc.php" <<'LKM_EOF_ALERTS'
@@FILE lib/alerts.inc.php@@
LKM_EOF_ALERTS
cat > "$LIBDIR/sys.php" <<'LKM_EOF_SYS'
@@FILE lib/sys.php@@
LKM_EOF_SYS
cat > "$LIBDIR/apps.inc.php" <<'LKM_EOF_APPSINC'
@@FILE lib/apps.inc.php@@
LKM_EOF_APPSINC
cat > "$LIBDIR/apps.php" <<'LKM_EOF_APPS'
@@FILE lib/apps.php@@
LKM_EOF_APPS
cat > "$LIBDIR/cf.php" <<'LKM_EOF_CF'
@@FILE lib/cf.php@@
LKM_EOF_CF
cat > "$LIBDIR/term.py" <<'LKM_EOF_TERMPY'
@@FILE lib/term.py@@
LKM_EOF_TERMPY
find "$DIR" -maxdepth 1 -type f -exec chown root:"$LKUSER" {} + -exec chmod 644 {} +
chown root:"$LKUSER" "$DIR/config.php"; chmod 640 "$DIR/config.php"
chown -R root:root "$LIBDIR"; chmod 755 "$LIBDIR"; chmod 644 "$LIBDIR"/*.php "$LIBDIR"/*.py

# ---------------------------------------------------------------- xterm.js (tela do terminal completo)
# Baixado uma vez para o servidor; se não der (sem internet), o navegador usa a CDN.
XTV=5.5.0; XFV=0.10.0; XDIR=$DIR/vendor/xterm
mkdir -p "$XDIR"
xt_get() { # xt_get <arquivo local> <caminho no pacote> <pacote@versão> <texto que precisa ter>
  local f=$XDIR/$1 tmp; tmp=$(mktemp)
  [[ -s $f ]] && grep -q "$4" "$f" && grep -q "$3" "$XDIR/.versao" 2>/dev/null && { rm -f "$tmp"; return 0; }
  for base in https://cdn.jsdelivr.net/npm https://unpkg.com; do
    if curl -fsSL --max-time 60 -o "$tmp" "$base/$3/$2" 2>/dev/null && [[ $(wc -c < "$tmp") -gt 1000 ]] && grep -q "$4" "$tmp"; then
      install -m 644 -o root -g "$LKUSER" "$tmp" "$f"; rm -f "$tmp"; return 0
    fi
  done
  rm -f "$tmp"; return 1
}
if command -v curl >/dev/null \
  && xt_get xterm.js lib/xterm.js "@xterm/xterm@$XTV" Terminal \
  && xt_get xterm.css css/xterm.css "@xterm/xterm@$XTV" xterm-viewport \
  && xt_get addon-fit.js lib/addon-fit.js "@xterm/addon-fit@$XFV" FitAddon; then
  echo "@xterm/xterm@$XTV @xterm/addon-fit@$XFV" > "$XDIR/.versao"
  ok "xterm.js $XTV guardado no servidor (o terminal funciona mesmo sem internet no navegador)"
else
  warn "Não foi possível baixar o xterm.js agora: o navegador vai buscar na internet (CDN) ao abrir o Terminal."
fi
chown root:"$LKUSER" "$DIR/vendor" "$XDIR"; chmod 755 "$DIR/vendor" "$XDIR"
rm -f "$DIR/lib.php" "$DIR/collect.php" "$DIR/sites.php" "$DIR/db.php" "$DIR/job.php" "$DIR/jobs.inc.php" "$DIR/alerts.inc.php"

# programas usados pelos backups (rsync na restauração, sqlite3 para copiar SQLite com segurança)
for b in rsync sqlite3; do
  command -v "$b" >/dev/null || DEBIAN_FRONTEND=noninteractive apt-get install -y -qq "$b" >/dev/null 2>&1 || warn "Não foi possível instalar $b"
done

# extensões do PHP: zip (compactar/extrair), sqlite3 (histórico e SQLite), mysql (aba Bancos) e curl (alertas)
for ext in zip sqlite3 mysql curl; do
  mod=$ext; [[ $ext == sqlite3 ]] && mod=pdo_sqlite; [[ $ext == mysql ]] && mod=pdo_mysql
  if ! "php$PHPV" -m 2>/dev/null | grep -qi "^$mod\$"; then
    info "Instalando php$PHPV-$ext..."
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq "php$PHPV-$ext" >/dev/null 2>&1 \
      || warn "Não foi possível instalar php$PHPV-$ext ($(case $ext in zip) echo "compactar/extrair .zip";; sqlite3) echo "histórico de métricas e SQLite";; mysql) echo "aba Bancos (MySQL)";; *) echo "envio de alertas em paralelo";; esac) ficará indisponível)"
  fi
done

# ---------------------------------------------------------------- PHP-FPM próprio (usuário lkmonitor)
info "Configurando PHP-FPM $PHPV (pool lkmonitor)..."
for f in /etc/php/*/fpm/pool.d/lkmonitor.conf; do [[ -f $f && $f != /etc/php/$PHPV/* ]] && rm -f "$f"; done
cat > "/etc/php/$PHPV/fpm/pool.d/lkmonitor.conf" <<EOF
; LK Monitor - pool isolado, gerado em $(date '+%F %T')
[lkmonitor]
user = $LKUSER
group = $LKUSER
listen = /run/php/lkmonitor.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = ondemand
pm.max_children = 6
pm.process_idle_timeout = 60s
php_admin_value[session.save_path] = $DATA/sessions
php_admin_value[max_execution_time] = 0
php_admin_value[upload_tmp_dir] = $DATA/tmp
php_admin_value[upload_max_filesize] = 4G
php_admin_value[post_max_size] = 4G
php_admin_value[memory_limit] = 256M
php_admin_value[max_file_uploads] = 50
request_terminate_timeout = 0
php_admin_value[display_errors] = Off
EOF
if ! "php-fpm$PHPV" -t >/dev/null 2>&1; then
  "php-fpm$PHPV" -t
  rm -f "/etc/php/$PHPV/fpm/pool.d/lkmonitor.conf"
  die "Configuração do PHP-FPM com erro."
fi
systemctl restart "php$PHPV-fpm" || die "Falha ao reiniciar php$PHPV-fpm"
for i in 1 2 3 4 5; do [[ -S /run/php/lkmonitor.sock ]] && break; sleep 1; done

# ---------------------------------------------------------------- permissão do terminal
if [[ -n $SHELL_USER ]]; then
  tmp=$(mktemp)
  echo "# LK Monitor - terminal web roda comandos como $SHELL_USER" > "$tmp"
  echo "$LKUSER ALL=($SHELL_USER) NOPASSWD: ALL" >> "$tmp"
  if visudo -cf "$tmp" >/dev/null 2>&1; then
    install -m 440 -o root -g root "$tmp" "$SUDOERS"
    ok "Terminal ativo (comandos como $SHELL_USER)"
  else
    warn "Não foi possível validar a regra do sudo; terminal ficará sem permissão."
  fi
  rm -f "$tmp"
else
  rm -f "$SUDOERS"
  info "Terminal desativado"
fi

# ---------------------------------------------------------------- terminal completo (serviço lkmonitor-term)
# Shell de verdade no navegador: lib/term.py (usuário lkmonitor) abre "sudo -u <usuário do terminal> -i" num PTY.
# Sem isolamento do systemd (ProtectSystem etc.): o shell aberto aqui herdaria as restrições.
TERM_ON=0
if [[ -n $SHELL_USER && $TERM_PORT != 0 ]]; then
  if ! command -v python3 >/dev/null; then
    info "Instalando python3 (usado pelo terminal completo)..."
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq python3-minimal >/dev/null 2>&1 || warn "Não foi possível instalar o python3"
  fi
  if command -v python3 >/dev/null && python3 -c 'import sys; sys.exit(sys.version_info < (3, 8))' 2>/dev/null; then
    mkdir -p "$DATA/term"; chown "$LKUSER:$LKUSER" "$DATA/term"; chmod 700 "$DATA/term"
    cat > /etc/systemd/system/lkmonitor-term.service <<EOF
[Unit]
Description=LK Monitor - terminal web (xterm.js)
After=network.target

[Service]
User=$LKUSER
Group=$LKUSER
Environment=LKM_DATA=$DATA
Environment=LKM_CONFIG=$DIR/config.php
Environment=LANG=C.UTF-8
ExecStart=$(command -v python3) -I $LIBDIR/term.py --port $TERM_PORT
Restart=always
RestartSec=2

[Install]
WantedBy=multi-user.target
EOF
    systemctl daemon-reload
    systemctl enable lkmonitor-term.service >/dev/null 2>&1
    if grep -q 'lkmonitor-term' /proc/$$/cgroup 2>/dev/null; then
      # rodando de dentro do próprio terminal do painel: reiniciar agora mataria esta instalação
      systemd-run --quiet --on-active=10 --unit="lkm-term-restart-$$" /bin/systemctl restart lkmonitor-term.service >/dev/null 2>&1 \
        || warn "Reinicie o terminal depois com: sudo systemctl restart lkmonitor-term"
      warn "Você está no terminal do painel: ele reinicia em 10 s para pegar a versão nova (a tela reconecta sozinha, mas o que estiver rodando nele para)."
      TERM_ON=1
    elif systemctl restart lkmonitor-term.service; then
      sleep 1
      if systemctl is-active --quiet lkmonitor-term.service; then TERM_ON=1; ok "Terminal completo ativo (serviço lkmonitor-term, porta interna $TERM_PORT)"
      else warn "O terminal completo não iniciou (veja: journalctl -u lkmonitor-term -n 30). O modo simples continua."; fi
    else
      warn "O terminal completo não iniciou (veja: journalctl -u lkmonitor-term -n 30). O modo simples continua."
    fi
  else
    warn "Python 3.8 ou mais novo não encontrado: o terminal completo fica desativado (o modo simples continua)."
  fi
fi
if [[ $TERM_ON == 0 ]]; then
  systemctl disable --now lkmonitor-term.service >/dev/null 2>&1
  [[ -z $SHELL_USER ]] && rm -f /etc/systemd/system/lkmonitor-term.service
fi

# ---------------------------------------------------------------- histórico de métricas e alertas (systemd timer)
info "Configurando o coletor de métricas e alertas (a cada 60 s)..."
# grupos para ler o log do SSH (falhas de login) sem dar isso ao painel
LOGGRP=""; for g in adm systemd-journal; do getent group "$g" >/dev/null && LOGGRP+="$g "; done
cat > /etc/systemd/system/lkmonitor-collect.service <<EOF
[Unit]
Description=LK Monitor - coleta de métricas
After=network.target

[Service]
Type=oneshot
User=$LKUSER
Group=$LKUSER
Environment=LKM_DATA=$DATA
$([[ -n $LOGGRP ]] && echo "SupplementaryGroups=${LOGGRP% }")
ExecStart=/usr/bin/php$PHPV -d display_errors=stderr $LIBDIR/collect.php
Nice=10
TimeoutStartSec=50
NoNewPrivileges=yes
PrivateTmp=yes
ProtectSystem=strict
ProtectHome=read-only
ReadWritePaths=$DATA
EOF
cat > /etc/systemd/system/lkmonitor-collect.timer <<'EOF'
[Unit]
Description=LK Monitor - coleta de métricas a cada minuto

[Timer]
OnCalendar=*-*-* *:*:00
AccuracySec=1s

[Install]
WantedBy=timers.target
EOF
systemctl daemon-reload
systemctl enable --now lkmonitor-collect.timer >/dev/null 2>&1 || warn "Não foi possível ativar o lkmonitor-collect.timer"
systemctl start lkmonitor-collect.service >/dev/null 2>&1 \
  && ok "Coletor ativo: histórico de 7 dias em $DATA/metrics.sqlite" \
  || warn "O coletor falhou na primeira execução (veja: journalctl -u lkmonitor-collect)"

# ---------------------------------------------------------------- backup automático (systemd timer, horário configurável no painel)
info "Configurando o backup automático..."
cat > /etc/systemd/system/lkmonitor-backup.service <<EOF
[Unit]
Description=LK Monitor - backup automático dos sites
After=network.target mariadb.service mysql.service

[Service]
Type=oneshot
Environment=LKM_DATA=$DATA
ExecStart=/usr/bin/php$PHPV -d memory_limit=512M -d display_errors=stderr $LIBDIR/job.php --auto
Nice=10
IOSchedulingClass=idle
TimeoutStartSec=6h
EOF
mkdir -p /var/backups/lkmonitor; chmod 700 /var/backups/lkmonitor
LKM_DATA=$DATA "/usr/bin/php$PHPV" -r 'require $argv[1]; $c = bk_cfg(); bk_timer_write($c); $f = bk_cfg_file(); if (!is_file($f)) bk_cfg_save($c);' "$LIBDIR/jobs.inc.php" \
  && ok "Backup automático: $(LKM_DATA=$DATA "/usr/bin/php$PHPV" -r 'require $argv[1]; $c = bk_cfg(); echo $c["enabled"] ? "diário às {$c["time"]}, " . count($c["sites"]) . " site(s) (escolha no painel: Config)" : "desativado";' "$LIBDIR/jobs.inc.php")" \
  || warn "Não foi possível configurar o lkmonitor-backup.timer"

# ---------------------------------------------------------------- HTTPS (CA local + certificado do servidor)
lan_ips() { local ip; for ip in $(hostname -I 2>/dev/null); do [[ $ip == *:* || $ip == 172.17.* || $ip == 192.168.122.* ]] || echo "$ip"; done; }

make_ca() {
  local nc="permitted;IP:10.0.0.0/255.0.0.0,permitted;IP:172.16.0.0/255.240.0.0,permitted;IP:192.168.0.0/255.255.0.0"
  nc+=",permitted;IP:100.64.0.0/255.192.0.0,permitted;IP:127.0.0.0/255.0.0.0,permitted;DNS:localhost,permitted;DNS:local,permitted;DNS:lan"
  nc+=",permitted;DNS:$(hostname -s | tr 'A-Z' 'a-z')"
  local ip; for ip in $(lan_ips); do nc+=",permitted;IP:$ip/255.255.255.255"; done
  openssl genrsa -out "$CADIR/ca.key" 2048 >/dev/null 2>&1 || return 1
  chmod 600 "$CADIR/ca.key"
  openssl req -x509 -new -key "$CADIR/ca.key" -sha256 -days 3650 -out "$CADIR/ca.crt" \
    -subj "/O=LK Monitor/CN=LK Monitor CA ($(hostname -s))" \
    -addext "basicConstraints=critical,CA:TRUE,pathlen:0" \
    -addext "keyUsage=critical,keyCertSign,cRLSign" \
    -addext "subjectKeyIdentifier=hash" \
    -addext "nameConstraints=critical,$nc" >/dev/null 2>&1 || return 1
}

cert_ok() { # o certificado atual serve? (assinado pela CA, válido por mais 30 dias, com todos os IPs atuais)
  local f=$CADIR/server.crt san ip
  [[ -s $f && -s $CADIR/server.key ]] || return 1
  openssl verify -CAfile "$CADIR/ca.crt" "$f" >/dev/null 2>&1 || return 1
  openssl x509 -in "$f" -noout -checkend 2592000 >/dev/null 2>&1 || return 1
  san=$(openssl x509 -in "$f" -noout -ext subjectAltName 2>/dev/null)
  for ip in $(lan_ips); do grep -q "IP Address:$ip\(,\|$\)" <<< "$san" || return 1; done
  return 0
}

make_cert() {
  local h; h=$(hostname -s | tr 'A-Z' 'a-z')
  local san="DNS:$h,DNS:localhost,IP:127.0.0.1" ip ext
  for ip in $(lan_ips); do san+=",IP:$ip"; done
  ext=$(mktemp)
  printf 'basicConstraints=CA:FALSE\nkeyUsage=critical,digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\nsubjectAltName=%s\nsubjectKeyIdentifier=hash\nauthorityKeyIdentifier=keyid\n' "$san" > "$ext"
  openssl req -new -newkey rsa:2048 -nodes -keyout "$CADIR/server.key.new" -subj "/O=LK Monitor/CN=$h" -out "$CADIR/server.csr" >/dev/null 2>&1 \
    && openssl x509 -req -in "$CADIR/server.csr" -CA "$CADIR/ca.crt" -CAkey "$CADIR/ca.key" -set_serial "0x$(openssl rand -hex 16)" \
       -days 730 -sha256 -extfile "$ext" -out "$CADIR/server.crt.new" >/dev/null 2>&1
  local rc=$?
  rm -f "$ext" "$CADIR/server.csr"
  ((rc == 0)) || { rm -f "$CADIR/server.key.new" "$CADIR/server.crt.new"; return 1; }
  chmod 600 "$CADIR/server.key.new"
  mv -f "$CADIR/server.key.new" "$CADIR/server.key"; mv -f "$CADIR/server.crt.new" "$CADIR/server.crt"
}

HTTPS_ON=0
if [[ $HTTPS_PORT != 0 ]]; then
  info "Configurando HTTPS na porta $HTTPS_PORT (certificado próprio do servidor)..."
  if ! command -v openssl >/dev/null; then
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq openssl >/dev/null 2>&1
  fi
  mkdir -p "$CADIR"; chown root:root "$CADIR"; chmod 755 "$CADIR"
  if [[ ! -s $CADIR/ca.key || ! -s $CADIR/ca.crt ]]; then
    rm -f "$CADIR"/server.*
    make_ca && ok "Autoridade certificadora local criada em $CADIR" || warn "Falha ao criar a CA local"
  fi
  if [[ -s $CADIR/ca.crt ]]; then
    chmod 644 "$CADIR/ca.crt"; chmod 600 "$CADIR/ca.key"
    if cert_ok; then ok "Certificado HTTPS em dia"
    else make_cert && ok "Certificado HTTPS gerado (válido por 2 anos: $(hostname -s), $(lan_ips | tr '\n' ' '))" || warn "Falha ao gerar o certificado HTTPS"; fi
  fi
  if [[ -s $CADIR/server.crt && -s $CADIR/server.key ]] && a2enmod -q ssl socache_shmcb >/dev/null 2>&1; then HTTPS_ON=1
  else warn "HTTPS não ativado; o painel segue só em HTTP."; HTTPS_PORT=0; write_config; fi
fi

# ---------------------------------------------------------------- Apache
vhost_body() { # vhost_body <ServerName>
  cat <<EOF
    ServerName $1
    DocumentRoot $DIR
    DirectoryIndex index.php
    <Directory $DIR>
        Options -Indexes
        AllowOverride None
        $REQ
    </Directory>
    <Files "config.php">
        Require all denied
    </Files>
    <FilesMatch "\.php$">
        SetHandler "proxy:unix:/run/php/lkmonitor.sock|fcgi://lkmonitor"
    </FilesMatch>
    AddType application/manifest+json .webmanifest
$([[ $TERM_PORT != 0 ]] && printf '    <Location /term-ws>\n        %s\n        ProxyPreserveHost On\n        ProxyPass ws://127.0.0.1:%s/term-ws\n    </Location>\n' "$REQ" "$TERM_PORT")
    ProxyTimeout 600
    LimitRequestBody 0
    ErrorLog \${APACHE_LOG_DIR}/lkmonitor_error.log
    CustomLog \${APACHE_LOG_DIR}/lkmonitor_access.log combined
EOF
}
info "Configurando Apache na porta $PORT$([[ $HTTPS_ON == 1 ]] && echo " e HTTPS na $HTTPS_PORT")..."
[[ -f $SITE ]] && cp -f "$SITE" "$SITE.lkm-bak"
{
  echo "# LK Monitor - gerado em $(date '+%F %T')"
  echo "Listen $PORT"
  echo "<VirtualHost *:$PORT>"
  vhost_body lkmonitor
  echo "</VirtualHost>"
  if [[ $HTTPS_ON == 1 ]]; then
    echo "Listen $HTTPS_PORT https"
    echo "<VirtualHost *:$HTTPS_PORT>"
    vhost_body "$(hostname -s | tr 'A-Z' 'a-z')"
    echo "    SSLEngine on"
    echo "    SSLCertificateFile $CADIR/server.crt"
    echo "    SSLCertificateKeyFile $CADIR/server.key"
    echo "</VirtualHost>"
  fi
} > "$SITE"
a2enmod proxy proxy_fcgi proxy_wstunnel setenvif mime >/dev/null 2>&1
a2ensite lkmonitor >/dev/null
if ! apache2ctl configtest >/dev/null 2>&1; then
  apache2ctl configtest
  if [[ -f $SITE.lkm-bak ]]; then
    mv -f "$SITE.lkm-bak" "$SITE"
    apache2ctl configtest >/dev/null 2>&1 || a2dissite lkmonitor >/dev/null 2>&1
    die "Configuração do Apache com erro. A configuração anterior do painel foi mantida."
  fi
  a2dissite lkmonitor >/dev/null 2>&1
  die "Configuração do Apache com erro. Painel não ativado."
fi
rm -f "$SITE.lkm-bak"
systemctl reload apache2 || systemctl restart apache2

if command -v ufw >/dev/null && ufw status 2>/dev/null | grep -q "Status: active"; then
  ufw allow "$PORT/tcp" >/dev/null && info "Porta $PORT liberada no UFW"
  [[ $HTTPS_ON == 1 ]] && ufw allow "$HTTPS_PORT/tcp" >/dev/null && info "Porta $HTTPS_PORT liberada no UFW"
fi

echo
echo "${G}=============================================${N}"
echo "${G} LK Monitor $([[ $MODE == update ]] && echo atualizado || echo instalado)!${N}"
echo "${G}=============================================${N}"
echo " Abra no navegador:"
echo "   ${C}http://localhost:$PORT${N}"
for ip in $(lan_ips); do echo "   ${C}http://$ip:$PORT${N}"; done
if [[ $HTTPS_ON == 1 ]]; then
  echo " Endereço seguro (app no celular - instale o certificado pelo botão \"Instalar no celular\" do painel):"
  for ip in $(lan_ips); do echo "   ${C}https://$ip:$HTTPS_PORT${N}"; done
fi
echo
[[ -n $SHELL_USER ]] && echo " Terminal: comandos rodam como ${Y}$SHELL_USER${N} (histórico em $DATA/cmd.log)"
[[ $TERM_ON == 1 ]] && echo " Terminal completo: aba Terminal do painel (nano, htop, o menu do lk...). Log: journalctl -u lkmonitor-term"
[[ $SHELL_USER != root ]] && echo " Sites: ${Y}somente leitura${N} (para gerenciar pelo painel, escolha root no acesso do terminal)"
show_temp
echo " Trocar senha:  ${C}sudo lk senha${N}  (ou pelo painel: aba Internet › Senha do painel)"
echo " Remover:       sudo bash $0 uninstall"
echo
