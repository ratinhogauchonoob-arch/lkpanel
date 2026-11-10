#!/usr/bin/env bash
# =====================================================================
#  WhatsApp no servidor: GOWA (go-whatsapp-web-multidevice) + página PHP
#  https://github.com/aldinokemal/go-whatsapp-web-multidevice
#
#  Uso:
#    sudo bash install-whatsapp.sh             instala / atualiza
#    sudo bash install-whatsapp.sh info        mostra endereços e dados para os alertas do LK
#    sudo bash install-whatsapp.sh senha       troca a senha da página
#    sudo bash install-whatsapp.sh lk          liga os alertas do LK Monitor ao WhatsApp deste servidor
#    sudo bash install-whatsapp.sh guardiao    configura o Guardião (aviso de temperatura e comandos pelo WhatsApp)
#    sudo bash install-whatsapp.sh uninstall   remove tudo
#  Sem acesso ao GitHub: baixe o whatsapp_<versão>_linux_amd64.zip no PC e rode
#    sudo GOWA_ZIP=/root/whatsapp_<versão>_linux_amd64.zip bash install-whatsapp.sh
# =====================================================================
[[ $EUID -ne 0 ]] && exec sudo bash "$(readlink -f "$0")" "$@"

G=$'\e[32m'; R=$'\e[31m'; C=$'\e[36m'; Y=$'\e[33m'; W=$'\e[1m'; N=$'\e[0m'
ok()   { echo "${G}[OK]${N} $*"; }
info() { echo "${C}[..]${N} $*"; }
warn() { echo "${Y}[!]${N} $*"; }
die()  { echo "${R}[ERRO]${N} $*"; exit 1; }

REPO="aldinokemal/go-whatsapp-web-multidevice"
GDIR=/opt/gowa
ETC=/etc/gowa
ENVF=$ETC/gowa.env
SVC=/etc/systemd/system/gowa.service
WDIR=/var/www/whatsapp
SITE=/etc/apache2/sites-available/whatsapp-panel.conf
DEVICE=lk
GCONF=/etc/lk-guard.conf
GSVC=/etc/systemd/system/lk-guard.service

envget() { [[ -f $ENVF ]] && grep -m1 "^$1=" "$ENVF" | cut -d= -f2-; }
gconf_get() { [[ -f $GCONF ]] && grep -m1 "^$1=" "$GCONF" | cut -d= -f2-; }
# grava/troca uma linha KEY=valor no gowa.env; devolve 0 se mudou
envset() {
  local k=$1 v=$2
  [[ $(envget "$k") == "$v" ]] && grep -q "^$k=" "$ENVF" && return 1
  if grep -q "^$k=" "$ENVF"; then sed -i "s|^$k=.*|$k=$v|" "$ENVF"; else echo "$k=$v" >> "$ENVF"; fi
  return 0
}
port_busy() { ss -ltnH "sport = :$1" 2>/dev/null | grep -q .; }
lan_ips() { hostname -I 2>/dev/null | tr ' ' '\n' | grep -vE '^$|:|^172\.17\.|^192\.168\.122\.' ; }

