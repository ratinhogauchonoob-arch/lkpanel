/* LK Monitor - aba Bancos (MySQL/MariaDB e SQLite) */
(function () {
  'use strict';
  const U = window.UI;
  if (!U) return;
  const { E, qs, qsa, toast, fail, dialog, confirmDlg, menu, svg, copyText, fsize } = U;
  const C0 = typeof CFG !== 'undefined' ? CFG : {};
  const TOKEN = C0.token || '';
  const ROOT = C0.user === 'root';
  const S = { tab: 'mysql', data: null, error: '', filter: '', busy: false };
  let root, inited = false;

  async function api(act, body, raw) {
    let r;
    try {
      r = await fetch('?db=' + act, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-LKM-Token': TOKEN }, body: JSON.stringify(body || {}) });
    } catch (e) { throw new Error('Sem conexão com o painel'); }
    if (r.status === 401) { location.reload(); throw new Error('Sessão expirada'); }
    if (raw && (r.headers.get('Content-Type') || '').startsWith('text/csv')) return r;
    let d; try { d = await r.json(); } catch (e) { throw new Error('Resposta inválida do servidor (' + r.status + ')'); }
    if (!d.ok) { const er = new Error(d.error || 'Erro desconhecido'); er.detail = d.detail || ''; er.data = d; throw er; }
    return d;
  }
  const errHtml = e => E(e.message) + (e.detail ? '<pre class="nf-pre">' + E(e.detail) + '</pre>' : '');
  const envLines = d => 'DB_CONNECTION=mysql\nDB_HOST=127.0.0.1\nDB_PORT=3306\nDB_DATABASE=' + d.name + '\nDB_USERNAME=' + d.user + '\nDB_PASSWORD=' + d.pass;
  const num = n => n == null ? '—' : Number(n).toLocaleString('pt-BR');

  /* ------------------------------------------------------------ lista */
  function build() {
    root = qs('#bancos');
    if (!ROOT) {
      root.innerHTML = '<div class="card st"><div class="st-top"><div class="st-ttl"><h2>Bancos de dados</h2></div></div><div class="st-ro">' + svg('lock') +
        '<div><b>Indisponível nesta instalação.</b> Para ver e gerenciar os bancos, o painel precisa de acesso root (para entrar no MySQL pelo socket). Rode <code>sudo lk</code> › opção 33 › 1 e escolha <b>root</b> no acesso do terminal.</div></div><div class="st-empty"></div></div>';
      return;
    }
    root.innerHTML =
      '<div class="card st">' +
      '<div class="st-top"><div class="st-ttl"><h2>Bancos de dados</h2><span id="dbCount"></span></div>' +
      '<div class="st-tools"><div class="seg" id="dbTab"><button type="button" data-t="mysql" class="on">MySQL / MariaDB</button><button type="button" data-t="sqlite">SQLite</button></div>' +
      '<div class="st-search">' + svg('search') + '<input id="dbQ" placeholder="Filtrar" spellcheck="false" autocomplete="off"></div>' +
      '<button class="abtn" data-a="reload" title="Atualizar">' + svg('refresh') + '</button>' +
      '<button class="abtn pri" data-a="new" id="dbNew">' + svg('plus') + '<span>Novo banco</span></button></div></div>' +
      '<div id="dbMsg"></div><div id="dbList" class="st-list"></div></div>' +
      '<div class="card st" id="dbUsersCard"><div class="st-top"><div class="st-ttl"><h2>Usuários do MySQL</h2><span id="dbUCount"></span></div></div><div id="dbUsers" class="st-list"></div></div>';
    root.addEventListener('click', onClick);
    qs('#dbQ').addEventListener('input', e => { S.filter = e.target.value.trim().toLowerCase(); render(); });
    qsa('#dbTab button').forEach(b => b.onclick = () => { S.tab = b.dataset.t; qsa('#dbTab button').forEach(x => x.classList.toggle('on', x === b)); render(); });
  }

  async function load(quiet) {
    if (S.busy || !ROOT) return;
    S.busy = true;
    if (!quiet && !S.data) qs('#dbList').innerHTML = '<div class="st-empty">Carregando bancos…</div>';
    try { S.data = await api('overview'); S.error = ''; }
    catch (e) { S.error = e.message; }
    finally { S.busy = false; }
    render();
  }

  function render() {
    if (!root || !ROOT) return;
    const list = qs('#dbList'), msg = qs('#dbMsg'), d = S.data;
    qs('#dbNew').hidden = S.tab !== 'mysql';
    qs('#dbUsersCard').hidden = S.tab !== 'mysql' || !d || !d.mysql.ok;
    if (S.error) { list.innerHTML = '<div class="fm-err">' + E(S.error) + '</div>'; msg.innerHTML = ''; return; }
    if (!d) return;
    const f = S.filter;
    if (S.tab === 'mysql') {
      msg.innerHTML = d.mysql.ok ? '' : '<div class="st-ro">' + svg('warn') + '<div>' + E(d.mysql.error || 'MySQL indisponível') + '</div></div>';
      qs('#dbNew').disabled = !d.mysql.ok;
      const v = d.dbs.filter(x => !f || (x.name + ' ' + x.users.join(' ') + ' ' + x.sites.join(' ')).toLowerCase().includes(f));
      qs('#dbCount').textContent = d.mysql.ok ? d.dbs.length + (d.dbs.length === 1 ? ' banco' : ' bancos') + ' · ' + d.mysql.version : '';
      list.innerHTML = !d.mysql.ok ? '' : !d.dbs.length ? '<div class="st-empty">' + svg('db') + '<b>Nenhum banco criado ainda</b><span>Crie o primeiro com <b>Novo banco</b>.</span></div>'
        : !v.length ? '<div class="st-empty">Nada corresponde ao filtro.</div>' : v.map(rowMy).join('');
      const us = d.users.filter(u => !f || (u.user + ' ' + u.dbs.join(' ')).toLowerCase().includes(f));
      qs('#dbUCount').textContent = d.users.length ? d.users.length + (d.users.length === 1 ? ' usuário' : ' usuários') : '';
      qs('#dbUsers').innerHTML = us.length ? us.map(rowUser).join('') : '<div class="st-empty">Nenhum usuário além dos do sistema.</div>';
    } else {
      msg.innerHTML = '';
      const v = d.sqlite.filter(x => !f || (x.path + ' ' + x.site).toLowerCase().includes(f));
      qs('#dbCount').textContent = d.sqlite.length + (d.sqlite.length === 1 ? ' arquivo' : ' arquivos') + ' em /var/www';
      list.innerHTML = !d.sqlite.length ? '<div class="st-empty">' + svg('db') + '<b>Nenhum arquivo SQLite encontrado</b><span>Procuro arquivos .sqlite, .sqlite3 e .db dentro de /var/www (ex.: database/database.sqlite do Laravel).</span></div>'
        : !v.length ? '<div class="st-empty">Nada corresponde ao filtro.</div>' : v.map(rowLite).join('');
    }
  }

  function rowMy(x) {
    return '<div class="st-row db-row" data-db="' + E(x.name) + '">' +
      '<div class="st-site"><span class="db-ico">' + svg('db') + '</span><div class="st-dn"><button type="button" class="db-name" data-a="open">' + E(x.name) + '</button>' +
      '<small>' + (x.sites.length ? 'usado por ' + E(x.sites.join(', ')) : 'nenhum site usa este banco no .env') + '</small></div></div>' +
      '<div class="st-tags"><span class="tag">' + num(x.tables) + (x.tables === 1 ? ' tabela' : ' tabelas') + '</span>' + x.users.map(u => '<span class="tag mono">' + svg('users') + E(u) + '</span>').join('') + '</div>' +
      '<div class="st-size r num">' + fsize(x.size) + '</div>' +
      '<div class="st-acts"><button class="ib" data-a="open" title="Abrir tabelas e consultas">' + svg('table') + '<span class="lbl">Abrir</span></button>' +
      '<button class="ib" data-a="more" title="Mais ações">' + svg('more') + '</button></div></div>';
  }
  function rowUser(u) {
    return '<div class="st-row db-row" data-user="' + E(u.user) + '" data-host="' + E(u.host) + '">' +
      '<div class="st-site"><span class="db-ico">' + svg('users') + '</span><div class="st-dn"><b class="mono">' + E(u.user) + '<span class="st-dim">@' + E(u.host) + '</span></b>' +
      '<small>' + (u.sites.length ? 'no .env de ' + E(u.sites.join(', ')) : 'não aparece no .env de nenhum site') + '</small></div></div>' +
      '<div class="st-tags">' + (u.dbs.length ? u.dbs.map(b => '<span class="tag mono">' + E(b) + '</span>').join('') : '<span class="st-dim">sem banco</span>') + '</div><div></div>' +
      '<div class="st-acts">' + (u.host === 'localhost' ? '<button class="ib" data-a="pass" title="Trocar senha">' + svg('key') + '<span class="lbl">Trocar senha</span></button>' : '') + '</div></div>';
  }
  function rowLite(x) {
    const i = x.path.lastIndexOf('/');
    return '<div class="st-row db-row" data-path="' + E(x.path) + '">' +
      '<div class="st-site"><span class="db-ico lite">' + svg('db') + '</span><div class="st-dn"><button type="button" class="db-name" data-a="open">' + E(x.path.slice(i + 1)) + '</button>' +
      '<small class="mono">' + E(x.path.slice(0, i)) + '</small></div></div>' +
      '<div class="st-tags"><span class="tag">' + E(x.site) + '</span>' + (x.used ? '<span class="tag t-ssl">usado pelo .env</span>' : '') + '</div>' +
      '<div class="st-size r num">' + fsize(x.size) + '</div>' +
      '<div class="st-acts"><button class="ib" data-a="open" title="Abrir tabelas e consultas">' + svg('table') + '<span class="lbl">Abrir</span></button></div></div>';
  }

  function onClick(e) {
    const b = e.target.closest('[data-a]');
    if (!b || b.disabled) return;
    const a = b.dataset.a, row = b.closest('.db-row');
    if (a === 'reload') return load();
    if (a === 'new') return createDb();
    if (!row) return;
    const d = S.data;
    if (row.dataset.db) {
      const x = d.dbs.find(y => y.name === row.dataset.db);
      if (a === 'open') return viewer({ type: 'mysql', db: x.name }, x.name);
      if (a === 'more') {
        const r = b.getBoundingClientRect();
        return menu(Math.max(6, r.right - 240), r.bottom + 4, [
          { l: 'Abrir tabelas e consultas', i: 'table', fn: () => viewer({ type: 'mysql', db: x.name }, x.name) },
          ...x.users.map(u => ({ l: 'Trocar senha de ' + u, i: 'key', fn: () => changePass(d.users.find(z => z.user === u && z.host === 'localhost') || { user: u, sites: [] }) })),
          { l: 'Copiar nome', i: 'copy', fn: () => copyText(x.name).then(() => toast('Copiado')) },
          '-', { l: 'Apagar banco…', i: 'trash', danger: true, fn: () => dropDb(x) }
        ]);
      }
    } else if (row.dataset.user) {
      if (a === 'pass') return changePass(d.users.find(u => u.user === row.dataset.user && u.host === row.dataset.host));
    } else if (row.dataset.path) {
      if (a === 'open') return viewer({ type: 'sqlite', path: row.dataset.path }, row.dataset.path.split('/').pop());
    }
  }

  /* ------------------------------------------------------------ criar / apagar / senha */
  function credsHtml(d, title) {
    return '<div class="nf-box"><div class="nf-l">' + svg('db') + ' ' + title + '</div>' +
      '<div class="nf-warn">' + svg('warn') + 'Anote agora: a senha não será mostrada de novo.</div>' +
      '<dl class="props"><dt>Banco</dt><dd><code>' + E(d.name) + '</code></dd><dt>Usuário</dt><dd><code>' + E(d.user) + '</code></dd><dt>Senha</dt><dd><code>' + E(d.pass) + '</code></dd><dt>Servidor</dt><dd><code>127.0.0.1:3306</code></dd></dl>' +
      '<div class="nf-l">Linhas para o .env</div><div class="cpl col"><pre id="envl">' + E(envLines(d)) + '</pre><button type="button" class="abtn sm" data-copy="envl">' + svg('copy') + 'Copiar .env</button></div></div>';
  }
  const bindCopy = ov => qsa('[data-copy]', ov).forEach(b => b.onclick = () => copyText(qs('#' + b.dataset.copy, ov).textContent).then(() => toast('Copiado')));

  async function createDb() {
    const r = await dialog({
      title: 'Novo banco MySQL', icon: 'db', cls: 'nfd',
      html: '<div class="nf"><div class="nf-l">Nome do banco</div><input class="fm-in mono" id="cdN" placeholder="minha_loja" spellcheck="false" autocomplete="off" autocapitalize="off">' +
        '<div class="nf-h">Letras, números e _ (ex.: loja ou loja_producao).</div>' +
        '<div class="nf-l">Usuário</div><input class="fm-in mono" id="cdU" placeholder="igual ao nome do banco" spellcheck="false" autocomplete="off" autocapitalize="off">' +
        '<div class="nf-h">Recebe acesso total só a este banco. A senha é gerada automaticamente.</div><div class="nf-err" id="cdErr"></div></div>',
      buttons: [{ label: 'Cancelar', value: null }, { label: 'Criar banco', value: 'ok', kind: 'pri' }],
      onOpen(ov) {
        const n = qs('#cdN', ov), u = qs('#cdU', ov);
        n.addEventListener('input', () => { const v = n.value.trim().toLowerCase().replace(/[^a-z0-9_]+/g, '_'); if (v !== n.value) n.value = v; u.placeholder = v ? v.slice(0, 32) : 'igual ao nome do banco'; });
      },
      async validate(ov) {
        const name = qs('#cdN', ov).value.trim(), user = qs('#cdU', ov).value.trim(), err = qs('#cdErr', ov);
        if (!/^[A-Za-z0-9_]{1,64}$/.test(name)) { err.textContent = 'Nome inválido.'; return false; }
        if (user && !/^[A-Za-z0-9_]{1,32}$/.test(user)) { err.textContent = 'Usuário inválido.'; return false; }
        const b = qs('.df button.pri', ov); b.disabled = true; b.textContent = 'Criando…';
        try { return { d: await api('create', { name, user }) }; }
        catch (e) { err.innerHTML = errHtml(e); b.disabled = false; b.textContent = 'Criar banco'; return false; }
      }
    });
    if (!r) return;
    load(true);
    dialog({ title: 'Banco criado', icon: 'check', wide: true, html: '<div class="nf">' + credsHtml(r.d.db, 'Credenciais') + '</div>', buttons: [{ label: 'Concluir', value: true, kind: 'pri' }], onOpen: bindCopy });
  }

  async function dropDb(x) {
    const r = await dialog({
      title: 'Apagar o banco ' + x.name, icon: 'trash',
      html: '<p>Todas as tabelas e dados de <b>' + E(x.name) + '</b> serão apagados. Antes disso é feito um backup automático (.sql.gz) em <code>/var/backups/lkmonitor/_bancos</code>.</p>' +
        (x.sites.length ? '<div class="nf-warn">' + svg('warn') + 'Usado pelo .env de ' + E(x.sites.join(', ')) + '.</div>' : '') +
        x.only.map(u => '<label class="ck"><input type="checkbox" data-u="' + E(u) + '"> Apagar também o usuário <code>' + E(u) + '</code> (só tem acesso a este banco)</label>').join('') +
        '<div class="nf-l">Para confirmar, digite <b>' + E(x.name) + '</b></div><input class="fm-in mono" id="ddC" spellcheck="false" autocomplete="off" autocapitalize="off"><div class="nf-err" id="ddErr"></div>',
      buttons: [{ label: 'Cancelar', value: null }, { label: 'Apagar', value: 'ok', kind: 'danger' }],
      onOpen(ov) { const i = qs('#ddC', ov), b = qs('.df button.danger', ov); const c = () => { b.disabled = i.value.trim() !== x.name; }; i.addEventListener('input', c); c(); },
      async validate(ov) {
        const conf = qs('#ddC', ov).value.trim();
        if (conf !== x.name) return false;
        const users = qsa('[data-u]', ov).filter(i => i.checked).map(i => i.dataset.u);
        const b = qs('.df button.danger', ov); b.disabled = true; b.textContent = 'Fazendo backup…';
        try { return { d: await api('drop', { name: x.name, confirm: conf, users }) }; }
        catch (e) { qs('#ddErr', ov).innerHTML = errHtml(e); b.disabled = false; b.textContent = 'Apagar'; return false; }
      }
    });
    if (!r) return;
    toast('Banco ' + x.name + ' apagado · backup em ' + r.d.backup);
    load(true);
  }

  async function changePass(u) {
    if (!u) return;
    const r = await dialog({
      title: 'Trocar senha de ' + u.user, icon: 'key', cls: 'nfd',
      html: '<div class="nf"><label class="ck"><input type="radio" name="pm" value="gen" checked> Gerar uma senha forte</label>' +
        '<label class="ck"><input type="radio" name="pm" value="typed"> Digitar a senha</label>' +
        '<input class="fm-in mono" id="cpP" placeholder="mínimo 8 caracteres" spellcheck="false" autocomplete="new-password" hidden>' +
        (u.sites && u.sites.length ? '<div class="nf-l">Sites que usam este usuário</div>' + u.sites.map(s => '<label class="ck"><input type="checkbox" data-s="' + E(s) + '" checked> Atualizar a senha no .env de <b>' + E(s) + '</b></label>').join('') +
          '<div class="nf-h">Depois de atualizar, roda <code>php artisan config:clear</code> no site.</div>' : '<div class="nf-h">Nenhum site usa este usuário no .env; lembre de atualizar onde ele for usado.</div>') +
        '<div class="nf-err" id="cpErr"></div></div>',
      buttons: [{ label: 'Cancelar', value: null }, { label: 'Trocar senha', value: 'ok', kind: 'pri' }],
      onOpen(ov) { qsa('input[name=pm]', ov).forEach(i => i.onchange = () => { const t = qs('input[name=pm]:checked', ov).value === 'typed'; qs('#cpP', ov).hidden = !t; if (t) qs('#cpP', ov).focus(); }); },
      async validate(ov) {
        const typed = qs('input[name=pm]:checked', ov).value === 'typed', pass = typed ? qs('#cpP', ov).value : '';
        if (typed && (pass.length < 8 || /[\s'"\\`$]/.test(pass))) { qs('#cpErr', ov).textContent = 'Use pelo menos 8 caracteres, sem espaços, aspas, barra invertida, crase ou $.'; return false; }
        const sites = qsa('[data-s]', ov).filter(i => i.checked).map(i => i.dataset.s);
        const b = qs('.df button.pri', ov); b.disabled = true; b.textContent = 'Trocando…';
        try { return { d: await api('password', { user: u.user, pass, sites }) }; }
        catch (e) { qs('#cpErr', ov).innerHTML = errHtml(e); b.disabled = false; b.textContent = 'Trocar senha'; return false; }
      }
    });
    if (!r) return;
    dialog({
      title: 'Senha trocada', icon: 'check',
      html: '<div class="nf"><div class="nf-warn">' + svg('warn') + 'Anote agora: a senha não será mostrada de novo.</div>' +
        '<div class="cpl"><code id="np">' + E(r.d.pass) + '</code><button type="button" class="abtn sm" data-copy="np">' + svg('copy') + 'Copiar</button></div>' +
        (r.d.updated.length ? '<p class="nf-h">.env atualizado em: ' + E(r.d.updated.join(', ')) + '</p>' : '') + '</div>',
      buttons: [{ label: 'Concluir', value: true, kind: 'pri' }], onOpen: bindCopy
    });
  }

  /* ------------------------------------------------------------ visualizador */
  function viewer(src, label) {
    const kind = src.type;
    const V = { tables: [], table: null, tab: 'data', page: 1, q: '', sort: '', desc: false, write: false, lastSql: '' };
    const el = document.createElement('div');
    el.className = 'dbv';
    el.innerHTML =
      '<div class="dbv-h"><button class="ib" data-v="close" title="Fechar (Esc)">' + svg('back') + '</button>' + svg('db') +
      '<div class="dbv-t"><b>' + E(label) + '</b><small>' + (kind === 'mysql' ? 'MySQL / MariaDB' : E(src.path)) + '</small></div>' +
      '<select class="fm-in sm dbv-sel" id="vSel"></select></div>' +
      '<div class="dbv-b"><aside class="dbv-side"><div class="st-search">' + svg('search') + '<input id="vTQ" placeholder="Filtrar tabelas" spellcheck="false" autocomplete="off"></div><div class="dbv-tl" id="vTL"><div class="st-dim">Carregando…</div></div></aside>' +
      '<section class="dbv-main"><div class="dbv-tabs"><div class="seg" id="vTabs"><button type="button" data-t="data" class="on">Dados</button><button type="button" data-t="struct">Estrutura</button><button type="button" data-t="sql">Consulta SQL</button></div><span id="vInfo" class="st-dim"></span></div>' +
      '<div class="dbv-pane" id="pData"><div class="dbv-bar"><div class="st-search">' + svg('search') + '<input id="vQ" placeholder="Buscar em todas as colunas (Enter)" spellcheck="false" autocomplete="off"></div>' +
      '<div class="dbv-pg"><button class="ib" data-v="prev" title="Página anterior">' + svg('chevL') + '</button><span id="vPg" class="num"></span><button class="ib" data-v="next" title="Próxima página">' + svg('chevR') + '</button></div>' +
      '<button class="abtn sm" data-v="exptable">' + svg('download') + 'CSV</button></div><div class="dbv-grid" id="vGrid"></div></div>' +
      '<div class="dbv-pane" id="pStruct" hidden><div class="dbv-grid" id="vCols"></div></div>' +
      '<div class="dbv-pane" id="pSql" hidden><textarea class="dbv-sql" id="vSql" spellcheck="false" placeholder="SELECT * FROM tabela LIMIT 50"></textarea>' +
      '<div class="dbv-bar"><button class="abtn pri" data-v="run">' + svg('play') + 'Executar <small>Ctrl+Enter</small></button>' +
      '<button type="button" class="sw" id="vW" data-v="write"><i></i><span>Permitir alterações</span></button>' +
      '<span class="sp"></span><button class="abtn sm" data-v="expsql" disabled>' + svg('download') + 'Exportar CSV</button></div>' +
      '<div class="dbv-res st-dim" id="vRes">Somente leitura: SELECT, SHOW, DESCRIBE, EXPLAIN' + (kind === 'sqlite' ? ', PRAGMA' : '') + '. Até 1000 linhas na tela, 30 s por consulta.</div><div class="dbv-grid" id="vOut"></div></div>' +
      '</section></div>';
    document.body.appendChild(el);
    document.body.style.overflow = 'hidden';
    const $ = s => qs(s, el);
    const close = () => { el.remove(); document.body.style.overflow = ''; document.removeEventListener('keydown', key, true); };
    const key = e => { if (e.key === 'Escape' && !qs('.fm-ov') && !qs('#fmMenu')) { e.preventDefault(); close(); } };
    document.addEventListener('keydown', key, true);

    const grid = (cols, rows, sortable) => {
      if (!cols.length) return '<div class="st-empty">Nenhuma coluna.</div>';
      return '<table><thead><tr>' + cols.map(c => '<th' + (sortable ? ' data-sort="' + E(c) + '"' : '') + '>' + E(c) + (sortable && V.sort === c ? (V.desc ? ' ↓' : ' ↑') : '') + '</th>').join('') + '</tr></thead><tbody>' +
        (rows.length ? rows.map(r => '<tr>' + r.map(v => v === null ? '<td class="nul">NULL</td>' : '<td title="' + E(String(v).slice(0, 300)) + '">' + E(v) + '</td>').join('') + '</tr>').join('')
          : '<tr><td colspan="' + cols.length + '" class="st-dim">Nenhuma linha.</td></tr>') + '</tbody></table>';
    };

    async function loadTables() {
      try {
        V.tables = (await api('tables', { src })).tables;
        renderTables();
        $('#vSel').innerHTML = V.tables.map(t => '<option value="' + E(t.name) + '">' + E(t.name) + ' (' + (t.rows == null ? '?' : (t.exact ? '' : '~') + num(t.rows)) + ')</option>').join('');
        if (V.tables.length) pick(V.tables[0].name);
        else { $('#vGrid').innerHTML = '<div class="st-empty">' + svg('table') + '<b>Banco sem tabelas</b><span>Use a aba Consulta SQL ou rode as migrations do site.</span></div>'; setTab('sql'); }
      } catch (e) { $('#vTL').innerHTML = '<div class="fm-err">' + E(e.message) + '</div>'; }
    }
    function renderTables() {
      const f = $('#vTQ').value.trim().toLowerCase();
      const v = V.tables.filter(t => !f || t.name.toLowerCase().includes(f));
      $('#vTL').innerHTML = v.length ? v.map(t => '<button type="button" class="dbv-ti' + (t.name === V.table ? ' on' : '') + '" data-tb="' + E(t.name) + '">' + svg(t.view ? 'eye' : 'table') + '<span>' + E(t.name) + '</span><small class="num">' +
        (t.rows == null ? '' : (t.exact ? '' : '~') + num(t.rows)) + '</small></button>').join('') : '<div class="st-dim">Nenhuma tabela.</div>';
    }
    function pick(name) {
      V.table = name; V.page = 1; V.q = ''; V.sort = ''; V.desc = false; $('#vQ').value = '';
      $('#vSel').value = name; renderTables();
      if (!$('#vSql').value.trim()) $('#vSql').value = 'SELECT * FROM ' + (kind === 'mysql' ? '`' + name + '`' : '"' + name + '"') + ' LIMIT 50';
      if (V.tab === 'sql') setTab('data'); else refresh();
    }
    function setTab(t) {
      V.tab = t;
      qsa('#vTabs button', el).forEach(b => b.classList.toggle('on', b.dataset.t === t));
      $('#pData').hidden = t !== 'data'; $('#pStruct').hidden = t !== 'struct'; $('#pSql').hidden = t !== 'sql';
      if (t === 'sql') { $('#vInfo').textContent = ''; setTimeout(() => $('#vSql').focus(), 30); } else refresh();
    }
    async function refresh() {
      if (!V.table) return;
      const t = V.table;
      if (V.tab === 'data') {
        $('#vGrid').classList.add('busy');
        try {
          const d = await api('rows', { src, table: t, page: V.page, q: V.q, sort: V.sort, desc: V.desc });
          if (t !== V.table) return;
          $('#vGrid').innerHTML = grid(d.columns, d.rows, true);
          V.page = d.page; V.pages = d.pages;
          $('#vPg').textContent = d.page + ' / ' + d.pages;
          $('#vInfo').textContent = num(d.total) + (d.total === 1 ? ' linha' : ' linhas') + (V.q ? ' com “' + V.q + '”' : '') + ' · 50 por página';
          qs('[data-v=prev]', el).disabled = d.page <= 1; qs('[data-v=next]', el).disabled = d.page >= d.pages;
        } catch (e) { $('#vGrid').innerHTML = '<div class="fm-err">' + E(e.message) + '</div>'; }
        $('#vGrid').classList.remove('busy');
      } else if (V.tab === 'struct') {
        try {
          const d = await api('columns', { src, table: t });
          $('#vCols').innerHTML = grid(['Coluna', 'Tipo', 'Nulo', 'Chave', 'Padrão', 'Extra'], d.columns.map(c => [c.name, c.type, c.null ? 'sim' : 'não', c.key || '', c.default, c.extra || '']), false);
          $('#vInfo').textContent = d.columns.length + ' colunas';
        } catch (e) { $('#vCols').innerHTML = '<div class="fm-err">' + E(e.message) + '</div>'; }
      }
    }
    async function runSql() {
      const sql = $('#vSql').value.trim();
      if (!sql) return;
      const isWrite = /^\s*(insert|update|delete|replace)\b/i.test(sql.replace(/^(\s*(--[^\n]*\n|\/\*[\s\S]*?\*\/))*/, ''));
      if (isWrite && V.write && !(await confirmDlg('Executar alteração?', 'Este comando vai <b>alterar dados</b> em <b>' + E(label) + '</b> e não pode ser desfeito pelo painel:<pre class="nf-pre">' + E(sql.slice(0, 600)) + '</pre>', 'Executar', true))) return;
      const b = qs('[data-v=run]', el); b.disabled = true;
      $('#vRes').textContent = 'Executando…'; $('#vRes').className = 'dbv-res st-dim';
      try {
        const d = await api('query', { src, sql, write: V.write });
        if (d.affected != null && !d.columns.length) {
          $('#vRes').innerHTML = svg('check') + ' ' + num(d.affected) + (d.affected === 1 ? ' linha alterada' : ' linhas alteradas') + ' em ' + d.ms + ' ms';
          $('#vRes').className = 'dbv-res ok'; $('#vOut').innerHTML = ''; qs('[data-v=expsql]', el).disabled = true;
          const tb = await api('tables', { src }).catch(() => null); if (tb) { V.tables = tb.tables; renderTables(); }
        } else {
          $('#vRes').textContent = num(d.rows.length) + (d.rows.length === 1 ? ' linha' : ' linhas') + (d.more ? ' (mostrando as primeiras; exporte para ver mais)' : '') + ' em ' + d.ms + ' ms';
          $('#vRes').className = 'dbv-res';
          $('#vOut').innerHTML = grid(d.columns, d.rows, false);
          V.lastSql = sql; qs('[data-v=expsql]', el).disabled = false;
        }
      } catch (e) {
        $('#vRes').innerHTML = '<span class="e">' + E(e.message) + '</span>' + (e.data && e.data.needs_write ? ' <button type="button" class="lnk" data-v="write">Permitir alterações</button>' : '');
        $('#vRes').className = 'dbv-res err';
      }
      b.disabled = false;
    }
    async function exportCsv(body, name) {
      try {
        toast('Preparando o CSV…');
        const r = await api('export', Object.assign({ src }, body), true);
        if (!(r instanceof Response)) throw new Error('Falha ao exportar');
        const blob = await r.blob(), a = document.createElement('a');
        const cd = r.headers.get('Content-Disposition') || '', m = /filename="([^"]+)"/.exec(cd);
        a.href = URL.createObjectURL(blob); a.download = m ? m[1] : (name || 'resultado') + '.csv'; document.body.appendChild(a); a.click(); a.remove();
        setTimeout(() => URL.revokeObjectURL(a.href), 4000);
        if (r.headers.get('X-LKM-More') === '1') toast('CSV com as primeiras 20.000 linhas', 'er');
      } catch (e) { fail(e); }
    }
    function setWrite(on) {
      V.write = on; const w = $('#vW');
      w.classList.toggle('on', on); w.classList.toggle('danger', on);
      $('#pSql').classList.toggle('writing', on);
      if (on) { $('#vRes').innerHTML = svg('warn') + ' Alterações liberadas: INSERT, UPDATE e DELETE pedem confirmação antes de rodar.'; $('#vRes').className = 'dbv-res warn'; }
      else { $('#vRes').textContent = 'Somente leitura.'; $('#vRes').className = 'dbv-res st-dim'; }
    }

    el.addEventListener('click', async e => {
      const t = e.target.closest('[data-tb]'); if (t) return pick(t.dataset.tb);
      const tab = e.target.closest('#vTabs button'); if (tab) return setTab(tab.dataset.t);
      const th = e.target.closest('th[data-sort]');
      if (th) { const c = th.dataset.sort; if (V.sort === c) V.desc = !V.desc; else { V.sort = c; V.desc = false; } V.page = 1; return refresh(); }
      const b = e.target.closest('[data-v]'); if (!b || b.disabled) return;
      const a = b.dataset.v;
      if (a === 'close') close();
      else if (a === 'prev' && V.page > 1) { V.page--; refresh(); }
      else if (a === 'next' && V.page < (V.pages || 1)) { V.page++; refresh(); }
      else if (a === 'run') runSql();
      else if (a === 'write') {
        if (V.write) return setWrite(false);
        if (await confirmDlg('Permitir alterações?', 'Libera <b>INSERT</b>, <b>UPDATE</b> e <b>DELETE</b> na consulta SQL deste banco. Cada comando ainda pede confirmação.', 'Permitir', true)) setWrite(true);
      } else if (a === 'exptable' && V.table) exportCsv({ table: V.table }, V.table);
      else if (a === 'expsql' && V.lastSql) exportCsv({ sql: V.lastSql }, 'consulta');
    });
    $('#vTQ').addEventListener('input', renderTables);
    $('#vSel').addEventListener('change', e => pick(e.target.value));
    $('#vQ').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); V.q = e.target.value.trim(); V.page = 1; refresh(); } });
    $('#vSql').addEventListener('keydown', e => {
      if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); runSql(); }
      else if (e.key === 'Tab') { e.preventDefault(); const t = e.target, p = t.selectionStart; t.value = t.value.slice(0, p) + '  ' + t.value.slice(t.selectionEnd); t.selectionStart = t.selectionEnd = p + 2; }
    });
    loadTables();
  }

  /* ------------------------------------------------------------ inicialização */
  window.DB = {
    show() {
      if (!qs('#bancos')) return;
      if (!inited) { inited = true; build(); load(); }
      else load(true);
    }
  };
  if (location.hash === '#bancos') window.DB.show();
})();
