#!/usr/bin/env bash
# =====================================================================
#  Instalador do LK - Mini gestor de hosting via terminal
#  Inclui o LK Monitor (painel web), instalado pelo menu: lk -> 33
#  Uso:
#    sudo bash install-lk.sh            instala / atualiza
#    sudo bash install-lk.sh uninstall  remove o LK
# =====================================================================
[[ $EUID -ne 0 ]] && exec sudo bash "$(readlink -f "$0")" "$@"
G=$'\e[32m'; R=$'\e[31m'; C=$'\e[36m'; N=$'\e[0m'
SHARE=/usr/local/share/lk

if [[ "${1:-}" == "uninstall" ]]; then
  systemctl disable --now lk-firewall.service >/dev/null 2>&1 || true
  rm -f /etc/systemd/system/lk-firewall.service /usr/local/bin/lk /usr/local/bin/LK /usr/bin/lk /usr/bin/LK
  rm -rf "$SHARE"
  systemctl daemon-reload || true
  echo "${G}LK removido.${N} Configurações mantidas em /etc/lk (apague manualmente se quiser)."
  exit 0
fi

command -v apt-get >/dev/null || { echo "${R}O LK suporta apenas Ubuntu/Debian.${N}"; exit 1; }

mkdir -p /etc/lk /root/lk-backups "$SHARE"
chmod 700 /etc/lk /root/lk-backups
touch /etc/lk/blocked_ips.txt
TMP=$(mktemp)

echo "${C}[..]${N} Instalando o comando lk..."
cat > "$TMP" <<'LK_SCRIPT_EOF'
