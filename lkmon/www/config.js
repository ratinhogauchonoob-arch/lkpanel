/* LK Monitor - aba Config: alertas, canais (Telegram / WhatsApp), histórico de alertas e backup automático */
(function () {
  'use strict';
  const U = window.UI;
  if (!U) return;
  const { E, qs, qsa, toast, fail, confirmDlg, svg } = U;
  const C0 = typeof CFG !== 'undefined' ? CFG : {};
  const TOKEN = C0.token || '';
  const ROOT = C0.user === 'root';
  let root, inited = false, D = null, BK = null, waMode = 'webhook', waSeq = 0;

  async function req(url, body) {
    let r;
    try {
      r = await fetch(url, body === undefined ? { credentials: 'same-origin', cache: 'no-store' }
        : { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-LKM-Token': TOKEN }, body: JSON.stringify(body) });
    } catch (e) { throw new Error('Sem conexão com o painel'); }
    if (r.status === 401) { location.reload(); throw new Error('Sessão expirada'); }
    let d; try { d = await r.json(); } catch (e) { throw new Error('Resposta inválida do servidor (' + r.status + ')'); }
    if (!d.ok) { const er = new Error(d.error || 'Erro desconhecido'); er.data = d; throw er; }
    return d;
  }
  const p2 = n => String(n).padStart(2, '0');
  const fdt = t => { const d = new Date(t * 1000); return p2(d.getDate()) + '/' + p2(d.getMonth() + 1) + ' ' + p2(d.getHours()) + ':' + p2(d.getMinutes()); };
  const sw = (id, on) => '<button type="button" class="sw' + (on ? ' on' : '') + '" id="' + id + '" data-sw><i></i></button>';
  const num = (id, v, min, max, w) => '<input type="number" class="fm-in sm cf-num" id="' + id + '" value="' + E(v) + '" min="' + min + '" max="' + max + '"' + (w ? ' style="width:' + w + 'px"' : '') + '>';

  function build() {
    root = qs('#config');
    root.innerHTML =
      '<section class="cf-grid">' +
      '<div class="card cf-card"><div class="ct"><h2>' + svg('globe') + 'Idioma do painel</h2><span>o painel inteiro muda na hora</span></div><div class="lk-langrow"><div class="lk-lang2" id="cfLang"></div><small>Algumas mensagens longas de ajuda ainda aparecem em português.</small></div></div>' +
      '<div class="card cf-card"><div class="ct"><h2>' + svg('bell') + 'Alertas</h2><span id="cfAct"></span></div><div id="cfRules"></div></div>' +
      '<div class="card cf-card"><div class="ct"><h2>' + svg('send') + 'Canais de aviso</h2></div><div id="cfCh"></div></div>' +
      '</section>' +
      '<div class="cf-save"><span id="cfMsg" class="st-dim"></span><button type="button" class="abtn pri" id="cfSave">' + svg('save') + 'Salvar alertas e canais</button></div>' +
      '<section class="cf-grid">' +
      '<div class="card cf-card"><div class="ct"><h2>' + svg('archive') + 'Backup automático</h2><span id="bkNext"></span></div><div id="cfBk"></div></div>' +
      '<div class="card cf-card"><div class="ct"><h2>' + svg('log') + 'Histórico de alertas</h2><button type="button" class="lnk" id="cfClr">limpar</button></div><div id="cfHist" class="cf-hist"></div></div>' +
      '</section>';
    if (window.LKI18N) window.LKI18N.picker(qs('#cfLang'));
    qs('#cfSave').onclick = save;
    qs('#cfClr').onclick = clearHist;
    root.addEventListener('click', e => { const b = e.target.closest('[data-sw]'); if (b && !b.disabled) { b.classList.toggle('on'); dirty(); } });
    root.addEventListener('input', e => { if (e.target.closest('#cfRules,#cfCh')) dirty(); });
  }
  const dirty = () => { qs('#cfMsg').textContent = 'alterações não salvas'; qs('#cfMsg').className = 'cf-dirty'; };

  async function load() {
    try { D = await req('?cfg=get'); } catch (e) { qs('#cfRules').innerHTML = '<div class="fm-err">' + E(e.message) + '</div>'; return; }
    renderRules(); renderCh(); renderHist();
    qs('#cfMsg').textContent = ''; qs('#cfMsg').className = 'st-dim';
    loadBk();
  }

  function renderRules() {
    const r = D.settings.rules, act = D.active || [];
    qs('#cfAct').innerHTML = act.length ? '<span class="jst error">' + act.length + ' alerta' + (act.length > 1 ? 's' : '') + ' ativo' + (act.length > 1 ? 's' : '') + '</span>' : '<span class="jst ok">tudo normal</span>';
    const row = (k, html, hint) => '<div class="cf-rule">' + sw('r_' + k, r[k].on) + '<div><div class="cf-rt">' + html + '</div>' + (hint ? '<small>' + hint + '</small>' : '') + '</div></div>';
    qs('#cfRules').innerHTML =
      row('temp', 'Temperatura da CPU acima de ' + num('temp_l', r.temp.limit, 30, 150) + ' °C por ' + num('temp_m', r.temp.minutes, 1, 120) + ' min') +
      row('disk', 'Disco acima de ' + num('disk_l', r.disk.limit, 1, 100) + ' % de uso', 'vale para cada disco montado') +
      row('ram', 'Memória RAM acima de ' + num('ram_l', r.ram.limit, 1, 100) + ' % por ' + num('ram_m', r.ram.minutes, 1, 120) + ' min') +
      row('service', 'Serviço parado', D.services.length ? 'verifica: ' + E(D.services.join(', ')) : 'nenhum dos serviços conhecidos está instalado') +
      row('site', 'Site fora do ar', D.sites.length ? 'testa ' + D.sites.length + ' site' + (D.sites.length > 1 ? 's' : '') + ' ligado' + (D.sites.length > 1 ? 's' : '') + ' em 127.0.0.1 (sem resposta ou erro 5xx, 2 vezes seguidas)' : 'nenhum site ligado no momento') +
      row('ssh', 'Mais de ' + num('ssh_l', r.ssh.limit, 1, 100000, 76) + ' falhas de login no SSH em 10 min') +
      '<div class="cf-rep">Repetir o mesmo alerta a cada ' + num('repeat', D.settings.repeat, 5, 1440, 70) + ' min enquanto continuar. Quando volta ao normal, também avisa.</div>';
  }

  function renderCh() {
    const t = D.settings.telegram, w = D.settings.whatsapp;
    qs('#cfCh').innerHTML =
      '<div class="cf-ch"><div class="cf-chh">' + sw('t_on', t.on) + '<b>Telegram</b><button type="button" class="abtn sm" data-test="telegram">' + svg('send') + 'Enviar teste</button></div>' +
      '<div class="cf-f"><label>Token do bot</label><input class="fm-in mono" id="t_token" value="' + E(t.token) + '" placeholder="123456789:AA..." autocomplete="off" spellcheck="false"></div>' +
      '<div class="cf-f"><label>Chat id</label><input class="fm-in mono" id="t_chat" value="' + E(t.chat) + '" placeholder="ex.: 123456789 ou -100..." autocomplete="off"></div>' +
      '<small class="cf-h">Crie o bot com o <b>@BotFather</b>, mande uma mensagem para ele e pegue o seu chat id com o <b>@userinfobot</b>.' + (t.token_set ? ' O token salvo aparece mascarado; deixe como está para manter.' : '') + '</small></div>' +
      '<div class="cf-ch"><div class="cf-chh">' + sw('w_on', w.on) + '<b id="wTitle"></b><button type="button" class="abtn sm" data-test="whatsapp">' + svg('send') + 'Enviar teste</button></div>' +
      (D.gowa ? '<div id="wGowa"><div class="wa-st" id="waSt"><i></i><span>Verificando o número…</span></div>' +
        '<div class="cf-f"><label>Números de destino</label><input class="fm-in mono" id="w_numbers" value="' + E(w.numbers) + '" placeholder="5511999998888, 5521988887777" autocomplete="off" spellcheck="false" inputmode="tel"></div>' +
        '<small class="cf-h">Só dígitos, com código do país e DDD (55 para o Brasil). Para avisar mais de uma pessoa, separe por vírgula. As mensagens saem pelo número conectado neste servidor.</small>' +
        '<button type="button" class="lnk cf-alt" id="waHook">Usar outro gateway</button></div>' : '') +
      '<div id="wHook"><div class="cf-row"><div class="cf-f" style="flex:0 0 110px"><label>Método</label><select class="fm-in" id="w_method">' + ['POST', 'GET', 'PUT'].map(m => '<option' + (w.method === m ? ' selected' : '') + '>' + m + '</option>').join('') + '</select></div>' +
      '<div class="cf-f" style="flex:1"><label>URL do gateway</label><input class="fm-in mono" id="w_url" value="' + E(w.url) + '" placeholder="https://seu-gateway/api/send-message" autocomplete="off" spellcheck="false"></div></div>' +
      '<div class="cf-f"><label>Cabeçalhos <small>(um por linha)</small></label><textarea class="fm-in mono cf-ta" id="w_headers" rows="2" placeholder="Authorization: Bearer seu-token" spellcheck="false">' + E(w.headers) + '</textarea></div>' +
      '<div class="cf-f"><label>Corpo (JSON) <small>variáveis {phone} e {message}</small></label><textarea class="fm-in mono cf-ta" id="w_body" rows="3" spellcheck="false">' + E(w.body) + '</textarea></div>' +
      '<div class="cf-f"><label>Número de destino</label><input class="fm-in mono" id="w_phone" value="' + E(w.phone) + '" placeholder="5511999998888" autocomplete="off"></div>' +
      '<small class="cf-h">Funciona com Wablas, GOWA ou qualquer gateway que receba uma requisição HTTP. Valores dos cabeçalhos salvos aparecem mascarados; deixe a linha como está para manter.</small>' +
      (D.gowa ? '<button type="button" class="lnk cf-alt" id="waLocal">Usar o WhatsApp deste servidor</button>' : '') + '</div></div>';
    qsa('[data-test]', qs('#cfCh')).forEach(b => b.onclick = () => test(b));
    waMode = D.gowa && w.mode_eff === 'gowa' ? 'gowa' : 'webhook';
    if (D.gowa) {
      qs('#waHook').onclick = () => { setWa('webhook'); dirty(); };
      qs('#waLocal').onclick = () => { setWa('gowa'); dirty(); };
    }
    setWa(waMode);
  }

  /** Alterna o canal WhatsApp entre o serviço deste servidor e um gateway próprio (webhook). */
  function setWa(mode) {
    waMode = D.gowa ? mode : 'webhook';
    qs('#wTitle').textContent = waMode === 'gowa' ? 'WhatsApp deste servidor' : 'WhatsApp (gateway próprio)';
    if (qs('#wGowa')) qs('#wGowa').hidden = waMode !== 'gowa';
    qs('#wHook').hidden = waMode === 'gowa';
    if (waMode === 'gowa') waStatus();
  }

  async function waStatus() {
    const box = qs('#waSt'); if (!box) return;
    const seq = ++waSeq;
    box.className = 'wa-st'; box.innerHTML = '<i></i><span>Verificando o número…</span>';
    let d;
    let g = D.gowa || {};
    try { const r = await req('?cfg=wa_status'); d = r.st; g = r.gowa || g; } catch (e) { d = { ok: false, logged: null, error: e.message }; }
    if (seq !== waSeq || !qs('#waSt')) return;
    const host = location.hostname.includes(':') ? '[' + location.hostname + ']' : location.hostname;
    const page = g.page_port ? '<a href="http://' + E(host) + ':' + E(g.page_port) + '/" target="_blank" rel="noopener">Abrir página do WhatsApp</a>' : '';
    const st = d.logged === true ? ['ok', 'Conectado'] : d.ok ? ['er', 'Número desconectado'] : ['er', 'Serviço do WhatsApp parado'];
    box.className = 'wa-st ' + st[0];
    box.innerHTML = '<i></i><b>' + st[1] + '</b>' + page + '<button type="button" class="lnk" id="waRe" title="Verificar de novo">' + svg('refresh') + '</button>' +
      (d.logged !== true ? '<small>' + E(d.error || 'Abra a página do WhatsApp e leia o QR code com o celular.') + '</small>' : '');
    qs('#waRe').onclick = waStatus;
  }

  function collect() {
    const on = id => qs('#' + id).classList.contains('on'), v = id => qs('#' + id).value;
    return {
      repeat: +v('repeat'),
      rules: {
        temp: { on: on('r_temp'), limit: +v('temp_l'), minutes: +v('temp_m') }, disk: { on: on('r_disk'), limit: +v('disk_l') },
        ram: { on: on('r_ram'), limit: +v('ram_l'), minutes: +v('ram_m') }, service: { on: on('r_service') }, site: { on: on('r_site') }, ssh: { on: on('r_ssh'), limit: +v('ssh_l') }
      },
      telegram: { on: on('t_on'), token: v('t_token'), chat: v('t_chat') },
      whatsapp: Object.assign({ on: on('w_on'), mode: waMode, method: v('w_method'), url: v('w_url'), headers: v('w_headers'), body: v('w_body'), phone: v('w_phone') },
        qs('#w_numbers') ? { numbers: v('w_numbers') } : {})
    };
  }

  async function save() {
    const b = qs('#cfSave'); b.disabled = true;
    try {
      const d = await req('?cfg=save', { settings: collect() });
      D.settings = d.settings; renderRules(); renderCh();
      qs('#cfMsg').textContent = 'salvo'; qs('#cfMsg').className = 'cf-ok';
      toast('Alertas e canais salvos');
    } catch (e) { fail(e); }
    b.disabled = false;
  }

  async function test(b) {
    const ch = b.dataset.test, lbl = b.innerHTML;
    b.disabled = true; b.textContent = 'Enviando…';
    try { await req('?cfg=test', { channel: ch, settings: collect() }); toast('Mensagem de teste enviada pelo ' + (ch === 'telegram' ? 'Telegram' : 'WhatsApp')); }
    catch (e) { fail(e); }
    if (ch === 'whatsapp' && waMode === 'gowa') waStatus();
    b.disabled = false; b.innerHTML = lbl;
  }

  function renderHist() {
    const h = D.history || [];
    const ico = { alert: 'warn', repeat: 'warn', ok: 'check' };
    qs('#cfHist').innerHTML = h.length ? h.map(x => '<div class="cf-hi ' + x.kind + '">' + svg(ico[x.kind] || 'info') + '<div><div>' + E(x.msg) + '</div><small>' + fdt(x.t) + ' · ' +
      (x.sent && Object.keys(x.sent).length ? Object.entries(x.sent).map(([c, r]) => '<span class="' + (r === true ? 'ok' : 'er') + '" title="' + E(r === true ? 'enviado' : r) + '">' + (c === 'telegram' ? 'Telegram' : 'WhatsApp') + (r === true ? ' ✓' : ' ✗') + '</span>').join(' ') : 'nenhum canal ativo') +
      '</small></div></div>').join('') : '<div class="st-empty">' + svg('bell') + '<b>Nenhum alerta até agora</b><span>As regras são verificadas a cada minuto pelo coletor.</span></div>';
  }

  async function clearHist() {
    if (!(await confirmDlg('Limpar histórico?', 'Apaga a lista dos últimos alertas (as regras continuam valendo).', 'Limpar', true))) return;
    try { await req('?cfg=clear', {}); D.history = []; renderHist(); } catch (e) { fail(e); }
  }

  /* ------------------------------------------------------------ backup automático */
  async function loadBk() {
    const box = qs('#cfBk');
    if (!ROOT) { box.innerHTML = '<div class="st-ro" style="margin:0">' + svg('lock') + '<div>Precisa de acesso root no painel (sudo lk › 33 › 1 › escolha root).</div></div>'; return; }
    try { BK = await req('?sites=backup_cfg', {}); } catch (e) { box.innerHTML = '<div class="fm-err">' + E(e.message) + '</div>'; return; }
    const c = BK.cfg;
    qs('#bkNext').textContent = BK.next ? 'próxima: ' + fdt(BK.next) : '';
    box.innerHTML =
      '<div class="cf-rule">' + sw('b_on', c.enabled) + '<div><div class="cf-rt">Todo dia às <input type="time" class="fm-in sm" id="b_time" value="' + E(c.time) + '"> e manter os últimos ' + num('b_keep', c.keep, 1, 365, 64) + ' backups automáticos de cada site</div>' +
      '<small>Backups manuais e os feitos antes de restaurar não são apagados. Arquivos em /var/backups/lkmonitor/&lt;site&gt;/</small></div></div>' +
      '<div class="nf-l">Sites incluídos</div>' +
      (BK.sites.length ? '<div class="cf-sites">' + BK.sites.map(s => '<label class="ck"><input type="checkbox" data-bs="' + E(s) + '"' + (c.sites.includes(s) ? ' checked' : '') + '> ' + E(s) + '</label>').join('') + '</div>' : '<div class="st-dim">Nenhum site criado ainda.</div>') +
      '<div class="cf-save in"><span id="bkMsg" class="st-dim"></span><button type="button" class="abtn pri" id="bkSave">' + svg('save') + 'Salvar backup automático</button></div>';
    qs('#bkSave').onclick = async () => {
      const cfg = { enabled: qs('#b_on').classList.contains('on'), time: qs('#b_time').value, keep: +qs('#b_keep').value, sites: qsa('[data-bs]', box).filter(i => i.checked).map(i => i.dataset.bs) };
      const b = qs('#bkSave'); b.disabled = true;
      try { await req('?sites=backup_cfg_set', { cfg }); toast('Backup automático salvo'); await loadBk(); }
      catch (e) { fail(e); b.disabled = false; }
    };
  }

  window.CONF = {
    show() {
      if (!qs('#config')) return;
      if (!inited) { inited = true; build(); }
      load();
    }
  };
  if (location.hash === '#config') window.CONF.show();
})();
