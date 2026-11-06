# LK + LK Monitor — fontes

- `lk/lk` — o comando `lk` (menu de 36 opções + comandos diretos). `lk/header.sh`, `lk/middle.sh` e `lk/footer.sh` são o começo, o meio e o fim do `install-lk.sh`.
- `lkmon/install-lk-monitor.sh` — instalador do painel. As linhas `@@FILE caminho@@` e `@@BASE64 caminho@@` são trocadas pelo conteúdo de `lkmon/www/` e `lkmon/lib/` na hora do build.
- `lkmon/www/` — vai para `/var/www/lkmonitor` (index.php, fm.js, term.js, sites.js, db.js, config.js, sys.js, css, PWA).
- `lkmon/lib/` — vai para `/usr/local/lib/lkmonitor` (helpers root em PHP, coletor, tarefas, alertas e `term.py`, o serviço do terminal).
- Gerar: `./build.sh` → `dist/install-lk.sh`. Instalar/atualizar no servidor: `sed -i 's/\r$//' install-lk.sh && sudo bash install-lk.sh` (atualiza o painel junto, mantendo porta, senha, usuário do terminal e dados).

## Regras
- Interface e mensagens em português, visual clássico claro (1.9.0: menu lateral escuro, caixas brancas, verde `--pri`; terminal, editor e visualizador continuam escuros com os tokens escuros em app.css), componentes do fm.js (`window.UI`: dialog, toast, menu…).
- Toda ação perigosa vai para `/var/lib/lkmonitor/cmd.log`. Rotas novas: login + token CSRF (`X-LKM-Token`).
- Quem já tem instalado não pode perder nada ao atualizar (`install-lk-monitor.sh atualizar` roda sem perguntas).
- Nenhum fonte pode conter uma linha que seja só o marcador do heredoc (o build confere).

## Terminal completo (1.6.0)
- `lib/term.py`: serviço `lkmonitor-term` (usuário lkmonitor, só 127.0.0.1, porta em `LKM_TERM_PORT` no config.php, 7681+). Cada aba = `sudo -n -u <LKM_SHELL_USER> -i` num PTY. Sessões ficam 30 min vivas sem navegador (`LKM_TERM_TTL`).
- O painel (`?term=ticket`) grava `LKM_DATA/term/t-<ticket>.json` (uso único, 60 s); o navegador abre `term-ws?t=…`, que o Apache (`<Location /term-ws>`, proxy_wstunnel) manda para o serviço.
- `www/term.js`: xterm.js 5.5.0 de `www/vendor/xterm/` (baixado pelo instalador) ou da CDN. O terminal antigo (`?cmd`) continua como "modo simples" e é usado pelo app Android para reiniciar/desligar.

## Ações do sistema (1.7.0)
- `www/sys.js` + `lib/sys.php` (rota `?sys=status|service|kill|mode|power`, só com terminal como root). Botões no Painel: menu nos chips de Serviços, ✕ na tabela de Processos, e no card Sistema: Modo servidor / Modo com tela, Reiniciar PC, Desligar.
- Reiniciar apache2/php-fpm, isolate e reboot/poweroff são agendados com `systemd-run --on-active` (a resposta chega antes). Parar apache2/php-fpm pelo painel é bloqueado.
- Modo servidor usa `lk servidor on|off boot` (novo 3º argumento `agora|reiniciar|boot` roda sem perguntar).

## App Store (1.8.0)
- Aba Apps: `www/apps.js` + `lib/apps.php` (rota `?apps=list|install|remove|svc`, ações só com terminal root). Catálogo em `lib/apps.inc.php` (`apps_catalog()`): pacotes apt, binário, container Docker (`lk-*`), serviço, porta/caminho para o botão Abrir.
- Instalar/desinstalar vira tarefa `type=app` no job.php (`app_steps` em jobs.inc.php) e o log aparece em `window.JOBS.view`. Uma tarefa de app por vez (trava do apt).
- Apps Docker mantêm os volumes ao desinstalar. MariaDB tem `protect` (não desinstala pelo painel). phpMyAdmin fica em /usr/share/lk-phpmyadmin, Alias /phpmyadmin só para a rede local.
- Fotos dos apps: `www/appicons/<id>.svg|png|webp|jpg` (o build grava a pasta inteira com `@@DIR www/appicons $DIR/appicons@@`). Sem foto, o card mostra as iniciais na cor do app. Imagens já normalizadas em 128×128 com fundo (branco ou a cor da marca). Supervisor e Ferramentas: ícones próprios; Certbot: só o emblema. Composer ainda sem foto.