show_info() {
  local port user pass b64 pport
  port=$(envget APP_PORT); user=$(envget GOWA_USER); pass=$(envget GOWA_PASS)
  [[ -n $port && -n $user ]] || die "O WhatsApp (GOWA) não está instalado"
  b64=$(printf '%s:%s' "$user" "$pass" | base64 -w0)
  pport=""; [[ -f $SITE ]] && pport=$(grep -m1 -oE '^Listen [0-9]+' "$SITE" | awk '{print $2}')
  echo
  echo "${W}Serviço:${N} gowa ($(systemctl is-active gowa 2>/dev/null)) em 127.0.0.1:$port"
  if [[ -n $pport ]]; then
    echo "${W}Página (conecte o número por QR code ou código e envie mensagens):${N}"
    for ip in $(lan_ips); do echo "   ${C}http://$ip:$pport${N}"; done
  fi
  echo
  echo "${W}Para os alertas do LK Monitor${N} (Config › Alertas › WhatsApp):"
  echo "  Método:  POST"
  echo "  URL:     http://127.0.0.1:$port/send/message"
  echo "  Cabeçalhos:"
  echo "    Authorization: Basic $b64"
  echo "    X-Device-Id: $DEVICE"
  echo "    Content-Type: application/json"
  echo "  Corpo:   {\"phone\": \"{phone}@s.whatsapp.net\", \"message\": \"{message}\"}"
  echo "  Número:  o seu, com código do país (ex.: 50761234567)"
  echo
  echo "${W}Mídia pela API${N} (mesmos Authorization e X-Device-Id, corpo multipart/form-data):"
  echo "  /send/image   phone, image_url  ou arquivo em image, caption"
  echo "  /send/video   phone, video_url  ou arquivo em video, caption"
  echo "  /send/audio   phone, audio_url  ou arquivo em audio, ptt=true (mensagem de voz)"
  echo "  /send/file    phone, file_url   ou arquivo em file, caption"
  echo "  Exemplo: curl -H 'Authorization: Basic $b64' -H 'X-Device-Id: $DEVICE' -F phone=50761234567@s.whatsapp.net \\"
  echo "             -F image_url=https://site.com/foto.jpg -F caption=Oi http://127.0.0.1:$port/send/image"
  echo
  if [[ -f /opt/lk-guard/guard.php ]]; then
    local gn; gn=$(gconf_get NUMBERS)
    echo "${W}Guardião${N} ($(systemctl is-active lk-guard 2>/dev/null)): avisa acima de $(gconf_get WARN_TEMP) °C e desliga acima de $(gconf_get CRIT_TEMP) °C"
    echo "  Números: ${gn:-nenhum (configure com: sudo lk whatsapp guardiao)}"
    echo "  Mande no WhatsApp para o número do servidor: STATUS, REINICIAR, DESLIGAR ou AJUDA"
    echo
  fi
}

# Pergunta os números do Guardião e grava /etc/lk-guard.conf (mantém os limites já configurados)
configure_guard() {
  local cur nums
  cur=$(gconf_get NUMBERS)
  echo
  echo "${W}Guardião:${N} avisa no seu WhatsApp se a CPU esquentar demais e deixa você mandar"
  echo "STATUS, REINICIAR ou DESLIGAR para o número do servidor."
  echo "Use o seu número pessoal (não o número que está conectado no servidor)."
  if [[ ${WA_AUTO:-0} == 1 ]]; then nums=${WA_NUMBERS:-}
  else read -rp "Seu número do WhatsApp (ex.: 50761234567; vários separados por vírgula)${cur:+ [$cur]}: " nums; fi
  nums=${nums:-$cur}
  nums=$(tr -cd '0-9,' <<< "$nums" | sed -E 's/,+/,/g; s/^,//; s/,$//')
  local wt wm rp ct cm ao bot lg
  bot=$(gconf_get BOT); lg=$(gconf_get LANG)
  wt=$(gconf_get WARN_TEMP); wm=$(gconf_get WARN_MIN); rp=$(gconf_get REPEAT_MIN)
  ct=$(gconf_get CRIT_TEMP); cm=$(gconf_get CRIT_MIN); ao=$(gconf_get AUTO_OFF)
  cat > "$GCONF" <<EOF
# Guardião LK - gerado em $(date '+%F %T')
# Números que recebem os avisos e podem mandar comandos (só dígitos, com código do país)
NUMBERS=$nums
# Aviso: temperatura da CPU (°C) e por quantos minutos seguidos
WARN_TEMP=${wt:-90}
WARN_MIN=${wm:-1}
# Repete o aviso a cada N minutos enquanto continuar quente
REPEAT_MIN=${rp:-15}
# Desliga sozinho acima desta temperatura (°C) depois de N minutos (AUTO_OFF=0 desativa)
CRIT_TEMP=${ct:-97}
CRIT_MIN=${cm:-2}
AUTO_OFF=${ao:-1}
# Bot do WhatsApp: 1 = manda avisos e responde comandos, 0 = mudo (lk bot on|off)
BOT=${bot:-1}
# Idioma do bot: en, pt ou es (WhatsApp: IDIOMA PT / LANGUAGE EN / IDIOMA ES · lk bot idioma pt)
LANG=${lg:-${WA_LANG:-en}}
EOF
  chmod 600 "$GCONF"
  if [[ -n $nums ]]; then ok "Guardião configurado para: $nums"
  else warn "Sem número: o Guardião não vai mandar avisos. Configure depois com: sudo lk whatsapp guardiao"; fi
}

