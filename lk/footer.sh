LK_MONITOR_INSTALLER_EOF
chmod 755 "$SHARE/install-lk-monitor.sh"

# WhatsApp no servidor + bot (Guardião LK): instalado só quando pedir (sudo lk whatsapp, opção 37 ou App Store)
cat > "$SHARE/install-whatsapp.sh" <<'LK_WHATSAPP_INSTALLER_EOF'
@@WHATSAPP_INSTALLER@@
LK_WHATSAPP_INSTALLER_EOF
chmod 755 "$SHARE/install-whatsapp.sh"

# LK-BOT-I18N (2.4.1): bot do WhatsApp já instalado? atualiza o guard.php (a config e os números ficam).
# Quem já usava o bot continua em português (LANG=pt); instalação nova começa em inglês (LANG=en).
if [[ -f /opt/lk-guard/guard.php ]] && command -v php >/dev/null 2>&1; then
  lkg_tmp=$(mktemp)
  awk 'f&&$0=="LK_GUARD_EOF"{f=0} f{print} $0=="cat > /opt/lk-guard/guard.php <<\047LK_GUARD_EOF\047"{f=1}' "$SHARE/install-whatsapp.sh" > "$lkg_tmp"
  if [[ -s $lkg_tmp ]] && php -l "$lkg_tmp" >/dev/null 2>&1 && ! cmp -s "$lkg_tmp" /opt/lk-guard/guard.php; then
    install -m 644 -o root -g root "$lkg_tmp" /opt/lk-guard/guard.php
    if [[ -f /etc/lk-guard.conf ]] && ! grep -q '^LANG=' /etc/lk-guard.conf; then
      printf '# Idioma do bot: en, pt ou es (WhatsApp: IDIOMA PT / LANGUAGE EN / IDIOMA ES · lk bot idioma pt)\nLANG=pt\n' >> /etc/lk-guard.conf
    fi
    systemctl restart lk-guard >/dev/null 2>&1 || true
    echo "${G}[ok]${N} Bot do WhatsApp atualizado: agora fala português, inglês e espanhol (mande IDIOMA ES / LANGUAGE EN no WhatsApp)"
  fi
  rm -f "$lkg_tmp"
fi

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

# Sites atrás do Funnel / Cloudflare Tunnel: PHP enxerga https (sem "Mixed Content")
if command -v apache2ctl >/dev/null && [[ ! -e /etc/apache2/conf-enabled/lk-proxy-https.conf ]]; then
  /usr/bin/lk proxy-https >/dev/null 2>&1 && echo "${G}[OK]${N} Sites atrás do Tailscale Funnel / Cloudflare abrem com CSS (https)"
fi

# phpMyAdmin instalado por App Store antiga (umask 027): pastas 750 = 403 "search permissions are missing"
if [[ -d /usr/share/lk-phpmyadmin ]] && find /usr/share/lk-phpmyadmin -type d ! -perm -o+x -print -quit | grep -q .; then
  find /usr/share/lk-phpmyadmin -type d -exec chmod 755 {} + ; find /usr/share/lk-phpmyadmin -type f -exec chmod 644 {} +
  echo "${G}[OK]${N} phpMyAdmin: permissões das pastas corrigidas (erro 403)"
fi

# Tailscale instalado: o Apache não pode pedir a 443 em todos os IPs (o Funnel usa a 443 no IP do Tailscale)
if command -v tailscale >/dev/null && command -v apache2ctl >/dev/null && ! grep -qsE '^\s*Include\s+lk-listen-443\.conf' /etc/apache2/ports.conf; then
  echo "${C}[..]${N} Ajustando o Apache para dividir a porta 443 com o Tailscale..."
  /usr/bin/lk apache-443 || echo "  aviso: não deu para ajustar agora (depois: sudo lk apache-443)"
fi

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
