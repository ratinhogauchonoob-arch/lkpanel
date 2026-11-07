/* LK Monitor - aba Apps (App Store em tabela, estilo clássico) e a grade "Software" + números da visão geral do Painel.
   As instalações rodam como tarefa em segundo plano (job.php) e o log aparece ao vivo (window.JOBS). */
(function () {
  'use strict';
  const U = window.UI;
  if (!U) return;
  const { E, qs, toast, dialog, menu, svg } = U;
  const C0 = typeof CFG !== 'undefined' ? CFG : {};
  const TOKEN = C0.token || '';
  const S = { data: null, error: '', cat: 'all', q: '', loading: false };
  let root, inited = false, poll = null;
  const ls = { get(k, d) { try { const v = localStorage.getItem('lkm.apps.' + k); return v === null ? d : v; } catch (e) { return d; } }, set(k, v) { try { localStorage.setItem('lkm.apps.' + k, v); } catch (e) { } } };
  S.cat = ls.get('cat', 'all');

  async function post(route, act, body) {
    let r;
    try {
      r = await fetch('?' + route + '=' + act, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-LKM-Token': TOKEN }, body: JSON.stringify(body || {}) });
    } catch (e) { throw new Error('Sem conexão com o painel'); }
    if (r.status === 401) { location.reload(); throw new Error('Sessão expirada'); }
    let d; try { d = await r.json(); } catch (e) { throw new Error('Resposta inválida do servidor (' + r.status + ')'); }
    if (!d.ok) { const er = new Error(d.error || 'Erro desconhecido'); er.data = d; throw er; }
    return d;
  }
  const api = (act, body) => post('apps', act, body);
  const showErr = (title, e) => dialog({ title, icon: 'info', html: '<pre class="sy-pre">' + E(e.message || String(e)) + '</pre>', buttons: [{ label: 'Fechar', value: null }] });
  const byId = id => (S.data && S.data.apps.find(a => a.id === id)) || null;
  const url = a => a.path ? 'http://' + location.hostname + a.path : a.port ? (a.https ? 'https://' : 'http://') + location.hostname + ':' + a.port + '/' : null;
  const initials = n => n.replace(/[^A-Za-z0-9 ]/g, ' ').split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0]).join('').toUpperCase() || '?';
  const logo = (a, cls) => a.icon ? '<img class="' + cls + '" src="' + E(a.icon) + '" alt="" loading="lazy">'
    : '<span class="' + cls + ' ini" style="background:' + E(a.color) + '">' + E(initials(a.name)) + '</span>';
  const rw = () => S.data && S.data.root && C0.user === 'root';

  /* ------------------------------------------------------------ montagem */
  function build() {
    root = qs('#apps');
    root.innerHTML = '<div class="card ap"><div class="ap-tabs" id="apCats"></div>' +
      '<div class="ap-tool"><span id="apCount"></span><span class="sp"></span><input class="ap-q" id="apQ" placeholder="Buscar app" autocomplete="off" spellcheck="false" aria-label="Buscar app">' +
      '<button class="abtn sm" id="apRf" title="Atualizar">' + svg('refresh') + '</button></div><div id="apMsg"></div>' +
      '<div class="ap-tw"><table class="ap-t"><thead><tr><th>Nome</th><th>Descrição</th><th>Status</th><th class="r">Ação</th></tr></thead><tbody id="apGrid"></tbody></table></div></div>';
    qs('#apQ').oninput = e => { S.q = e.target.value.trim().toLowerCase(); render(); };
    qs('#apRf').onclick = () => load();
    qs('#apCats').onclick = e => { const b = e.target.closest('button[data-c]'); if (!b) return; S.cat = b.dataset.c; ls.set('cat', S.cat); render(); };
    qs('#apGrid').onclick = onClick;
    qs('#apMsg').onclick = onClick;
  }

  async function load(quiet) {
    if (S.loading) return;
    S.loading = true;
    if (!quiet && !S.data && root) qs('#apGrid').innerHTML = '<tr><td colspan="4" class="ap-empty">Carregando…</td></tr>';
    try { S.data = await api('list'); S.error = ''; }
    catch (e) { S.error = e.message; }
    S.loading = false;
    render();
    homeAt = Date.now(); paintHome();
    clearTimeout(poll);
    if (S.data && S.data.busy && location.hash === '#apps') poll = setTimeout(() => load(true), 4000);
  }

  function render() {
    if (!root) return;
    const d = S.data, msg = qs('#apMsg');
    msg.innerHTML = S.error ? '<div class="st-ro">' + svg('info') + '<div>' + E(S.error) + '</div></div>' : '';
    if (!d) { if (S.error) qs('#apGrid').innerHTML = ''; return; }
    if (!rw()) msg.innerHTML += '<div class="st-ro">' + svg('lock') + '<div>Só para ver. Para instalar e desinstalar, o terminal do LK Monitor precisa ser <b>root</b>: no servidor rode <b>sudo lk</b> › opção <b>33</b> › instalar / atualizar e escolha root.</div></div>';
    if (d.busy) msg.innerHTML += '<div class="ap-busy"><i class="jspin"></i><span><b>' + E(d.busy.title) + '</b> em andamento…</span><button class="abtn sm" data-a="watch" data-j="' + E(d.busy.id) + '">Ver andamento</button></div>';

    const n = d.apps.filter(a => a.installed).length;
    qs('#apCount').textContent = n + ' de ' + d.apps.length + ' instalados';
    const cats = [['all', 'Todos'], ['inst', 'Instalados']].concat(Object.entries(d.cats));
    if (!cats.some(c => c[0] === S.cat)) S.cat = 'all';
    qs('#apCats').innerHTML = cats.map(([k, l]) => '<button data-c="' + k + '" class="' + (k === S.cat ? 'on' : '') + '">' + E(l) + '</button>').join('');
    const list = d.apps.filter(a => (S.cat === 'all' || (S.cat === 'inst' ? a.installed : a.cat === S.cat)) &&
      (!S.q || (a.name + ' ' + a.desc).toLowerCase().includes(S.q)));
    qs('#apGrid').innerHTML = list.length ? list.map(row).join('') : '<tr><td colspan="4" class="ap-empty">Nenhum app encontrado.</td></tr>';
  }

  function row(a) {
    const d = S.data, ok = rw();
    const mine = d.busy && d.busy.app === a.id, other = d.busy && !mine;
    let st;
    if (mine) st = '<span class="ap-st run"><i class="jspin"></i>' + (a.job && a.job.op === 'remove' ? 'Removendo' : 'Instalando') + '</span>';
    else if (!a.installed) st = '<span class="ap-st">Não instalado</span>';
    else if (a.state === 'active') st = '<span class="ap-st on"><b></b>' + (a.page ? 'Ligado' : 'Rodando') + '</span>';
    else if (a.page && a.state === 'inactive') st = '<span class="ap-st"><b style="background:var(--dim)"></b>Desligado</span>';
    else if (a.state && a.state !== 'unknown') st = '<span class="ap-st off"><b></b>' + ({ inactive: 'Parado', exited: 'Parado', failed: 'Com erro', activating: 'Iniciando', created: 'Parado' }[a.state] || E(a.state)) + '</span>';
    else st = '<span class="ap-st ok">Instalado</span>';

    const need = a.needs ? d.apps.find(x => x.id === a.needs) : null;
    const tags = [];
    if (a.docker) tags.push('<span class="tag">Docker</span>');
    if (a.port) tags.push('<span class="tag mono">:' + a.port + '</span>');
    if (!a.installed && need && !a.needs_ok) tags.push('<span class="tag ap-need">requer ' + E(need.name) + '</span>');
    if (a.job && a.job.state === 'error' && !mine) tags.push('<button class="tag ap-fail" data-a="watch" data-j="' + E(a.job.id) + '">' + (a.job.op === 'remove' ? 'remoção' : 'instalação') + ' falhou · ver log</button>');

    const acts = [];
    const sep = '<span class="ap-sep">|</span>';
    if (mine) acts.push('<button class="lnk" data-a="watch" data-j="' + E(d.busy.id) + '">Ver andamento</button>');
    else if (!a.installed) {
      const dis = !ok || other || !a.needs_ok;
      const why = !ok ? 'Precisa de root' : other ? 'Espere a outra instalação terminar' : !a.needs_ok ? 'Instale ' + (need ? need.name : a.needs) + ' antes' : '';
      acts.push('<button class="abtn sm pri" data-a="install"' + (dis ? ' disabled title="' + E(why) + '"' : '') + '>Instalar</button>');
    } else {
      const u = url(a);
      if (u && !(a.page && a.state !== 'active')) acts.push('<a class="lnk" href="' + E(u) + '" target="_blank" rel="noopener">Abrir</a>');
      if (a.page) acts.push(a.state === 'active' ? '<button class="lnk" data-a="off"' + (ok ? '' : ' disabled') + '>Desligar</button>'
        : '<button class="lnk" data-a="start"' + (ok ? '' : ' disabled') + '>Ligar</button>');
      else if (a.svc || a.docker) acts.push(a.state === 'active' ? '<button class="lnk" data-a="restart"' + (ok ? '' : ' disabled') + '>Reiniciar</button>'
        : '<button class="lnk" data-a="start"' + (ok ? '' : ' disabled') + '>Iniciar</button>');
      acts.push('<button class="lnk" data-a="more">Mais ▾</button>');
    }
    return '<tr class="ap-c' + (a.installed ? ' inst' : '') + '" data-id="' + E(a.id) + '">' +
      '<td><div class="ap-n">' + logo(a, 'ap-logo') + '<div><b>' + E(a.name) + '</b><small>' + (a.version ? 'v' + E(a.version) : '—') + '</small></div></div></td>' +
      '<td class="ap-d"><div>' + E(a.desc) + '</div>' + (a.installed && a.note ? '<div class="ap-note">' + E(a.note) + '</div>' : '') +
      (tags.length ? '<div class="ap-tags">' + tags.join('') + '</div>' : '') + '</td>' +
      '<td>' + st + '</td><td class="r ap-acts">' + acts.join(sep) + '</td></tr>';
  }

  /* ------------------------------------------------------------ Painel: grade "Software" e números */
  let homeBusy = false, homeAt = 0;
  async function count(route, act, pick, id) {
    const el = qs('#' + id); if (!el) return;
    try { el.textContent = pick(await post(route, act)); } catch (e) { el.textContent = '–'; el.title = e.message; }
  }
  async function home(force) {
    const soft = qs('#soft');
    if (!soft || homeBusy) return;
    if (!force && S.data && Date.now() - homeAt < 5000) return paintHome();
    homeBusy = true;
    try { if (!S.data || force) S.data = await api('list'); homeAt = Date.now(); paintHome(); }
    catch (e) { soft.innerHTML = '<div class="empty">' + E(e.message) + '</div>'; }
    homeBusy = false;
  }
  function paintHome() {
    const soft = qs('#soft'), d = S.data;
    if (!soft || !d) return;
    const inst = d.apps.filter(a => a.installed);
    const ov = qs('#ovApps'); if (ov) ov.textContent = inst.length;
    soft.innerHTML = inst.length ? inst.map(a => '<a class="sw2" href="#apps" data-cat="inst" title="' + E(a.name) + (a.version ? ' v' + E(a.version) : '') + '">' +
      (a.state && a.state !== 'unknown' ? '<span class="dot' + (a.state === 'active' ? '' : ' off') + '"></span>' : '') + logo(a, '') + '<span>' + E(a.name) + '</span></a>').join('')
      : '<div class="empty">Nenhum app instalado ainda. Abra a App Store.</div>';
  }
  document.addEventListener('click', e => { const a = e.target.closest('#soft a[data-cat]'); if (a) { S.cat = 'inst'; ls.set('cat', 'inst'); } });
  function homeAll() {
    if (!qs('#soft')) return;
    home(true);
    count('sites', 'list', d => d.sites.length, 'ovSites');
    count('db', 'overview', d => d.mysql && d.mysql.ok ? d.dbs.length : '–', 'ovDbs');
  }

  /* ------------------------------------------------------------ ações */
  async function watch(id, title) {
    if (!window.JOBS) return toast('Atualize a página', 'er');
    await window.JOBS.view(id, title || 'App Store', 'download');
    load(true);
  }

  async function start(a, op) {
    const rm = op === 'remove';
    if (rm) {
      const deps = S.data.apps.filter(x => x.installed && x.needs === a.id);
      const html = '<p><b>' + E(a.name) + '</b> será removido do servidor.</p>' +
        (a.docker ? '<p class="sy-note">O container é apagado, mas os dados ficam guardados nos volumes do Docker. Se instalar de novo, volta como estava.</p>' : '') +
        (deps.length ? '<p class="sy-warn">Estes apps usam ' + E(a.name) + ' e vão parar de funcionar: <b>' + deps.map(x => E(x.name)).join(', ') + '</b>.</p>' : '') +
        (a.id === 'node' ? '<p class="sy-warn">Os apps Node do PM2 param de funcionar.</p>' : '') +
        (a.id === 'composer' ? '<p class="sy-warn">O deploy dos sites Laravel usa o Composer.</p>' : '');
      const ok = await dialog({ title: 'Desinstalar ' + a.name + '?', icon: 'trash', html, buttons: [{ label: 'Cancelar', value: null }, { label: 'Desinstalar', value: true, kind: 'danger' }] });
      if (!ok) return;
    }
    try {
      const d = await api(op, { id: a.id });
      load(true);
      await watch(d.job, (rm ? 'Desinstalar ' : 'Instalar ') + a.name);
    } catch (e) {
      if (e.data && e.data.running) return watch(e.data.running);
      showErr('Não foi possível ' + (rm ? 'desinstalar ' : 'instalar ') + a.name, e);
    }
  }

  async function svc(a, op) {
    if (op === 'stop' && !a.page) {
      const ok = await dialog({ title: 'Parar ' + a.name + '?', icon: 'power', html: '<p>' + E(a.name) + ' vai parar até você iniciar de novo (ou até o PC reiniciar).</p>', buttons: [{ label: 'Cancelar', value: null }, { label: 'Parar', value: true, kind: 'danger' }] });
      if (!ok) return;
    }
    toast((a.page ? { start: 'Ligando ', stop: 'Desligando ', restart: 'Recarregando ' } : { start: 'Iniciando ', stop: 'Parando ', restart: 'Reiniciando ' })[op] + a.name + '…');
    try { const d = await api('svc', { id: a.id, op }); toast(d.msg); load(true); }
    catch (e) { showErr('Falha em ' + a.name, e); }
  }

  function onClick(e) {
    const b = e.target.closest('[data-a]');
    if (!b || b.disabled) return;
    const c = b.closest('.ap-c'), a = c ? byId(c.dataset.id) : null;
    const act = b.dataset.a;
    if (act === 'watch') return watch(b.dataset.j, a ? a.name : null);
    if (!a) return;
    if (act === 'install') return start(a, 'install');
    if (act === 'start' || act === 'restart') return svc(a, act);
    if (act === 'off') return svc(a, 'stop');
    if (act === 'more') {
      const ok = rw(), r = b.getBoundingClientRect(), ent = [];
      if (a.svc || a.docker) {
        const pg = a.page;
        ent.push({ l: pg ? 'Ligar' : 'Iniciar', i: 'power', dis: !ok || a.state === 'active', fn: () => svc(a, 'start') });
        if (!pg) ent.push({ l: 'Reiniciar', i: 'refresh', dis: !ok, fn: () => svc(a, 'restart') });
        ent.push({ l: pg ? 'Desligar' : 'Parar', i: 'x', dis: !ok || a.state !== 'active', fn: () => svc(a, 'stop') }, '-');
      }
      const u = url(a);
      if (u) ent.push({ l: 'Copiar endereço', i: 'link', fn: () => U.copyText(u).then(() => toast('Endereço copiado')) });
      if (a.job) ent.push({ l: 'Ver último log', i: 'log', fn: () => watch(a.job.id, a.name) });
      if (ent.length && ent[ent.length - 1] !== '-') ent.push('-');
      ent.push({ l: a.protect ? 'Desinstalar (bloqueado)' : 'Desinstalar', i: 'trash', danger: true, dis: !ok || !!a.protect || !!S.data.busy, fn: () => start(a, 'remove') });
      if (a.protect) ent.push({ l: a.protect.length > 46 ? a.protect.slice(0, 44) + '…' : a.protect, i: 'lock', dis: true, fn() { } });
      menu(Math.max(6, r.right - 230), r.bottom + 4, ent);
    }
  }

  window.APPS = {
    show() {
      if (!inited) { build(); inited = true; }
      load();
    },
    home: homeAll
  };
  window.addEventListener('hashchange', () => { if (location.hash !== '#apps') clearTimeout(poll); });
  if (location.hash === '#apps') window.APPS.show();
  homeAll();
  setInterval(() => { if (!document.hidden && qs('#v-painel.on')) homeAll(); }, 300000);
})();