# Senha do LkPanel (a que vale agora): a página do WhatsApp usa a mesma quando instalada pela App Store
lk_panel_hash() {
  local h
  h=$(php -r '$d = json_decode((string)@file_get_contents("/var/lib/lkmonitor/pass.json"), true); echo is_array($d) ? ($d["hash"] ?? "") : "";' 2>/dev/null)
  [[ -z $h && -f /var/www/lkmonitor/config.php ]] && h=$(grep -oP "define\('LKM_PASS_HASH', '\K[^']*" /var/www/lkmonitor/config.php | head -n1)
  echo "$h"
}

ask_pass() {
  local p1 p2
  if [[ ${WA_AUTO:-0} == 1 ]]; then
    PAGE_HASH=$(lk_panel_hash)
    if [[ $PAGE_HASH == \$* ]]; then info "Senha da página do WhatsApp: a mesma do painel"; return 0; fi
    p1=$(tr -dc 'A-HJ-NP-Za-km-z2-9' </dev/urandom | head -c 16)
    PAGE_HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' -- "$p1")
    warn "Senha da página do WhatsApp: $p1  (troque com: sudo lk whatsapp senha)"
    return 0
  fi
  while true; do
    read -rsp "Senha da página do WhatsApp: " p1; echo
    read -rsp "Repita a senha: " p2; echo
    [[ ${#p1} -ge 4 ]] || { warn "Use pelo menos 4 caracteres."; continue; }
    [[ $p1 == "$p2" ]] || { warn "As senhas não conferem."; continue; }
    break
  done
  PAGE_HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' -- "$p1")
  [[ $PAGE_HASH == \$* ]] || die "Falha ao gerar a senha (PHP CLI instalado?)"
}

write_page_config() {
  local port user pass
  port=$(envget APP_PORT); user=$(envget GOWA_USER); pass=$(envget GOWA_PASS)
  printf "<?php\ndefine('WA_PASS_HASH', '%s');\ndefine('WA_GOWA_URL', 'http://127.0.0.1:%s');\ndefine('WA_GOWA_USER', '%s');\ndefine('WA_GOWA_PASS', '%s');\ndefine('WA_DEVICE', '%s');\ndefine('WA_HOOK_SECRET', '%s');\n" \
    "$PAGE_HASH" "$port" "$user" "$pass" "$DEVICE" "$(envget WHATSAPP_WEBHOOK_SECRET)" > "$WDIR/config.php"
  chown root:www-data "$WDIR/config.php"; chmod 640 "$WDIR/config.php"
}

# Entrega ao LK Monitor os dados do GOWA local (alertas pelo WhatsApp sem configurar gateway)
lkmon_link() {
  local f=/var/lib/lkmonitor/gowa.json
  id lkmonitor >/dev/null 2>&1 && [[ -d /var/lib/lkmonitor ]] || return 0
  [[ -n $(envget APP_PORT) ]] || return 0
  printf '{"url":"http://127.0.0.1:%s","user":"%s","pass":"%s","device":"%s","page_port":"%s"}\n' \
    "$(envget APP_PORT)" "$(envget GOWA_USER)" "$(envget GOWA_PASS)" "$DEVICE" "${PPORT:-}" > "$f"
  chown lkmonitor:lkmonitor "$f"; chmod 600 "$f"
  ok "LK Monitor ligado ao WhatsApp deste servidor"
}

case "${1:-}" in
  info) show_info; exit 0;;
  lk)
    PPORT=""; [[ -f $SITE ]] && PPORT=$(grep -m1 -oE '^Listen [0-9]+' "$SITE" | awk '{print $2}')
    id lkmonitor >/dev/null 2>&1 || die "O LK Monitor não está instalado"
    lkmon_link; exit 0;;
  guardiao|guard)
    [[ -f /opt/lk-guard/guard.php ]] || die "O Guardião não está instalado. Rode primeiro: sudo lk whatsapp"
    configure_guard
    systemctl restart lk-guard
    [[ -n $(gconf_get NUMBERS) ]] && { sleep 1; php /opt/lk-guard/guard.php teste; }
    exit 0;;
  senha|password)
    [[ -f $WDIR/config.php ]] || die "A página do WhatsApp não está instalada"
    ask_pass; write_page_config; ok "Senha da página alterada"; exit 0;;
  uninstall)
    if [[ ${WA_AUTO:-0} != 1 ]]; then
      read -rp "Remover o WhatsApp do servidor? O número conectado será desconectado. [s/N]: " a
      [[ $a =~ ^[sSyY]$ ]] || exit 0
    fi
    systemctl disable --now gowa lk-guard >/dev/null 2>&1
    rm -f "$SVC" "$GSVC"; systemctl daemon-reload
    rm -rf /opt/lk-guard /var/lib/lk-guard "$GCONF"
    a2dissite whatsapp-panel >/dev/null 2>&1; rm -f "$SITE"; systemctl reload apache2 2>/dev/null
    rm -rf "$WDIR" "$GDIR" "$ETC"
    rm -f /var/lib/lkmonitor/gowa.json
    id gowa >/dev/null 2>&1 && userdel gowa 2>/dev/null
    ok "WhatsApp removido"; exit 0;;
  ""|install|update) ;;
  *) die "Opção desconhecida: $1 (use: info, senha, lk, guardiao, uninstall)";;
