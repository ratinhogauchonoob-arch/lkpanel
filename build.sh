#!/usr/bin/env bash
# Gera dist/install-lk.sh (LK + LK Monitor num arquivo só) a partir dos fontes em lk/ e lkmon/.
set -e
cd "$(dirname "$0")"
for f in lkmon/www/index.php lkmon/lib/*.php whatsapp/panel/index.php whatsapp/guard/*.php; do php -l "$f" >/dev/null || { echo "ERRO de sintaxe em $f"; exit 1; }; done
python3 -m py_compile lkmon/lib/term.py && rm -rf lkmon/lib/__pycache__
bash -n lk/lk
python3 build.py
bash -n dist/install-lk.sh && bash -n dist/install-whatsapp.sh && bash -n dist/atualizar-lk.sh
echo "OK: dist/install-lk.sh"
