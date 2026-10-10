/* Essência — service worker mínimo: permite instalar o programa como aplicativo.
   Sempre busca na rede (dados e sincronização nunca ficam em cache); se estiver sem internet,
   abre a última versão da tela do programa. */
const CACHE = 'essencia-shell-v1';
self.addEventListener('install', e => { self.skipWaiting(); });
self.addEventListener('activate', e => {
  e.waitUntil(caches.keys().then(ks => Promise.all(ks.filter(k => k !== CACHE).map(k => caches.delete(k)))).then(() => self.clients.claim()));
});
self.addEventListener('fetch', e => {
  const req = e.request;
  if(req.method !== 'GET' || req.mode !== 'navigate') return;      // só a página do programa
  const url = new URL(req.url);
  if(url.origin !== location.origin || /catalogo\.php|api\.php/.test(url.pathname)) return;
  e.respondWith(fetch(req).then(res => {
    const copy = res.clone(); caches.open(CACHE).then(c => c.put('shell', copy)).catch(()=>{});
    return res;
  }).catch(() => caches.open(CACHE).then(c => c.match('shell')).then(r => r || Response.error())));
});