esac

command -v apt-get >/dev/null || die "Suporta apenas Ubuntu/Debian."
case "$(uname -m)" in
  x86_64|amd64) ARCH=amd64;;
  aarch64|arm64) ARCH=arm64;;
  armv7l|armv7*) ARCH=armv7;;
  i386|i686) ARCH=386;;
  *) die "Arquitetura não suportada: $(uname -m)";;
esac

info "Instalando dependências (curl, unzip)..."
DEBIAN_FRONTEND=noninteractive apt-get install -y -qq curl unzip ca-certificates >/dev/null 2>&1 || true
command -v curl >/dev/null && command -v unzip >/dev/null || die "Não foi possível instalar curl/unzip"
# ffmpeg: necessário para enviar vídeo e mensagem de voz | webp: figurinhas animadas
if ! command -v ffmpeg >/dev/null || ! command -v webpmux >/dev/null; then
  info "Instalando ffmpeg e webp (envio de vídeo, áudio de voz e figurinhas)... pode levar alguns minutos"
  DEBIAN_FRONTEND=noninteractive apt-get install -y -qq ffmpeg webp >/dev/null 2>&1 \
    || { apt-get update -qq >/dev/null 2>&1; DEBIAN_FRONTEND=noninteractive apt-get install -y -qq ffmpeg webp >/dev/null 2>&1; }
fi
command -v ffmpeg >/dev/null && ok "ffmpeg instalado" || warn "ffmpeg não instalado: envio de vídeo e mensagem de voz não vai funcionar (tente: apt install ffmpeg)"

# ---------------------------------------------------------------- download do GOWA
#  GOWA_VERSION=x.y.z  fixa a versão   |   GOWA_ZIP=/caminho/whatsapp_x.y.z_linux_amd64.zip  instala sem internet
info "Buscando a versão mais recente do GOWA..."
if [[ -n ${GOWA_ZIP:-} ]]; then
  V=$(basename "$GOWA_ZIP" | sed -nE 's/^whatsapp_([^_]+)_linux_.*/\1/p'); V=${V:-local}; URL="file://$GOWA_ZIP"