## Internet / Cloudflare Tunnel (2.0.0)
- Aba Internet: `www/cf.js` + `lib/cf.php` (rota `?cf=status|login|setup|route_add|route_del|toggle|quick_start|quick_stop|unblock`). App "Cloudflare Tunnel" (cloudflared, repo pkg.cloudflare.com) na App Store.
- Túnel gerenciado localmente: `cloudflared tunnel login` roda em `systemd-run --unit=lk-cf-login` (o link sai em /run/lk-cf-login.log), cert em /root/.cloudflared/cert.pem. Túnel, credenciais, routes.json e config.yml em /etc/lk/cloudflared (fora das pastas padrão, para o teste rápido funcionar). Serviço `lk-cloudflared`; teste rápido trycloudflare em `lk-cf-quick` (6 h).
- Publicar = `tunnel route dns` + regra no config.yml (sempre com o 404 no fim, validado com `ingress validate`). Site com HTTPS próprio vai para https://localhost:443 (noTLSVerify + originServerName) para não entrar em laço de redirecionamento. Remover não apaga o DNS na Cloudflare.
- Apache: conf `lk-cloudflare` (mod_remoteip, CF-Connecting-IP só vindo de 127.0.0.1) para o IP real.
- Login do painel: `client_ip()`, bloqueio do IP depois de 5 senhas erradas por 15 min (LKM_DATA/login-fails.json), histórico em LKM_DATA/logins.log, aviso por al_send. As constantes LKM_LOCK_* ficam no topo do index.php (const só existe depois da linha que a declara).
- Tarefas de app rodam com `umask 022` (o job.php usa 027 e o _apt não lia as chaves em /usr/share/keyrings: erro NO_PUBKEY / "is not signed" no 2.0.0).
- 2.0.3: `client_ip()` também aceita o último IP do X-Forwarded-For quando vem de 127.0.0.1 (Tailscale Funnel); histórico mostra rede de casa / Tailscale / Funnel / Cloudflare.

## Login com 2 fatores (2.1.0)
- TOTP (RFC 6238, SHA1, 30 s, 6 dígitos, janela ±1, sem reaproveitar o mesmo passo) escrito no index.php (`totp`, `b32_enc/dec`, `tfa_*`), conferido com os vetores do RFC. Estado em LKM_DATA/2fa.json (0600): secret, since, last, recovery (8 hashes, uso único).
- Login: senha certa + 2FA ligado -> `$_SESSION['pre2fa']` (5 min) -> tela do código; erros contam no bloqueio de 5/15 min.
- Rota `?tfa=status|start|enable|disable|recovery` (card na aba Internet). QR via qrcode-generator 1.4.4 da cdnjs (carregado só na ativação; sem internet mostra só a chave).
- Emergência: `lk 2fa off` apaga /var/lib/lkmonitor/2fa.json. Constante B32 fica no topo (mesma armadilha do LKM_LOCK_*).

## Senha / 1º acesso (2.2.0)
- Login = usuário (`LKM_USER`, padrão `root`, no config.php) + senha. POST sem o campo `user` ainda entra (app Android antigo).
- Senha vale de `LKM_DATA/pass.json` (gravada pelo painel) e, se não existir, do `LKM_PASS_HASH` do config.php. Senha nova pelo instalador (`ask_pass` + `apply_pass_flags`) apaga o pass.json.
- `LKM_DATA/must-change`: criado quando o instalador gera a senha temporária (Enter na pergunta, ou `LKM_AUTO=1`). Com ele, depois do login só abre `page_setpw()` (rotas da API dão 401) até trocar. `pw_apply()` confere a força (`pw_weak`), troca o root via `sys.php rootpass` (chpasswd pelo stdin; com `ssh:true` grava /etc/ssh/sshd_config.d/10-root.conf `PermitRootLogin yes` + `PasswordAuthentication yes`, valida com `sshd -t` (desfaz se falhar) e recarrega o ssh; devolve se o sshd aceita root com senha). No 1º acesso a caixinha liga root + SSH; no card da aba Internet o SSH é uma caixinha separada, desmarcada e só então grava o pass.json.
- Rota `?pw=status|change` (change pede a senha atual; erro conta no bloqueio). Card "Senha do painel" no cf.js; `CFG.pwdone` mostra o aviso depois do 1º acesso.
- `lk setup` (`lk_setup`): LAMP com `LK_AUTO=1` (sem perguntas) + `LKM_AUTO=1 install-lk-monitor.sh instalar` (porta 8088, HTTPS automático, só rede local/Tailscale, terminal root, senha temporária) e instala o openssh-server se faltar. O footer do install-lk.sh oferece isso quando o painel não está instalado e há terminal (`-t 0`). `lk senha` = `install-lk-monitor.sh senha`.

