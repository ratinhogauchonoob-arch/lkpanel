LK_WA_PAGE_EOF
chown root:www-data "$WDIR/index.php"; chmod 644 "$WDIR/index.php"

# limites de upload só desta página (PHP-FPM lê o .user.ini da pasta)
cat > "$WDIR/.user.ini" <<'EOF'
upload_max_filesize = 100M
post_max_size = 101M
max_file_uploads = 5
max_execution_time = 360
max_input_time = 360
memory_limit = 256M
EOF
chown root:www-data "$WDIR/.user.ini"; chmod 644 "$WDIR/.user.ini"

# receptor das mensagens do WhatsApp para o Guardião (só o GOWA local chama)
cat > "$WDIR/hook.php" <<'LK_WA_HOOK_EOF'
@@HOOK_PHP@@
LK_WA_HOOK_EOF
chown root:www-data "$WDIR/hook.php"; chmod 644 "$WDIR/hook.php"

cat > "$SITE" <<EOF
# Página do WhatsApp (GOWA) - gerado em $(date '+%F %T')
Listen $PPORT
<VirtualHost *:$PPORT>
    ServerName whatsapp-panel
    DocumentRoot $WDIR
    DirectoryIndex index.php
    <Directory $WDIR>
        Options -Indexes
        AllowOverride None
        Require ip 127.0.0.1 ::1 10.0.0.0/8 172.16.0.0/12 192.168.0.0/16 100.64.0.0/10
    </Directory>
    <FilesMatch "^(config\.php|\.user\.ini)$">
        Require all denied
    </FilesMatch>
    <Files "hook.php">
        Require ip 127.0.0.1 ::1
    </Files>
    <FilesMatch "\.php$">
        SetHandler "proxy:unix:/run/php/php$PHPV-fpm.sock|fcgi://localhost"
    </FilesMatch>
    ProxyTimeout 400
    LimitRequestBody 110000000
    ErrorLog \${APACHE_LOG_DIR}/whatsapp-panel_error.log
    CustomLog \${APACHE_LOG_DIR}/whatsapp-panel_access.log combined
</VirtualHost>
EOF
a2enmod proxy_fcgi setenvif >/dev/null 2>&1
a2ensite whatsapp-panel >/dev/null
if ! apache2ctl configtest >/dev/null 2>&1; then
  apache2ctl configtest
  a2dissite whatsapp-panel >/dev/null 2>&1
  die "Configuração do Apache com erro. Página não ativada."
fi
systemctl restart "php$PHPV-fpm" 2>/dev/null
systemctl reload apache2 || systemctl restart apache2

if command -v ufw >/dev/null && ufw status 2>/dev/null | grep -q "Status: active"; then
  ufw allow from 192.168.0.0/16 to any port "$PPORT" proto tcp >/dev/null && info "Porta $PPORT liberada no UFW para a rede local"
fi

# ---------------------------------------------------------------- Guardião (temperatura + comandos pelo WhatsApp)
info "Instalando o Guardião..."
mkdir -p /opt/lk-guard /var/lib/lk-guard/inbox
cat > /opt/lk-guard/guard.php <<'LK_GUARD_EOF'
@@GUARD_PHP@@
LK_GUARD_EOF
chown -R root:root /opt/lk-guard; chmod 755 /opt/lk-guard; chmod 644 /opt/lk-guard/guard.php
chown root:root /var/lib/lk-guard; chmod 711 /var/lib/lk-guard
chown root:www-data /var/lib/lk-guard/inbox; chmod 770 /var/lib/lk-guard/inbox
[[ -f $GCONF ]] || configure_guard

cat > "$GSVC" <<EOF
[Unit]
Description=Guardião LK - temperatura e comandos pelo WhatsApp
After=network-online.target gowa.service
Wants=gowa.service

[Service]
ExecStart=$(command -v php) /opt/lk-guard/guard.php
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload
systemctl enable lk-guard >/dev/null 2>&1
systemctl restart lk-guard

# GOWA entrega as mensagens recebidas para a página (hook.php), que repassa ao Guardião
CHG=0
envset WHATSAPP_WEBHOOK "http://127.0.0.1:$PPORT/hook.php" && CHG=1
envset WHATSAPP_WEBHOOK_EVENTS "message" && CHG=1
if [[ $CHG == 1 ]]; then
  info "Ativando o recebimento de mensagens no GOWA..."
  systemctl restart gowa
  for i in $(seq 1 30); do
    [[ $(curl -s -o /dev/null -w '%{http_code}' -u "$(envget GOWA_USER):$(envget GOWA_PASS)" "http://127.0.0.1:$PORT/devices") == 200 ]] && break
    sleep 1
  done
fi
sleep 2
if systemctl is-active --quiet lk-guard; then ok "Guardião rodando"; else warn "O Guardião não iniciou. Veja: journalctl -u lk-guard -n 30 --no-pager"; fi

lkmon_link

echo
echo "${G}=============================================${N}"
echo "${G} WhatsApp instalado!${N}"
echo "${G}=============================================${N}"
show_info
echo " Ver de novo estes dados:  sudo lk whatsapp info"
echo " Trocar senha da página:   sudo lk whatsapp senha"
echo " Números do Guardião:      sudo lk whatsapp guardiao"
echo " Remover:                  sudo lk whatsapp remover"
echo