elif [[ -n ${GOWA_VERSION:-} ]]; then
  V=${GOWA_VERSION#v}
  URL="https://github.com/$REPO/releases/download/v$V/whatsapp_${V}_linux_${ARCH}.zip"
else
  JSON=$(curl -fsSL --max-time 30 -H 'Accept: application/vnd.github+json' "https://api.github.com/repos/$REPO/releases/latest") \
    || die "Não foi possível consultar o GitHub. Verifique a internet do servidor (ou defina GOWA_VERSION=x.y.z)."
  V=$(grep -m1 -oE '"tag_name": *"[^"]+"' <<< "$JSON" | sed -E 's/.*"v?([^"]+)"$/\1/')
  URL=$(grep -oE '"browser_download_url": *"[^"]+_linux_'"$ARCH"'\.zip"' <<< "$JSON" | head -n1 | sed -E 's/.*"(https[^"]+)"$/\1/')
fi
[[ -n $URL ]] || die "Não encontrei o arquivo do GOWA para linux/$ARCH na última versão."
CUR=""; [[ -f $GDIR/VERSION ]] && CUR=$(<"$GDIR/VERSION")

TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
if [[ -n ${GOWA_ZIP:-} ]]; then
  [[ -f $GOWA_ZIP ]] || die "Arquivo não encontrado: $GOWA_ZIP"
  cp "$GOWA_ZIP" "$TMP/gowa.zip"; CUR=""
fi
if [[ $CUR == "$V" && -x $GDIR/gowa && -z ${GOWA_ZIP:-} ]]; then
  ok "GOWA já está na versão $V"
else
  if [[ ! -f $TMP/gowa.zip ]]; then
    info "Baixando GOWA $V (linux/$ARCH)..."
    curl -fSL --max-time 300 -o "$TMP/gowa.zip" "$URL" || die "Falha ao baixar $URL"
  fi
  unzip -q -o "$TMP/gowa.zip" -d "$TMP/x" || die "Arquivo baixado inválido"
  BIN=$(find "$TMP/x" -type f \( -name "linux-*" -o -name "whatsapp" \) ! -name "*.txt" ! -name "*.md" | head -n1)
  [[ -n $BIN ]] || BIN=$(find "$TMP/x" -type f -size +5M | head -n1)
  [[ -n $BIN ]] || die "Não encontrei o executável dentro do zip"
  id gowa >/dev/null 2>&1 || useradd --system --home-dir "$GDIR" --no-create-home --shell /usr/sbin/nologin gowa || die "Falha ao criar o usuário gowa"
  mkdir -p "$GDIR"
  systemctl stop gowa >/dev/null 2>&1
  install -m 755 "$BIN" "$GDIR/gowa"
  echo "$V" > "$GDIR/VERSION"
  ok "GOWA $V instalado em $GDIR"
fi
id gowa >/dev/null 2>&1 || useradd --system --home-dir "$GDIR" --no-create-home --shell /usr/sbin/nologin gowa
mkdir -p "$GDIR/storages" "$GDIR/statics"
chown -R gowa:gowa "$GDIR/storages" "$GDIR/statics"; chmod 750 "$GDIR/storages"

# ---------------------------------------------------------------- configuração e serviço
mkdir -p "$ETC"
if [[ ! -f $ENVF ]]; then
  P=3000
  while port_busy "$P"; do P=$((P + 1)); done
  PASS=$(tr -dc 'A-Za-z0-9' </dev/urandom | head -c 24)
  cat > "$ENVF" <<EOF
# GOWA - gerado em $(date '+%F %T'). Só escuta em 127.0.0.1.
APP_HOST=127.0.0.1
APP_PORT=$P
APP_OS=LK
GOWA_USER=lk
GOWA_PASS=$PASS
APP_BASIC_AUTH=lk:$PASS
EOF
fi
# segredo que assina as mensagens que o GOWA entrega ao Guardião (webhook)
grep -q '^WHATSAPP_WEBHOOK_SECRET=.' "$ENVF" || envset WHATSAPP_WEBHOOK_SECRET "$(tr -dc 'A-Za-z0-9' </dev/urandom | head -c 32)"
chown root:gowa "$ENVF"; chmod 640 "$ENVF"; chmod 750 "$ETC"; chown root:gowa "$ETC"
PORT=$(envget APP_PORT)

cat > "$SVC" <<'EOF'
[Unit]
Description=GOWA - WhatsApp API (go-whatsapp-web-multidevice)
After=network-online.target
Wants=network-online.target

[Service]
User=gowa
Group=gowa
WorkingDirectory=/opt/gowa
EnvironmentFile=/etc/gowa/gowa.env
ExecStart=/opt/gowa/gowa rest --host=${APP_HOST} --port=${APP_PORT} --os=${APP_OS} --basic-auth=${APP_BASIC_AUTH}
Restart=always
RestartSec=5
NoNewPrivileges=true
PrivateTmp=true

[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload
systemctl enable gowa >/dev/null 2>&1
systemctl restart gowa

info "Aguardando o serviço subir na porta $PORT..."
UPOK=0
for i in $(seq 1 30); do
  code=$(curl -s -o /dev/null -w '%{http_code}' -u "$(envget GOWA_USER):$(envget GOWA_PASS)" "http://127.0.0.1:$PORT/devices" 2>/dev/null)
  [[ $code == 200 ]] && { UPOK=1; break; }
  sleep 1
done
if [[ $UPOK == 1 ]]; then
  ok "Serviço gowa rodando"
  curl -s -o /dev/null -u "$(envget GOWA_USER):$(envget GOWA_PASS)" -H 'Content-Type: application/json' \
    -d "{\"device_id\":\"$DEVICE\"}" "http://127.0.0.1:$PORT/devices" || true
else
  warn "O serviço não respondeu. Veja o log com: journalctl -u gowa -n 50 --no-pager"
fi

# ---------------------------------------------------------------- página PHP
if [[ ! -d /etc/apache2 ]] || ! command -v php >/dev/null; then
  warn "Apache/PHP não encontrados: a página não foi instalada (instale o LAMP com: lk → 1, e rode este instalador de novo)."
  show_info; exit 0
fi
PHPV=""
for v in $(ls /etc/php 2>/dev/null | grep -E '^[0-9]+\.[0-9]+$' | sort -V); do [[ -S /run/php/php$v-fpm.sock || -d /etc/php/$v/fpm ]] && PHPV=$v; done
[[ -n $PHPV ]] || die "PHP-FPM não encontrado (instale o LAMP com: lk → 1)."
php -m 2>/dev/null | grep -qi '^curl$' || { info "Instalando php$PHPV-curl..."; DEBIAN_FRONTEND=noninteractive apt-get install -y -qq "php$PHPV-curl" >/dev/null 2>&1; }
php -m 2>/dev/null | grep -qi '^mbstring$' || DEBIAN_FRONTEND=noninteractive apt-get install -y -qq "php$PHPV-mbstring" >/dev/null 2>&1

OLDPP=""; [[ -f $SITE ]] && OLDPP=$(grep -m1 -oE '^Listen [0-9]+' "$SITE" | awk '{print $2}')
if [[ -n $OLDPP ]]; then
  PPORT=$OLDPP
else
  DEF=8089; while port_busy "$DEF"; do DEF=$((DEF + 1)); done
  if [[ ${WA_AUTO:-0} == 1 ]]; then PPORT=$DEF; else read -rp "Porta da página do WhatsApp [$DEF]: " PPORT; fi
  PPORT=${PPORT:-$DEF}
  [[ $PPORT =~ ^[0-9]+$ ]] && ((PPORT >= 1024 && PPORT <= 65535)) || die "Porta inválida"
  port_busy "$PPORT" && die "A porta $PPORT já está em uso"
  grep -qsE "^\s*Listen\s+(\S+:)?$PPORT\s*$" /etc/apache2/ports.conf && die "A porta $PPORT já está no ports.conf do Apache"
fi

mkdir -p "$WDIR"
if [[ -f $WDIR/config.php ]] && grep -q "WA_PASS_HASH" "$WDIR/config.php"; then
  PAGE_HASH=$(grep -oP "define\('WA_PASS_HASH', '\K[^']*" "$WDIR/config.php")
  K=""; [[ ${WA_AUTO:-0} == 1 ]] || read -rp "Manter a senha atual da página? [S/n]: " K
  [[ $K =~ ^[nN]$ || -z $PAGE_HASH ]] && ask_pass
else
  ask_pass
fi
write_page_config

info "Instalando a página em $WDIR..."
cat > "$WDIR/index.php" <<'LK_WA_PAGE_EOF'
