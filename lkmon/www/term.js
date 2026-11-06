/* LK Monitor - terminal completo (xterm.js + serviço lkmonitor-term)
 * Cada aba é um shell de verdade no servidor (nano, htop, o menu do lk, Ctrl+C...).
 * As sessões continuam vivas por um tempo quando a página fecha e voltam como estavam.
 */
(function () {
  'use strict';
  const U = window.UI;
  if (!U) return;
  const C0 = typeof CFG !== 'undefined' ? CFG : {};
  const { E, qs, qsa, toast, svg } = U;
  const $ = id => document.getElementById(id);
  const coarse = U.coarse;
  const ls = {
    get(k, d) { try { const v = localStorage.getItem('lkm.term.' + k); return v == null ? d : JSON.parse(v); } catch (e) { return d; } },
    set(k, v) { try { localStorage.setItem('lkm.term.' + k, JSON.stringify(v)); } catch (e) { } }
  };

  // ícones que o gerenciador de arquivos ainda não tem
  Object.assign(U.IC, {
    expand: '<path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/>',
    shrink: '<path d="M4 14h6v6M20 10h-6V4M14 10l7-7M3 21l7-7"/>',
    minus: '<path d="M5 12h14"/>',
    zap: '<path d="M13 2 3 14h9l-1 8 10-12h-9z"/>'
  });

  /* ------------------------------------------------------------ xterm.js: cópia local (baixada pelo instalador) ou CDN */
  const XV = '5.5.0', FV = '0.10.0';
  const SOURCES = [
    { css: 'vendor/xterm/xterm.css?v=' + XV, js: 'vendor/xterm/xterm.js?v=' + XV, fit: 'vendor/xterm/addon-fit.js?v=' + FV },
    { css: 'https://cdn.jsdelivr.net/npm/@xterm/xterm@' + XV + '/css/xterm.css', js: 'https://cdn.jsdelivr.net/npm/@xterm/xterm@' + XV + '/lib/xterm.js', fit: 'https://cdn.jsdelivr.net/npm/@xterm/addon-fit@' + FV + '/lib/addon-fit.js' },
    { css: 'https://unpkg.com/@xterm/xterm@' + XV + '/css/xterm.css', js: 'https://unpkg.com/@xterm/xterm@' + XV + '/lib/xterm.js', fit: 'https://unpkg.com/@xterm/addon-fit@' + FV + '/lib/addon-fit.js' }
  ];
  function tag(url) {
    return new Promise((res, rej) => {
      let el;
      if (/\.css(\?|$)/.test(url)) { el = document.createElement('link'); el.rel = 'stylesheet'; el.href = url; }
      else { el = document.createElement('script'); el.src = url; el.async = false; }
      const t = setTimeout(() => { el.remove(); rej(new Error('demorou')); }, 10000);
      el.onload = () => { clearTimeout(t); res(el); };
      el.onerror = () => { clearTimeout(t); el.remove(); rej(new Error('falhou')); };
      document.head.appendChild(el);
    });
  }
  let libP = null;
  function loadLib() {
    if (window.Terminal) return Promise.resolve();
    if (libP) return libP;
    libP = (async () => {
      for (const s of SOURCES) {
        try {
          const css = await tag(s.css);
          try { await tag(s.js); await tag(s.fit).catch(() => null); } catch (e) { css.remove(); throw e; }
          if (window.Terminal) return;
        } catch (e) { /* tenta a próxima fonte */ }
      }
      libP = null;
      throw new Error('Não foi possível carregar o terminal (xterm.js). Atualize o LK Monitor com o servidor ligado à internet, ou use o modo simples.');
    })();
    return libP;
  }

  /* ------------------------------------------------------------ API do painel */
  async function api(act, body) {
    let r;
    try {
      r = await fetch('?term=' + act, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-LKM-Token': C0.token || '' }, body: JSON.stringify(body || {}) });
    } catch (e) { const er = new Error('Sem conexão com o painel'); er.net = true; throw er; }
    if (r.status === 401) { location.reload(); throw new Error('Sessão expirada'); }
    let d; try { d = await r.json(); } catch (e) { throw new Error('Resposta inválida do painel (' + r.status + ')'); }
    if (!d.ok) { const er = new Error(d.error || 'Erro'); er.down = !!d.down; er.off = !!d.off; throw er; }
    return d;
  }

  /* ------------------------------------------------------------ estado */
  const THEME = {
    background: '#05070c', foreground: '#cfd7e6', cursor: '#9cc2ff', cursorAccent: '#05070c', selectionBackground: '#5b9cff55',
    black: '#1e2940', red: '#ff7b72', green: '#7ee787', yellow: '#f5c542', blue: '#5b9cff', magenta: '#c08bff', cyan: '#22d3ee', white: '#cfd7e6',
    brightBlack: '#56627d', brightRed: '#ffa198', brightGreen: '#a5f0b0', brightYellow: '#ffd866', brightBlue: '#9cc2ff', brightMagenta: '#d7b8ff', brightCyan: '#67e8f9', brightWhite: '#ffffff'
  };
  const FONT = '"JetBrains Mono", "Cascadia Mono", ui-monospace, "SFMono-Regular", Menlo, Consolas, "DejaVu Sans Mono", "Liberation Mono", monospace';
  const QUICK = ['lk', 'htop', 'lk status', 'lk sites', 'df -h', 'free -h', 'ip -br a', 'systemctl --failed', 'journalctl -f -n 50', 'tail -f /var/log/apache2/error.log'];
  const S = { tabs: [], cur: null, built: false, mode: ls.get('mode', 'full'), fs: ls.get('fs', coarse ? 12 : 13), full: false, ctrl: false, alt: false, n: 0, error: '' };
  let root, host, visible = false;
  const canFull = () => !!(C0.user && C0.term);
  const active = () => visible && S.mode === 'full' && canFull() && !S.error;

  /* ------------------------------------------------------------ montagem */
  function build() {
    root = $('xt');
    root.innerHTML =
      '<div class="xt" id="xtBox">' +
      '<div class="xt-bar"><div class="xt-tabs" id="xtTabs"></div>' +
      '<button class="ib xt-new" data-x="new" title="Novo terminal">' + svg('plus') + '</button><span class="sp"></span>' +
      '<button class="ib" data-x="quick" title="Comandos rápidos">' + svg('zap') + '<span class="lbl">Comandos</span></button>' +
      '<button class="ib" data-x="copy" title="Copiar seleção (Ctrl+Shift+C)">' + svg('copy') + '<span class="lbl">Copiar</span></button>' +
      '<button class="ib" data-x="paste" title="Colar (Ctrl+Shift+V)">' + svg('paste') + '<span class="lbl">Colar</span></button>' +
      '<button class="ib" data-x="font-" title="Diminuir letra">' + svg('minus') + '</button>' +
      '<button class="ib" data-x="font+" title="Aumentar letra">' + svg('plus') + '</button>' +
      '<button class="ib" data-x="full" id="xtFullBtn" title="Tela cheia">' + svg('expand') + '</button>' +
      '<button class="ib" data-x="more" title="Mais opções">' + svg('more') + '</button></div>' +
      '<div class="xt-wrap"><div class="xt-host" id="xtHost"></div><div class="xt-msg" id="xtMsg" hidden></div></div>' +
      '<div class="xt-keys" id="xtKeys">' + KEYS.map(k => '<button type="button" data-k="' + E(k[0]) + '"' + (k[2] ? ' class="' + k[2] + '"' : '') + '>' + k[1] + '</button>').join('') + '</div>' +
      '<div class="xt-foot"><span class="ubadge' + (C0.user === 'root' ? ' root' : '') + '">' + E(C0.user === 'root' ? 'root · acesso total' : C0.user || '') + '</span><span id="xtSt"></span><span class="sp"></span>' +
      '<span class="xt-hint">Ctrl+C com texto selecionado copia · Ctrl+Shift+V cola · o terminal continua aberto se você sair da página</span></div>' +
      '</div>';
    host = $('xtHost');
    root.addEventListener('click', onBar);
    const keys = $('xtKeys');
    keys.addEventListener('pointerdown', e => { if (e.target.closest('button')) e.preventDefault(); }); // não fecha o teclado do celular
    keys.addEventListener('click', e => { const b = e.target.closest('button[data-k]'); if (b) key(b.dataset.k); });
    window.addEventListener('resize', fitSoon);
    if (window.visualViewport) window.visualViewport.addEventListener('resize', fitSoon);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) S.tabs.forEach(t => { if (t.state === 'off') connect(t); }); });
    window.addEventListener('online', () => S.tabs.forEach(t => { if (t.state === 'off') connect(t); }));
    S.built = true;
  }

  // teclas extras para o celular: [valor, rótulo, classe]
  const KEYS = [['esc', 'Esc'], ['tab', 'Tab'], ['ctrl', 'Ctrl', 'mod'], ['alt', 'Alt', 'mod'], ['up', '↑'], ['down', '↓'], ['left', '←'], ['right', '→'],
    ['|', '|'], ['/', '/'], ['-', '-'], ['~', '~'], ['home', 'Home'], ['end', 'End'], ['pgup', 'PgUp'], ['pgdn', 'PgDn'], ['^c', 'Ctrl+C'], ['^d', 'Ctrl+D'], ['^z', 'Ctrl+Z']];

  /* ------------------------------------------------------------ tamanho */
  let fitT = 0;
  function fitSoon() { clearTimeout(fitT); fitT = setTimeout(fit, 60); }
  function layout() {
    const box = $('xtBox'); if (!box || !visible) return;
    const vv = window.visualViewport;
    if (S.full) {                                     // tela cheia: acompanha o teclado do celular
      box.style.top = (vv ? vv.offsetTop : 0) + 'px';
      box.style.height = (vv ? vv.height : window.innerHeight) + 'px';
      return;
    }
    box.style.top = '';
    // altura que cabe na tela com a página no topo (não depende da rolagem, senão a caixa "anda" enquanto rola)
    const avail = vv ? vv.height : window.innerHeight;
    const top = box.getBoundingClientRect().top + window.scrollY;
    box.style.height = Math.max(260, Math.floor(avail - top - (innerWidth <= 600 ? 8 : 16))) + 'px';
  }
  function fit() {
    if (!visible || !S.cur) return;
    layout();
    const t = S.cur;
    if (t.fit) { try { t.fit.fit(); } catch (e) { } }
  }

  /* ------------------------------------------------------------ abas */
  function paintTabs() {
    const el = $('xtTabs'); if (!el) return;
    el.innerHTML = S.tabs.map((t, i) => {
      const st = t.state === 'on' ? 'on' : t.state === 'connecting' ? 'wait' : t.state === 'ended' ? 'end' : 'off';
      return '<div class="xt-tab' + (t === S.cur ? ' cur' : '') + '" data-i="' + i + '" title="' + E(t.title) + '"><i class="d ' + st + '"></i><span>' + E(short(t.title)) + '</span>' +
        '<button type="button" class="x" data-close="' + i + '" title="Fechar este terminal">' + svg('x') + '</button></div>';
    }).join('');
    const st = $('xtSt');
    if (st && S.cur) st.textContent = { on: 'conectado', connecting: 'conectando…', off: 'sem conexão · tentando de novo', ended: 'sessão encerrada', kicked: 'aberto em outra janela' }[S.cur.state] || '';
  }
  function short(title) {
    const m = /^[^@\s]+@[^:\s]+:\s*(.*)$/.exec(title || '');
    let s = m ? m[1] || '~' : title || 'Terminal';
    if (s.length > 28) s = '…' + s.slice(-27);
    return s;
  }
  function saveTabs() { ls.set('sids', S.tabs.filter(t => t.sid && t.state !== 'ended').map(t => t.sid)); }

  function newTab(sid, select) {
    const t = { sid: sid || '', title: 'Terminal ' + (++S.n), state: 'connecting', ws: null, retry: 0, timer: 0, queue: [], el: document.createElement('div') };
    t.el.className = 'xt-term';
    host.appendChild(t.el);
    t.term = new window.Terminal({
      fontFamily: FONT, fontSize: S.fs, lineHeight: 1.1, cursorBlink: true, scrollback: 5000, theme: THEME,
      allowProposedApi: false, macOptionIsMeta: true, rightClickSelectsWord: false, convertEol: false
    });
    const F = window.FitAddon && (window.FitAddon.FitAddon || window.FitAddon);
    if (typeof F === 'function') { t.fit = new F(); t.term.loadAddon(t.fit); }
    t.term.open(t.el);
    t.term.onData(d => input(t, d));
    if (t.term.onBinary) t.term.onBinary(d => { if (t.ws && t.ws.readyState === 1) t.ws.send(Uint8Array.from(d, c => c.charCodeAt(0) & 255)); });
    t.term.onResize(({ cols, rows }) => { if (t.ws && t.ws.readyState === 1) t.ws.send('r' + cols + ',' + rows); });
    t.term.onTitleChange(title => { if (title) { t.title = title; paintTabs(); } });
    t.term.attachCustomKeyEventHandler(ev => keyHandler(t, ev));
    t.el.addEventListener('contextmenu', ev => { if (t.term.hasSelection()) { ev.preventDefault(); copySel(); } });
    S.tabs.push(t);
    if (select !== false) pick(t);
    connect(t);
    return t;
  }

  function pick(t) {
    S.cur = t;
    S.tabs.forEach(x => x.el.classList.toggle('on', x === t));
    paintTabs();
    hideMsg();
    if (t.state === 'kicked') showMsg('Este terminal foi aberto em outra janela ou aparelho.', [['Usar aqui', () => { hideMsg(); connect(t); }]]);
    requestAnimationFrame(() => { fit(); if (!coarse || document.activeElement === document.body) t.term.focus(); });
  }

  function closeTab(t) {
    if (t.ws && t.ws.readyState === 1 && t.state === 'on') { try { t.ws.send('k'); } catch (e) { } }
    clearTimeout(t.timer);
    t.state = 'ended';
    if (t.ws) { try { t.ws.close(); } catch (e) { } }
    t.term.dispose();
    t.el.remove();
    const i = S.tabs.indexOf(t);
    S.tabs.splice(i, 1);
    saveTabs();
    if (S.cur === t) {
      if (S.tabs.length) pick(S.tabs[Math.max(0, i - 1)]);
      else { S.cur = null; newTab(); }
    } else paintTabs();
  }

  /* ------------------------------------------------------------ conexão */
  async function connect(t) {
    if (t.state === 'ended' || t.asking || (t.ws && t.ws.readyState <= 1)) return;
    clearTimeout(t.timer);
    t.state = 'connecting'; paintTabs();
    let d;
    t.asking = true;
    try {
      d = await api('ticket', { sid: t.sid, cols: t.term.cols, rows: t.term.rows });
    } catch (e) {
      t.asking = false;
      if (e.off) { fatal(e.message); return; }
      t.state = 'off'; paintTabs();
      if (t === S.cur && (e.down || t.retry > 1)) showMsg(e.message, [['Tentar de novo', () => { hideMsg(); t.retry = 0; connect(t); }]]);
      return retryLater(t);
    }
    t.asking = false;
    if (t.state === 'ended') return;
    const u = new URL('term-ws?t=' + d.ticket, location.href);
    u.protocol = location.protocol === 'https:' ? 'wss:' : 'ws:';
    let ws;
    try { ws = new WebSocket(u.href); } catch (e) { t.state = 'off'; return retryLater(t); }
    ws.binaryType = 'arraybuffer';
    t.ws = ws;
    t.reattach = !!t.sid;
    ws.onmessage = ev => {
      if (t.ws !== ws) return;
      if (typeof ev.data === 'string') { let m; try { m = JSON.parse(ev.data); } catch (e) { return; } control(t, m); }
      else t.term.write(new Uint8Array(ev.data));
    };
    ws.onclose = () => {
      if (t.ws !== ws) return;
      t.ws = null;
      if (t.state === 'ended' || t.state === 'kicked') { paintTabs(); return; }
      const was = t.state;
      t.state = 'off'; paintTabs();
      if (was === 'on') t.retry = 0;
      retryLater(t);
    };
  }
  function retryLater(t) {
    clearTimeout(t.timer);
    if (t.state === 'ended' || t.state === 'kicked') return;
    const wait = [500, 1500, 3000, 5000, 10000][Math.min(t.retry++, 4)];
    t.timer = setTimeout(() => { if (!document.hidden || t.retry < 3) connect(t); }, wait);
  }

  function control(t, m) {
    if (m.t === 'hello') {
      if (t.reattach) t.term.reset();
      t.sid = m.sid; t.state = 'on'; t.retry = 0;
      saveTabs(); paintTabs();
      if (t === S.cur) hideMsg();
      if (t.term.cols && t.ws) t.ws.send('r' + t.term.cols + ',' + t.term.rows);
      const q = t.queue.splice(0);
      q.forEach(s => send(t, s));
    } else if (m.t === 'exit') {
      t.state = 'ended'; t.sid = '';
      saveTabs(); paintTabs();
      t.term.write('\r\n\x1b[2m[sessão encerrada · tecle Enter para abrir outra]\x1b[0m\r\n');
    } else if (m.t === 'gone') {
      t.ws = null;                                    // o fechamento que vem a seguir não deve reconectar de novo
      t.sid = ''; t.reattach = false;
      t.term.reset();
      t.term.write('\x1b[2m[a sessão anterior foi encerrada no servidor · abrindo uma nova]\x1b[0m\r\n');
      t.state = 'off';
      setTimeout(() => connect(t), 50);
    } else if (m.t === 'kicked') {
      t.state = 'kicked'; paintTabs();
      if (t === S.cur) showMsg('Este terminal foi aberto em outra janela ou aparelho.', [['Usar aqui', () => { hideMsg(); t.state = 'off'; connect(t); }]]);
    } else if (m.t === 'error') {
      t.state = 'ended'; t.sid = ''; saveTabs(); paintTabs();
      toast(m.msg || 'Erro no terminal', 'er');
      t.term.write('\r\n\x1b[31m' + (m.msg || 'Erro') + '\x1b[0m\r\n');
    }
  }

  function send(t, s) {
    if (t.ws && t.ws.readyState === 1 && t.state === 'on') t.ws.send('i' + s);
    else { t.queue.push(s); if (t.state === 'off') connect(t); }
  }

  /* ------------------------------------------------------------ teclado */
  function input(t, d) {
    if (t.state === 'ended') {                       // Enter numa sessão encerrada abre outra no lugar
      if (d === '\r' || d === '\n') { t.state = 'off'; t.sid = ''; t.reattach = false; t.term.reset(); connect(t); }
      return;
    }
    if (S.ctrl && d.length === 1) {
      const c = d.toUpperCase().charCodeAt(0);
      if (c >= 64 && c <= 95) d = String.fromCharCode(c - 64);
      else if (d === '?') d = '\x7f';
      else if (d === ' ') d = '\x00';
      setMod('ctrl', false);
    }
    if (S.alt) { d = '\x1b' + d; setMod('alt', false); }
    send(t, d);
  }

  function keyHandler(t, ev) {
    if (ev.type !== 'keydown') return true;
    const k = ev.key.toLowerCase();
    if (ev.ctrlKey && ev.shiftKey && k === 'c') { copySel(); ev.preventDefault(); return false; }
    if (ev.ctrlKey && ev.shiftKey && k === 'v') return false;            // o navegador cola sozinho
    if (ev.ctrlKey && !ev.shiftKey && !ev.altKey && k === 'c' && t.term.hasSelection()) { copySel(); ev.preventDefault(); return false; }
    return true;
  }

  function setMod(m, on) {
    S[m] = on;
    const b = qs('#xtKeys [data-k="' + m + '"]'); if (b) b.classList.toggle('on', on);
  }

  function key(k) {
    const t = S.cur; if (!t) return;
    if (k === 'ctrl' || k === 'alt') { setMod(k, !S[k]); t.term.focus(); return; }
    const app = t.term.modes && t.term.modes.applicationCursorKeysMode;
    const cur = c => (app ? '\x1bO' : '\x1b[') + c;
    const map = {
      esc: '\x1b', tab: '\t', up: cur('A'), down: cur('B'), right: cur('C'), left: cur('D'),
      home: '\x1b[H', end: '\x1b[F', pgup: '\x1b[5~', pgdn: '\x1b[6~', '^c': '\x03', '^d': '\x04', '^z': '\x1a'
    };
    let d = map[k] != null ? map[k] : k;
    if (S.ctrl && k.length === 1) { input(t, k); t.term.focus(); return; }
    if (S.alt) { d = '\x1b' + d; setMod('alt', false); }
    if (S.ctrl) setMod('ctrl', false);
    if (t.state === 'ended') input(t, d); else send(t, d);
    t.term.focus();
  }

  /* ------------------------------------------------------------ ações da barra */
  function copySel() {
    const t = S.cur; if (!t) return;
    const s = t.term.getSelection();
    if (!s) return toast('Selecione um texto no terminal primeiro');
    U.copyText(s).then(() => { toast('Copiado'); t.term.clearSelection(); });
  }
  async function pasteText() {
    const t = S.cur; if (!t) return;
    if (navigator.clipboard && navigator.clipboard.readText && window.isSecureContext) {
      try { const s = await navigator.clipboard.readText(); if (s) { t.term.paste(s); t.term.focus(); } return; } catch (e) { }
    }
    const r = await U.dialog({
      title: 'Colar no terminal', icon: 'paste', wide: true,
      html: '<div>Cole o texto aqui (Ctrl+V ou segure e toque em Colar):</div><textarea class="fm-in xt-paste" spellcheck="false"></textarea>',
      buttons: [{ label: 'Cancelar', value: null }, { label: 'Colar', value: 'ok', kind: 'pri' }],
      validate(ov) { const v = qs('textarea', ov).value; return v ? { v } : false; }
    });
    if (r && r.v) { t.term.paste(r.v); t.term.focus(); }
  }
  function setFont(d) {
    S.fs = Math.max(9, Math.min(24, S.fs + d));
    ls.set('fs', S.fs);
    S.tabs.forEach(t => { t.term.options.fontSize = S.fs; });
    fitSoon();
  }
  function toggleFull(on) {
    S.full = on == null ? !S.full : on;
    $('xtBox').classList.toggle('xt-full', S.full);
    document.body.classList.toggle('xt-noscroll', S.full);
    $('xtFullBtn').innerHTML = svg(S.full ? 'shrink' : 'expand');
    $('xtFullBtn').title = S.full ? 'Sair da tela cheia' : 'Tela cheia';
    fitSoon();
    if (S.cur) S.cur.term.focus();
  }
  function onBar(e) {
    const tb = e.target.closest('.xt-tab');
    const cl = e.target.closest('[data-close]');
    if (cl) {
      const t = S.tabs[+cl.dataset.close];
      if (!t) return;
      if (t.state === 'on' || t.state === 'off') {
        U.confirmDlg('Fechar este terminal?', 'O que estiver rodando nele será encerrado.', 'Fechar', true).then(ok => { if (ok) closeTab(t); });
      } else closeTab(t);
      return;
    }
    if (tb) { const t = S.tabs[+tb.dataset.i]; if (t && t !== S.cur) pick(t); else if (t) t.term.focus(); return; }
    const b = e.target.closest('[data-x]'); if (!b) return;
    const r = b.getBoundingClientRect();
    switch (b.dataset.x) {
      case 'new': if (S.tabs.length >= 8) return toast('Máximo de 8 terminais abertos', 'er'); newTab(); break;
      case 'copy': copySel(); break;
      case 'paste': pasteText(); break;
      case 'font-': setFont(-1); break;
      case 'font+': setFont(1); break;
      case 'full': toggleFull(); break;
      case 'quick':
        U.menu(r.left, r.bottom + 4, QUICK.map(c => ({ l: c, i: 'term', fn: () => run(c) })));
        break;
      case 'more':
        U.menu(r.right - 230, r.bottom + 4, [
          { l: 'Limpar a tela', i: 'refresh', fn: () => { if (S.cur) { S.cur.term.clear(); S.cur.term.focus(); } } },
          { l: 'Reconectar', i: 'refresh', fn: () => { const t = S.cur; if (!t) return; hideMsg(); if (t.ws) { try { t.ws.close(); } catch (er) { } } t.state = 'off'; t.retry = 0; setTimeout(() => connect(t), 100); } },
          '-',
          { l: 'Modo simples (o terminal antigo)', i: 'term', fn: () => setMode('simple') },
          '-',
          { l: 'Fechar todos os terminais', i: 'trash', danger: true, fn: closeAll }
        ]);
        break;
    }
  }
  async function closeAll() {
    if (!(await U.confirmDlg('Fechar todos os terminais?', 'Todas as sessões abertas no servidor serão encerradas.', 'Fechar todos', true))) return;
    S.tabs.slice().forEach(t => { if (t.ws && t.ws.readyState === 1) { try { t.ws.send('k'); } catch (e) { } } t.state = 'ended'; });
    S.tabs.slice().forEach(t => { if (t.ws) try { t.ws.close(); } catch (e) { } t.term.dispose(); t.el.remove(); });
    S.tabs = []; S.cur = null; saveTabs();
    newTab();
  }

  /* ------------------------------------------------------------ mensagens */
  function showMsg(text, btns) {
    const m = $('xtMsg'); if (!m) return;
    m.innerHTML = '<div class="xt-mbox"><p>' + E(text) + '</p><div>' + (btns || []).map((b, i) => '<button type="button" class="abtn' + (i ? '' : ' pri') + ' sm" data-m="' + i + '">' + E(b[0]) + '</button>').join(' ') + '</div></div>';
    m.hidden = false;
    qsa('[data-m]', m).forEach(b => b.onclick = () => btns[+b.dataset.m][1]());
  }
  function hideMsg() { const m = $('xtMsg'); if (m) m.hidden = true; }
  function fatal(msg) {
    S.error = msg;
    root.innerHTML = '<div class="card xt-fail"><b>Terminal completo indisponível</b><p>' + E(msg) + '</p><button type="button" class="abtn pri" id="xtUseSimple">Usar o modo simples</button></div>';
    $('xtUseSimple').onclick = () => setMode('simple');
    S.built = false; S.tabs = []; S.cur = null;
  }

  /* ------------------------------------------------------------ modo (completo / simples) */
  function setMode(m) {
    S.mode = m; ls.set('mode', m);
    if (m === 'full') { S.error = ''; }
    show();
  }
  function applyMode() {
    const full = S.mode === 'full' && canFull();
    $('xt').hidden = !full;
    $('tSimple').hidden = full;
    const b = $('tFull');
    if (b) { b.hidden = !canFull(); b.onclick = () => setMode('full'); }
    return full;
  }

  async function start() {
    if (S.built || S.error) return;
    root = $('xt');
    root.innerHTML = '<div class="card xt-load">Abrindo o terminal…</div>';
    try { await loadLib(); } catch (e) { fatal(e.message); return; }
    build();
    let sids = ls.get('sids', []);
    if (!Array.isArray(sids)) sids = [];
    let server = [];
    try { server = (await api('list')).sessions; } catch (e) { if (e.off) { fatal(e.message); return; } }
    const alive = new Set(server.map(s => s.id));
    // as abas desta janela + as que ficaram abertas sem ninguém olhando (ex.: começou no PC e abriu no celular)
    const want = sids.filter(id => alive.has(id));
    server.forEach(s => { if (!s.attached && !want.includes(s.id)) want.push(s.id); });
    if (want.length) want.forEach((id, i) => newTab(id, i === 0));
    else newTab();
    pending.splice(0).forEach(run);
  }

  const pending = [];
  function run(cmd) {
    if (!S.built) { pending.push(cmd); return; }
    let t = S.cur || newTab();
    if (t.state === 'ended') { t.state = 'off'; t.sid = ''; t.reattach = false; t.term.reset(); connect(t); }
    send(t, cmd + '\r');
    t.term.focus();
  }

  function show() {
    visible = true;
    if (!applyMode()) { setTimeout(() => $('tIn') && $('tIn').focus(), 50); return; }
    if (!S.built) { start(); return; }
    requestAnimationFrame(() => { fit(); if (S.cur) S.cur.term.focus(); });
    S.tabs.forEach(t => { if (t.state === 'off') connect(t); });
  }
  function hide() {
    visible = false;
    if (S.full) toggleFull(false);
  }

  window.TERM = {
    show, hide,
    active: () => active(),
    run(cmd) { if (!visible) location.hash = '#terminal'; run(cmd); }
  };
  if ((location.hash || '') === '#terminal') show();
})();
