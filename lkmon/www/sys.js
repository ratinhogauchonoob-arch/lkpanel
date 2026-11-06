/* LK Monitor - ações do sistema no Painel: serviços (iniciar/parar/reiniciar), encerrar processo,
   modo servidor ↔ modo com tela, reiniciar e desligar o PC. Tudo passa por ?sys= (login + token) e vai para o cmd.log. */
(function () {
  'use strict';
  const U = window.UI;
  if (!U) return;
  const { E, qs, toast, dialog, menu, svg, IC } = U;
  const C0 = typeof CFG !== 'undefined' ? CFG : {};
  const TOKEN = C0.token || '';
  const ROOT = C0.user === 'root';
  const NEED_ROOT = 'Precisa do terminal do LK Monitor como root (sudo lk › 33 › instalar / atualizar e escolha root).';
  const PANEL = n => n === 'apache2' || /^php\d+\.\d+-fpm$/.test(n);
  const CRITICAL = ['systemd', 'init', 'sshd', 'dbus-daemon', 'systemd-journal', 'systemd-logind', 'apache2', 'php-fpm', 'mariadbd', 'mysqld', 'dockerd', 'containerd', 'Xorg', 'gnome-shell', 'gdm', 'gdm3'];
  let MODE = null, busy = false;

  Object.assign(IC, {
    play: '<path d="M7 4v16l13-8z"/>',
    stop: '<rect x="6" y="6" width="12" height="12" rx="1.5"/>',
    restart: '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/>',
    monitor: '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/>',
    srv: '<rect x="3" y="4" width="18" height="7" rx="2"/><rect x="3" y="13" width="18" height="7" rx="2"/><path d="M7 7.5h.01M7 16.5h.01"/>',
    skull: '<path d="M12 3a8 8 0 0 0-5 14.2V20h10v-2.8A8 8 0 0 0 12 3z"/><path d="M9 12h.01M15 12h.01M10 20v-2M14 20v-2"/>'
  });

  async function api(act, body) {
    let r;
    try {
      r = await fetch('?sys=' + act, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-LKM-Token': TOKEN }, body: JSON.stringify(body || {}) });
    } catch (e) { throw new Error('Sem conexão com o painel'); }
    if (r.status === 401) { location.reload(); throw new Error('Sessão expirada'); }
    let d; try { d = await r.json(); } catch (e) { throw new Error('Resposta inválida do servidor (' + r.status + ')'); }
    if (!d.ok) throw new Error(d.error || 'Erro desconhecido');
    return d;
  }
  const refresh = () => { if (typeof tick === 'function') setTimeout(tick, 400); };
  const showErr = (title, e) => dialog({ title, icon: 'info', html: '<pre class="sy-pre">' + E(e.message || String(e)) + '</pre>', buttons: [{ label: 'Fechar', value: null }] });

  /* ------------------------------------------------------------ serviços */
  async function service(name, op) {
    const verb = { start: 'Iniciar', stop: 'Parar', restart: 'Reiniciar' }[op];
    if (op === 'stop' || (op === 'restart' && name === 'ssh')) {
      const warn = name === 'ssh' ? '<p class="sy-warn">Quem estiver conectado por SSH pode cair.</p>'
        : name === 'ufw' ? '<p class="sy-warn">Sem o ufw o firewall fica desligado.</p>'
        : /mariadb|mysql/.test(name) ? '<p class="sy-warn">Os sites que usam banco de dados vão dar erro enquanto estiver parado.</p>' : '';
      const ok = await dialog({ title: verb + ' ' + name + '?', icon: 'power', html: '<p>O serviço <b>' + E(name) + '</b> vai ' + (op === 'stop' ? 'parar' : 'reiniciar') + '.</p>' + warn,
        buttons: [{ label: 'Cancelar', value: null }, { label: verb, value: true, kind: op === 'stop' ? 'danger' : 'pri' }] });
      if (!ok) return;
    }
    toast(verb + ' ' + name + '…');
    try { const d = await api('service', { name, op }); toast(d.msg); refresh(); if (d.delayed) setTimeout(refresh, 4000); }
    catch (e) { showErr('Não foi possível ' + verb.toLowerCase() + ' ' + name, e); }
  }

  document.addEventListener('click', e => {
    const b = e.target.closest('#svcs .svc[data-svc]');
    if (!b) return;
    e.preventDefault();
    const name = b.dataset.svc, on = b.dataset.st === 'active', r = b.getBoundingClientRect();
    const ent = [
      { l: 'Iniciar', i: 'play', dis: on || !ROOT, fn: () => service(name, 'start') },
      { l: 'Reiniciar', i: 'restart', dis: !ROOT, fn: () => service(name, 'restart') },
      { l: PANEL(name) ? 'Parar (desligaria o painel)' : 'Parar', i: 'stop', danger: true, dis: !on || !ROOT || PANEL(name), fn: () => service(name, 'stop') }
    ];
    if (!ROOT) ent.push('-', { l: 'Precisa de root', i: 'lock', dis: true, fn() { } });
    menu(r.left, r.bottom + 4, ent);
  });

  /* ------------------------------------------------------------ processos */
  document.addEventListener('click', async e => {
    const b = e.target.closest('#procs .pk');
    if (!b) return;
    if (!ROOT) return toast(NEED_ROOT, 'er');
    const pid = +b.dataset.pid, name = b.dataset.name, user = b.dataset.user;
    const crit = CRITICAL.some(c => name === c || name.startsWith(c));
    const r = await dialog({
      title: 'Encerrar ' + name + '?', icon: 'trash',
      html: '<p>Processo <b>' + E(name) + '</b> · PID <code>' + pid + '</code> · usuário ' + E(user) + '</p>' +
        (crit ? '<p class="sy-warn">Este é um processo do sistema. Encerrar pode derrubar serviços, o SSH ou o próprio painel.</p>' : '') +
        '<p class="sy-note"><b>Encerrar</b> pede para o programa fechar (SIGTERM). <b>Forçar</b> mata na hora, sem salvar nada (kill -9).</p>',
      buttons: [{ label: 'Cancelar', value: null }, { label: 'Forçar', value: 'KILL' }, { label: 'Encerrar', value: 'TERM', kind: 'danger' }]
    });
    if (!r) return;
    try { const d = await api('kill', { pid, name, sig: r }); toast(d.msg); refresh(); }
    catch (er) { showErr('Não foi possível encerrar ' + name, er); }
  });

  /* ------------------------------------------------------------ modo servidor e energia */
  function paint() {
    const box = qs('#sysAct'), lab = qs('#sysMode');
    if (!box) return;
    const m = MODE;
    if (lab) lab.textContent = !m ? '' : m.server ? (m.desktop_now ? 'modo servidor · tela ainda ligada' : 'modo servidor') : (m.desktop_now ? 'com área de trabalho' : 'normal · tela desligada agora');
    const dis = !ROOT || busy ? ' disabled' : '';
    const tip = ROOT ? '' : ' title="' + E(NEED_ROOT) + '"';
    const modeBtn = !m ? '' : m.server
      ? '<button class="abtn sm" data-a="desktop"' + dis + tip + '>' + svg('monitor') + 'Modo com tela</button>'
      : '<button class="abtn sm" data-a="server"' + dis + tip + '>' + svg('srv') + 'Modo servidor</button>';
    box.innerHTML = modeBtn +
      '<button class="abtn sm" data-a="reboot"' + dis + tip + '>' + svg('restart') + 'Reiniciar PC</button>' +
      '<button class="abtn sm sy-off" data-a="poweroff"' + dis + tip + '>' + svg('power') + 'Desligar</button>' +
      (busy ? '<span class="sy-busy">' + E(busy) + '</span>' : '');
  }

  async function loadMode() {
    try { MODE = (await api('status')).mode; } catch (e) { MODE = null; }
    paint();
  }

  async function setMode(to) {
    const srv = to === 'server';
    const html = (srv
      ? '<p>Desliga a área de trabalho (fica só o servidor), a tela apaga sozinha depois de 1 minuto, fechar a tampa não suspende e a suspensão fica bloqueada. Igual à opção 35 do <code>sudo lk</code>.</p>'
      : '<p>A área de trabalho volta a ligar, a tela, a tampa e a suspensão voltam ao padrão do sistema.</p>') +
      '<div class="sy-lbl">Quando aplicar</div><div class="seg sy-when">' +
      '<button type="button" data-w="agora" class="on">Agora</button><button type="button" data-w="reiniciar">Reiniciar o PC</button><button type="button" data-w="boot">Só no próximo boot</button></div>' +
      '<p class="sy-note" data-n="agora">' + (srv ? 'A tela do notebook vai para o modo texto. Programas abertos na área de trabalho são fechados.' : 'A tela de login aparece no notebook.') + ' O painel continua no ar.</p>' +
      '<p class="sy-note" data-n="reiniciar" hidden>O PC reinicia; o painel volta em 1 ou 2 minutos.</p>' +
      '<p class="sy-note" data-n="boot" hidden>Nada muda agora; vale quando o PC ligar de novo.</p>';
    const r = await dialog({
      title: srv ? 'Ativar modo servidor' : 'Voltar ao modo com tela', icon: 'server', html,
      buttons: [{ label: 'Cancelar', value: null }, { label: 'Aplicar', value: 'ok', kind: 'pri' }],
      onOpen(ov) {
        ov.dataset.w = 'agora';
        ov.querySelectorAll('.sy-when button').forEach(b => b.onclick = () => {
          ov.dataset.w = b.dataset.w;
          ov.querySelectorAll('.sy-when button').forEach(x => x.classList.toggle('on', x === b));
          ov.querySelectorAll('.sy-note[data-n]').forEach(n => n.hidden = n.dataset.n !== b.dataset.w);
        });
      },
      validate(ov) { return { when: ov.dataset.w }; }
    });
    if (!r) return;
    busy = 'Configurando… pode levar até 1 minuto'; paint();
    try {
      const d = await api('mode', { to, when: r.when });
      if (d.mode) MODE = d.mode;
      toast(d.msg);
      if (r.when === 'agora') setTimeout(loadMode, 6000);
    } catch (e) { showErr('Falha ao trocar o modo', e); }
    busy = false; paint();
  }

  async function power(op) {
    const off = op === 'poweroff';
    const ok = await dialog({
      title: off ? 'Desligar o PC?' : 'Reiniciar o PC?', icon: 'power',
      html: off ? '<p>O servidor <b>' + E(C0.host || '') + '</b> vai desligar. Todos os sites e o painel saem do ar.</p><p class="sy-warn">Para ligar de novo, alguém precisa apertar o botão de ligar no PC.</p>'
        : '<p>O servidor <b>' + E(C0.host || '') + '</b> vai reiniciar. Os sites e o painel ficam fora do ar por 1 ou 2 minutos.</p>',
      buttons: [{ label: 'Cancelar', value: null }, { label: off ? 'Desligar' : 'Reiniciar', value: true, kind: 'danger' }]
    });
    if (!ok) return;
    try {
      const d = await api('power', { op });
      busy = off ? 'Desligando…' : 'Reiniciando… o painel volta sozinho'; paint();
      toast(d.msg);
      if (!off) waitBack();
    } catch (e) { showErr('Não foi possível ' + (off ? 'desligar' : 'reiniciar'), e); }
  }

  /* depois de reiniciar: espera o painel cair e voltar, então recarrega */
  function waitBack() {
    let down = false, n = 0;
    const t = setInterval(async () => {
      if (++n > 120) { clearInterval(t); busy = false; paint(); return; }
      try {
        const r = await fetch('?api=1', { cache: 'no-store', credentials: 'same-origin' });
        if (down && (r.ok || r.status === 401)) { clearInterval(t); location.reload(); }
      } catch (e) { down = true; }
    }, 5000);
  }

  document.addEventListener('click', e => {
    const b = e.target.closest('#sysAct button[data-a]');
    if (!b || b.disabled) return;
    const a = b.dataset.a;
    if (a === 'server' || a === 'desktop') setMode(a); else power(a);
  });

  /* o menu do fm.js só fecha sozinho depois que a aba Arquivos abre: fecha aqui também */
  document.addEventListener('mousedown', e => { if (!e.target.closest('#fmMenu')) U.closeMenu(); }, true);
  document.addEventListener('keydown', e => { if (e.key === 'Escape') U.closeMenu(); });
  window.addEventListener('scroll', () => U.closeMenu(), true);
  window.addEventListener('blur', () => U.closeMenu());

  paint();
  loadMode();
})();
