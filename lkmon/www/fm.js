/* LK Monitor - gerenciador de arquivos */
(function () {
  'use strict';

  /* ------------------------------------------------------------ utilitários */
  const C0 = typeof CFG !== 'undefined' ? CFG : {};
  const TOKEN = C0.token || '';
  const USER = C0.user || '';
  const HOME = USER === 'root' ? '/root' : USER ? '/home/' + USER : '/';
  const coarse = matchMedia('(pointer:coarse)').matches;
  const E = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const qs = (s, r) => (r || document).querySelector(s);
  const qsa = (s, r) => Array.from((r || document).querySelectorAll(s));
  const ls = {
    get(k, d) { try { const v = localStorage.getItem('lkm.fm.' + k); return v == null ? d : JSON.parse(v); } catch (e) { return d; } },
    set(k, v) { try { localStorage.setItem('lkm.fm.' + k, JSON.stringify(v)); } catch (e) { } }
  };
  function fsize(b) {
    if (b == null) return '—';
    const u = ['B', 'KB', 'MB', 'GB', 'TB']; let i = 0; b = +b;
    while (b >= 1024 && i < u.length - 1) { b /= 1024; i++; }
    return (i ? b.toFixed(b >= 100 ? 0 : 1) : Math.round(b)) + ' ' + u[i];
  }
  function fdate(t) {
    if (!t) return '—';
    const d = new Date(t * 1000), p = n => String(n).padStart(2, '0');
    return p(d.getDate()) + '/' + p(d.getMonth() + 1) + '/' + d.getFullYear() + ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
  }
  function rwx(perm) {
    const m = parseInt(perm, 8) || 0, s = 'rwxrwxrwx';
    let o = ''; for (let i = 0; i < 9; i++) o += (m & (1 << (8 - i))) ? s[i] : '-';
    return o;
  }
  const dirname = p => { const i = p.replace(/\/+$/, '').lastIndexOf('/'); return i <= 0 ? '/' : p.slice(0, i); };
  const basename = p => p.replace(/\/+$/, '').split('/').pop() || '/';
  const pjoin = (d, n) => (d === '/' ? '' : d.replace(/\/+$/, '')) + '/' + n;
  const extOf = n => { const m = /\.([^.\/]+)$/.exec(n); return m && m.index > 0 ? m[1].toLowerCase() : ''; };
  const shq = s => "'" + String(s).replace(/'/g, "'\\''") + "'";
  function copyText(t) {
    if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(t);
    const ta = document.createElement('textarea'); ta.value = t; ta.style.position = 'fixed'; ta.style.opacity = '0';
    document.body.appendChild(ta); ta.select(); try { document.execCommand('copy'); } catch (e) { } ta.remove();
    return Promise.resolve();
  }

  /* ------------------------------------------------------------ ícones */
  const IC = {
    back: '<path d="M19 12H5M12 19l-7-7 7-7"/>',
    fwd: '<path d="M5 12h14M12 5l7 7-7 7"/>',
    up: '<path d="M12 19V5M5 12l7-7 7 7"/>',
    refresh: '<path d="M21 12a9 9 0 1 1-2.6-6.4L21 8"/><path d="M21 3v5h-5"/>',
    folderPlus: '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M12 10.5v6M9 13.5h6"/>',
    filePlus: '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M12 11v6M9 14h6"/>',
    upload: '<path d="M12 15V3M7 8l5-5 5 5"/><path d="M4 15v4a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-4"/>',
    download: '<path d="M12 3v12M7 10l5 5 5-5"/><path d="M4 15v4a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-4"/>',
    copy: '<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v1"/>',
    cut: '<circle cx="6" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><path d="M20 4 8.1 15.9M14.5 14.5 20 20M8.1 8.1 12 12"/>',
    paste: '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/>',
    rename: '<path d="M17 3a2.8 2.8 0 0 1 4 4L7.5 20.5 2 22l1.5-5.5z"/>',
    lock: '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
    zip: '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M10 3v2h2v2h-2v2h2v2h-2M10 15h2v3h-2z"/>',
    unzip: '<path d="M21 8v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8"/><path d="M2 3h20v5H2zM12 11v7M9 15l3 3 3-3"/>',
    trash: '<path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6M10 11v6M14 11v6"/>',
    search: '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>',
    grid: '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
    list: '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
    eye: '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
    eyeOff: '<path d="M9.9 4.2A10 10 0 0 1 12 4c6.5 0 10 8 10 8a17 17 0 0 1-2.2 3.2M6.6 6.6C3.6 8.5 2 12 2 12s3.5 8 10 8a9.7 9.7 0 0 0 5.4-1.6M2 2l20 20M9.9 9.9a3 3 0 0 0 4.2 4.2"/>',
    info: '<circle cx="12" cy="12" r="9"/><path d="M12 16v-4M12 8h.01"/>',
    open: '<path d="M15 3h6v6M10 14 21 3M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
    edit: '<path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
    term: '<path d="M4 17l6-5-6-5M12 19h8"/>',
    link: '<path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/>',
    selAll: '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="m8 12 3 3 5-6"/>',
    x: '<path d="M18 6 6 18M6 6l12 12"/>',
    save: '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><path d="M17 21v-8H7v8M7 3v5h8"/>',
    wrap: '<path d="M3 6h18M3 12h15a3 3 0 1 1 0 6h-4M3 18h7M16 16l-2 2 2 2"/>',
    home: '<path d="M3 11 12 3l9 8M5 10v10h14V10"/>',
    globe: '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
    server: '<rect x="3" y="3" width="18" height="7" rx="2"/><rect x="3" y="14" width="18" height="7" rx="2"/><path d="M7 6.5h.01M7 17.5h.01"/>',
    cog: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
    log: '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h6M9 9h1"/>',
    tmp: '<circle cx="12" cy="13" r="8"/><path d="M12 9v4l2 2M9 2h6"/>',
    users: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8"/>',
    folderUp: '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M12 16v-5M9.5 13.5 12 11l2.5 2.5"/>',
    chevL: '<path d="m15 18-6-6 6-6"/>',
    chevR: '<path d="m9 18 6-6-6-6"/>',
    sortU: '<path d="m7 14 5-5 5 5"/>',
    sortD: '<path d="m7 10 5 5 5-5"/>',
    phone: '<rect x="6" y="2" width="12" height="20" rx="2.5"/><path d="M11 18h2"/>',
    power: '<path d="M12 3v8"/><path d="M6.3 7.5a8 8 0 1 0 11.4 0"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
    more: '<circle cx="5" cy="12" r="1.3"/><circle cx="12" cy="12" r="1.3"/><circle cx="19" cy="12" r="1.3"/>',
    folder: '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
    db: '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/>',
    check: '<path d="m5 12 5 5 9-10"/>',
    warn: '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/>',
    pulse: '<path d="M3 12h4l3-8 4 16 3-8h4"/>',
    rocket: '<path d="M4.5 16.5c-1.5 1.3-2 5-2 5s3.7-.5 5-2c.7-.8.7-2.1-.1-2.9a2.2 2.2 0 0 0-2.9-.1z"/><path d="m12 15-3-3a22 22 0 0 1 2-3.9A12.9 12.9 0 0 1 22 2c0 2.7-.8 7.5-6 11a22.4 22.4 0 0 1-4 2z"/><path d="M9 12H4s.6-3 2-4c1.6-1.1 5 0 5 0M12 15v5s3-.6 4-2c1.1-1.6 0-5 0-5"/>',
    play: '<path d="M6 4l14 8-14 8z"/>',
    table: '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 10h18M3 15h18M9 4v16"/>',
    key: '<circle cx="7.5" cy="15.5" r="4.5"/><path d="m10.7 12.3 9.3-9.3M17 6l3 3M14 9l2 2"/>',
    archive: '<rect x="3" y="4" width="18" height="5" rx="1"/><path d="M5 9v10a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V9M10 13h4"/>',
    bell: '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9M10.3 21a1.9 1.9 0 0 0 3.4 0"/>',
    send: '<path d="m22 2-7 20-4-9-9-4z"/><path d="M22 2 11 13"/>'
  };
  const svg = (k, cls) => '<svg class="i ' + (cls || '') + '" viewBox="0 0 24 24">' + (IC[k] || '') + '</svg>';

  const T = {
    code: ['php', 'phtml', 'js', 'mjs', 'cjs', 'ts', 'tsx', 'jsx', 'vue', 'py', 'rb', 'go', 'java', 'c', 'h', 'cpp', 'hpp', 'cs', 'rs', 'lua', 'kt', 'swift', 'pl', 'dart', 'scala', 'r'],
    web: ['html', 'htm', 'twig', 'tpl', 'blade', 'xml', 'xhtml'],
    style: ['css', 'scss', 'sass', 'less'],
    data: ['json', 'yml', 'yaml', 'toml', 'csv', 'tsv', 'sql', 'db', 'sqlite', 'ndjson'],
    conf: ['conf', 'cnf', 'cfg', 'ini', 'env', 'htaccess', 'properties', 'service', 'timer', 'socket', 'lock', 'gitignore', 'editorconfig', 'npmrc'],
    shell: ['sh', 'bash', 'zsh', 'fish', 'bat', 'cmd', 'ps1'],
    text: ['txt', 'md', 'markdown', 'log', 'rst', 'srt', 'vtt', 'nfo', 'readme'],
    img: ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'ico', 'bmp', 'avif', 'tif', 'tiff', 'psd'],
    zip: ['zip', 'tar', 'gz', 'tgz', 'bz2', 'tbz2', 'xz', 'txz', '7z', 'rar', 'zst'],
    pdf: ['pdf'],
    doc: ['doc', 'docx', 'odt', 'rtf', 'xls', 'xlsx', 'ods', 'ppt', 'pptx', 'odp'],
    audio: ['mp3', 'wav', 'ogg', 'flac', 'm4a', 'aac', 'opus'],
    video: ['mp4', 'webm', 'mkv', 'avi', 'mov', 'wmv', 'm4v'],
    font: ['ttf', 'otf', 'woff', 'woff2', 'eot'],
    key: ['pem', 'key', 'crt', 'cer', 'csr', 'pub', 'p12', 'pfx', 'gpg'],
    bin: ['exe', 'deb', 'rpm', 'apk', 'iso', 'img', 'bin', 'so', 'dll', 'appimage', 'jar', 'phar']
  };
  const EXTCOL = { php: '#8892f6', js: '#e8c547', mjs: '#e8c547', cjs: '#e8c547', ts: '#3b8eea', tsx: '#3b8eea', jsx: '#5ccfe6', vue: '#41b883', py: '#4f8fd8', go: '#29beb0', rb: '#e0525b', java: '#e8873a', rs: '#de8a5a', html: '#f06a2b', htm: '#f06a2b', css: '#3fa9f5', scss: '#d6609c', json: '#d9b23a', sql: '#e86aa8', md: '#8b9bb8', yml: '#d8545a', yaml: '#d8545a' };
  const CATCOL = { code: '#8892f6', web: '#f06a2b', style: '#3fa9f5', data: '#d9b23a', conf: '#8b9bb8', shell: '#3ccf7e', text: '#8b9bb8', img: '#b57cf2', zip: '#e3a92f', pdf: '#e5484d', doc: '#3f7fe0', audio: '#e8579b', video: '#f2555a', font: '#22b3a6', key: '#f0a33a', bin: '#38a3d9' };
  const catOf = n => { const e = extOf(n); for (const k in T) if (T[k].includes(e)) return k; return ''; };
  const TEXTISH = new Set([].concat(T.code, T.web, T.style, T.conf, T.shell, T.text, ['json', 'yml', 'yaml', 'toml', 'csv', 'tsv', 'sql', 'svg', 'ndjson', 'xml']));
  function isText(it) {
    const e = extOf(it.name);
    if (TEXTISH.has(e)) return true;
    if (!e) return (it.size || 0) < 2 * 1024 * 1024;
    return /^(dockerfile|makefile|vagrantfile|procfile|license|readme|authors|changelog)$/i.test(it.name);
  }
  const isImg = it => ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'ico', 'bmp', 'avif'].includes(extOf(it.name));
  const isArchive = it => /\.(zip|tar|tar\.gz|tgz|tar\.bz2|tbz2|tar\.xz|txz)$/i.test(it.name);
  const isMedia = it => ['pdf', 'mp4', 'webm', 'mp3', 'ogg', 'wav'].includes(extOf(it.name));

  function fileIcon(it, big) {
    if (it.type === 'dir') {
      const badge = it.link ? '<circle cx="9" cy="25" r="6" fill="#ffffff"/><path d="M6.5 26.5l5-5M8 21.5h3.5V25" stroke="#2f8cdc" stroke-width="1.6" fill="none" stroke-linecap="round" stroke-linejoin="round"/>' : '';
      return '<svg viewBox="0 0 40 32"><path d="M0 4.5A4.5 4.5 0 0 1 4.5 0h9.2a3 3 0 0 1 2.1.9L19 4h16.5A4.5 4.5 0 0 1 40 8.5V11H0z" fill="#c98a1c"/><path d="M0 10a3.5 3.5 0 0 1 3.5-3.5h33A3.5 3.5 0 0 1 40 10v17.5a4.5 4.5 0 0 1-4.5 4.5h-31A4.5 4.5 0 0 1 0 27.5z" fill="#f2b33d"/><path d="M0 12h40" stroke="#ffd27a" stroke-opacity=".35"/>' + badge + '</svg>';
    }
    const e = extOf(it.name), cat = catOf(it.name);
    const col = it.broken ? '#e5484d' : EXTCOL[e] || CATCOL[cat] || '#5d6b87';
    const lab = big ? E((e || (it.name[0] === '.' ? it.name.slice(1) : '')).slice(0, 4).toUpperCase()) : '';
    const badge = it.link ? '<circle cx="7" cy="33" r="6" fill="#ffffff"/><path d="M4.5 34.5l5-5M6 29.5h3.5V33" stroke="#2f8cdc" stroke-width="1.6" fill="none" stroke-linecap="round" stroke-linejoin="round"/>' : '';
    return '<svg viewBox="0 0 32 40"><path d="M5 0h15.5L32 11.5V35a5 5 0 0 1-5 5H5a5 5 0 0 1-5-5V5a5 5 0 0 1 5-5z" fill="#e6eaef"/><path d="M20.5 0 32 11.5h-7.5a4 4 0 0 1-4-4z" fill="#cdd4dd"/>' +
      (big ? '<rect x="0" y="22" width="32" height="11" fill="' + col + '"/><text x="16" y="30.2" text-anchor="middle" font-family="ui-sans-serif,system-ui,sans-serif" font-size="7.6" font-weight="700" fill="#fff">' + lab + '</text>'
        : '<rect x="5" y="25" width="22" height="6" rx="2" fill="' + col + '"/><rect x="5" y="16" width="14" height="2.6" rx="1.3" fill="#b4bdc8"/>') + badge + '</svg>';
  }

  /* ------------------------------------------------------------ API */
  async function api(act, body) {
    let r;
    try {
      r = await fetch('?fm=' + act, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-LKM-Token': TOKEN }, body: JSON.stringify(body || {}) });
    } catch (e) { throw new Error('Sem conexão com o painel'); }
    if (r.status === 401) { location.reload(); throw new Error('Sessão expirada'); }
    let d; try { d = await r.json(); } catch (e) { throw new Error('Resposta inválida do servidor (' + r.status + ')'); }
    if (!d.ok) throw new Error(d.error || 'Erro desconhecido');
    return d;
  }
  const dlUrl = (p, view) => '?fm=' + (view ? 'view' : 'download') + '&t=' + encodeURIComponent(TOKEN) + '&path=' + encodeURIComponent(p);

  /* ------------------------------------------------------------ estado */
  const S = {
    path: ls.get('path', '/var/www'), items: [], view: [], sel: new Set(), anchor: null,
    sort: ls.get('sort', { k: 'name', d: 1 }), mode: ls.get('mode', 'list'), hidden: ls.get('hidden', true),
    filter: '', back: [], fwd: [], clip: null, search: null, writable: true, free: 0, total: 0, loaded: false, busy: false, error: ''
  };
  let root, body, inited = false;

  /* ------------------------------------------------------------ notificações */
  function toast(msg, kind) {
    let box = qs('.fm-toasts');
    if (!box) { box = document.createElement('div'); box.className = 'fm-toasts'; document.body.appendChild(box); }
    const t = document.createElement('div'); t.className = 'fm-toast ' + (kind || 'ok'); t.textContent = msg;
    box.appendChild(t);
    setTimeout(() => { t.style.transition = 'opacity .3s'; t.style.opacity = '0'; setTimeout(() => t.remove(), 320); }, kind === 'er' ? 6000 : 3200);
  }
  const fail = e => toast(e && e.message ? e.message : String(e), 'er');

  /* ------------------------------------------------------------ janelas */
  function dialog(o) {
    return new Promise(res => {
      const ov = document.createElement('div'); ov.className = 'fm-ov';
      ov.innerHTML = '<div class="fm-dlg ' + (o.wide ? 'wide ' : '') + (o.cls || '') + '"><h4>' + (o.icon ? svg(o.icon) : '') + E(o.title) + '</h4><div class="db">' + (o.html || '') + '</div><div class="df"></div></div>';
      const df = qs('.df', ov);
      let done = false;
      const close = v => { if (done) return; done = true; ov.remove(); document.removeEventListener('keydown', key, true); res(v); };
      (o.buttons || [{ label: 'OK', value: true, kind: 'pri' }]).forEach(b => {
        const btn = document.createElement('button'); btn.textContent = b.label; if (b.kind) btn.className = b.kind;
        btn.onclick = async () => {
          if (b.value === undefined || b.value === false || b.value === null || !o.validate) return close(b.value);
          const ok = await o.validate(ov, b.value); if (ok !== false) close(typeof ok === 'object' && ok !== null ? ok : b.value);
        };
        df.appendChild(btn);
      });
      const key = e => {
        if (e.key === 'Escape') { e.stopPropagation(); e.preventDefault(); close(null); }
        else if (e.key === 'Enter' && !e.shiftKey && e.target.tagName !== 'TEXTAREA' && e.target.tagName !== 'BUTTON') {
          const pri = qs('.df button.pri, .df button.danger', ov); if (pri) { e.preventDefault(); e.stopPropagation(); pri.click(); }
        }
      };
      document.addEventListener('keydown', key, true);
      ov.addEventListener('mousedown', e => { if (e.target === ov) close(null); });
      document.body.appendChild(ov);
      if (o.onOpen) o.onOpen(ov, close);
      const f = qs('input:not([type=checkbox]),textarea', ov) || qs('.df button.pri,.df button.danger', ov);
      if (f) setTimeout(() => f.focus(), 30);
    });
  }
  function prompt(title, label, value, icon) {
    return dialog({
      title, icon, html: '<div>' + E(label) + '</div><input class="fm-in" value="' + E(value || '') + '" spellcheck="false" autocomplete="off">',
      buttons: [{ label: 'Cancelar', value: null }, { label: 'OK', value: 'ok', kind: 'pri' }],
      onOpen(ov) {
        const i = qs('input', ov);
        setTimeout(() => { i.focus(); const dot = i.value.lastIndexOf('.'); i.setSelectionRange(0, dot > 0 ? dot : i.value.length); }, 40);
      },
      validate(ov) { const v = qs('input', ov).value.trim(); if (!v) return false; return { v }; }
    }).then(r => r && r.v);
  }
  function confirmDlg(title, html, okLabel, danger) {
    return dialog({ title, html, icon: danger ? 'trash' : 'info', buttons: [{ label: 'Cancelar', value: null }, { label: okLabel || 'OK', value: true, kind: danger ? 'danger' : 'pri' }] });
  }
  const namesHtml = list => '<div class="fm-names">' + list.slice(0, 12).map(p => '<div>' + E(basename(p)) + '</div>').join('') + (list.length > 12 ? '<div>… e mais ' + (list.length - 12) + '</div>' : '') + '</div>';

  /* ------------------------------------------------------------ montagem */
  const PLACES = [
    { p: HOME, l: 'Início', i: 'home' },
    { p: '/var/www', l: 'Sites', i: 'globe' },
    { p: '/', l: 'Raiz', i: 'server' },
    { p: '/etc', l: 'Configurações', i: 'cog' },
    { p: '/var/log', l: 'Logs', i: 'log' },
    { p: '/home', l: 'Usuários', i: 'users' },
    { p: '/tmp', l: 'Temporários', i: 'tmp' }
  ];

  function build() {
    root = qs('#fm');
    root.innerHTML =
      '<div class="fm">' +
      '<aside class="fm-side"><h3>Locais</h3>' + PLACES.map(x => '<div class="fm-place" data-go="' + E(x.p) + '">' + svg(x.i) + '<span>' + E(x.l) + '</span><small>' + E(x.p) + '</small></div>').join('') +
      '<div class="fm-disk"><div class="rl"><span>Espaço livre</span><span id="fmFree">—</span></div><div class="bar"><i id="fmDiskBar" style="width:0"></i></div></div></aside>' +
      '<section class="fm-main">' +
      '<div class="fm-top"><div class="fm-nav">' +
      '<button class="ib" data-a="back" title="Voltar (Alt+←)">' + svg('back') + '</button>' +
      '<button class="ib" data-a="fwd" title="Avançar (Alt+→)">' + svg('fwd') + '</button>' +
      '<button class="ib" data-a="up" title="Pasta acima (Backspace)">' + svg('up') + '</button>' +
      '<button class="ib" data-a="refresh" title="Atualizar">' + svg('refresh') + '</button></div>' +
      '<div class="fm-crumbs" id="fmCrumbs" title="Clique para digitar um caminho"></div>' +
      '<div class="fm-search">' + svg('search') + '<input id="fmSearch" placeholder="Filtrar · Enter busca em subpastas" spellcheck="false" autocomplete="off"></div></div>' +
      '<div class="fm-tools">' +
      '<button class="ib" data-a="mkdir" title="Nova pasta">' + svg('folderPlus') + '<span class="lbl">Nova pasta</span></button>' +
      '<button class="ib" data-a="newfile" title="Novo arquivo">' + svg('filePlus') + '<span class="lbl">Novo arquivo</span></button>' +
      '<button class="ib pri" data-a="upload" title="Enviar arquivos">' + svg('upload') + '<span class="lbl">Enviar</span></button>' +
      '<button class="ib" data-a="uploadDir" title="Enviar uma pasta inteira">' + svg('folderUp') + '</button>' +
      '<span class="sep"></span>' +
      '<button class="ib" data-a="copy" data-need="1" title="Copiar (Ctrl+C)">' + svg('copy') + '<span class="lbl">Copiar</span></button>' +
      '<button class="ib" data-a="cut" data-need="1" title="Recortar (Ctrl+X)">' + svg('cut') + '<span class="lbl">Recortar</span></button>' +
      '<button class="ib" data-a="paste" title="Colar (Ctrl+V)">' + svg('paste') + '<span class="lbl">Colar</span><span class="cnt" id="fmClipCnt" hidden></span></button>' +
      '<span class="sep"></span>' +
      '<button class="ib" data-a="rename" data-need="one" title="Renomear (F2)">' + svg('rename') + '<span class="lbl">Renomear</span></button>' +
      '<button class="ib" data-a="perm" data-need="1" title="Permissões e dono">' + svg('lock') + '<span class="lbl">Permissões</span></button>' +
      '<button class="ib" data-a="zip" data-need="1" title="Compactar em .zip">' + svg('zip') + '<span class="lbl">Compactar</span></button>' +
      '<button class="ib" data-a="extract" data-need="archive" title="Extrair">' + svg('unzip') + '<span class="lbl">Extrair</span></button>' +
      '<button class="ib" data-a="download" data-need="1" title="Baixar">' + svg('download') + '<span class="lbl">Baixar</span></button>' +
      '<button class="ib danger" data-a="delete" data-need="1" title="Excluir (Delete)">' + svg('trash') + '<span class="lbl">Excluir</span></button>' +
      '<span class="sp"></span>' +
      '<button class="ib" data-a="hidden" id="fmHidden" title="Mostrar/ocultar arquivos ocultos">' + svg('eye') + '</button>' +
      '<button class="ib" data-a="mode" id="fmMode" title="Lista ou grade">' + svg('grid') + '</button>' +
      '</div>' +
      '<div class="fm-body" id="fmBody" tabindex="0"></div>' +
      '<div class="fm-load" id="fmLoad"></div>' +
      '<div class="fm-status" id="fmStatus"></div>' +
      '<div class="fm-drop" id="fmDrop">' + svg('upload') + '<b>Solte para enviar</b><small id="fmDropTo"></small></div>' +
      '</section></div>' +
      '<input type="file" id="fmFile" multiple hidden><input type="file" id="fmDir" webkitdirectory multiple hidden>';
    body = qs('#fmBody');
    bind();
  }

  /* ------------------------------------------------------------ navegação e dados */
  async function load(path, opts) {
    opts = opts || {};
    const target = path || S.path;
    setBusy(true);
    try {
      const d = await api('list', { path: target });
      if (!opts.keepHistory && d.path !== S.path && S.loaded) { S.back.push(S.path); if (S.back.length > 60) S.back.shift(); S.fwd = []; }
      const changed = d.path !== S.path;
      S.path = d.path; S.items = d.items; S.writable = d.writable; S.free = d.free; S.total = d.total; S.error = ''; S.loaded = true;
      if (changed) { S.sel.clear(); S.anchor = null; S.filter = ''; const si = qs('#fmSearch'); if (si) si.value = ''; }
      else { const have = new Set(d.items.map(i => i.path)); S.sel.forEach(p => { if (!have.has(p)) S.sel.delete(p); }); }
      if (opts.select) { S.sel = new Set(opts.select); }
      S.search = null;
      ls.set('path', S.path);
      if (d.truncated) toast('Pasta com muitos itens: mostrando os primeiros 10.000', 'er');
      render();
      if (opts.select && opts.select.length) { const el = qs('.sel', body); if (el) el.scrollIntoView({ block: 'nearest' }); }
      return true;
    } catch (e) {
      if (!S.loaded) { S.error = e.message; S.loaded = true; S.items = []; S.path = target; render(); }
      else fail(e);
      return false;
    } finally { setBusy(false); }
  }
  const go = p => load(p);
  function setBusy(b) {
    S.busy = b; const l = qs('#fmLoad');
    if (!l) return;
    if (b) { l.style.opacity = '1'; l.classList.add('on'); }
    else { l.classList.remove('on'); l.style.width = '100%'; setTimeout(() => { l.style.opacity = '0'; l.style.width = ''; }, 250); }
  }

  function computeView() {
    let list = S.search ? S.search.items.slice() : S.items.slice();
    if (!S.hidden) list = list.filter(i => i.name[0] !== '.');
    if (S.filter && !S.search) { const f = S.filter.toLowerCase(); list = list.filter(i => i.name.toLowerCase().includes(f)); }
    const k = S.sort.k, d = S.sort.d;
    const col = new Intl.Collator('pt-BR', { numeric: true, sensitivity: 'base' });
    list.sort((a, b) => {
      if (a.type !== b.type) return a.type === 'dir' ? -1 : 1;
      let r = 0;
      if (k === 'size') r = (a.size || 0) - (b.size || 0);
      else if (k === 'mtime') r = a.mtime - b.mtime;
      else if (k === 'perm') r = a.perm.localeCompare(b.perm);
      else if (k === 'owner') r = col.compare(a.owner + ':' + a.group, b.owner + ':' + b.group);
      if (!r) r = col.compare(a.name, b.name);
      return r * d;
    });
    S.view = list;
  }

  /* ------------------------------------------------------------ renderização */
  function render() {
    computeView();
    renderCrumbs();
    renderPlaces();
    if (S.error) {
      body.innerHTML = '<div class="fm-err">' + E(S.error) + '</div>';
    } else if (S.mode === 'grid') {
      body.innerHTML = S.view.length ? '<div class="fm-grid">' + S.view.map(tileHtml).join('') + '</div>' : emptyHtml();
    } else {
      const h = (k, l, r) => '<span data-sort="' + k + '" class="' + (S.sort.k === k ? 'on ' : '') + (r ? 'r' : '') + '">' + l + (S.sort.k === k ? svg(S.sort.d > 0 ? 'sortU' : 'sortD') : '') + '</span>';
      const all = S.view.length && S.view.every(i => S.sel.has(i.path)), some = !all && S.view.some(i => S.sel.has(i.path));
      body.innerHTML = '<div class="fm-head"><span data-a="selAll"><i class="chk ' + (all ? 'on' : some ? 'part' : '') + '"></i></span>' + h('name', 'Nome') + h('size', 'Tamanho', 1) + h('mtime', 'Modificado') + h('perm', 'Permissões') + h('owner', 'Dono') + '</div>' +
        (S.view.length ? S.view.map(rowHtml).join('') : emptyHtml());
    }
    renderState();
  }
  function emptyHtml() {
    if (S.search) return '<div class="fm-empty"><div class="ico">' + fileIcon({ type: 'file', name: 'x' }, true) + '</div><b>Nada encontrado</b><span>Nenhum item com "' + E(S.search.q) + '" em ' + E(S.search.root) + '</span></div>';
    if (S.filter) return '<div class="fm-empty"><b>Nenhum item corresponde ao filtro</b><span>Pressione Enter para buscar também nas subpastas</span></div>';
    return '<div class="fm-empty"><div class="ico">' + fileIcon({ type: 'dir', name: '' }) + '</div><b>Pasta vazia</b><span>Arraste arquivos para cá ou use o botão Enviar</span></div>';
  }
  function clipCls(it) { return S.clip && S.clip.mode === 'cut' && S.clip.paths.includes(it.path) ? ' cut' : ''; }
  function rowHtml(it, i) {
    const sel = S.sel.has(it.path);
    const own = it.owner + (it.group !== it.owner ? ':' + it.group : '');
    return '<div class="fm-row' + (sel ? ' sel' : '') + clipCls(it) + '" data-i="' + i + '"' + (coarse ? '' : ' draggable="true"') + '>' +
      '<span class="c-chk"><i class="chk"></i></span>' +
      '<span class="c-name"><span class="ico">' + fileIcon(it) + '</span><span class="nm" title="' + E(it.name) + '">' + E(it.name) + '</span>' +
      (it.link != null ? '<span class="lk" title="' + E(it.link) + '">→ ' + E(it.link) + '</span>' : '') +
      (S.search ? '<span class="lk c-path" title="' + E(dirname(it.path)) + '">' + E(dirname(it.path)) + '</span>' : '') + '</span>' +
      '<span class="c-size r">' + (it.type === 'dir' ? '—' : fsize(it.size)) + '</span>' +
      '<span class="c-date">' + fdate(it.mtime) + '</span>' +
      '<span class="c-perm" title="' + rwx(it.perm) + '"><b>' + it.perm.slice(-3) + '</b>' + rwx(it.perm) + '</span>' +
      '<span class="c-own" title="' + E(it.owner + ':' + it.group) + '">' + E(own) + '</span></div>';
  }
  function tileHtml(it, i) {
    const sel = S.sel.has(it.path);
    const thumb = isImg(it) && (it.size || 0) < 6 * 1024 * 1024;
    return '<div class="fm-tile' + (sel ? ' sel' : '') + clipCls(it) + '" data-i="' + i + '"' + (coarse ? '' : ' draggable="true"') + ' title="' + E(it.name) + '"><i class="chk"></i>' +
      (thumb ? '<img class="thumb" loading="lazy" draggable="false" src="' + E(dlUrl(it.path, true)) + '" alt="" onerror="this.hidden=true;this.nextElementSibling.hidden=false"><span class="ico" hidden>' + fileIcon(it, true) + '</span>'
        : '<span class="ico">' + fileIcon(it, true) + '</span>') +
      '<span class="nm">' + E(it.name) + '</span><span class="meta">' + (it.type === 'dir' ? 'pasta' : fsize(it.size)) + '</span></div>';
  }
  function renderCrumbs() {
    const c = qs('#fmCrumbs'); if (!c || c.querySelector('input')) return;
    const parts = S.path.split('/').filter(Boolean);
    let acc = '', h = '<div class="cr"><span class="seg' + (parts.length ? '' : ' last') + '" data-go="/">/</span>';
    parts.forEach((p, i) => { acc += '/' + p; h += (i ? '<span class="sl">/</span>' : '') + '<span class="seg' + (i === parts.length - 1 ? ' last' : '') + '" data-go="' + E(acc) + '">' + E(p) + '</span>'; });
    c.innerHTML = h + '</div>';
    const cr = c.firstChild; cr.scrollLeft = cr.scrollWidth;
  }
  function renderPlaces() {
    qsa('.fm-place').forEach(el => el.classList.toggle('on', el.dataset.go === S.path));
    const used = S.total ? (S.total - S.free) / S.total * 100 : 0;
    const f = qs('#fmFree'); if (f) f.textContent = S.total ? fsize(S.free) + ' de ' + fsize(S.total) : '—';
    const b = qs('#fmDiskBar'); if (b) { b.style.width = used + '%'; b.style.background = used > 92 ? 'var(--bad)' : used > 80 ? 'var(--warn)' : 'var(--disk)'; }
  }
  function selItems() { return S.view.filter(i => S.sel.has(i.path)).concat(S.search ? [] : S.items.filter(i => S.sel.has(i.path) && !S.view.includes(i))); }
  function renderState() {
    const sel = selItems(), n = sel.length;
    qsa('.fm-tools [data-need]').forEach(b => {
      const need = b.dataset.need;
      b.disabled = need === 'one' ? n !== 1 : need === 'archive' ? !(n === 1 && isArchive(sel[0])) : n < 1;
    });
    qs('[data-a=back]', root).disabled = !S.back.length;
    qs('[data-a=fwd]', root).disabled = !S.fwd.length;
    qs('[data-a=up]', root).disabled = S.path === '/';
    const pb = qs('[data-a=paste]', root); pb.disabled = !S.clip || !!S.search;
    const cc = qs('#fmClipCnt'); cc.hidden = !S.clip; if (S.clip) cc.textContent = S.clip.paths.length;
    qs('#fmHidden').innerHTML = svg(S.hidden ? 'eye' : 'eyeOff'); qs('#fmHidden').classList.toggle('on', !S.hidden);
    qs('#fmMode').innerHTML = svg(S.mode === 'grid' ? 'list' : 'grid');
    ['mkdir', 'newfile', 'upload', 'uploadDir'].forEach(a => { qs('[data-a=' + a + ']', root).disabled = !!S.search || !!S.error; });
    // linhas
    qsa('[data-i]', body).forEach(el => { const it = S.view[+el.dataset.i]; if (it) el.classList.toggle('sel', S.sel.has(it.path)); });
    const hc = qs('.fm-head .chk', body);
    if (hc) { const all = S.view.length && S.view.every(i => S.sel.has(i.path)); const some = !all && S.view.some(i => S.sel.has(i.path)); hc.className = 'chk ' + (all ? 'on' : some ? 'part' : ''); }
    // status
    const dirs = S.view.filter(i => i.type === 'dir').length, files = S.view.length - dirs;
    const sz = sel.reduce((a, i) => a + (i.size || 0), 0);
    let h = S.search ? '<span class="srch">' + S.search.items.length + (S.search.limited ? '+' : '') + ' resultado(s) para "' + E(S.search.q) + '"</span><button class="ib" data-a="endSearch" style="height:24px">' + svg('x') + 'Fechar busca</button>'
      : '<span>' + S.view.length + ' itens · ' + dirs + ' pastas, ' + files + ' arquivos</span>';
    if (n) h += '<span>' + n + ' selecionado(s)' + (sz ? ' · ' + fsize(sz) : '') + '</span>';
    if (!S.writable && !S.search) h += '<span style="color:var(--warn)">somente leitura</span>';
    h += '<span class="sp"></span>';
    if (S.clip) h += '<span class="clip">' + S.clip.paths.length + ' item(s) ' + (S.clip.mode === 'cut' ? 'recortado(s)' : 'copiado(s)') + '<button data-a="paste">Colar aqui</button><button data-a="clearClip" title="Limpar">' + svg('x') + '</button></span>';
    qs('#fmStatus').innerHTML = h;
  }

  /* ------------------------------------------------------------ seleção */
  function itemFromEl(el) { const r = el && el.closest('[data-i]'); return r ? S.view[+r.dataset.i] : null; }
  function selectClick(it, e) {
    const idx = S.view.indexOf(it);
    if (e.shiftKey && S.anchor != null) {
      const a = S.view.findIndex(i => i.path === S.anchor); const [x, y] = a < idx ? [a, idx] : [idx, a];
      if (!(e.ctrlKey || e.metaKey)) S.sel.clear();
      for (let i = x; i <= y; i++) if (S.view[i]) S.sel.add(S.view[i].path);
    } else if (e.ctrlKey || e.metaKey || e.toggle) {
      S.sel.has(it.path) ? S.sel.delete(it.path) : S.sel.add(it.path); S.anchor = it.path;
    } else { S.sel = new Set([it.path]); S.anchor = it.path; }
    renderState();
  }

  /* ------------------------------------------------------------ abrir */
  function open(it) {
    if (!it) return;
    if (it.type === 'dir') { if (S.search) return load(it.path); return go(it.path); }
    if (it.broken) return toast('Link quebrado: ' + it.link, 'er');
    if (isImg(it)) return viewImage(it);
    if (isArchive(it)) return doExtract(it);
    if (isMedia(it)) return window.open(dlUrl(it.path, true), '_blank', 'noopener');
    if (isText(it)) return editFile(it.path);
    return download([it]);
  }

  /* ------------------------------------------------------------ ações */
  async function doMkdir() {
    const n = await prompt('Nova pasta', 'Nome da pasta em ' + S.path, 'nova-pasta', 'folderPlus'); if (!n) return;
    try { const d = await api('mkdir', { path: S.path, name: n }); await load(null, { select: [d.path] }); toast('Pasta criada'); } catch (e) { fail(e); }
  }
  async function doNewFile() {
    const n = await prompt('Novo arquivo', 'Nome do arquivo em ' + S.path, 'novo-arquivo.txt', 'filePlus'); if (!n) return;
    try { const d = await api('newfile', { path: S.path, name: n }); await load(null, { select: [d.path] }); editFile(d.path); } catch (e) { fail(e); }
  }
  async function doRename(it) {
    it = it || selItems()[0]; if (!it) return;
    const n = await prompt('Renomear', 'Novo nome para "' + it.name + '"', it.name, 'rename');
    if (!n || n === it.name) return;
    try { const d = await api('rename', { path: it.path, name: n }); await (S.search ? runSearch(S.search.q) : load(null, { select: [d.path] })); toast('Renomeado'); } catch (e) { fail(e); }
  }
  async function doDelete(list) {
    list = list || selItems(); if (!list.length) return;
    const paths = list.map(i => i.path);
    const ok = await confirmDlg('Excluir ' + (list.length === 1 ? '"' + list[0].name + '"' : list.length + ' itens') + '?',
      'Os itens serão apagados <b>permanentemente</b>, incluindo todo o conteúdo das pastas. Isso não pode ser desfeito.' + namesHtml(paths), 'Excluir', true);
    if (!ok) return;
    setBusy(true);
    try { const d = await api('delete', { paths }); S.sel.clear(); if (S.clip) { S.clip.paths = S.clip.paths.filter(p => !paths.includes(p)); if (!S.clip.paths.length) S.clip = null; } await (S.search ? runSearch(S.search.q) : load()); toast(d.count + ' item(s) excluído(s)'); }
    catch (e) { fail(e); await load(); } finally { setBusy(false); }
  }
  function doClip(mode, list) {
    list = list || selItems(); if (!list.length) return;
    S.clip = { mode, paths: list.map(i => i.path) };
    toast(list.length + ' item(s) ' + (mode === 'cut' ? 'recortado(s)' : 'copiado(s)') + '. Abra a pasta de destino e clique em Colar.');
    render();
  }
  async function doPaste(dest) {
    if (!S.clip) return;
    dest = dest || S.path;
    const { mode, paths } = S.clip;
    if (mode === 'cut' && paths.every(p => dirname(p) === dest)) { S.clip = null; render(); return; }
    setBusy(true);
    try {
      const d = await api(mode === 'cut' ? 'move' : 'copy', { paths, dest });
      if (mode === 'cut') S.clip = null;
      await load(null, { select: dest === S.path ? d.paths : [] });
      toast(d.paths.length + ' item(s) ' + (mode === 'cut' ? 'movido(s)' : 'colado(s)') + (dest !== S.path ? ' em ' + dest : ''));
    } catch (e) { fail(e); await load(); } finally { setBusy(false); }
  }
  async function moveTo(paths, dest, copy) {
    paths = paths.filter(p => p !== dest && dirname(p) !== dest || copy);
    if (!paths.length) return;
    if (!copy && paths.some(p => dest === p || dest.startsWith(p + '/'))) return toast('Não é possível mover uma pasta para dentro dela mesma', 'er');
    setBusy(true);
    try { const d = await api(copy ? 'copy' : 'move', { paths, dest }); await load(); toast(d.paths.length + ' item(s) ' + (copy ? 'copiado(s)' : 'movido(s)') + ' para ' + dest); }
    catch (e) { fail(e); } finally { setBusy(false); }
  }
  async function doZip(list) {
    list = list || selItems(); if (!list.length) return;
    const def = (list.length === 1 ? list[0].name.replace(/\.[^.]+$/, '') || list[0].name : basename(S.path) || 'arquivos') + '.zip';
    const n = await prompt('Compactar', 'Nome do arquivo .zip (' + list.length + ' item(s))', def, 'zip'); if (!n) return;
    setBusy(true);
    try { const d = await api('zip', { paths: list.map(i => i.path), dest: S.search ? dirname(list[0].path) : S.path, name: n }); await load(null, { select: [d.path] }); toast('Compactado: ' + basename(d.path)); }
    catch (e) { fail(e); } finally { setBusy(false); }
  }
  async function doExtract(it) {
    it = it || selItems()[0]; if (!it || !isArchive(it)) return;
    const stem = it.name.replace(/\.(zip|tar|tar\.gz|tgz|tar\.bz2|tbz2|tar\.xz|txz)$/i, '');
    const r = await dialog({
      title: 'Extrair "' + it.name + '"', icon: 'unzip',
      html: 'Onde extrair o conteúdo?',
      buttons: [{ label: 'Cancelar', value: null }, { label: 'Extrair aqui', value: 'here' }, { label: 'Extrair para "' + stem + '/"', value: 'folder', kind: 'pri' }]
    });
    if (!r) return;
    setBusy(true);
    try { const d = await api('extract', { path: it.path, here: r === 'here' }); await load(null, { select: r === 'here' ? [] : [d.path] }); toast('Extraído em ' + d.path); }
    catch (e) { fail(e); } finally { setBusy(false); }
  }
  function download(list) {
    list = list || selItems(); if (!list.length) return;
    const a = document.createElement('a');
    a.href = list.length === 1 ? dlUrl(list[0].path) : '?fm=download&t=' + encodeURIComponent(TOKEN) + '&paths=' + encodeURIComponent(JSON.stringify(list.map(i => i.path)));
    a.download = ''; document.body.appendChild(a); a.click(); a.remove();
    if (list.length > 1 || list[0].type === 'dir') toast('Compactando para download…');
  }
  function goTerminal(dir) {
    location.hash = '#terminal';
    setTimeout(() => { if (typeof window.tRun === 'function') window.tRun('cd ' + shq(dir)); }, 120);
  }

  /* ------------------------------------------------------------ permissões */
  async function doPerm(list) {
    list = list || selItems(); if (!list.length) return;
    const one = list.length === 1 ? list[0] : null;
    const perm = one ? one.perm.slice(-3) : '644';
    const hasDir = list.some(i => i.type === 'dir');
    const owner = one ? one.owner : '', group = one ? one.group : '';
    const who = [['Dono', 6], ['Grupo', 3], ['Outros', 0]], bits = [['Ler', 4], ['Escrever', 2], ['Executar', 1]];
    const html = (one ? '<b>' + E(one.name) + '</b>' : '<b>' + list.length + ' itens</b>') +
      '<div class="perm-grid"><span></span>' + bits.map(b => '<span class="h">' + b[0] + '</span>').join('') +
      who.map(w => '<span class="w">' + w[0] + '</span>' + bits.map(b => '<label><input type="checkbox" data-bit="' + (b[1] << w[1]) + '"></label>').join('')).join('') + '</div>' +
      '<div class="perm-row" style="margin-top:14px"><div><span>Permissão (octal)</span><input class="fm-in mono" id="pmOct" value="' + perm + '" maxlength="4"></div><div><span>Equivale a</span><div class="fm-in mono" id="pmRwx" style="display:flex;align-items:center;color:var(--tx)"></div></div></div>' +
      '<div class="perm-row" style="margin-top:10px"><div><span>Dono</span><input class="fm-in" id="pmOwn" value="' + E(owner) + '" placeholder="sem alteração" spellcheck="false"></div><div><span>Grupo</span><input class="fm-in" id="pmGrp" value="' + E(group) + '" placeholder="sem alteração" spellcheck="false"></div></div>' +
      (USER !== 'root' ? '<div style="margin-top:8px;font-size:12px;color:var(--dim)">Mudar o dono só funciona quando o painel roda como root.</div>' : '') +
      (hasDir ? '<label class="ck"><input type="checkbox" id="pmRec"> Aplicar em todo o conteúdo das pastas</label>' +
        '<div id="pmRecBox" style="display:none;margin-top:8px"><div class="perm-row"><div><span>Arquivos</span><input class="fm-in mono" id="pmF" value="644" maxlength="4"></div><div><span>Pastas</span><input class="fm-in mono" id="pmD" value="755" maxlength="4"></div></div></div>' : '') +
      '<div style="margin-top:12px;font-size:12px;color:var(--dim)">Comuns: <b>644</b> arquivos · <b>755</b> pastas e scripts · <b>600</b> privado · <b>775</b> grupo pode editar</div>';
    const r = await dialog({
      title: 'Permissões', icon: 'lock', wide: true, html,
      buttons: [{ label: 'Cancelar', value: null }, { label: 'Aplicar', value: 'ok', kind: 'pri' }],
      onOpen(ov) {
        const oct = qs('#pmOct', ov), boxes = qsa('[data-bit]', ov);
        const fromOct = () => { const v = parseInt(oct.value.slice(-3), 8); if (isNaN(v)) return; boxes.forEach(b => b.checked = !!(v & +b.dataset.bit)); qs('#pmRwx', ov).textContent = rwx(oct.value.slice(-3)); };
        const toOct = () => { const v = boxes.reduce((a, b) => a + (b.checked ? +b.dataset.bit : 0), 0); const s = oct.value.length === 4 ? oct.value[0] : ''; oct.value = s + v.toString(8).padStart(3, '0'); qs('#pmRwx', ov).textContent = rwx(v.toString(8)); };
        oct.addEventListener('input', fromOct); boxes.forEach(b => b.addEventListener('change', toOct)); fromOct();
        const rec = qs('#pmRec', ov);
        if (rec) rec.addEventListener('change', () => { qs('#pmRecBox', ov).style.display = rec.checked ? 'block' : 'none'; qs('.perm-grid', ov).style.opacity = rec.checked ? '.4' : ''; oct.disabled = rec.checked; });
      },
      validate(ov) {
        const rec = qs('#pmRec', ov) && qs('#pmRec', ov).checked;
        const o = { mode: qs('#pmOct', ov).value.trim(), rec, f: rec ? qs('#pmF', ov).value.trim() : '', d: rec ? qs('#pmD', ov).value.trim() : '', own: qs('#pmOwn', ov).value.trim(), grp: qs('#pmGrp', ov).value.trim() };
        const re = /^[0-7]{3,4}$/;
        if (!re.test(rec ? o.f : o.mode) || (rec && !re.test(o.d))) { toast('Permissão inválida: use números de 0 a 7 (ex: 755)', 'er'); return false; }
        return o;
      }
    });
    if (!r) return;
    const paths = list.map(i => i.path);
    setBusy(true);
    try {
      const modeChanged = r.rec || !one || r.mode.slice(-3) !== one.perm.slice(-3) || r.mode.length === 4;
      if (modeChanged) await api('chmod', r.rec ? { paths, mode: r.f, dmode: r.d, recursive: true } : { paths, mode: r.mode });
      const ownChanged = (r.own && r.own !== owner) || (r.grp && r.grp !== group) || (r.rec && (r.own || r.grp));
      if (ownChanged) await api('chown', { paths, owner: r.own, group: r.grp, recursive: r.rec });
      await load(); toast('Permissões atualizadas');
    } catch (e) { fail(e); await load(); } finally { setBusy(false); }
  }

  /* ------------------------------------------------------------ propriedades */
  async function doProps(it) {
    it = it || selItems()[0] || { path: S.path, name: basename(S.path), type: 'dir' };
    let st; try { st = await api('stat', { path: it.path }); } catch (e) { return fail(e); }
    const row = (k, v) => '<dt>' + k + '</dt><dd>' + v + '</dd>';
    const html = '<dl class="props">' +
      row('Nome', E(st.name)) +
      row('Local', '<code>' + E(dirname(st.path)) + '</code><button class="lnk" data-copy="' + E(st.path) + '">copiar caminho</button>') +
      row('Tipo', st.is_dir ? 'Pasta' : E(st.mime)) +
      (st.link != null ? row('Link para', '<code>' + E(st.link) + '</code>' + (st.broken ? ' <span style="color:var(--bad)">(quebrado)</span>' : '')) : '') +
      row('Tamanho', st.is_dir ? '<span id="pDu">—</span><button class="lnk" id="pCalc">calcular</button>' : fsize(st.size) + ' <span style="color:var(--dim)">(' + (st.size || 0).toLocaleString('pt-BR') + ' bytes)</span>') +
      row('Modificado', fdate(st.mtime)) + row('Acessado', fdate(st.atime)) +
      row('Permissões', '<code>' + st.perm.slice(-3) + '  ' + rwx(st.perm) + '</code>') +
      row('Dono', E(st.owner + ' : ' + st.group)) + '</dl>';
    dialog({
      title: 'Propriedades', icon: 'info', wide: true, html, buttons: [{ label: 'Fechar', value: true, kind: 'pri' }],
      onOpen(ov) {
        qsa('[data-copy]', ov).forEach(b => b.onclick = () => copyText(b.dataset.copy).then(() => toast('Caminho copiado')));
        const c = qs('#pCalc', ov);
        if (c) c.onclick = async () => {
          c.textContent = 'calculando…'; c.disabled = true;
          try { const d = await api('stat', { path: st.path, du: true }); qs('#pDu', ov).textContent = fsize(d.size) + ' · ' + (d.count || 0).toLocaleString('pt-BR') + ' itens'; c.remove(); }
          catch (e) { fail(e); c.textContent = 'calcular'; c.disabled = false; }
        };
      }
    });
  }

  /* ------------------------------------------------------------ busca */
  async function runSearch(q) {
    q = (q || '').trim(); if (!q) return;
    setBusy(true);
    try {
      const d = await api('search', { path: S.path, q });
      S.search = { q, root: S.path, items: d.items, limited: d.limited }; S.sel.clear(); render();
    } catch (e) { fail(e); } finally { setBusy(false); }
  }
  function endSearch() { S.search = null; S.filter = ''; qs('#fmSearch').value = ''; S.sel.clear(); render(); }

  /* ------------------------------------------------------------ upload */
  let upPanel = null, upQueue = [], upRunning = false, upTouched = new Set();
  function upUI() {
    if (!upPanel) {
      upPanel = document.createElement('div'); upPanel.className = 'fm-up';
      upPanel.innerHTML = '<div class="uh">' + svg('upload') + '<span id="upTitle">Enviando</span><button class="ib" id="upClose" title="Fechar">' + svg('x') + '</button></div><div class="ul" id="upList"></div>';
      document.body.appendChild(upPanel);
      qs('#upClose', upPanel).onclick = () => {
        if (upRunning && !window.confirm('Cancelar os envios em andamento?')) return;
        upQueue.forEach(u => { if (u.xhr) u.xhr.abort(); u.cancel = true; });
        upPanel.remove(); upPanel = null; upQueue = [];
      };
    }
    const done = upQueue.filter(u => u.st === 'ok').length, err = upQueue.filter(u => u.st === 'er').length;
    qs('#upTitle', upPanel).textContent = upRunning ? 'Enviando ' + (done + err + 1 > upQueue.length ? upQueue.length : done + err + 1) + ' de ' + upQueue.length : 'Concluído · ' + done + ' enviado(s)' + (err ? ', ' + err + ' com erro' : '');
    qs('#upList', upPanel).innerHTML = upQueue.map(u => '<div class="ui ' + (u.st === 'ok' ? 'done' : u.st === 'er' ? 'err' : '') + '"><div class="t"><b title="' + E(u.rel + u.name) + '">' + E(u.rel + u.name) + '</b><small class="' + (u.st === 'er' ? 'er' : u.st === 'ok' ? 'ok' : '') + '">' +
      (u.st === 'ok' ? 'enviado' : u.st === 'er' ? E(u.err) : u.st === 'up' ? Math.round(u.pct) + '% de ' + fsize(u.file.size) : 'na fila · ' + fsize(u.file.size)) + '</small></div><div class="bar"><i style="width:' + (u.st === 'ok' || u.st === 'er' ? 100 : u.pct) + '%"></i></div></div>').join('');
  }
  async function startUpload(entries) {
    // entries: [{file, rel}] rel = subpasta relativa ("" ou "pasta/sub/")
    if (!entries.length) return;
    const dest = S.path;
    const top = new Set(entries.map(e => (e.rel ? e.rel.split('/')[0] : e.file.name)));
    const existing = new Set(S.items.map(i => i.name));
    const conflicts = [...top].filter(n => existing.has(n));
    let overwrite = false;
    if (conflicts.length) {
      const r = await dialog({
        title: conflicts.length === 1 ? '"' + conflicts[0] + '" já existe' : conflicts.length + ' itens já existem', icon: 'info',
        html: 'O que fazer com os itens que já existem em <b>' + E(dest) + '</b>?' + namesHtml(conflicts),
        buttons: [{ label: 'Cancelar', value: null }, { label: 'Pular', value: 'skip' }, { label: 'Manter os dois', value: 'keep' }, { label: 'Substituir', value: 'over', kind: 'pri' }]
      });
      if (!r) return;
      if (r === 'skip') entries = entries.filter(e => !conflicts.includes(e.rel ? e.rel.split('/')[0] : e.file.name));
      overwrite = r === 'over';
      if (!entries.length) return;
    }
    // cria as subpastas primeiro
    const dirs = [...new Set(entries.filter(e => e.rel).flatMap(e => { const p = e.rel.replace(/\/$/, '').split('/'); return p.map((_, i) => p.slice(0, i + 1).join('/')); }))].sort((a, b) => a.split('/').length - b.split('/').length);
    for (const d of dirs) {
      try { await api('mkdir', { path: pjoin(dest, dirname('/' + d) === '/' ? '' : dirname('/' + d).slice(1)).replace(/\/$/, '') || '/', name: basename(d) }); }
      catch (e) { if (!/já existe/i.test(e.message)) { fail(e); return; } }
    }
    entries.forEach(e => upQueue.push({ file: e.file, name: e.file.name, rel: e.rel || '', dest: e.rel ? pjoin(dest, e.rel.replace(/\/$/, '')) : dest, st: 'q', pct: 0, overwrite }));
    upTouched.add(dest);
    upUI();
    if (!upRunning) runQueue();
  }
  async function runQueue() {
    upRunning = true;
    let u;
    while ((u = upQueue.find(x => x.st === 'q' && !x.cancel))) {
      u.st = 'up'; upUI();
      await new Promise(res => {
        const fd = new FormData(); fd.append('dest', u.dest); fd.append('name', u.name); fd.append('overwrite', u.overwrite ? '1' : '0'); fd.append('file', u.file, u.name);
        const x = new XMLHttpRequest(); u.xhr = x;
        x.open('POST', '?fm=upload'); x.setRequestHeader('X-LKM-Token', TOKEN);
        let last = 0;
        x.upload.onprogress = ev => { if (ev.lengthComputable) { u.pct = ev.loaded / ev.total * 100; if (Date.now() - last > 150) { last = Date.now(); upUI(); } } };
        x.onload = () => { let d = null; try { d = JSON.parse(x.responseText); } catch (e) { } if (d && d.ok) u.st = 'ok'; else { u.st = 'er'; u.err = (d && d.error) || (x.status === 413 ? 'Arquivo grande demais para o servidor' : 'Erro ' + x.status); } res(); };
        x.onerror = () => { u.st = 'er'; u.err = 'Falha de conexão'; res(); };
        x.onabort = () => { u.st = 'er'; u.err = 'Cancelado'; res(); };
        x.send(fd);
      });
      u.xhr = null; upUI();
    }
    upRunning = false; upUI();
    if (upTouched.has(S.path) || [...upTouched].some(d => d.startsWith(S.path))) load();
    upTouched.clear();
    const err = upQueue.filter(x => x.st === 'er').length;
    if (!err) setTimeout(() => { if (upPanel && !upRunning) { upPanel.remove(); upPanel = null; upQueue = []; } }, 4000);
  }
  async function entriesFromDrop(dt) {
    const items = dt.items ? [...dt.items].filter(i => i.kind === 'file') : [];
    const roots = items.map(i => i.webkitGetAsEntry && i.webkitGetAsEntry()).filter(Boolean);
    if (!roots.length) return [...(dt.files || [])].map(f => ({ file: f, rel: '' }));
    const out = [];
    const readAll = r => new Promise(res => { const all = []; const next = () => r.readEntries(b => { if (!b.length) return res(all); all.push(...b); next(); }, () => res(all)); next(); });
    async function walk(en, rel) {
      if (en.isFile) await new Promise(res => en.file(f => { out.push({ file: f, rel }); res(); }, () => res()));
      else if (en.isDirectory) { const kids = await readAll(en.createReader()); if (!kids.length) out.push({ dir: rel + en.name + '/' }); for (const k of kids) await walk(k, rel + en.name + '/'); }
    }
    for (const r of roots) await walk(r, '');
    return out.filter(e => e.file);
  }

  /* ------------------------------------------------------------ editor */
  const CMV = '5.65.16', CMB = window.LKM_CM_BASE || 'https://cdnjs.cloudflare.com/ajax/libs/codemirror/' + CMV + '/';
  const loaded = {};
  function loadRes(url) {
    if (loaded[url]) return loaded[url];
    loaded[url] = new Promise((res, rej) => {
      let el;
      if (url.endsWith('.css')) { el = document.createElement('link'); el.rel = 'stylesheet'; el.href = url; }
      else { el = document.createElement('script'); el.src = url; el.async = false; }
      el.onload = res; el.onerror = () => { delete loaded[url]; rej(new Error('falha ao carregar ' + url)); };
      document.head.appendChild(el);
    });
    return loaded[url];
  }
  const MODES = {
    php: ['PHP', 'application/x-httpd-php', ['xml', 'javascript', 'css', 'htmlmixed', 'clike', 'php']], phtml: ['PHP', 'application/x-httpd-php', ['xml', 'javascript', 'css', 'htmlmixed', 'clike', 'php']],
    js: ['JavaScript', 'javascript', ['javascript']], mjs: ['JavaScript', 'javascript', ['javascript']], cjs: ['JavaScript', 'javascript', ['javascript']], jsx: ['JSX', 'jsx', ['xml', 'javascript', 'jsx']],
    ts: ['TypeScript', 'text/typescript', ['javascript']], tsx: ['TSX', 'text/typescript-jsx', ['xml', 'javascript', 'jsx']],
    json: ['JSON', 'application/json', ['javascript']], css: ['CSS', 'css', ['css']], scss: ['SCSS', 'text/x-scss', ['css']], less: ['LESS', 'text/x-less', ['css']],
    html: ['HTML', 'htmlmixed', ['xml', 'javascript', 'css', 'htmlmixed']], htm: ['HTML', 'htmlmixed', ['xml', 'javascript', 'css', 'htmlmixed']], vue: ['Vue', 'htmlmixed', ['xml', 'javascript', 'css', 'htmlmixed']],
    xml: ['XML', 'xml', ['xml']], svg: ['SVG', 'xml', ['xml']], sh: ['Shell', 'shell', ['shell']], bash: ['Shell', 'shell', ['shell']], zsh: ['Shell', 'shell', ['shell']],
    py: ['Python', 'python', ['python']], sql: ['SQL', 'text/x-sql', ['sql']], yml: ['YAML', 'yaml', ['yaml']], yaml: ['YAML', 'yaml', ['yaml']],
    md: ['Markdown', 'markdown', ['xml', 'markdown']], ini: ['INI', 'properties', ['properties']], conf: ['Config', 'properties', ['properties']], cnf: ['Config', 'properties', ['properties']], env: ['ENV', 'properties', ['properties']], properties: ['INI', 'properties', ['properties']],
    c: ['C', 'text/x-csrc', ['clike']], h: ['C', 'text/x-csrc', ['clike']], cpp: ['C++', 'text/x-c++src', ['clike']], java: ['Java', 'text/x-java', ['clike']], cs: ['C#', 'text/x-csharp', ['clike']], kt: ['Kotlin', 'text/x-kotlin', ['clike']],
    go: ['Go', 'go', ['go']], lua: ['Lua', 'lua', ['lua']], rb: ['Ruby', 'ruby', ['ruby']], rs: ['Rust', 'rust', ['rust']], toml: ['TOML', 'toml', ['toml']], nginx: ['Nginx', 'nginx', ['nginx']], dockerfile: ['Dockerfile', 'text/x-sh', ['shell']]
  };
  function modeFor(name) {
    const e = extOf(name) || name.toLowerCase().replace(/^\./, '');
    if (/^(htaccess|apache2\.conf)$/.test(e) || /\.conf$/.test(name) && /apache|sites-/.test(name)) return MODES.conf;
    if (/^(bashrc|profile|bash_profile|zshrc|bash_aliases)$/.test(e)) return MODES.sh;
    if (/^dockerfile$/i.test(name)) return MODES.dockerfile;
    return MODES[e] || ['Texto', null, []];
  }
  async function loadCM(deps) {
    await loadRes(CMB + 'codemirror.min.css');
    await loadRes(CMB + 'codemirror.min.js');
    // os extras são opcionais: se algum falhar o editor abre mesmo assim
    await Promise.allSettled([
      loadRes(CMB + 'addon/dialog/dialog.min.css'),
      loadRes(CMB + 'addon/dialog/dialog.min.js'), loadRes(CMB + 'addon/search/searchcursor.min.js'), loadRes(CMB + 'addon/search/search.min.js'),
      loadRes(CMB + 'addon/search/jump-to-line.min.js'), loadRes(CMB + 'addon/edit/matchbrackets.min.js'), loadRes(CMB + 'addon/edit/closebrackets.min.js'),
      loadRes(CMB + 'addon/selection/active-line.min.js'), loadRes(CMB + 'addon/mode/simple.min.js')
    ]);
    for (const m of deps) { try { await loadRes(CMB + 'mode/' + m + '/' + m + '.min.js'); } catch (e) { } }
  }
  let ED = null;
  window.addEventListener('beforeunload', e => { if (ED && ED.dirty()) { e.preventDefault(); e.returnValue = ''; } });

  async function editFile(path) {
    if (ED) return;
    const name = basename(path);
    const [label, mode, deps] = modeFor(name);
    const box = document.createElement('div'); box.className = 'fm-ed';
    box.innerHTML = '<div class="eh"><span class="ico">' + fileIcon({ type: 'file', name }) + '</span><div class="ep" title="' + E(path) + '"><bdi>' + E(dirname(path) === '/' ? '' : dirname(path)) + '/<b>' + E(name) + '</b></bdi></div><span class="dirty" title="Alterações não salvas"></span><span class="badge" id="edEnc" hidden></span><span class="badge">' + E(label) + '</span>' +
      '<button class="ib" id="edWrap" title="Quebra de linha">' + svg('wrap') + '</button><button class="ib" id="edFind" title="Buscar (Ctrl+F)">' + svg('search') + '</button><button class="ib" id="edDl" title="Baixar">' + svg('download') + '</button>' +
      '<button class="ib pri" id="edSave" title="Salvar (Ctrl+S)">' + svg('save') + '<span>Salvar</span></button><button class="ib" id="edClose" title="Fechar (Esc)">' + svg('x') + '</button></div>' +
      '<div class="eb" id="edBody"><div class="eload">Abrindo ' + E(name) + '…</div></div><div class="ef"><span id="edPos">Ln 1, Col 1</span><span id="edInfo"></span><span class="sp"></span><span>Ctrl+S salvar · Ctrl+F buscar · Esc fechar</span></div>';
    document.body.appendChild(box);
    let doc;
    try { doc = await api('read', { path }); } catch (e) { box.remove(); fail(e); if (/binário/.test(e.message)) { const it = S.items.find(i => i.path === path); if (it && await confirmDlg('Arquivo binário', 'Este arquivo não pode ser editado como texto. Deseja baixá-lo?', 'Baixar')) download([it]); } return; }
    let wrap = ls.get('wrap', false), cm = null, ta = null, clean = doc.content, mtime = doc.mtime, saving = false;
    const enc = doc.enc;
    if (enc === 'latin1') { const b = qs('#edEnc', box); b.hidden = false; b.textContent = 'ISO-8859-1'; }
    const bodyEl = qs('#edBody', box);
    const setDirty = () => box.classList.toggle('is-dirty', ED.dirty());
    const getVal = () => cm ? cm.getValue() : ta.value;
    const usesTabs = (doc.content.match(/^\t/mg) || []).length > (doc.content.match(/^ {2,}/mg) || []).length;
    try {
      await Promise.race([loadCM(deps || []), new Promise((_, rej) => setTimeout(() => rej(new Error('timeout')), 8000))]);
      bodyEl.innerHTML = '';
      cm = window.CodeMirror(bodyEl, {
        value: doc.content, mode: mode || 'text/plain', theme: 'lkm', lineNumbers: true, indentUnit: 4, tabSize: 4, indentWithTabs: usesTabs,
        lineWrapping: wrap, matchBrackets: true, autoCloseBrackets: true, styleActiveLine: true, readOnly: !doc.writable, autofocus: true, viewportMargin: 20,
        extraKeys: {
          'Ctrl-S': () => save(), 'Cmd-S': () => save(), 'Ctrl-F': 'findPersistent', 'Cmd-F': 'findPersistent', 'Ctrl-G': 'jumpToLine',
          Tab: c => { if (c.somethingSelected()) c.indentSelection('add'); else c.replaceSelection(c.getOption('indentWithTabs') ? '\t' : ' '.repeat(c.getOption('indentUnit')), 'end'); },
          'Shift-Tab': c => c.indentSelection('subtract')
        }
      });
      cm.on('change', setDirty);
      cm.on('cursorActivity', () => { const p = cm.getCursor(); qs('#edPos', box).textContent = 'Ln ' + (p.line + 1) + ', Col ' + (p.ch + 1); });
    } catch (e) {
      bodyEl.innerHTML = '<textarea class="plain' + (wrap ? ' wrap' : '') + '" spellcheck="false"></textarea>';
      ta = qs('textarea', bodyEl); ta.value = doc.content; ta.readOnly = !doc.writable; ta.focus();
      ta.addEventListener('input', setDirty);
      ta.addEventListener('keydown', ev => { if (ev.key === 'Tab') { ev.preventDefault(); const s = ta.selectionStart, en = ta.selectionEnd; ta.setRangeText(usesTabs ? '\t' : '    ', s, en, 'end'); setDirty(); } });
      const pos = () => { const t = ta.value.slice(0, ta.selectionStart).split('\n'); qs('#edPos', box).textContent = 'Ln ' + t.length + ', Col ' + (t[t.length - 1].length + 1); };
      ta.addEventListener('keyup', pos); ta.addEventListener('click', pos);
      qs('#edFind', box).disabled = true;
      qs('#edInfo', box).textContent = 'editor simples (sem internet para carregar o editor completo)';
    }
    const info = () => { const v = getVal(); qs('#edInfo', box).textContent = (ta ? qs('#edInfo', box).textContent.split(' · ').pop() + ' · ' : '') + v.split('\n').length + ' linhas · ' + fsize(new Blob([v]).size) + (doc.writable ? '' : ' · somente leitura'); };
    if (cm) info();
    ED = { dirty: () => getVal() !== clean, path };
    async function save(force) {
      if (saving || !doc.writable) return;
      saving = true; const b = qs('#edSave', box); b.disabled = true;
      const val = getVal();
      try {
        const d = await api('save', { path, content: val, enc, mtime, force: !!force });
        clean = val; mtime = d.mtime; setDirty(); if (cm) info(); toast('Salvo: ' + name);
        const it = S.items.find(i => i.path === path); if (it) { it.size = d.size; it.mtime = d.mtime; }
      } catch (e) {
        if (e.message === 'CONFLICT') {
          saving = false; b.disabled = false;
          if (await confirmDlg('Arquivo alterado por fora', 'O arquivo <b>' + E(name) + '</b> foi modificado no servidor depois que você abriu. Salvar mesmo assim e sobrescrever?', 'Sobrescrever', true)) return save(true);
          return;
        }
        fail(e);
      } finally { saving = false; b.disabled = false; (cm || ta).focus(); }
    }
    async function close() {
      if (ED && ED.dirty() && !(await confirmDlg('Descartar alterações?', 'Você tem alterações não salvas em <b>' + E(name) + '</b>.', 'Descartar', true))) return;
      document.removeEventListener('keydown', keys, true);
      box.remove(); ED = null; load(); body.focus();
    }
    const keys = ev => {
      if (!document.body.contains(box) || qs('.fm-ov')) return;
      if ((ev.ctrlKey || ev.metaKey) && ev.key.toLowerCase() === 's') { ev.preventDefault(); save(); }
      else if (ev.key === 'Escape' && !qs('.CodeMirror-dialog', box)) { ev.preventDefault(); close(); }
    };
    document.addEventListener('keydown', keys, true);
    qs('#edSave', box).onclick = () => save();
    qs('#edClose', box).onclick = close;
    qs('#edDl', box).onclick = () => download([{ path, type: 'file', name }]);
    qs('#edFind', box).onclick = () => cm && cm.execCommand('findPersistent');
    qs('#edWrap', box).classList.toggle('on', wrap);
    qs('#edWrap', box).onclick = () => { wrap = !wrap; ls.set('wrap', wrap); qs('#edWrap', box).classList.toggle('on', wrap); if (cm) cm.setOption('lineWrapping', wrap); else ta.classList.toggle('wrap', wrap); };
    if (!doc.writable) toast('Arquivo somente leitura', 'er');
  }

  /* ------------------------------------------------------------ visualizador */
  function viewImage(it) {
    const imgs = S.view.filter(isImg); let idx = Math.max(0, imgs.findIndex(i => i.path === it.path));
    const v = document.createElement('div'); v.className = 'fm-view';
    v.innerHTML = '<div class="vh"><span id="vName"></span><button class="ib" id="vDl" title="Baixar">' + svg('download') + '</button><button class="ib" id="vX" title="Fechar (Esc)">' + svg('x') + '</button></div>' +
      '<div class="vb"><img id="vImg" alt="">' + (imgs.length > 1 ? '<button class="nv p" id="vP">' + svg('chevL') + '</button><button class="nv n" id="vN">' + svg('chevR') + '</button>' : '') + '</div>';
    document.body.appendChild(v);
    const show = () => { const c = imgs[idx]; qs('#vImg', v).src = dlUrl(c.path, true); qs('#vName', v).textContent = c.name + '  ·  ' + fsize(c.size) + '  ·  ' + (idx + 1) + '/' + imgs.length; };
    const close = () => { v.remove(); document.removeEventListener('keydown', key, true); };
    const step = d => { idx = (idx + d + imgs.length) % imgs.length; show(); };
    const key = e => { if (e.key === 'Escape') { e.preventDefault(); close(); } else if (e.key === 'ArrowLeft') step(-1); else if (e.key === 'ArrowRight') step(1); };
    document.addEventListener('keydown', key, true);
    qs('#vX', v).onclick = close; qs('#vDl', v).onclick = () => download([imgs[idx]]);
    if (qs('#vP', v)) { qs('#vP', v).onclick = () => step(-1); qs('#vN', v).onclick = () => step(1); }
    qs('.vb', v).addEventListener('click', e => { if (e.target.classList.contains('vb')) close(); });
    show();
  }

  /* ------------------------------------------------------------ menu de contexto */
  function menu(x, y, entries) {
    closeMenu();
    const m = document.createElement('div'); m.className = 'fm-menu'; m.id = 'fmMenu';
    m.innerHTML = entries.map((e, i) => e === '-' ? '<hr>' : '<button data-k="' + i + '"' + (e.dis ? ' disabled' : '') + ' class="' + (e.danger ? 'danger' : '') + '">' + svg(e.i) + '<span>' + E(e.l) + '</span>' + (e.k ? '<kbd>' + e.k + '</kbd>' : '') + '</button>').join('');
    document.body.appendChild(m);
    const r = m.getBoundingClientRect();
    m.style.left = Math.max(6, Math.min(x, innerWidth - r.width - 6)) + 'px';
    m.style.top = Math.max(6, Math.min(y, innerHeight - r.height - 6)) + 'px';
    m.addEventListener('click', e => { const b = e.target.closest('button[data-k]'); if (!b || b.disabled) return; closeMenu(); entries[+b.dataset.k].fn(); });
  }
  function closeMenu() { const m = qs('#fmMenu'); if (m) m.remove(); }
  function itemMenu(e, it) {
    if (!S.sel.has(it.path)) { S.sel = new Set([it.path]); S.anchor = it.path; renderState(); }
    const sel = selItems(), one = sel.length === 1 ? sel[0] : null;
    const ent = [];
    if (one) {
      ent.push({ l: one.type === 'dir' ? 'Abrir' : isText(one) ? 'Editar' : isImg(one) ? 'Visualizar' : isArchive(one) ? 'Extrair…' : 'Abrir', i: one.type === 'dir' ? 'open' : isText(one) ? 'edit' : isArchive(one) ? 'unzip' : 'eye', k: 'Enter', fn: () => open(one) });
      if (one.type === 'file' && !isText(one) && !isImg(one)) ent.push({ l: 'Editar como texto', i: 'edit', fn: () => editFile(one.path) });
      if (S.search) ent.push({ l: 'Abrir local do arquivo', i: 'folderUp', fn: () => load(dirname(one.path), { select: [one.path] }) });
    }
    ent.push({ l: 'Baixar' + (sel.length > 1 || (one && one.type === 'dir') ? ' (.zip)' : ''), i: 'download', fn: () => download(sel) }, '-');
    ent.push({ l: 'Copiar', i: 'copy', k: 'Ctrl+C', fn: () => doClip('copy', sel) }, { l: 'Recortar', i: 'cut', k: 'Ctrl+X', fn: () => doClip('cut', sel) });
    if (one && one.type === 'dir' && S.clip) ent.push({ l: 'Colar dentro desta pasta', i: 'paste', fn: () => doPaste(one.path) });
    ent.push('-');
    ent.push({ l: 'Renomear', i: 'rename', k: 'F2', dis: !one, fn: () => doRename(one) }, { l: 'Permissões e dono', i: 'lock', fn: () => doPerm(sel) }, { l: 'Compactar (.zip)', i: 'zip', fn: () => doZip(sel) });
    if (one && isArchive(one)) ent.push({ l: 'Extrair', i: 'unzip', fn: () => doExtract(one) });
    ent.push('-');
    if (one) ent.push({ l: 'Copiar caminho', i: 'link', fn: () => copyText(one.path).then(() => toast('Caminho copiado')) });
    if (one && USER) ent.push({ l: 'Abrir no terminal', i: 'term', fn: () => goTerminal(one.type === 'dir' ? one.path : dirname(one.path)) });
    if (one) ent.push({ l: 'Propriedades', i: 'info', fn: () => doProps(one) });
    ent.push('-', { l: 'Excluir', i: 'trash', k: 'Del', danger: true, fn: () => doDelete(sel) });
    menu(e.clientX, e.clientY, ent);
  }
  function bgMenu(e) {
    if (S.sel.size) { S.sel.clear(); renderState(); }
    const ro = !!S.search;
    menu(e.clientX, e.clientY, [
      { l: 'Nova pasta', i: 'folderPlus', dis: ro, fn: doMkdir }, { l: 'Novo arquivo', i: 'filePlus', dis: ro, fn: doNewFile },
      { l: 'Enviar arquivos', i: 'upload', dis: ro, fn: () => qs('#fmFile').click() }, '-',
      { l: 'Colar', i: 'paste', k: 'Ctrl+V', dis: !S.clip || ro, fn: () => doPaste() }, { l: 'Selecionar tudo', i: 'selAll', k: 'Ctrl+A', fn: selectAll },
      { l: 'Atualizar', i: 'refresh', fn: () => load() }, '-',
      { l: 'Abrir no terminal', i: 'term', dis: !USER, fn: () => goTerminal(S.path) }, { l: 'Copiar caminho da pasta', i: 'link', fn: () => copyText(S.path).then(() => toast('Caminho copiado')) },
      { l: 'Propriedades da pasta', i: 'info', fn: () => doProps({ path: S.path, name: basename(S.path), type: 'dir' }) }
    ]);
  }
  function selectAll() { S.sel = new Set(S.view.map(i => i.path)); renderState(); }

  /* ------------------------------------------------------------ eventos */
  function editPath() {
    const c = qs('#fmCrumbs'); if (c.querySelector('input')) return;
    c.innerHTML = '<input value="' + E(S.path) + '" spellcheck="false" autocomplete="off">';
    const i = qs('input', c); i.focus(); i.select();
    let done = false;
    const end = async ok => { if (done) return; done = true; const v = i.value.trim(); c.innerHTML = ''; if (ok && v && v !== S.path) { if (!(await load(v.startsWith('/') ? v : pjoin(S.path, v)))) renderCrumbs(); } else renderCrumbs(); };
    i.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); end(true); } else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); end(false); } });
    i.addEventListener('blur', () => end(false));
  }

  const ACT = {
    back() { if (!S.back.length) return; S.fwd.push(S.path); load(S.back.pop(), { keepHistory: true }); },
    fwd() { if (!S.fwd.length) return; S.back.push(S.path); load(S.fwd.pop(), { keepHistory: true }); },
    up() { if (S.search) return endSearch(); if (S.path !== '/') load(dirname(S.path), { select: [S.path] }); },
    refresh() { S.search ? runSearch(S.search.q) : load(); },
    mkdir: doMkdir, newfile: doNewFile,
    upload() { qs('#fmFile').click(); }, uploadDir() { qs('#fmDir').click(); },
    copy() { doClip('copy'); }, cut() { doClip('cut'); }, paste() { doPaste(); }, clearClip() { S.clip = null; render(); },
    rename() { doRename(); }, perm() { doPerm(); }, zip() { doZip(); }, extract() { doExtract(); }, download() { download(); }, delete() { doDelete(); },
    hidden() { S.hidden = !S.hidden; ls.set('hidden', S.hidden); render(); },
    mode() { S.mode = S.mode === 'grid' ? 'list' : 'grid'; ls.set('mode', S.mode); render(); },
    selAll() { const all = S.view.length && S.view.every(i => S.sel.has(i.path)); if (all) S.sel.clear(); else selectAll(); renderState(); },
    endSearch
  };

  function bind() {
    root.addEventListener('click', e => {
      const a = e.target.closest('[data-a]');
      if (a && !a.disabled && ACT[a.dataset.a]) { e.preventDefault(); ACT[a.dataset.a](); return; }
      const g = e.target.closest('[data-go]');
      if (g && g.classList.contains('last')) { editPath(); return; }
      if (g) { load(g.dataset.go); return; }
      const so = e.target.closest('[data-sort]');
      if (so) { const k = so.dataset.sort; S.sort = { k, d: S.sort.k === k ? -S.sort.d : 1 }; ls.set('sort', S.sort); render(); return; }
      if (e.target.closest('#fmCrumbs') && !e.target.closest('.seg')) { editPath(); }
    });
    body.addEventListener('click', e => {
      const it = itemFromEl(e.target);
      if (!it) { if (!e.target.closest('.fm-head') && (S.sel.size)) { S.sel.clear(); renderState(); } return; }
      if (e.target.closest('.chk')) { selectClick(it, { toggle: true, shiftKey: e.shiftKey }); return; }
      if (coarse && !e.ctrlKey && !e.shiftKey && !S.sel.size) { open(it); return; }
      if (coarse && S.sel.size && !e.ctrlKey && !e.shiftKey) { selectClick(it, { toggle: true }); return; }
      selectClick(it, e);
    });
    body.addEventListener('dblclick', e => { const it = itemFromEl(e.target); if (it && !e.target.closest('.chk')) open(it); });
    body.addEventListener('contextmenu', e => { e.preventDefault(); const it = itemFromEl(e.target); it ? itemMenu(e, it) : bgMenu(e); });
    document.addEventListener('mousedown', e => { if (!e.target.closest('#fmMenu')) closeMenu(); }, true);
    window.addEventListener('blur', closeMenu);
    window.addEventListener('resize', closeMenu);
    body.addEventListener('scroll', closeMenu);

    // busca
    const si = qs('#fmSearch');
    si.addEventListener('input', () => { if (S.search) { S.search = null; } S.filter = si.value; render(); });
    si.addEventListener('keydown', e => {
      if (e.key === 'Enter') { e.preventDefault(); runSearch(si.value); }
      else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); si.value = ''; S.filter = ''; if (S.search) endSearch(); else render(); body.focus(); }
    });

    // arquivos para enviar
    qs('#fmFile').addEventListener('change', e => { startUpload([...e.target.files].map(f => ({ file: f, rel: '' }))); e.target.value = ''; });
    qs('#fmDir').addEventListener('change', e => {
      const list = [...e.target.files].map(f => { const p = f.webkitRelativePath || f.name; const i = p.lastIndexOf('/'); return { file: f, rel: i > 0 ? p.slice(0, i + 1) : '' }; });
      startUpload(list); e.target.value = '';
    });

    // arrastar arquivos do computador / mover itens
    const main = qs('.fm-main', root), drop = qs('#fmDrop');
    let depth = 0, dragPaths = null;
    const isFiles = e => e.dataTransfer && [...e.dataTransfer.types].includes('Files') && !dragPaths;
    main.addEventListener('dragenter', e => { if (!isFiles(e) || S.search || S.error) return; depth++; drop.classList.add('on'); qs('#fmDropTo').textContent = 'para ' + S.path; });
    main.addEventListener('dragleave', e => { if (!isFiles(e)) return; depth = Math.max(0, depth - 1); if (!depth) drop.classList.remove('on'); });
    main.addEventListener('dragover', e => { if (isFiles(e)) { e.preventDefault(); e.dataTransfer.dropEffect = 'copy'; } });
    main.addEventListener('drop', async e => {
      if (!isFiles(e)) return;
      e.preventDefault(); depth = 0; drop.classList.remove('on');
      if (S.search || S.error) return;
      startUpload(await entriesFromDrop(e.dataTransfer));
    });
    // mover arrastando itens
    root.addEventListener('dragstart', e => {
      const it = itemFromEl(e.target); if (!it) return;
      if (!S.sel.has(it.path)) { S.sel = new Set([it.path]); S.anchor = it.path; renderState(); }
      dragPaths = selItems().map(i => i.path);
      e.dataTransfer.effectAllowed = 'copyMove';
      e.dataTransfer.setData('application/x-lkm', JSON.stringify(dragPaths));
      e.dataTransfer.setData('text/plain', dragPaths.join('\n'));
    });
    root.addEventListener('dragend', () => { dragPaths = null; qsa('.drop', root).forEach(x => x.classList.remove('drop')); });
    const targetOf = e => {
      const el = e.target.closest('[data-i], [data-go]'); if (!el) return null;
      if (el.dataset.go) return { el, path: el.dataset.go };
      const it = S.view[+el.dataset.i]; return it && it.type === 'dir' ? { el, path: it.path } : null;
    };
    root.addEventListener('dragover', e => {
      if (!dragPaths) return;
      const t = targetOf(e); qsa('.drop', root).forEach(x => { if (!t || x !== t.el) x.classList.remove('drop'); });
      if (t && !dragPaths.includes(t.path)) { e.preventDefault(); e.dataTransfer.dropEffect = e.ctrlKey || e.altKey ? 'copy' : 'move'; t.el.classList.add('drop'); }
    });
    root.addEventListener('drop', e => {
      if (!dragPaths) return;
      const t = targetOf(e); e.preventDefault(); const paths = dragPaths; dragPaths = null;
      qsa('.drop', root).forEach(x => x.classList.remove('drop'));
      if (t && !paths.includes(t.path)) moveTo(paths, t.path, e.ctrlKey || e.altKey);
    });

    // teclado
    document.addEventListener('keydown', e => {
      if (!root.offsetParent || ED || qs('.fm-ov') || qs('.fm-view')) return;
      const tag = (e.target.tagName || '').toLowerCase();
      if (tag === 'input' || tag === 'textarea' || e.target.isContentEditable) return;
      const k = e.key, mod = e.ctrlKey || e.metaKey;
      const sel = selItems();
      if (k === 'Escape') { closeMenu(); if (S.sel.size) { S.sel.clear(); renderState(); } else if (S.search) endSearch(); }
      else if (mod && k.toLowerCase() === 'a') { e.preventDefault(); selectAll(); }
      else if (mod && k.toLowerCase() === 'c') { if (sel.length) { e.preventDefault(); doClip('copy'); } }
      else if (mod && k.toLowerCase() === 'x') { if (sel.length) { e.preventDefault(); doClip('cut'); } }
      else if (mod && k.toLowerCase() === 'v') { if (S.clip && !S.search) { e.preventDefault(); doPaste(); } }
      else if (mod && k.toLowerCase() === 'f') { e.preventDefault(); qs('#fmSearch').focus(); }
      else if (k === 'Delete') { if (sel.length) { e.preventDefault(); doDelete(); } }
      else if (k === 'F2') { if (sel.length === 1) { e.preventDefault(); doRename(); } }
      else if (k === 'Enter') { if (sel.length === 1) { e.preventDefault(); open(sel[0]); } }
      else if (k === 'Backspace' || (e.altKey && k === 'ArrowUp')) { e.preventDefault(); ACT.up(); }
      else if (e.altKey && k === 'ArrowLeft') { e.preventDefault(); ACT.back(); }
      else if (e.altKey && k === 'ArrowRight') { e.preventDefault(); ACT.fwd(); }
      else if ((k === 'ArrowDown' || k === 'ArrowUp') && S.view.length) {
        e.preventDefault();
        let i = S.view.findIndex(x => x.path === S.anchor);
        i = i < 0 ? 0 : Math.max(0, Math.min(S.view.length - 1, i + (k === 'ArrowDown' ? 1 : -1)));
        selectClick(S.view[i], { shiftKey: e.shiftKey });
        if (!e.shiftKey) S.anchor = S.view[i].path;
        const el = qs('[data-i="' + i + '"]', body); if (el) el.scrollIntoView({ block: 'nearest' });
      }
    });
  }

  /* ------------------------------------------------------------ inicialização */
  /* componentes compartilhados com as outras abas (sites.js) */
  window.UI = { E, qs, qsa, toast, fail, dialog, prompt, confirmDlg, menu, closeMenu, svg, IC, copyText, fsize, fdate, coarse };

  window.FM = {
    go(p) {
      if (!inited) { S.path = p; return; }
      load(p);
    },
    show() {
      if (!qs('#fm')) return;
      if (!USER) { qs('#fm').innerHTML = '<div class="card" style="max-width:640px;margin:40px auto">O gerenciador de arquivos está desativado. Rode o instalador do LK Monitor de novo e escolha <b>root</b> ou seu usuário na pergunta do terminal.</div>'; return; }
      if (!inited) { inited = true; build(); load(S.path).then(ok => { if (!ok && S.path !== HOME) { S.loaded = false; S.error = ''; load(HOME); } }); }
      else if (!S.busy && !ED) load(null);
    }
  };
  if (location.hash === '#arquivos') window.FM.show();
})();