## WhatsApp + Bot dentro do LK (2.2.0)
- Fontes em `whatsapp/` (vieram do projeto whatsapp-guardiao; o build.py faz o que o build.sh antigo fazia: header.sh + panel/index.php + footer.sh com @@HOOK_PHP@@/@@GUARD_PHP@@). Sai em `dist/install-whatsapp.sh` e vai embutido no install-lk.sh (footer.sh, @@WHATSAPP_INSTALLER@@) para `/usr/local/share/lk/install-whatsapp.sh`. Não instala nada sozinho.
- guard.php já tem o lk-bot-patch aplicado (LK-BOT-PATCH e LK-BOT-PATCH2: BOT=0 mudo, anti-spam do aviso crítico, REPEAT_MIN do painel). O conf ganhou `BOT=` no configure_guard.
- `WA_AUTO=1`: sem perguntas (porta 8089 ou a próxima livre, senha da página = hash atual do LkPanel (pass.json ou LKM_PASS_HASH), números de `WA_NUMBERS`, uninstall sem confirmar). Usado pelo app `whatsapp` da App Store (file /opt/lk-guard/guard.php, svc lk-guard; apps.php lê a porta da página no whatsapp-panel.conf para o botão Abrir).
- lk: funções do bot (marcador LK-BOT-PATCH, então o lk-bot-patch.sh antigo pula o lk), opção 37 `bot_menu` (só aparece no menu com /etc/lk-guard.conf, montada em show_menu com o estado LIGADO/desligado; ligar/desligar, números, info, senha, atualizar, teste), `lk bot [on|off|teste]`, `lk whatsapp [instalar|info|guardiao|senha|remover]`.

- `dist/atualizar-lk.sh` (build.py): o mesmo install-lk.sh com um bloco no topo: LK_NO_SETUP=1, recusa sem painel instalado e recusa uninstall, backup do lk/config.php/pass.json em /root/lk-backups/antes-da-atualizacao-*.

## Apache x Tailscale na 443 (2.2.1)
- O tailscaled (Serve/Funnel) escuta 100.x:443 e [fd7a:…]:443 no tailscale0. Com `Listen 443` (wildcard) o Apache dava EADDRINUSE em qualquer restart e caía tudo (painel e sites).
- `lk apache-443` (apache_443_fix): /usr/local/sbin/lk-apache-listen gera /etc/apache2/lk-listen-443.conf (127.0.0.1, ::1 e os IPv4 fora do tailscale0 e de 100.64.0.0/10); ports.conf troca `Listen 443` por `Include lk-listen-443.conf` (backup ports.conf.lk-bak); drop-in apache2.service.d/lk-listen-443.conf com ExecStartPre e After=network-online/tailscaled. Faz restart (não reload). `lk apache-443 off` desfaz; `status` mostra.
- Aplicado sozinho: footer do install-lk.sh/atualizar-lk.sh (se tiver tailscale e ainda não estiver ligado) e passo extra do app Tailscale na App Store.

## HTTPS atrás de proxy (2.2.2)
- `apache_proxy_https` (lk): conf `lk-proxy-https` com `SetEnvIf Host "\.ts\.net$" HTTPS=on` e `SetEnvIf X-Forwarded-Proto "^https$" HTTPS=on`, para o PHP (Laravel) gerar links https quando o site vem pelo Funnel/Cloudflare. Ligada no install_lamp, no footer (update) e em `lk proxy-https`.
- Sintoma sem isso: site Laravel pelo Funnel abre sem CSS/JS ("Mixed Content": a página é https e os links saem http://).

## Sites sem pasta (2.2.3)
- `site_root` / `site_root_missing` (lk): DocumentRoot que não existe (e o site não é ProxyPass) aparece com "⚠ pasta não existe" em `lk sites` e no `site_pick` (opção 36). `site_enable` avisa (vai dar 404) e pede confirmação; no modo `lk site on <site>` recusa.
