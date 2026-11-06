<?php
/*
 * LK Monitor - catálogo da App Store (aba Apps).
 * Usado pelo apps.php (lista / inicia tarefas) e pelo jobs.inc.php (passos de instalar e desinstalar).
 *
 * Cada app:
 *   id, name, cat, desc, color        aparência
 *   pkgs                               pacotes apt (instalado = todos presentes; desinstalar remove os presentes)
 *   bin / ver                          detecção por comando no PATH e comando que mostra a versão
 *   docker                             nome do container (app em Docker)
 *   svc                                unidade systemd (iniciar / parar / reiniciar)
 *   port, path, https                  botão "Abrir" (porta no IP do servidor; path = caminho no Apache, porta 80)
 *   install / remove                   passos [rótulo, comando bash] (sem eles, usa apt com os pkgs)
 *   needs                              outro app que precisa estar instalado antes
 *   protect                            texto: desinstalar pelo painel é bloqueado
 *   note                               dica mostrada no card depois de instalado
 */
declare(strict_types=1);

const APT = 'DEBIAN_FRONTEND=noninteractive apt-get -y -o DPkg::Lock::Timeout=300 -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold';
const APP_CATS = ['db' => 'Banco de dados', 'web' => 'Web e PHP', 'docker' => 'Docker', 'net' => 'Rede e segurança', 'ia' => 'IA', 'sys' => 'Sistema'];

/** Remove só os pacotes da lista que estão instalados (apt reclama de pacote que não existe no repositório). */
function app_rm_pkgs(array $pkgs, string $verb = 'remove'): string
{
    $l = implode(' ', array_map('escapeshellarg', $pkgs));
    return 'P=$(dpkg-query -W -f=\'${binary:Package} ${db:Status-Abbrev}\n\' ' . $l . ' 2>/dev/null | awk \'$2 ~ /^ii/ {print $1}\'); '
        . '[ -n "$P" ] && ' . APT . ' ' . $verb . ' $P || echo "Nenhum pacote para remover"';
}

/** Container Docker: confere o Docker e a porta, baixa a imagem e cria o container. */
function app_docker(string $name, int $port, string $image, string $run): array
{
    return [
        ['Conferir Docker e porta ' . $port, 'command -v docker >/dev/null || { echo "Instale o Docker primeiro (App Store › Docker)"; exit 1; }; '
            . 'systemctl is-active --quiet docker || systemctl start docker; '
            . 'docker inspect ' . $name . ' >/dev/null 2>&1 && { echo "Container ' . $name . ' já existe: removendo para recriar (os dados ficam)"; docker rm -f ' . $name . '; }; '
            . 'if ss -ltnH "sport = :' . $port . '" | grep -q .; then echo "A porta ' . $port . ' já está em uso:"; ss -ltnpH "sport = :' . $port . '"; exit 1; fi; echo OK'],
        ['Baixar a imagem ' . $image, 'docker pull ' . $image],
        ['Criar o container ' . $name, 'docker run -d --name ' . $name . ' ' . $run . ' ' . $image],
    ];
}

function app_docker_rm(string $name): array
{
    return [['Remover o container ' . $name . ' (os dados ficam guardados)', 'docker rm -f ' . $name . ' 2>/dev/null || echo "Container já não existe"']];
}

