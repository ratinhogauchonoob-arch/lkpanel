/* LK Monitor - service worker: guarda só a "casca" do app (HTML de reserva, CSS, JS e ícones).
   Respostas das rotas ?api, ?cmd, ?fm, ?hist, ?sites, ?term e da página logada nunca vão para o cache. */
const VER = '2.3.0';
const CACHE = 'lkm-shell-' + VER;
const SHELL = ['offline.html', 'manifest.webmanifest', 'icon.svg', 'icon-192.png', 'icon-512.png', 'icon-maskable-512.png',
  'fm.css?v=' + VER, 'app.css?v=' + VER, 'fm.js?v=' + VER, 'term.js?v=' + VER, 'sites.js?v=' + VER, 'db.js?v=' + VER, 'config.js?v=' + VER, 'sys.js?v=' + VER, 'apps.js?v=' + VER, 'cf.js?v=' + VER];

self.addEventListener('install', e => {
  e.waitUntil(caches.open(CACHE).then(c => c.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', e => {
  e.waitUntil(caches.keys()
    .then(keys => Promise.all(keys.filter(k => k.startsWith('lkm-') && k !== CACHE).map(k => caches.delete(k))))
    .then(() => self.clients.claim()));
});

self.addEventListener('fetch', e => {
  const req = e.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== location.origin) return;
  const scope = new URL(self.registration.scope);
  if (!url.pathname.startsWith(scope.pathname)) return;
  // página do painel: sempre da rede; sem conexão mostra a tela de reserva
  if (req.mode === 'navigate') {
    e.respondWith(fetch(req).catch(() => caches.match('offline.html')));
    return;
  }
  const key = url.pathname.slice(scope.pathname.length) + url.search;
  if (!SHELL.includes(key)) return;
  e.respondWith(caches.match(key).then(hit => hit || fetch(req)));
});
