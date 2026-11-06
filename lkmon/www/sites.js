/* LK Monitor - aba Sites (VirtualHosts do Apache) */
(function () {
  'use strict';
  const U = window.UI;
  if (!U) return;
  const { E, qs, qsa, toast, fail, dialog, confirmDlg, menu, svg, copyText, fsize } = U;
  const C0 = typeof CFG !== 'undefined' ? CFG : {};
  const TOKEN = C0.token || '';
  const RW = C0.user === 'root';
  const TYPE = { php: 'PHP', laravel: 'Laravel', proxy: 'Proxy' };
  const S = { sites: [], php: [], mysql: false, ip: '', loaded: false, error: '', filter: '', sizes: {}, busy: false };
  let root, inited = false;

  async function api(act, body) {
    let r;
    try {
      r = await fetch('?sites=' + act, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-LKM-Token': TOKEN }, body: JSON.stringify(body || {}) });
    } catch (e) { throw new Error('Sem conexão com o painel'); }
    if (r.status === 401) { location.reload(); throw new Error('Sessão expirada'); }
    let d; try { d = await r.json(); } catch (e) { throw new Error('Resposta inválida do servidor (' + r.status + ')'); }
    if (!d.ok) { const er = new Error(d.error || 'Erro desconhecido'); er.detail = d.detail || ''; er.data = d; throw er; }
    return d;
  }
  const errHtml = e => E(e.message) + (e.detail ? '<pre class="nf-pre">' + E(e.detail) + '</pre>' : '');
  const siteUrl = s => (s.ssl ? 'https://' : 'http://') + s.domain + '/';
  const hostsLine = s => (S.ip || '127.0.0.1') + ' ' + s.domain + (s.aliases.includes('www.' + s.domain) ? ' www.' + s.domain : '');

  /* ------------------------------------------------------------ montagem */
  function build() {
    root = qs('#sites');
    const why = C0.user ? 'o painel roda comandos como <b>' + E(C0.user) + '</b> (sem root)' : 'o terminal do painel está desativado';
    root.innerHTML =
      '<div class="card st">' +
      '<div class="st-top"><div class="st-ttl"><h2>Sites</h2><span id="stCount"></span></div>' +
      '<div class="st-tools"><div class="st-search">' + svg('search') + '<input id="stQ" placeholder="Filtrar sites" spellcheck="false" autocomplete="off"></div>' +
      '<button class="abtn" data-a="reload" title="Atualizar">' + svg('refresh') + '</button>' +
      (RW ? '<button class="abtn pri" data-a="new">' + svg('plus') + '<span>Novo site</span></button>' : '') + '</div></div>' +
      (RW ? '' : '<div class="st-ro">' + svg('lock') + '<div><b>Somente leitura.</b> Como ' + why + ', os sites aparecem só para consulta. Para criar, ligar/desligar e remover sites por aqui, rode <code>sudo lk</code> › opção 33 › 1 e escolha <b>root</b> no acesso do terminal.</div></div>') +
      '<div class="st-head"><span>Site</span><span>Tipo</span><span class="r">Tamanho</span><span class="r">Ações</span></div>' +
      '<div id="stList" class="st-list"></div></div>';
    root.addEventListener('click', onClick);
    qs('#stQ').addEventListener('input', e => { S.filter = e.target.value.trim().toLowerCase(); render(); });
  }

  async function load(quiet) {
    if (S.busy) return;
    S.busy = true;
    if (!quiet && !S.loaded) qs('#stList').innerHTML = '<div class="st-empty">Carregando sites…</div>';
    try {
      const d = await api('list');
      Object.assign(S, { sites: d.sites, php: d.php, mysql: d.mysql, ip: d.ip, loaded: true, error: '' });
    } catch (e) { S.error = e.message; S.loaded = true; }
    finally { S.busy = false; }
    render();
  }

  function render() {
    const list = qs('#stList');
    if (!list) return;
    if (S.error) { list.innerHTML = '<div class="fm-err">' + E(S.error) + '</div>'; qs('#stCount').textContent = ''; return; }
    const v = S.sites.filter(s => !S.filter || (s.domain + ' ' + s.aliases.join(' ') + ' ' + s.root + ' ' + s.type).toLowerCase().includes(S.filter));
    const on = S.sites.filter(s => s.on).length;
    qs('#stCount').textContent = S.sites.length ? S.sites.length + (S.sites.length === 1 ? ' site' : ' sites') + ' · ' + on + ' ligado' + (on === 1 ? '' : 's') : '';
    if (!S.sites.length) {
      list.innerHTML = '<div class="st-empty">' + svg('globe') + '<b>Nenhum site criado ainda</b>' + (RW ? '<span>Crie o primeiro com o botão <b>Novo site</b>.</span><button class="abtn pri" data-a="new">' + svg('plus') + '<span>Novo site</span></button>' : '') + '</div>';
      return;
    }
    if (!v.length) { list.innerHTML = '<div class="st-empty">Nenhum site corresponde ao filtro.</div>'; return; }
    list.innerHTML = v.map(rowHtml).join('');
  }

  function rowHtml(s) {
    const al = s.aliases.filter(a => a !== s.domain);
    const size = S.sizes[s.name];
    const sizeHtml = size === 'busy' ? '<span class="st-dim">calculando…</span>' : size != null ? '<span class="num">' + fsize(size) + '</span>' : '<button class="lnk" data-a="size">calcular</button>';
    const tags = '<span class="tag t-' + s.type + '">' + TYPE[s.type] + '</span>' +
      (s.php ? '<span class="tag">PHP ' + E(s.php) + '</span>' : '') +
      (s.proxy ? '<span class="tag mono" title="' + E(s.proxy) + '">→ ' + E(s.proxy.replace(/^https?:\/\/(127\.0\.0\.1|localhost)/, '').replace(/\/$/, '')) + '</span>' : '') +
      (s.ssl ? '<span class="tag t-ssl">SSL</span>' : '');
    return '<div class="st-row' + (s.on ? '' : ' off') + '" data-n="' + E(s.name) + '">' +
      '<div class="st-site"><span class="st-dot" title="' + (s.on ? 'Ligado' : 'Desligado') + '"></span><div class="st-dn">' +
      '<a href="' + E(siteUrl(s)) + '" target="_blank" rel="noopener">' + E(s.domain) + '</a>' +
      '<small>' + (al.length ? E(al.join(', ')) + ' · ' : '') + '<span class="mono">' + E(s.root || '—') + '</span>' + (s.exists || s.type === 'proxy' ? '' : ' <em>(pasta não existe)</em>') + '</small></div></div>' +
      '<div class="st-tags">' + tags + '</div>' +
      '<div class="st-size r">' + sizeHtml + '</div>' +
      '<div class="st-acts">' +
      '<button class="sw' + (s.on ? ' on' : '') + '" data-a="toggle" title="' + (s.on ? 'Ligado: clique para desligar' : 'Desligado: clique para ligar') + '"' + (RW ? '' : ' disabled') + '><i></i><span>' + (s.on ? 'Ligado' : 'Desligado') + '</span></button>' +
      '<button class="ib" data-a="open" title="Abrir o site em nova aba">' + svg('open') + '</button>' +
      '<button class="ib" data-a="folder" title="Abrir a pasta no gerenciador de arquivos">' + svg('folder') + '</button>' +
      (RW && s.type === 'laravel' ? '<button class="ib" data-a="deploy" title="Deploy">' + svg('rocket') + '</button>' : '') +
      '<button class="ib" data-a="log" title="Ver logs">' + svg('log') + '</button>' +
      (RW ? '<button class="ib" data-a="backups" title="Backups">' + svg('archive') + '</button>' : '') +
      '<button class="ib" data-a="more" title="Mais ações">' + svg('more') + '</button>' +
      '</div></div>';
  }

  function siteOf(el) { const r = el.closest('[data-n]'); return r ? S.sites.find(s => s.name === r.dataset.n) : null; }

  function onClick(e) {
    const b = e.target.closest('[data-a]');
    if (!b || b.disabled) return;
    const a = b.dataset.a, s = siteOf(b);
    if (a === 'reload') return load();
    if (a === 'new') return newSite();
    if (!s) return;
    if (a === 'toggle') return toggle(s);
    if (a === 'open') return window.open(siteUrl(s), '_blank', 'noopener');
    if (a === 'folder') return openFolder(s);
    if (a === 'log') return viewLog(s);
    if (a === 'deploy') return deploy(s);
    if (a === 'backups') return backups(s);
    if (a === 'size') return calcSize(s);
    if (a === 'more') { const r = b.getBoundingClientRect(); return moreMenu(s, r.right - 230, r.bottom + 4); }
  }

  function moreMenu(s, x, y) {
    menu(Math.max(6, x), y, [
      { l: 'Abrir site', i: 'open', fn: () => window.open(siteUrl(s), '_blank', 'noopener') },
      { l: 'Abrir pasta', i: 'folder', dis: !s.root, fn: () => openFolder(s) },
      { l: 'Ver logs', i: 'log', fn: () => viewLog(s) },
      ...(s.type === 'laravel' ? [{ l: 'Deploy…', i: 'rocket', dis: !RW, fn: () => deploy(s) }] : []),
      { l: 'Backups…', i: 'archive', dis: !RW, fn: () => backups(s) },
      { l: 'Calcular tamanho', i: 'info', fn: () => calcSize(s) },
      { l: 'Copiar linha do arquivo hosts', i: 'copy', fn: () => copyText(hostsLine(s)).then(() => toast('Linha copiada: ' + hostsLine(s))) },
      '-',
      { l: s.on ? 'Desligar site' : 'Ligar site', i: 'power', dis: !RW, fn: () => toggle(s) },
      { l: 'Remover site…', i: 'trash', danger: true, dis: !RW, fn: () => removeSite(s) }
    ]);
  }

  /* ------------------------------------------------------------ ações */
  function openFolder(s) {
    if (!C0.user) return toast('O gerenciador de arquivos está desativado nesta instalação.', 'er');
    const p = s.exists ? s.root : '/var/www';
    if (window.FM) window.FM.go(p);
    location.hash = '#arquivos';
  }

  async function calcSize(s) {
    S.sizes[s.name] = 'busy'; render();
    try { const d = await api('size', { name: s.name }); S.sizes[s.name] = d.size; }
    catch (e) { delete S.sizes[s.name]; fail(e); }
    render();
  }

  async function toggle(s) {
    if (!RW) return;
    if (s.on && !(await confirmDlg('Desligar ' + s.domain + '?', 'O site para de responder, mas os arquivos continuam em <b>' + E(s.root) + '</b>. Dá para ligar de novo a qualquer momento.', 'Desligar', true))) return;
    try {
      const d = await api('toggle', { name: s.name, on: !s.on });
      Object.assign(s, d.site);
      toast(s.on ? 'Site ' + s.domain + ' ligado' : 'Site ' + s.domain + ' desligado');
    } catch (e) {
      dialog({ title: 'Não foi possível alterar o site', icon: 'warn', html: errHtml(e), wide: !!e.detail });
    }
    render();
  }

  async function removeSite(s) {
    if (!RW) return;
    const r = await dialog({
      title: 'Remover ' + s.domain, icon: 'trash',
      html: '<p>O site será desligado e a configuração dele no Apache será apagada' + (s.ssl ? ' (incluindo o SSL)' : '') + '.</p>' +
        (s.exists ? '<label class="ck"><input type="checkbox" id="rmFiles"> Apagar também os arquivos em <code>' + E(s.root) + '</code></label>' : '') +
        '<div class="nf-l">Para confirmar, digite <b>' + E(s.name) + '</b></div><input class="fm-in mono" id="rmConf" spellcheck="false" autocomplete="off" autocapitalize="off">' +
        '<div class="nf-err" id="rmErr"></div>',
      buttons: [{ label: 'Cancelar', value: null }, { label: 'Remover', value: 'ok', kind: 'danger' }],
      onOpen(ov) {
        const i = qs('#rmConf', ov), b = qs('.df button.danger', ov);
        const chk = () => { b.disabled = i.value.trim() !== s.name; };
        i.addEventListener('input', chk); chk();
      },
      async validate(ov) {
        const conf = qs('#rmConf', ov).value.trim();
        if (conf !== s.name) return false;
        const files = !!(qs('#rmFiles', ov) && qs('#rmFiles', ov).checked);
        const b = qs('.df button.danger', ov); b.disabled = true; b.textContent = 'Removendo…';
        try { return { d: await api('remove', { name: s.name, confirm: conf, files }) }; }
        catch (e) { qs('#rmErr', ov).innerHTML = errHtml(e); b.disabled = false; b.textContent = 'Remover'; return false; }
      }
    });
    if (!r || !r.d) return;
    toast('Site ' + s.domain + ' removido' + (r.d.notes && r.d.notes.length ? ' · ' + r.d.notes.join(' · ') : ''));
    S.sites = S.sites.filter(x => x !== s);
    render();
  }

  /* ------------------------------------------------------------ novo site */
  const normDom = v => v.trim().toLowerCase().replace(/^https?:\/\//, '').split('/')[0].replace(/^www\./, '');
  const validDom = d => d.length <= 200 && /^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/.test(d);

  async function newSite() {
    if (!RW) return;
    if (!S.loaded) await load(true);
    const phpOpts = S.php.slice().reverse().map(v => '<option value="' + E(v) + '">PHP ' + E(v) + '</option>').join('');
    const r = await dialog({
      title: 'Novo site', icon: 'globe', cls: 'nfd',
      html: '<div class="nf">' +
        '<div class="nf-l">Domínio</div><input class="fm-in mono" id="nfDom" placeholder="meusite.test" spellcheck="false" autocomplete="off" autocapitalize="off">' +
        '<div class="nf-h" id="nfDomH">Para usar só na rede local, termine com <b>.test</b> (ex.: loja.test).</div>' +
        '<div class="nf-l">Tipo</div><div class="seg" id="nfType"><button type="button" data-t="php" class="on">PHP / HTML</button><button type="button" data-t="laravel">Laravel</button><button type="button" data-t="proxy">Proxy (Node / PM2)</button></div>' +
        '<div class="nf-h" id="nfTypeH"></div>' +
        '<div id="nfPhpW"><div class="nf-l">Versão do PHP</div>' + (phpOpts ? '<select class="fm-in" id="nfPhp">' + phpOpts + '</select>' : '<div class="nf-err">Nenhum PHP-FPM instalado. Instale o LAMP pelo lk (opção 1).</div>') + '</div>' +
        '<div id="nfPortW" hidden><div class="nf-l">Porta local do app</div><input class="fm-in mono" id="nfPort" inputmode="numeric" placeholder="3000" autocomplete="off"></div>' +
        '<label class="ck"><input type="checkbox" id="nfWww" checked> Responder também em <span class="mono" id="nfWwwD">www.</span></label>' +
        '<label class="ck" id="nfDbW"><input type="checkbox" id="nfDb"' + (S.mysql ? '' : ' disabled') + '> Criar banco de dados MySQL para o site' + (S.mysql ? '' : ' <small>(MySQL/MariaDB não encontrado)</small>') + '</label>' +
        '<div class="nf-err" id="nfErr"></div></div>',
      buttons: [{ label: 'Cancelar', value: null }, { label: 'Criar site', value: 'ok', kind: 'pri' }],
      onOpen(ov) {
        let type = 'php';
        const dom = qs('#nfDom', ov), h = qs('#nfDomH', ov);
        const hint = () => {
          const d = normDom(dom.value) || 'dominio';
          qs('#nfWwwD', ov).textContent = 'www.' + d;
          qs('#nfTypeH', ov).innerHTML = type === 'php' ? 'Arquivos em <code>/var/www/' + E(d) + '/public_html</code>'
            : type === 'laravel' ? 'Projeto em <code>/var/www/' + E(d) + '</code>; o site aponta para a pasta <code>public/</code>'
              : 'Encaminha o domínio para um app rodando em <code>127.0.0.1:porta</code>';
          const v = normDom(dom.value);
          h.classList.remove('bad');
          if (!v) h.innerHTML = 'Para usar só na rede local, termine com <b>.test</b> (ex.: loja.test).';
          else if (/^[a-z0-9-]+$/.test(v)) h.innerHTML = 'Falta a terminação. Sugestão: <button type="button" class="lnk" data-sug="' + E(v) + '.test">' + E(v) + '.test</button>';
          else if (!validDom(v)) { h.classList.add('bad'); h.textContent = 'Domínio inválido: use letras, números, hífen e pontos (ex.: loja.test).'; }
          else h.innerHTML = /\.(test|local|lan|localhost)$/.test(v) ? 'Domínio local: lembre de adicionar no arquivo hosts do seu PC (mostramos a linha depois de criar).' : 'Para funcionar na internet, o DNS de <b>' + E(v) + '</b> precisa apontar para este servidor.';
        };
        dom.addEventListener('input', hint);
        h.addEventListener('click', e => { const b = e.target.closest('[data-sug]'); if (b) { dom.value = b.dataset.sug; hint(); dom.focus(); } });
        qsa('#nfType button', ov).forEach(b => b.onclick = () => {
          type = b.dataset.t; ov.dataset.type = type;
          qsa('#nfType button', ov).forEach(x => x.classList.toggle('on', x === b));
          qs('#nfPhpW', ov).hidden = type === 'proxy'; qs('#nfPortW', ov).hidden = type !== 'proxy'; qs('#nfDbW', ov).hidden = type === 'proxy';
          hint();
        });
        ov.dataset.type = 'php';
        hint();
      },
      async validate(ov) {
        const err = qs('#nfErr', ov), type = ov.dataset.type || 'php';
        const d = normDom(qs('#nfDom', ov).value);
        err.textContent = '';
        if (!validDom(d)) { err.textContent = d ? 'Domínio inválido. Exemplo: meusite.test' : 'Digite o domínio do site.'; qs('#nfDom', ov).focus(); return false; }
        if (S.sites.some(s => s.name === d)) { err.textContent = 'Já existe um site ' + d + '.'; return false; }
        const body = { domain: d, type, www: qs('#nfWww', ov).checked };
        if (type === 'proxy') {
          const p = +qs('#nfPort', ov).value;
          if (!(p >= 1 && p <= 65535)) { err.textContent = 'Informe a porta do app (ex.: 3000).'; qs('#nfPort', ov).focus(); return false; }
          body.port = p;
        } else {
          if (!qs('#nfPhp', ov)) { err.textContent = 'Nenhum PHP-FPM instalado.'; return false; }
          body.php = qs('#nfPhp', ov).value; body.db = qs('#nfDb', ov).checked;
        }
        const b = qs('.df button.pri', ov); b.disabled = true; b.textContent = 'Criando…';
        try { return { d: await api('create', body), type }; }
        catch (e) { err.innerHTML = errHtml(e); b.disabled = false; b.textContent = 'Criar site'; return false; }
      }
    });
    if (!r || !r.d) return;
    created(r.d, r.type);
    load(true);
  }

  function created(d, type) {
    const s = d.site, url = siteUrl(s);
    const env = d.db ? 'DB_CONNECTION=mysql\nDB_HOST=127.0.0.1\nDB_PORT=3306\nDB_DATABASE=' + d.db.name + '\nDB_USERNAME=' + d.db.user + '\nDB_PASSWORD=' + d.db.pass : '';
    dialog({
      title: 'Site criado', icon: 'check', wide: true,
      html: '<div class="nf">' +
        '<p>O site <b>' + E(s.domain) + '</b> está ligado. Endereço: <a href="' + E(url) + '" target="_blank" rel="noopener">' + E(url) + '</a></p>' +
        (type === 'laravel' ? '<p class="nf-h">Coloque o projeto Laravel em <code>' + E(s.root) + '</code> (ex.: <code>git clone … ' + E(s.root) + '</code>) e depois corrija as permissões.</p>' : '') +
        '<div class="nf-l">Arquivo hosts do Windows</div>' +
        '<div class="nf-h">Para abrir pelo nome no seu PC, adicione esta linha em <code>C:\\Windows\\System32\\drivers\\etc\\hosts</code> (abra o Bloco de Notas como administrador):</div>' +
        '<div class="cpl"><code id="hl">' + E(d.hosts) + '</code><button type="button" class="abtn sm" data-copy="hl">' + svg('copy') + 'Copiar</button></div>' +
        (d.db ? '<div class="nf-box"><div class="nf-l">' + svg('db') + ' Banco de dados criado</div>' +
          '<div class="nf-warn">' + svg('warn') + 'Anote agora: a senha não será mostrada de novo.</div>' +
          '<dl class="props"><dt>Banco</dt><dd><code>' + E(d.db.name) + '</code></dd><dt>Usuário</dt><dd><code>' + E(d.db.user) + '</code></dd><dt>Senha</dt><dd><code>' + E(d.db.pass) + '</code></dd><dt>Servidor</dt><dd><code>127.0.0.1:3306</code></dd></dl>' +
          '<div class="nf-l">Linhas para o .env</div><div class="cpl col"><pre id="envl">' + E(env) + '</pre><button type="button" class="abtn sm" data-copy="envl">' + svg('copy') + 'Copiar .env</button></div></div>' : '') +
        (d.db_error ? '<div class="nf-err">' + E(d.db_error) + '</div>' : '') +
        '</div>',
      buttons: [{ label: 'Abrir site', value: 'open' }, { label: 'Concluir', value: true, kind: 'pri' }],
      onOpen(ov) { qsa('[data-copy]', ov).forEach(b => b.onclick = () => copyText(qs('#' + b.dataset.copy, ov).textContent).then(() => toast('Copiado'))); }
    }).then(v => { if (v === 'open') window.open(url, '_blank', 'noopener'); });
  }

  /* ------------------------------------------------------------ logs */
  function viewLog(s) {
    let which = s.type === 'laravel' ? 'laravel' : 'error', timer = null, alive = true, reqId = 0;
    const LBL = { error: 'Erros do Apache', access: 'Acessos', laravel: 'Laravel' };
    const hl = (txt, q) => {
      const lines = txt.split('\n');
      return lines.map(l => {
        let h = E(l);
        if (q) { const re = new RegExp(q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'gi'); h = h.replace(re, m => '<mark>' + m + '</mark>'); }
        const c = /\b(error|critical|emergency|alert|fatal|emerg|crit)\b/i.test(l) ? 'e' : /\b(warn|warning|notice)\b/i.test(l) ? 'w' : '';
        return c ? '<span class="' + c + '">' + h + '</span>' : h;
      }).join('\n');
    };
    dialog({
      title: 'Logs · ' + s.domain, icon: 'log', cls: 'xl',
      html: '<div class="lv">' +
        '<div class="lv-bar"><div class="seg" id="lvW">' + Object.keys(LBL).map(k => '<button type="button" data-w="' + k + '">' + LBL[k] + '</button>').join('') + '</div>' +
        '<div class="st-search">' + svg('search') + '<input id="lvQ" placeholder="Buscar no log" spellcheck="false" autocomplete="off"></div>' +
        '<select class="fm-in sm" id="lvN"><option value="100">100 linhas</option><option value="200" selected>200 linhas</option><option value="500">500 linhas</option><option value="1000">1000 linhas</option></select>' +
        '<label class="ck"><input type="checkbox" id="lvLive"> Ao vivo</label>' +
        '<button type="button" class="ib" id="lvR" title="Atualizar">' + svg('refresh') + '</button></div>' +
        '<div class="lv-file" id="lvF"></div><pre class="lv-out" id="lvO">Carregando…</pre></div>',
      buttons: [{ label: 'Fechar', value: null }],
      onOpen(ov) {
        const out = qs('#lvO', ov), q = qs('#lvQ', ov), n = qs('#lvN', ov), live = qs('#lvLive', ov);
        const fetchLog = async () => {
          const id = ++reqId, query = q.value.trim();
          const stick = out.scrollTop + out.clientHeight >= out.scrollHeight - 30;
          try {
            const d = await api('logs', { name: s.name, which, lines: +n.value, q: query });
            if (id !== reqId || !alive) return;
            qsa('#lvW button', ov).forEach(b => { b.hidden = !(d.avail || []).includes(b.dataset.w); b.classList.toggle('on', b.dataset.w === which); });
            qs('#lvF', ov).innerHTML = '<span class="mono">' + E(d.file) + '</span>' + (d.size != null ? ' · ' + fsize(d.size) : '') + (query ? ' · ' + d.matches + ' linha(s) com “' + E(query) + '”' : '');
            out.innerHTML = d.missing ? '<span class="st-dim">O arquivo ainda não existe (nenhum registro até agora).</span>' : d.text ? hl(d.text, query) : '<span class="st-dim">' + (query ? 'Nada encontrado.' : 'Log vazio.') + '</span>';
            if (stick || id === 1) out.scrollTop = out.scrollHeight;
          } catch (e) {
            if (id !== reqId || !alive) return;
            if (e.data && e.data.avail) {
              qsa('#lvW button', ov).forEach(b => { b.hidden = !e.data.avail.includes(b.dataset.w); });
              if (e.data.avail.length && !e.data.avail.includes(which)) { which = e.data.avail[0]; return fetchLog(); }
            }
            qs('#lvF', ov).textContent = '';
            out.innerHTML = '<span class="e">' + E(e.message) + '</span>';
          }
        };
        const setLive = () => { clearInterval(timer); timer = live.checked ? setInterval(() => { if (!document.hidden) fetchLog(); }, 3000) : null; };
        qsa('#lvW button', ov).forEach(b => b.onclick = () => { which = b.dataset.w; out.textContent = 'Carregando…'; reqId++; fetchLog(); });
        let qt; q.addEventListener('input', () => { clearTimeout(qt); qt = setTimeout(fetchLog, 350); });
        q.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); e.stopPropagation(); fetchLog(); } });
        n.onchange = fetchLog; live.onchange = setLive; qs('#lvR', ov).onclick = fetchLog;
        fetchLog();
      }
    }).then(() => { alive = false; clearInterval(timer); });
  }

  /* ------------------------------------------------------------ tarefas em segundo plano */
  async function jget(q) {
    let r;
    try { r = await fetch('?jobs=' + q, { credentials: 'same-origin', cache: 'no-store' }); } catch (e) { throw new Error('Sem conexão com o painel'); }
    if (r.status === 401) { location.reload(); throw new Error('Sessão expirada'); }
    const d = await r.json();
    if (!d.ok) throw new Error(d.error || 'Erro');
    return d;
  }
  const ST = { queued: 'Na fila', running: 'Em andamento', ok: 'Concluído', error: 'Falhou', interrupted: 'Interrompido' };
  const fdt = t => { if (!t) return '—'; const d = new Date(t * 1000), p = n => String(n).padStart(2, '0'); return p(d.getDate()) + '/' + p(d.getMonth() + 1) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes()); };
  const fdur = s => s < 60 ? s + ' s' : Math.floor(s / 60) + ' min ' + (s % 60) + ' s';
  const jobBadge = J => '<span class="jst ' + J.state + '">' + (ST[J.state] || J.state) + '</span>';

  /** Acompanha uma tarefa: passos, log ao vivo e resultado final. */
  function jobView(id, title, icon) {
    let from = 0, timer = null, alive = true, J = null;
    const ico = { pending: '<i class="jdot"></i>', running: '<i class="jspin"></i>', ok: svg('check'), error: svg('x'), skipped: '<i class="jdot sk"></i>', canceled: '<i class="jdot"></i>' };
    return dialog({
      title: title || 'Tarefa', icon: icon || 'rocket', cls: 'xl',
      html: '<div class="jv"><div class="jv-head"><span id="jvS"></span><span id="jvT" class="st-dim"></span></div>' +
        '<div class="jv-res" id="jvR" hidden></div><div class="jv-steps" id="jvP"></div><pre class="lv-out" id="jvL"></pre></div>',
      buttons: [{ label: 'Baixar log', value: 'log' }, { label: 'Fechar', value: null, kind: 'pri' }],
      onOpen(ov) {
        const L = qs('#jvL', ov);
        const tick = async () => {
          if (!alive) return;
          try {
            const d = await jget('status&id=' + encodeURIComponent(id) + '&from=' + from);
            J = d.job;
            if (d.chunk) {
              const stick = L.scrollTop + L.clientHeight >= L.scrollHeight - 30;
              if (!from || d.from < from) L.textContent = '';
              L.textContent += d.chunk;
              if (stick || !from) L.scrollTop = L.scrollHeight;
            }
            from = d.size;
            qs('#jvS', ov).innerHTML = jobBadge(J);
            const t0 = J.started || J.created, t1 = J.ended || Math.floor(Date.now() / 1000);
            qs('#jvT', ov).textContent = 'iniciado ' + fdt(J.created) + ' · ' + fdur(Math.max(0, t1 - t0));
            qs('#jvP', ov).innerHTML = (J.steps || []).map(st => '<div class="jstep ' + st.state + '">' + (ico[st.state] || '') + '<span>' + E(st.label) + '</span>' +
              (st.ended && st.started ? '<small>' + fdur(st.ended - st.started) + '</small>' : st.state === 'skipped' ? '<small>ignorado</small>' : '') + '</div>').join('');
            if (['ok', 'error', 'interrupted'].includes(J.state)) {
              const R = qs('#jvR', ov); R.hidden = false; R.className = 'jv-res ' + J.state;
              R.innerHTML = J.state === 'ok' ? svg('check') + '<span><b>Concluído com sucesso</b>' + (J.ended ? ' em ' + fdur(J.ended - J.started) : '') + '</span>'
                : J.state === 'error' ? svg('warn') + '<span><b>Falhou</b> no passo <b>' + E(J.failed || '?') + '</b>. Veja o log abaixo.</span>'
                  : svg('warn') + '<span><b>Interrompido</b>: a tarefa parou sem terminar (servidor reiniciado?).</span>';
              return;
            }
          } catch (e) { qs('#jvT', ov).textContent = e.message; }
          timer = setTimeout(tick, 1000);
        };
        tick();
      }
    }).then(v => {
      alive = false; clearTimeout(timer);
      if (v === 'log') { location.href = '?jobs=log&id=' + encodeURIComponent(id); return jobView(id, title, icon); }
      if (J && ['queued', 'running'].includes(J.state)) toast('A tarefa continua em segundo plano');
      return J;
    });
  }
  window.JOBS = { view: jobView, get: jget, badge: jobBadge, fdt, fdur };

  /* ------------------------------------------------------------ backups */
  const KIND = { manual: 'manual', auto: 'automático', seguranca: 'antes de restaurar' };
  const fdtY = t => { const d = new Date(t * 1000), p = n => String(n).padStart(2, '0'); return p(d.getDate()) + '/' + p(d.getMonth() + 1) + '/' + d.getFullYear() + ' ' + p(d.getHours()) + ':' + p(d.getMinutes()); };
  const dbLabel = db => !db || !db.type ? 'sem banco (só arquivos)' : db.type === 'mysql' ? 'MySQL · ' + db.name : 'SQLite · ' + db.path;

  async function backups(s) {
    let d;
    try { d = await api('backups', { name: s.name }); } catch (e) { return fail(e); }
    if (d.running) {
      const ttl = (d.running_type === 'restore' ? 'Restaurar · ' : d.running_type === 'deploy' ? 'Deploy · ' : 'Backup · ') + s.domain;
      await jobView(d.running, ttl, d.running_type === 'deploy' ? 'rocket' : 'archive');
      return backups(s);
    }
    const a = d.auto;
    const list = d.backups.length ? '<div class="bk-list">' + d.backups.map(m => '<div class="bk-row" data-f="' + E(m.file) + '">' +
      '<div class="bk-main"><b>' + fdtY(m.created) + '</b><small>' + E(dbLabel(m.db)) + '</small></div>' +
      '<span class="tag' + (m.kind === 'seguranca' ? ' t-laravel' : m.kind === 'auto' ? ' t-proxy' : '') + '">' + (KIND[m.kind] || m.kind) + '</span>' +
      '<span class="num bk-size">' + fsize(m.size) + '</span>' +
      '<div class="bk-acts"><a class="ib" title="Baixar" href="?fm=download&t=' + encodeURIComponent(TOKEN) + '&path=' + encodeURIComponent(m.path) + '">' + svg('download') + '</a>' +
      (RW ? '<button type="button" class="ib" data-b="restore" title="Restaurar este backup">' + svg('refresh') + '</button><button type="button" class="ib danger" data-b="del" title="Apagar">' + svg('trash') + '</button>' : '') +
      '</div></div>').join('') + '</div>' : '<div class="st-empty">' + svg('archive') + '<b>Nenhum backup ainda</b><span>Faça o primeiro com o botão abaixo.</span></div>';
    const r = await dialog({
      title: 'Backups · ' + s.domain, icon: 'archive', wide: true,
      html: '<div class="nf"><p class="nf-h">Banco: <b>' + E(dbLabel(d.db)) + '</b> · pasta <code>' + E(d.root) + '</code>. Ficam fora do arquivo: vendor, node_modules, caches, sessões e logs.</p>' +
        '<div class="bk-auto">' + svg('archive') + '<span>Backup automático: ' + (a.enabled ? 'diário às <b>' + E(a.time) + '</b>, mantém os últimos <b>' + a.keep + '</b> · este site <b>' + (a.on ? 'está incluído' : 'não está incluído') + '</b>' : '<b>desativado</b>') +
        '</span><button type="button" class="lnk" data-b="cfg">Configurar</button></div>' + list + '<div class="nf-err" id="bkErr"></div></div>',
      buttons: [{ label: 'Fechar', value: null }].concat(RW ? [{ label: 'Fazer backup agora', value: 'now', kind: 'pri' }] : []),
      onOpen(ov, close) {
        qsa('[data-b]', ov).forEach(b => b.onclick = () => {
          const f = b.closest('[data-f]');
          close({ act: b.dataset.b, file: f ? f.dataset.f : null });
        });
      }
    });
    if (!r) return;
    if (r === 'now' || r.act === 'now') {
      try { const j = await api('backup', { name: s.name }); await jobView(j.job, 'Backup · ' + s.domain, 'archive'); }
      catch (e) { if (e.data && e.data.running) await jobView(e.data.running, 'Tarefa · ' + s.domain); else fail(e); }
      return backups(s);
    }
    if (r.act === 'cfg') { location.hash = '#config'; return; }
    const m = d.backups.find(x => x.file === r.file);
    if (!m) return;
    if (r.act === 'del') {
      if (await confirmDlg('Apagar backup?', 'O backup de <b>' + fdtY(m.created) + '</b> (' + fsize(m.size) + ') será apagado de vez.', 'Apagar', true)) {
        try { await api('backup_delete', { name: s.name, file: m.file }); toast('Backup apagado'); } catch (e) { fail(e); }
      }
      return backups(s);
    }
    if (r.act === 'restore') {
      const ok = await dialog({
        title: 'Restaurar ' + s.domain, icon: 'refresh',
        html: '<p>Os arquivos do site e o banco vão voltar ao estado de <b>' + fdtY(m.created) + '</b> (' + E(dbLabel(m.db)) + ').</p>' +
          '<div class="nf-warn">' + svg('check') + 'Antes, é feito um backup de segurança do estado atual (aparece como “antes de restaurar”).</div>' +
          '<p class="nf-h">vendor, node_modules, caches e logs atuais são mantidos; se faltar vendor, roda composer install no final.</p>' +
          '<div class="nf-l">Para confirmar, digite <b>' + E(s.name) + '</b></div><input class="fm-in mono" id="rsC" spellcheck="false" autocomplete="off" autocapitalize="off"><div class="nf-err" id="rsErr"></div>',
        buttons: [{ label: 'Cancelar', value: null }, { label: 'Restaurar', value: 'ok', kind: 'danger' }],
        onOpen(ov) { const i = qs('#rsC', ov), b = qs('.df button.danger', ov); const c = () => { b.disabled = i.value.trim() !== s.name; }; i.addEventListener('input', c); c(); },
        async validate(ov) {
          const conf = qs('#rsC', ov).value.trim();
          if (conf !== s.name) return false;
          const b = qs('.df button.danger', ov); b.disabled = true; b.textContent = 'Iniciando…';
          try { return { job: (await api('restore', { name: s.name, file: m.file, confirm: conf })).job }; }
          catch (e) { qs('#rsErr', ov).innerHTML = errHtml(e); b.disabled = false; b.textContent = 'Restaurar'; return false; }
        }
      });
      if (ok && ok.job) { await jobView(ok.job, 'Restaurar · ' + s.domain, 'refresh'); load(true); }
      return backups(s);
    }
  }

  /* ------------------------------------------------------------ deploy */
  async function deploy(s) {
    if (!RW) return;
    let info, hist;
    try { [info, hist] = await Promise.all([api('deploy_info', { name: s.name }), jget('list&type=deploy&site=' + encodeURIComponent(s.name))]); }
    catch (e) { return fail(e); }
    if (info.running) return jobView(info.running, 'Deploy · ' + s.domain).then(() => load(true));
    if (!info.laravel) {
      return dialog({ title: 'Deploy · ' + s.domain, icon: 'rocket', html: '<p>Não encontrei um projeto Laravel em <code>' + E(info.root) + '</code> (falta o arquivo <code>artisan</code>).</p><p class="nf-h">Coloque o projeto na pasta (ex.: <code>git clone … ' + E(info.root) + '</code>) e tente de novo.</p>' });
    }
    const npmOk = info.pkg && info.node;
    const opt = (k, label, desc, on, extra) => '<label class="dp-opt"><input type="checkbox" data-o="' + k + '"' + (on ? ' checked' : '') + '><div><b>' + label + '</b>' + (desc ? '<small>' + desc + '</small>' : '') + (extra || '') + '</div></label>';
    const histHtml = hist.jobs.length ? '<div class="nf-l">Últimos deploys</div><div class="dp-hist">' + hist.jobs.map(J => '<button type="button" class="dp-h" data-job="' + E(J.id) + '">' + jobBadge(J) + '<span>' + fdt(J.created) + '</span><small>' +
      (J.ended && J.started ? fdur(J.ended - J.started) : '') + (J.state === 'error' && J.failed ? ' · parou em ' + E(J.failed) : '') + '</small></button>').join('') + '</div>' : '';
    const r = await dialog({
      title: 'Deploy · ' + s.domain, icon: 'rocket', wide: true,
      html: '<div class="nf"><p class="nf-h">Pasta <code>' + E(info.root) + '</code> · PHP ' + E(info.php || '?') + '. Os comandos do artisan, composer e npm rodam como <b>www-data</b>.</p>' +
        '<div class="dp-opts">' +
        opt('hot', 'Remover public/hot', 'arquivo do servidor de desenvolvimento do Vite' + (info.hot ? ' · <span class="warn">existe agora</span>' : ''), true) +
        opt('perms', 'Corrigir dono e permissões', 'www-data em tudo; escrita em storage, bootstrap/cache e database', true) +
        opt('composer', 'composer install', info.composer ? (info.vendor ? '' : 'a pasta vendor ainda não existe') : '<span class="warn">Composer não instalado (lk, opção 16)</span>', info.composer,
          '<label class="ck sub"><input type="checkbox" data-o="nodev"> --no-dev (sem pacotes de desenvolvimento)</label>') +
        opt('migrate', 'php artisan migrate --force', 'aplica as migrations no banco', true) +
        opt('optimize', 'php artisan optimize:clear', 'limpa caches de config, rotas e views', true) +
        opt('storage', 'php artisan storage:link', 'cria o link public/storage', true) +
        (npmOk ? opt('npm', 'npm ci && npm run build', 'compila os assets (Vite)', false) : info.pkg ? '<div class="nf-h">npm: o Node.js não está instalado neste servidor (lk, opção 8).</div>' : '') +
        '</div>' + histHtml + '<div class="nf-err" id="dpErr"></div></div>',
      buttons: [{ label: 'Cancelar', value: null }, { label: 'Iniciar deploy', value: 'ok', kind: 'pri' }],
      onOpen(ov, close) {
        qsa('[data-job]', ov).forEach(b => b.onclick = () => close({ view: b.dataset.job }));
        const c = qs('[data-o="composer"]', ov), nd = qs('[data-o="nodev"]', ov);
        const sync = () => { nd.disabled = !c.checked; if (!c.checked) nd.checked = false; };
        c.onchange = sync; sync();
      },
      async validate(ov) {
        const opts = {};
        qsa('[data-o]', ov).forEach(i => { opts[i.dataset.o] = i.checked; });
        if (!['hot', 'perms', 'composer', 'migrate', 'optimize', 'storage', 'npm'].some(k => opts[k])) { qs('#dpErr', ov).textContent = 'Marque pelo menos um passo.'; return false; }
        const b = qs('.df button.pri', ov); b.disabled = true; b.textContent = 'Iniciando…';
        try { return { job: (await api('deploy', { name: s.name, opts })).job }; }
        catch (e) {
          if (e.data && e.data.running) return { job: e.data.running };
          qs('#dpErr', ov).innerHTML = errHtml(e); b.disabled = false; b.textContent = 'Iniciar deploy'; return false;
        }
      }
    });
    if (r && r.view) return jobView(r.view, 'Deploy · ' + s.domain).then(() => deploy(s));
    if (r && r.job) jobView(r.job, 'Deploy · ' + s.domain).then(() => load(true));
  }

  /* ------------------------------------------------------------ inicialização */
  window.SITES = {
    show() {
      if (!qs('#sites')) return;
      if (!inited) { inited = true; build(); load(); }
      else load(true);
    }
  };
  if (location.hash === '#sites') window.SITES.show();
})();
