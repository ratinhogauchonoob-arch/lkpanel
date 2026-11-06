/* LK Monitor - aba Internet: Cloudflare Tunnel (sites e painel na internet com HTTPS, sem abrir portas)
   e os acessos ao painel (bloqueio por senha errada). Rota ?cf= (helper lib/cf.php como root). */
(function () {
  'use strict';
  const U = window.UI;
  if (!U) return;
  const { E, qs, toast, dialog, svg } = U;
  const C0 = typeof CFG !== 'undefined' ? CFG : {};
  const TOKEN = C0.token || '';
  const S = { d: null, apps: [], err: '', busy: false };
  let root, inited = false, timer = null;

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
  const api = (a, b) => post('cf', a, b);
  const showErr = (title, e) => dialog({ title, icon: 'info', html: '<pre class="sy-pre">' + E(e.message || String(e)) + '</pre>', buttons: [{ label: 'Fechar', value: null }] });
  const rw = () => C0.user === 'root';
  const fdt = t => { const d = new Date(t * 1000), p = n => String(n).padStart(2, '0'); return p(d.getDate()) + '/' + p(d.getMonth() + 1) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes()); };
  const ok = '<span class="cf-ok">' + svg('check') + '</span>';

  /* ------------------------------------------------------------ montagem */
  function build() {
    root = qs('#internet');
    root.innerHTML =
      '<div class="card"><div class="ct"><h2>Cloudflare Tunnel</h2><span id="cfSub">sites e painel na internet com HTTPS, sem abrir portas no roteador</span></div><div id="cfSteps"></div></div>' +
      '<div class="card" id="cfPubBox" hidden><div class="ct"><h2>Endereços publicados</h2><span id="cfPubN"></span></div><div id="cfForm"></div><div class="cf-tw"><table class="cf-t"><thead><tr><th>Endereço</th><th>Aponta para</th><th class="r">Ação</th></tr></thead><tbody id="cfRoutes"></tbody></table></div></div>' +
      '<div class="card"><div class="ct"><h2>Teste rápido</h2><span>sem domínio e sem conta · endereço temporário</span></div><div id="cfQuick"></div></div>' +
      '<div class="card"><div class="ct"><h2>Senha do painel</h2><span id="pwSub"></span></div><div id="pwBox"></div></div>' +
      '<div class="card"><div class="ct"><h2>Login com 2 fatores</h2><span>código do celular além da senha</span></div><div id="tfaBox"></div></div>' +
      '<div class="card"><div class="ct"><h2>Acessos ao painel</h2><span id="cfLockInfo"></span></div><div id="cfBlocked"></div><div class="cf-tw"><table class="cf-t"><thead><tr><th>Quando</th><th>IP</th><th>Por onde</th><th>Resultado</th></tr></thead><tbody id="cfLogins"></tbody></table></div></div>';
    root.addEventListener('click', onClick);
    root.addEventListener('change', e => { if (e.target.id === 'cfDest') fillHost(); });
  }

  async function load() {
    try {
      const [d, a, t] = await Promise.all([api('status'), post('apps', 'list').catch(() => null), post('tfa', 'status').catch(() => null)]);
      S.d = d; S.err = ''; S.tfa = t;
      S.pw = await post('pw', 'status').catch(() => null);
      if (a) S.apps = a.apps.filter(x => x.installed && x.port);
    } catch (e) { S.err = e.message; }
    render();
  }

  /* ------------------------------------------------------------ desenho */
  function step(n, done, title, body, act) {
    return '<div class="cf-step' + (done ? ' done' : '') + '"><b class="cf-n">' + (done ? svg('check') : n) + '</b><div class="cf-sb"><div class="cf-st">' + title + '</div><div class="cf-sd">' + body + '</div></div><div class="cf-sa">' + (act || '') + '</div></div>';
  }

  function render() {
    if (!root) return;
    const d = S.d;
    if (!d) { qs('#cfSteps').innerHTML = '<div class="st-ro">' + svg('info') + '<div>' + E(S.err || 'Carregando…') + '</div></div>'; return; }
    const dis = rw() ? '' : ' disabled title="Precisa do terminal como root"';
    const tun = d.tunnel && d.tunnel.id, on = d.service === 'active' || d.service === 'activating';
    let h = '';
    h += step(1, d.installed, 'Programa da Cloudflare',
      d.installed ? 'cloudflared ' + E(d.version || '') + ' instalado.' : 'O cloudflared faz a ponte entre o servidor e a Cloudflare.',
      d.installed ? '' : '<button class="abtn sm pri" data-a="install"' + dis + '>Instalar</button>');
    h += step(2, d.cert, 'Conta da Cloudflare',
      d.cert ? 'Conectada' + (d.zone ? ' ao domínio <b>' + E(d.zone) + '</b>.' : '.') : 'Você precisa de um domínio seu já adicionado na Cloudflare (conta grátis). Na hora de autorizar, escolha esse domínio.',
      d.cert ? '' : '<button class="abtn sm pri" data-a="login"' + (d.installed ? dis : ' disabled') + '>Conectar conta</button>');
    h += step(3, tun && on, 'Túnel do servidor',
      tun ? 'Túnel <code>' + E(d.tunnel.name) + '</code> · ' + (on ? '<span class="cf-on">ligado</span>' : '<span class="cf-off">desligado</span>') : 'Criado automaticamente depois de conectar a conta.',
      tun ? '<button class="abtn sm" data-a="toggle" data-on="' + (on ? 0 : 1) + '"' + dis + '>' + (on ? 'Desligar' : 'Ligar') + '</button>'
        : (d.cert ? '<button class="abtn sm pri" data-a="setup"' + dis + '>Criar túnel</button>' : ''));
    if (d.via === 'cloudflare') h += '<div class="cf-tip">' + svg('info') + '<span>Você está acessando o painel <b>pelo túnel</b>. Se desligar o túnel ou remover este endereço, a página cai: entre de novo pela rede de casa.</span></div>';
    qs('#cfSteps').innerHTML = h;

    /* publicados */
    qs('#cfPubBox').hidden = !tun;
    if (tun) {
      const routes = d.routes || [];
      qs('#cfPubN').textContent = routes.length ? routes.length + (routes.length === 1 ? ' endereço' : ' endereços') : '';
      if (!qs('#cfDest')) {
        let o = '<option value="panel">Painel LK (porta ' + d.panel_port + ')</option>';
        if (d.sites.length) o += '<optgroup label="Sites">' + d.sites.map(s => '<option value="site:' + E(s.name) + '">' + E(s.domain) + (s.ssl ? ' (HTTPS)' : '') + '</option>').join('') + '</optgroup>';
        if (S.apps.length) o += '<optgroup label="Apps">' + S.apps.map(a => '<option value="app:' + E(a.id) + '">' + E(a.name) + ' (porta ' + a.port + ')</option>').join('') + '</optgroup>';
        o += '<option value="port">Outra porta…</option>';
        qs('#cfForm').innerHTML = '<div class="cf-form"><label><span>O que publicar</span><select class="fm-in sm" id="cfDest">' + o + '</select></label>' +
          '<label id="cfPortL" hidden><span>Porta</span><input class="fm-in sm" id="cfPort" type="number" min="1" max="65535" placeholder="3000"></label>' +
          '<label class="grow"><span>Endereço na internet</span><input class="fm-in sm" id="cfHost" placeholder="painel.' + E(d.zone || 'seudominio.com') + '" autocomplete="off" spellcheck="false"></label>' +
          '<button class="abtn pri" data-a="publish"' + dis + '>' + svg('upload') + 'Publicar</button></div><div id="cfWarn"></div>';
        fillHost();
      }
      qs('#cfRoutes').innerHTML = routes.length ? routes.map(r => '<tr><td><a href="https://' + E(r.host) + '" target="_blank" rel="noopener" class="cf-host">https://' + E(r.host) + '</a></td><td>' + E(r.label) + ' <small class="mono">' + E(r.service) + '</small></td>' +
        '<td class="r cf-acts"><button class="lnk" data-a="copy" data-u="https://' + E(r.host) + '">Copiar</button><span class="ap-sep">|</span><button class="lnk red" data-a="del" data-h="' + E(r.host) + '"' + dis + '>Remover</button></td></tr>').join('')
        : '<tr><td colspan="3" class="ap-empty">Nada publicado ainda. Escolha acima o que colocar na internet.</td></tr>';
    }

    /* teste rápido */
    const q = d.quick || {};
    let qh = '';
    if (q.active) {
      qh = '<div class="cf-quick on"><span class="cf-on">●</span><div class="grow">' + (q.url ? '<a href="' + E(q.url) + '" target="_blank" rel="noopener" class="cf-host">' + E(q.url) + '</a>' : 'Gerando o endereço…') +
        '<small>' + E(q.label || '') + ' · desliga sozinho em 6 h</small></div>' + (q.url ? '<button class="abtn sm" data-a="copy" data-u="' + E(q.url) + '">Copiar</button>' : '') + '<button class="abtn sm" data-a="qstop"' + dis + '>Parar</button></div>';
    } else if (d.installed) {
      qh = '<div class="cf-form"><label class="grow"><span>O que testar</span><select class="fm-in sm" id="cfQDest"><option value="panel">Painel LK</option>' +
        d.sites.map(s => '<option value="site:' + E(s.name) + '">' + E(s.domain) + '</option>').join('') + S.apps.map(a => '<option value="app:' + E(a.id) + '">' + E(a.name) + '</option>').join('') + '</select></label>' +
        '<button class="abtn" data-a="qstart"' + dis + '>Gerar endereço</button></div>';
    } else qh = '<div class="empty">Instale o programa da Cloudflare (passo 1) para usar o teste rápido.</div>';
    const qk = JSON.stringify([q, d.installed, d.sites.length, S.apps.length, rw()]);
    if (S.qk !== qk) { S.qk = qk; qs('#cfQuick').innerHTML = qh + '<div class="cf-note">Cria um endereço aleatório <code>…trycloudflare.com</code> na hora, sem conta. Bom para mostrar algo a alguém; muda toda vez que liga.</div>'; }

    /* senha */
    const pw = S.pw;
    qs('#pwSub').textContent = pw ? 'usuário ' + pw.user : '';
    qs('#pwBox').innerHTML = !pw ? '<div class="empty">Atualize o painel para trocar a senha por aqui.</div>'
      : '<div class="tfa-off"><div class="grow">' + (pw.since ? 'Trocada em ' + fdt(pw.since) + '. ' : '') + 'Troque a senha do painel e, se quiser, use a mesma no <b>root</b> do Linux (SSH). ' +
        'Ideal: uma frase de 4 palavras ou 20 letras e números.</div><button class="abtn" data-a="pw">' + svg('lock') + 'Trocar senha</button></div>';

    /* 2FA */
    const t = S.tfa;
    qs('#tfaBox').innerHTML = !t ? '<div class="empty">Atualize o LK Monitor para usar o 2FA.</div>' : t.enabled
      ? '<div class="tfa-on">' + svg('lock') + '<div class="grow"><b>Ligado</b> desde ' + fdt(t.since).slice(0, 5) + '. Depois da senha, o painel pede o código do app.<small>' +
        (t.recovery ? 'Restam ' + t.recovery + ' códigos de recuperação.' : '<span class="cf-off">Acabaram os códigos de recuperação: gere novos.</span>') + '</small></div>' +
        '<button class="abtn sm" data-a="tfa-rec">Novos códigos</button><button class="abtn sm" data-a="tfa-off">Desligar</button></div>'
      : '<div class="tfa-off"><div class="grow">Mesmo que alguém descubra a sua senha, não entra sem o código de 6 dígitos que muda a cada 30 segundos no seu celular. Funciona com <b>Google Authenticator</b>, Microsoft Authenticator, Aegis ou Authy.</div>' +
        '<button class="abtn pri" data-a="tfa-on">' + svg('lock') + 'Ativar 2FA</button></div>';

    /* acessos */
    qs('#cfLockInfo').textContent = d.lock ? 'bloqueio depois de ' + d.lock.max + ' senhas erradas, por ' + d.lock.min + ' min' : '';
    qs('#cfBlocked').innerHTML = (d.blocked || []).map(b => '<div class="cf-blk">' + svg('lock') + '<span><b class="mono">' + E(b.ip) + '</b> bloqueado até ' + fdt(b.until).slice(6) + '</span><button class="lnk" data-a="unblock" data-ip="' + E(b.ip) + '">Liberar</button></div>').join('');
    qs('#cfLogins').innerHTML = (d.logins || []).length ? d.logins.map(l => '<tr><td>' + fdt(l.t) + '</td><td class="mono">' + E(l.ip) + '</td><td>' + ({ cloudflare: 'internet (Cloudflare)', funnel: 'internet (Funnel)', tailscale: 'Tailscale' }[l.via] || 'rede de casa') + '</td><td>' +
      (l.ok ? '<span class="ap-st on"><b></b>entrou</span>' : '<span class="ap-st off"><b></b>senha errada</span>') + '</td></tr>').join('') : '<tr><td colspan="4" class="ap-empty">Nenhum acesso registrado ainda.</td></tr>';
  }

  function zoneHost(prefix) { return S.d && S.d.zone ? prefix + '.' + S.d.zone : ''; }
  function fillHost() {
    const v = (qs('#cfDest') || {}).value || '', d = S.d, h = qs('#cfHost');
    if (!h) return;
    qs('#cfPortL').hidden = v !== 'port';
    if (v === 'panel') h.value = zoneHost('painel');
    else if (v.startsWith('site:')) { const s = d.sites.find(x => 'site:' + x.name === v); h.value = s ? s.domain : ''; }
    else if (v.startsWith('app:')) h.value = zoneHost(v.slice(4));
    else h.value = '';
    qs('#cfWarn').innerHTML = v === 'panel' ? '<div class="sy-warn"><b>O painel tem terminal como root.</b> Use uma senha forte: depois de ' + (d.lock ? d.lock.max : 5) +
      ' senhas erradas o IP é bloqueado e você recebe aviso no WhatsApp/Telegram. Para proteção extra, ative o <b>Cloudflare Access</b> (código no seu e-mail antes de abrir o painel) em one.dash.cloudflare.com › Access › Applications.</div>'
      : v.startsWith('site:') ? '<div class="cf-note">Sites Laravel: para os links saírem com https, confie no proxy (TrustProxies) e use <code>APP_URL=https://…</code> no <code>.env</code>.</div>' : '';
  }
  function destTarget(v) {
    if (v === 'panel') return { type: 'panel' };
    if (v.startsWith('site:')) return { type: 'site', name: v.slice(5) };
    if (v.startsWith('app:')) { const a = S.apps.find(x => x.id === v.slice(4)); return a ? { type: 'port', port: a.port, https: !!a.https, label: a.name } : null; }
    return { type: 'port', port: +(qs('#cfPort') || {}).value || 0 };
  }

  /* ------------------------------------------------------------ senha do painel (e do root) */
  async function pwChange() {
    const canRoot = S.pw && S.pw.root;
    const res = await dialog({
      title: 'Trocar senha', icon: 'lock',
      html: '<label class="pw-l">Senha atual<input type="password" class="fm-in" id="pwCur" autocomplete="current-password"></label>' +
        '<label class="pw-l">Senha nova<input type="password" class="fm-in" id="pwN1" autocomplete="new-password"></label>' +
        '<label class="pw-l">Repita a senha nova<input type="password" class="fm-in" id="pwN2" autocomplete="new-password"></label>' +
        (canRoot ? '<label class="pw-c"><input type="checkbox" id="pwRoot" checked><span>Usar a mesma senha na conta <b>root</b> do Linux</span></label>' +
          '<label class="pw-c"><input type="checkbox" id="pwSsh"><span>Ligar também o <b>SSH do root</b> com senha</span></label>' : '') +
        '<div class="nf-err" id="pwErr"></div>',
      buttons: [{ label: 'Cancelar', value: null }, { label: 'Trocar', value: 1, kind: 'pri' }],
      async validate(ov) {
        const n1 = qs('#pwN1', ov).value, n2 = qs('#pwN2', ov).value, er = qs('#pwErr', ov);
        if (n1 !== n2) { er.textContent = 'As duas senhas não são iguais.'; return false; }
        er.textContent = 'Salvando…';
        try {
          const r = await post('pw', 'change', { cur: qs('#pwCur', ov).value, new: n1, root: !!(qs('#pwRoot', ov) || {}).checked, ssh: !!(qs('#pwSsh', ov) || {}).checked });
          S.pwRes = r; return true;
        } catch (e) { er.textContent = e.message; return false; }
      }
    });
    if (res && S.pwRes) { pwDone(S.pwRes); S.pwRes = null; load(); }
  }
  function pwDone(r) {
    const ip = (S.pw && S.pw.ips && S.pw.ips[0]) || (C0.ips && C0.ips[0]) || 'IP-DO-SERVIDOR';
    const ssh = !r.root ? ''
      : r.ssh === true ? '<p>A conta <b>root</b> do Linux agora usa a mesma senha' + (r.sshset ? ' e o <b>SSH do root está ligado</b>' : '') + '. No PC ou no celular (Termius):</p><pre class="sy-pre">ssh root@' + E(ip) + '</pre>'
      : r.ssh === false ? '<p>A senha do <b>root</b> foi trocada, mas o SSH deste servidor <b>não aceita o root com senha</b>. Para ligar: <code>sudo lk ssh</code> › opção 2.</p>'
      : '<p>A senha do <b>root</b> foi trocada. O SSH não está instalado: <code>sudo lk ssh</code> › opção 1.</p>';
    dialog({
      title: 'Senha trocada', icon: 'check',
      html: '<p>Na próxima vez, entre com o usuário <b>' + E((S.pw && S.pw.user) || 'root') + '</b> e a senha nova.</p>' + ssh +
        (S.tfa && S.tfa.enabled ? '' : '<p>Dica: ative o <b>login com 2 fatores</b> logo abaixo (aba Internet).</p>'),
      buttons: [{ label: 'Ok', value: true, kind: 'pri' }]
    });
  }

  /* ------------------------------------------------------------ 2FA */
  function loadQR() {
    if (window.qrcode) return Promise.resolve();
    return new Promise((ok, no) => {
      const s = document.createElement('script');
      s.src = 'https://cdnjs.cloudflare.com/ajax/libs/qrcode-generator/1.4.4/qrcode.min.js';
      s.onload = ok; s.onerror = no; document.head.appendChild(s);
    });
  }
  async function tfaOn() {
    let st;
    try { st = await post('tfa', 'start'); } catch (e) { return showErr('Não foi possível começar', e); }
    const res = await dialog({
      title: 'Ativar login com 2 fatores', icon: 'lock', wide: true,
      html: '<ol class="cf-ol"><li>No celular, instale o <b>Google Authenticator</b> (ou Aegis, Authy, Microsoft Authenticator).</li><li>No app, toque em <b>+</b> › <b>Ler código QR</b> e aponte para o código abaixo.</li><li>Digite o código de 6 dígitos que aparecer no app.</li></ol>' +
        '<div class="tfa-qr" id="tfaQr"><span class="cf-note">Carregando o QR…</span></div>' +
        '<div class="cf-note" style="text-align:center">Não dá para ler? No app escolha <b>Inserir chave</b> e digite:</div>' +
        '<div class="tfa-key"><code id="tfaKey">' + E(st.secret) + '</code><button type="button" class="abtn sm" id="tfaCopy">Copiar</button></div>' +
        '<input class="fm-in tfa-in" id="tfaCode" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="000000">' +
        '<div class="nf-err" id="tfaErr"></div>',
      buttons: [{ label: 'Cancelar', value: null }, { label: 'Ativar', value: 'ok', kind: 'pri' }],
      onOpen(ov) {
        qs('#tfaCopy', ov).onclick = () => U.copyText(st.secret.replace(/\s/g, '')).then(() => toast('Chave copiada'));
        loadQR().then(() => {
          const q = window.qrcode(0, 'M'); q.addData(st.uri); q.make();
          qs('#tfaQr', ov).innerHTML = q.createSvgTag(5, 2);
        }).catch(() => { qs('#tfaQr', ov).innerHTML = '<span class="cf-note">Sem internet para gerar o QR: use a chave abaixo.</span>'; });
      },
      async validate(ov) {
        const code = qs('#tfaCode', ov).value.trim();
        if (!/^\d{6}$/.test(code)) { qs('#tfaErr', ov).textContent = 'Digite os 6 números do app.'; return false; }
        try { const r = await post('tfa', 'enable', { code }); return { codes: r.recovery }; }
        catch (e) { qs('#tfaErr', ov).textContent = e.message; return false; }
      }
    });
    if (res && res.codes) { await showCodes(res.codes, true); load(); }
  }
  function showCodes(codes, first) {
    const txt = codes.join('\n');
    return dialog({
      title: first ? '2FA ligado! Guarde os códigos de recuperação' : 'Códigos de recuperação novos', icon: 'check', wide: true,
      html: '<p>Se você perder o celular, cada código abaixo entra <b>uma vez</b> no lugar do código do app. Guarde no seu <b>.txt</b> ou no Bitwarden: eles não aparecem de novo.' + (first ? '' : ' Os antigos não valem mais.') + '</p>' +
        '<div class="tfa-codes">' + codes.map(c => '<code>' + E(c) + '</code>').join('') + '</div>' +
        '<p class="cf-note">Perdeu o celular e os códigos? No servidor, como root: <code>lk 2fa off</code></p>',
      buttons: [{ label: 'Copiar todos', value: 'copy' }, { label: 'Já guardei', value: true, kind: 'pri' }],
      onOpen(ov) { qs('.df button', ov).onclick = e => { e.stopPropagation(); U.copyText(txt).then(() => toast('Códigos copiados')); }; }
    });
  }
  async function tfaAsk(act) {
    const off = act === 'disable';
    const code = await U.prompt(off ? 'Desligar o 2FA' : 'Gerar novos códigos de recuperação', 'Para confirmar, digite o código que aparece agora no app (ou um código de recuperação):', '', 'lock');
    if (!code) return;
    try {
      const r = await post('tfa', act, { code });
      if (off) toast('2FA desligado'); else await showCodes(r.recovery, false);
    } catch (e) { showErr('Não foi possível', e); }
    load();
  }

  /* ------------------------------------------------------------ ações */
  async function connect() {
    let url;
    try { const r = await api('login'); if (r.done) return setup(); url = r.url; }
    catch (e) { return showErr('Não foi possível iniciar o login', e); }
    let waiting = true;
    const res = dialog({
      title: 'Conectar a conta da Cloudflare', icon: 'link', wide: true,
      html: '<ol class="cf-ol"><li>Clique no botão abaixo: abre o site da Cloudflare.</li><li>Entre na sua conta (crie uma grátis se não tiver).</li><li>Escolha o <b>seu domínio</b> na lista e clique em <b>Authorize</b>.</li><li>Volte para esta aba: o painel continua sozinho.</li></ol>' +
        '<a class="abtn pri cf-big" href="' + E(url) + '" target="_blank" rel="noopener">' + svg('open') + 'Abrir a Cloudflare e autorizar</a>' +
        '<div class="cf-wait"><i class="jspin"></i><span id="cfWait">Esperando a autorização…</span></div>' +
        '<div class="cf-note">O link vale 15 minutos. Se a lista de domínios estiver vazia, primeiro adicione o domínio na Cloudflare (Add a site) e troque os nameservers onde você comprou.</div>',
      buttons: [{ label: 'Cancelar', value: null }],
      onOpen(ov, close) {
        const tick = async () => {
          if (!waiting) return;
          try {
            const d = await api('status');
            if (d.cert) { waiting = false; close('ok'); return; }
            if (!d.login.running) { const w = qs('#cfWait', ov); if (w) w.textContent = 'O link expirou. Feche e clique em Conectar conta de novo.'; return; }
          } catch (e) { }
          setTimeout(tick, 2500);
        };
        setTimeout(tick, 2500);
      }
    });
    const v = await res;
    waiting = false;
    if (v === 'ok') { toast('Conta conectada! Criando o túnel…'); await setup(); }
  }

  async function setup() {
    S.busy = true;
    try { const r = await api('setup'); toast(r.msg); } catch (e) { showErr('Não foi possível criar o túnel', e); }
    S.busy = false;
    setTimeout(load, 2500);
  }

  async function publish(overwrite) {
    const v = qs('#cfDest').value, host = qs('#cfHost').value.trim().toLowerCase(), target = destTarget(v);
    if (!host) return toast('Escreva o endereço, por exemplo painel.' + (S.d.zone || 'seudominio.com'), 'er');
    if (!target || (target.type === 'port' && !target.port)) return toast('Escolha a porta', 'er');
    const b = qs('[data-a=publish]'); b.disabled = true;
    try {
      const r = await api('route_add', { host, target, overwrite: !!overwrite });
      qs('#cfForm').innerHTML = '';
      await dialog({ title: 'Publicado!', icon: 'check', html: '<p>' + E(r.msg) + '</p><p><a class="cf-host" href="' + E(r.url) + '" target="_blank" rel="noopener">' + E(r.url) + '</a></p>', buttons: [{ label: 'OK', value: true, kind: 'pri' }] });
      load();
    } catch (e) {
      b.disabled = false;
      if (e.data && e.data.exists) {
        const ok2 = await dialog({ title: 'Endereço já existe na Cloudflare', icon: 'info', html: '<p>' + E(e.message) + '</p><p>Substituir faz <b>' + E(host) + '</b> apontar para este servidor. O que estiver nele hoje sai do ar.</p>',
          buttons: [{ label: 'Cancelar', value: null }, { label: 'Substituir', value: true, kind: 'danger' }] });
        if (ok2) publish(true);
      } else showErr('Não foi possível publicar', e);
    }
  }

  async function onClick(e) {
    const b = e.target.closest('[data-a]');
    if (!b || b.disabled) return;
    const a = b.dataset.a;
    if (a === 'copy') return U.copyText(b.dataset.u).then(() => toast('Endereço copiado'));
    if (a === 'install') {
      try { const r = await post('apps', 'install', { id: 'cloudflared' }); if (window.JOBS) await window.JOBS.view(r.job, 'Instalar Cloudflare Tunnel', 'download'); }
      catch (er) { if (er.data && er.data.running && window.JOBS) await window.JOBS.view(er.data.running, 'App Store', 'download'); else showErr('Não foi possível instalar', er); }
      return load();
    }
    if (a === 'login') return connect();
    if (a === 'pw') return pwChange();
    if (a === 'tfa-on') return tfaOn();
    if (a === 'tfa-off') return tfaAsk('disable');
    if (a === 'tfa-rec') return tfaAsk('recovery');
    if (a === 'setup') return setup();
    if (a === 'publish') return publish(false);
    if (a === 'toggle') {
      const on = b.dataset.on === '1';
      if (!on) {
        const k = await dialog({ title: 'Desligar o túnel?', icon: 'power', html: '<p>Todos os endereços publicados saem do ar até você ligar de novo.</p>', buttons: [{ label: 'Cancelar', value: null }, { label: 'Desligar', value: true, kind: 'danger' }] });
        if (!k) return;
      }
      try { const r = await api('toggle', { on }); toast(r.msg); } catch (er) { showErr('Falha', er); }
      return setTimeout(load, 3000);
    }
    if (a === 'del') {
      const h = b.dataset.h;
      const k = await dialog({ title: 'Tirar ' + h + ' do ar?', icon: 'trash', html: '<p>O endereço para de funcionar em alguns segundos. Os arquivos do site não são mexidos.</p>', buttons: [{ label: 'Cancelar', value: null }, { label: 'Remover', value: true, kind: 'danger' }] });
      if (!k) return;
      try { const r = await api('route_del', { host: h }); toast(r.msg); } catch (er) { showErr('Falha', er); }
      return setTimeout(load, 1500);
    }
    if (a === 'qstart') {
      const t = destTarget(qs('#cfQDest').value);
      b.disabled = true; b.textContent = 'Gerando…'; S.qk = '';
      try { await api('quick_start', { target: t }); } catch (er) { showErr('Não foi possível gerar o endereço', er); }
      return load();
    }
    if (a === 'qstop') { try { await api('quick_stop'); } catch (er) { } return load(); }
    if (a === 'unblock') { try { await api('unblock', { ip: b.dataset.ip }); toast('IP liberado'); } catch (er) { showErr('Falha', er); } return load(); }
  }

  window.CF = {
    show() {
      if (!inited) { build(); inited = true; }
      load();
      clearInterval(timer);
      timer = setInterval(() => { if (location.hash === '#internet' && !document.hidden && !qs('.fm-ov')) load(); }, 15000);
    }
  };
  window.addEventListener('hashchange', () => { if (location.hash !== '#internet') clearInterval(timer); });
  if (location.hash === '#internet') window.CF.show();
  // acabou de trocar a senha temporária do 1º acesso
  if (C0.pwdone) setTimeout(() => { S.tfa = null; pwDone(C0.pwdone); }, 500);
})();