function apps_catalog(): array
{
    $pma = <<<'BASH_PMA'
set -e
S=$(ls /run/php/ 2>/dev/null | grep -E '^php[0-9]+\.[0-9]+-fpm\.sock$' | sort -V | tail -1)
[ -n "$S" ] || { echo "PHP-FPM não encontrado. Instale o LAMP antes: sudo lk › opção 1"; exit 1; }
V=${S#php}; V=${V%-fpm.sock}
echo "PHP $V"
DEBIAN_FRONTEND=noninteractive apt-get -y -o DPkg::Lock::Timeout=300 install php$V-mysql php$V-mbstring php$V-xml php$V-zip php$V-gd php$V-curl >/dev/null || true
D=/usr/share/lk-phpmyadmin
T=$(mktemp -d)
echo "Baixando phpMyAdmin..."
curl -fsSL https://www.phpmyadmin.net/downloads/phpMyAdmin-latest-all-languages.tar.gz -o "$T/pma.tgz"
mkdir -p "$T/x" && tar xzf "$T/pma.tgz" -C "$T/x" --strip-components=1
K=$(head -c 64 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 32)
mkdir -p /var/lib/lk-phpmyadmin/tmp && chown -R www-data:www-data /var/lib/lk-phpmyadmin
cat > "$T/x/config.inc.php" <<PHPCFG
<?php
\$cfg['blowfish_secret'] = '$K';
\$cfg['TempDir'] = '/var/lib/lk-phpmyadmin/tmp';
\$i = 1;
\$cfg['Servers'][\$i]['auth_type'] = 'cookie';
\$cfg['Servers'][\$i]['host'] = 'localhost';
\$cfg['Servers'][\$i]['AllowNoPassword'] = false;
PHPCFG
rm -rf "$D.old"; [ -d "$D" ] && mv "$D" "$D.old"
mv "$T/x" "$D"; rm -rf "$T" "$D.old"
chown -R root:root "$D"; chmod 644 "$D/config.inc.php"
cat > /etc/apache2/conf-available/lk-phpmyadmin.conf <<APCONF
# phpMyAdmin instalado pela App Store do LK Monitor (somente rede local)
Alias /phpmyadmin $D
<Directory $D>
    DirectoryIndex index.php
    Require ip 127.0.0.1 ::1 10.0.0.0/8 172.16.0.0/12 192.168.0.0/16 100.64.0.0/10
    <FilesMatch "\.php$">
        SetHandler "proxy:unix:/run/php/$S|fcgi://localhost"
    </FilesMatch>
</Directory>
<Directory $D/libraries>
    Require all denied
</Directory>
<Directory $D/setup>
    Require all denied
</Directory>
APCONF
a2enmod -q proxy_fcgi >/dev/null
a2enconf -q lk-phpmyadmin >/dev/null
apache2ctl configtest
systemctl reload apache2
echo "phpMyAdmin em http://$(hostname -I | awk '{print $1}')/phpmyadmin"
BASH_PMA;

    return [
        /* ---------------- banco de dados */
        ['id' => 'mariadb', 'name' => 'MariaDB', 'cat' => 'db', 'color' => '#c0765a', 'pkgs' => ['mariadb-server', 'mariadb-client'], 'svc' => 'mariadb',
            'desc' => 'Banco de dados MySQL usado pelos sites (Laravel, WordPress…). Gerencie na aba Bancos.',
            'protect' => 'Os bancos dos seus sites ficam no MariaDB. Para remover, use o terminal do servidor.'],
        ['id' => 'phpmyadmin', 'name' => 'phpMyAdmin', 'cat' => 'db', 'color' => '#f29111', 'path' => '/phpmyadmin', 'needs' => 'mariadb', 'file' => '/usr/share/lk-phpmyadmin/index.php',
            'desc' => 'Gerenciar o MariaDB pelo navegador. Só abre na rede local.',
            'install' => [['Instalar phpMyAdmin e ligar no Apache (/phpmyadmin)', $pma]],
            'remove' => [['Remover phpMyAdmin', 'a2disconf -q lk-phpmyadmin 2>/dev/null; rm -f /etc/apache2/conf-available/lk-phpmyadmin.conf; rm -rf /usr/share/lk-phpmyadmin /var/lib/lk-phpmyadmin; systemctl reload apache2; echo OK']],
            'note' => 'Entre com o usuário e a senha de um banco (aba Bancos). O root do MariaDB só entra pelo terminal.'],
        ['id' => 'postgresql', 'name' => 'PostgreSQL', 'cat' => 'db', 'color' => '#336791', 'pkgs' => ['postgresql'], 'svc' => 'postgresql',
            'desc' => 'Banco de dados avançado, muito usado com Node, Python e Laravel.',
            'note' => 'Entrar no console: sudo -u postgres psql'],
        ['id' => 'redis', 'name' => 'Redis', 'cat' => 'db', 'color' => '#d82c20', 'pkgs' => ['redis-server'], 'svc' => 'redis-server',
            'desc' => 'Cache e filas em memória (cache, sessões e queue do Laravel).'],
        ['id' => 'memcached', 'name' => 'Memcached', 'cat' => 'db', 'color' => '#2a7a8c', 'pkgs' => ['memcached'], 'svc' => 'memcached',
            'desc' => 'Cache em memória simples e rápido.'],

        /* ---------------- web e PHP */
        ['id' => 'node', 'name' => 'Node.js + PM2', 'cat' => 'web', 'color' => '#5fa04e', 'bin' => 'node', 'ver' => 'node -v',
            'desc' => 'Node.js 22 LTS (NodeSource) e o PM2 para manter apps Node ligados. Igual à opção 8 do lk.',
            'install' => [
                ['Adicionar o repositório NodeSource 22.x', 'curl -fsSL https://deb.nodesource.com/setup_22.x | bash -'],
                ['Instalar Node.js', APT . ' install nodejs'],
                ['Instalar PM2', 'npm install -g pm2 && pm2 --version'],
            ],
            'remove' => [['Remover PM2', 'command -v npm >/dev/null && npm rm -g pm2 || true'], ['Remover Node.js', app_rm_pkgs(['nodejs'])]],
            'note' => 'Apps Node: sudo lk › opções 9 a 12.'],
        ['id' => 'composer', 'name' => 'Composer', 'cat' => 'web', 'color' => '#885630', 'bin' => 'composer', 'ver' => 'COMPOSER_ALLOW_SUPERUSER=1 composer --version 2>/dev/null | head -1',
            'desc' => 'Gerenciador de pacotes do PHP (Laravel). Usado pelo deploy dos sites.',
            'install' => [['Instalar Composer em /usr/local/bin', 'command -v php >/dev/null || { echo "PHP não encontrado. Instale o LAMP antes: sudo lk › opção 1"; exit 1; }; '
                . 'curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php && php /tmp/composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer; rc=$?; rm -f /tmp/composer-setup.php; exit $rc']],
            'remove' => [['Remover Composer', 'rm -f /usr/local/bin/composer; echo OK']]],
        ['id' => 'git', 'name' => 'Git', 'cat' => 'web', 'color' => '#f05133', 'pkgs' => ['git'], 'desc' => 'Controle de versão. Clonar e atualizar projetos do GitHub.'],
        ['id' => 'supervisor', 'name' => 'Supervisor', 'cat' => 'web', 'color' => '#6b7280', 'pkgs' => ['supervisor'], 'svc' => 'supervisor',
            'desc' => 'Mantém workers rodando (queue:work do Laravel). Igual à opção 13 do lk.',
            'note' => 'Criar worker: sudo lk › opção 14.'],
        ['id' => 'certbot', 'name' => 'Certbot (SSL)', 'cat' => 'web', 'color' => '#003a70', 'pkgs' => ['certbot', 'python3-certbot-apache'],
            'desc' => 'Certificados HTTPS grátis do Let\'s Encrypt para os domínios dos sites.'],

        /* ---------------- docker */
        ['id' => 'docker', 'name' => 'Docker', 'cat' => 'docker', 'color' => '#1d63ed', 'bin' => 'docker', 'ver' => 'docker --version', 'svc' => 'docker',
            'desc' => 'Roda apps em containers. Necessário para Portainer, Uptime Kuma, Jellyfin e Open WebUI.',
            'install' => [['Atualizar lista de pacotes', APT . ' update'], ['Instalar Docker e Docker Compose', APT . ' install docker.io docker-compose-v2'],
                ['Ligar o Docker no boot', 'systemctl enable --now docker && docker --version']],
            'remove' => [['Remover Docker (containers, imagens e volumes ficam em /var/lib/docker)',
                app_rm_pkgs(['docker.io', 'docker-ce', 'docker-ce-cli', 'containerd.io', 'docker-compose-v2', 'docker-compose-plugin', 'docker-buildx-plugin'])]]],
        ['id' => 'portainer', 'name' => 'Portainer', 'cat' => 'docker', 'color' => '#13bef9', 'docker' => 'lk-portainer', 'port' => 9443, 'https' => true, 'needs' => 'docker',
            'desc' => 'Painel visual para gerenciar containers, imagens e volumes do Docker.',
            'install' => app_docker('lk-portainer', 9443, 'portainer/portainer-ce:lts', '--restart=always -p 9443:9443 -v /var/run/docker.sock:/var/run/docker.sock -v portainer_data:/data'),
            'remove' => app_docker_rm('lk-portainer'),
            'note' => 'Crie o usuário admin nos primeiros 5 minutos depois de instalar. O navegador avisa do certificado: é normal.'],
        ['id' => 'uptimekuma', 'name' => 'Uptime Kuma', 'cat' => 'docker', 'color' => '#5cdd8b', 'docker' => 'lk-uptime-kuma', 'port' => 3001, 'needs' => 'docker',
            'desc' => 'Monitora se seus sites estão no ar e avisa quando caem.',
            'install' => app_docker('lk-uptime-kuma', 3001, 'louislam/uptime-kuma:1', '--restart=always -p 3001:3001 -v uptime-kuma:/app/data'),
            'remove' => app_docker_rm('lk-uptime-kuma')],
        ['id' => 'jellyfin', 'name' => 'Jellyfin', 'cat' => 'docker', 'color' => '#aa5cc3', 'docker' => 'lk-jellyfin', 'port' => 8096, 'needs' => 'docker',
            'desc' => 'Seu próprio Netflix: filmes, séries e músicas na TV e no celular.',
            'install' => array_merge([['Criar a pasta de mídia /srv/midia', 'mkdir -p /srv/midia && chmod 775 /srv/midia && echo OK']],
                app_docker('lk-jellyfin', 8096, 'jellyfin/jellyfin:latest', '--restart=unless-stopped -p 8096:8096 -v jellyfin-config:/config -v jellyfin-cache:/cache -v /srv/midia:/media')),
            'remove' => app_docker_rm('lk-jellyfin'),
            'note' => 'Coloque os vídeos em /srv/midia (aba Arquivos). No Jellyfin a pasta aparece como /media.'],
        ['id' => 'openwebui', 'name' => 'Open WebUI', 'cat' => 'ia', 'color' => '#9ca3af', 'docker' => 'lk-open-webui', 'port' => 3080, 'needs' => 'docker',
            'desc' => 'Chat estilo ChatGPT no navegador para os modelos do Ollama.',
            'install' => app_docker('lk-open-webui', 3080, 'ghcr.io/open-webui/open-webui:main',
                '--restart=always --network=host -e PORT=3080 -e OLLAMA_BASE_URL=http://127.0.0.1:11434 -v open-webui:/app/backend/data'),
            'remove' => app_docker_rm('lk-open-webui'),
            'note' => 'A primeira conta criada vira admin. Pode levar 1 ou 2 minutos para abrir depois de instalar.'],

        /* ---------------- IA */
        ['id' => 'ollama', 'name' => 'Ollama', 'cat' => 'ia', 'color' => '#e5e7eb', 'bin' => 'ollama', 'ver' => 'ollama --version 2>/dev/null | tail -1', 'svc' => 'ollama',
            'desc' => 'Roda modelos de IA (Llama, Qwen, Gemma…) no próprio servidor.',
            'install' => [['Instalar Ollama (script oficial)', 'curl -fsSL https://ollama.com/install.sh | sh']],
            'remove' => [['Remover Ollama (os modelos baixados ficam em /usr/share/ollama)',
                'systemctl disable --now ollama 2>/dev/null; rm -f /etc/systemd/system/ollama.service /usr/local/bin/ollama /usr/bin/ollama; systemctl daemon-reload; echo OK']],
            'note' => 'Baixar um modelo: ollama pull llama3.2 (no Terminal).'],

        /* ---------------- rede e segurança */
        ['id' => 'fail2ban', 'name' => 'Fail2ban', 'cat' => 'net', 'color' => '#b91c1c', 'pkgs' => ['fail2ban'], 'svc' => 'fail2ban',
            'desc' => 'Bloqueia IPs que erram a senha do SSH muitas vezes.',
            'note' => 'Ver bloqueados: sudo fail2ban-client status sshd'],
        ['id' => 'tailscale', 'name' => 'Tailscale', 'cat' => 'net', 'color' => '#4b5563', 'bin' => 'tailscale', 'ver' => 'tailscale version 2>/dev/null | head -1', 'svc' => 'tailscaled',
            'desc' => 'VPN fácil: acesse o servidor e o painel de qualquer lugar, sem abrir portas no roteador.',
            'install' => [['Instalar Tailscale (script oficial)', 'curl -fsSL https://tailscale.com/install.sh | sh']],
            'remove' => [['Remover Tailscale', 'tailscale down 2>/dev/null; ' . app_rm_pkgs(['tailscale'])]],
            'note' => 'Para conectar na sua conta: no Terminal rode sudo tailscale up e abra o link que aparecer.'],
        ['id' => 'cloudflared', 'name' => 'Cloudflare Tunnel', 'cat' => 'net', 'color' => '#f38020', 'bin' => 'cloudflared', 'ver' => 'cloudflared --version 2>/dev/null | head -1', 'svc' => 'lk-cloudflared',
            'desc' => 'Coloca os sites e o painel na internet com HTTPS e o seu domínio, sem abrir portas no roteador.',
            'install' => [
                ['Adicionar o repositório da Cloudflare', 'install -m 0755 -d /usr/share/keyrings && curl -fsSL https://pkg.cloudflare.com/cloudflare-main.gpg -o /usr/share/keyrings/cloudflare-main.gpg && chmod 644 /usr/share/keyrings/cloudflare-main.gpg '
                    . '&& echo "deb [signed-by=/usr/share/keyrings/cloudflare-main.gpg] https://pkg.cloudflare.com/cloudflared any main" > /etc/apt/sources.list.d/cloudflared.list && chmod 644 /etc/apt/sources.list.d/cloudflared.list && echo OK'],
                ['Atualizar lista de pacotes', APT . ' update'],
                ['Instalar cloudflared', APT . ' install cloudflared && cloudflared --version'],
            ],
            'remove' => [['Desligar os túneis do LK', 'systemctl disable --now lk-cloudflared lk-cf-quick 2>/dev/null; echo OK'], ['Remover cloudflared', app_rm_pkgs(['cloudflared'])]],
            'note' => 'Configure na aba Internet do painel.'],
        ['id' => 'whatsapp', 'name' => 'WhatsApp + Bot', 'cat' => 'net', 'color' => '#25d366', 'file' => '/opt/lk-guard/guard.php', 'svc' => 'lk-guard',
            'desc' => 'Avisos do servidor no seu WhatsApp e um bot que responde STATUS, REINICIAR e DESLIGAR.',
            'install' => [['Instalar WhatsApp (GOWA), página do QR code e bot', 'WA_AUTO=1 bash /usr/local/share/lk/install-whatsapp.sh']],
            'remove' => [['Remover WhatsApp e bot', 'WA_AUTO=1 bash /usr/local/share/lk/install-whatsapp.sh uninstall']],
            'note' => 'Depois de instalar: abra a página (botão Abrir, mesma senha do painel) e conecte o número do servidor pelo QR code. Seu número para receber os avisos: no Terminal, sudo lk whatsapp guardiao. Ligar/desligar o bot: sudo lk bot on|off.'],
        ['id' => 'cockpit', 'name' => 'Cockpit', 'cat' => 'sys', 'color' => '#0066cc', 'pkgs' => ['cockpit'], 'svc' => 'cockpit.socket', 'port' => 9090, 'https' => true,
            'desc' => 'Painel do Ubuntu: usuários, discos, rede, atualizações e logs.',
            'install' => [['Atualizar lista de pacotes', APT . ' update'], ['Instalar Cockpit', APT . ' install --no-install-recommends cockpit'],
                ['Ligar o Cockpit', 'systemctl enable --now cockpit.socket && echo OK']],
            'note' => 'Entre com o seu usuário e senha do Linux.'],
        ['id' => 'tools', 'name' => 'Ferramentas', 'cat' => 'sys', 'color' => '#22c55e', 'pkgs' => ['htop', 'btop', 'ncdu', 'tmux'],
            'desc' => 'htop e btop (uso do sistema), ncdu (o que ocupa o disco) e tmux (terminal que não fecha). Use no terminal completo.'],
    ];
}

function app_find(string $id): ?array
{
    foreach (apps_catalog() as $a) if ($a['id'] === $id) return $a;
    return null;
}

/** Passos de instalar / desinstalar (para o jobs.inc.php). */
function app_job_steps(array $a, string $op): array
{
    if ($op === 'install') {
        $l = $a['install'] ?? [['Atualizar lista de pacotes', APT . ' update'], ['Instalar ' . implode(', ', $a['pkgs']), APT . ' install ' . implode(' ', array_map('escapeshellarg', $a['pkgs']))]];
        if (!isset($a['install']) && !empty($a['svc'])) $l[] = ['Ligar ' . $a['svc'] . ' no boot', 'systemctl enable --now ' . escapeshellarg($a['svc']) . ' && systemctl is-active ' . escapeshellarg($a['svc'])];
    } else {
        $l = $a['remove'] ?? [['Remover ' . implode(', ', $a['pkgs']), app_rm_pkgs($a['pkgs'])]];
    }
    $out = [];
    foreach ($l as $i => [$label, $cmd]) {
        // o job.php roda com umask 027; chaves em /usr/share/keyrings precisam ser legíveis pelo usuário _apt
        $out[] = ['key' => $op . $i, 'label' => $label, 'cmd' => 'umask 022; ' . $cmd, 'timeout' => 3600,
            'env' => ['DEBIAN_FRONTEND' => 'noninteractive', 'COMPOSER_ALLOW_SUPERUSER' => '1']];
    }
    return $out;
}
