LK_SCRIPT_EOF
install -m 755 "$TMP" /usr/bin/lk
install -m 755 "$TMP" /usr/local/bin/lk 2>/dev/null || true
ln -sf /usr/bin/lk /usr/bin/LK
rm -f "$TMP"
[[ -x /usr/bin/lk ]] || { echo "${R}Falha ao gravar /usr/bin/lk${N}"; exit 1; }

echo "${C}[..]${N} Guardando o instalador do LK Monitor (painel web)..."
cat > "$SHARE/install-lk-monitor.sh" <<'LK_MONITOR_INSTALLER_EOF'
