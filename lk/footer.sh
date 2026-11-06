LK_MONITOR_INSTALLER_EOF
chmod 755 "$SHARE/install-lk-monitor.sh"

# WhatsApp no servidor + bot (Guardião LK): instalado só quando pedir (sudo lk whatsapp, opção 37 ou App Store)
cat > "$SHARE/install-whatsapp.sh" <<'LK_WHATSAPP_INSTALLER_EOF'
@@WHATSAPP_INSTALLER@@
LK_WHATSAPP_INSTALLER_EOF
chmod 755 "$SHARE/install-whatsapp.sh"

echo "${C}[..]${N} Instalando dependências básicas (curl, ipset, iptables)..."
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq >/dev/null 2>&1 || echo "  aviso: apt-get update falhou, seguindo assim mesmo"
apt-get install -y -qq curl ca-certificates ipset iptables conntrack >/dev/null 2>&1 || echo "  aviso: algumas dependências não instalaram (o LK instala quando precisar)"

# Restaura os IPs bloqueados a cada boot
cat > /etc/systemd/system/lk-firewall.service <<'SVC'
[Unit]
Description=LK - restaurar IPs bloqueados
After=network-pre.target docker.service
Wants=network-pre.target

[Service]
Type=oneshot
ExecStart=/usr/bin/lk fw-restore
RemainAfterExit=yes

[Install]
WantedBy=multi-user.target
SVC
systemctl daemon-reload || true
systemctl enable lk-firewall.service >/dev/null 2>&1 || true
/usr/bin/lk fw-restore >/dev/null 2>&1 || true

# Painel já instalado: atualiza junto, mantendo porta, senha, usuário do terminal e dados
if [[ -f /var/www/lkmonitor/config.php && -f /etc/apache2/sites-available/lkmonitor.conf ]]; then
  echo "${C}[..]${N} Atualizando o LK Monitor (painel web) já instalado..."
  bash "$SHARE/install-lk-monitor.sh" atualizar || echo "  aviso: não foi possível atualizar o painel agora (rode: sudo lk -> opção 33 -> 1)"
elif [[ -t 0 && ${LK_NO_SETUP:-0} != 1 ]]; then
  # primeira vez: oferece instalar tudo de uma vez (LAMP + painel com senha temporária)
  echo
  read -rp "Instalar o painel agora (Apache, PHP, MariaDB e o painel web)? [S/n]: " LKS
  if [[ ! $LKS =~ ^[nN]$ ]]; then /usr/bin/lk setup || echo "  aviso: a instalação do painel falhou (tente de novo: sudo lk setup)"; fi
fi

echo
echo "${G}=============================================${N}"
echo "${G} LK $(/usr/bin/lk version | awk '{print $2}') instalado com sucesso!${N}"
echo "${G}=============================================${N}"
echo " Abrir o menu:      ${C}sudo lk${N}"
echo " Painel web:        ${C}sudo lk${N} -> opção 33"
echo " SSH:               ${C}sudo lk${N} -> opção 34"
echo " Ligar/desligar site: ${C}lk site off meusite.com${N} / ${C}lk site on meusite.com${N}"
echo " Modo servidor:     ${C}sudo lk${N} -> opção 35  (apagar tela / voltar ao normal)"
echo " Comandos diretos:  ${C}lk help${N}"
echo " Desinstalar:       ${C}sudo bash $0 uninstall${N}"
echo
