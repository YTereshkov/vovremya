const SHELL_CACHE = 'vovremya-shell-v1'
const CORE_FILES = ['/', '/manifest.webmanifest', '/icon.svg', '/icon-192.png', '/icon-512.png']

self.addEventListener('install', (event) => {
  event.waitUntil((async () => {
    const shell = await fetch('/', { cache: 'no-store' })
    if (!shell.ok) throw new Error('Application shell is unavailable.')
    const html = await shell.text()
    const assets = [...html.matchAll(/<(?:script|link)\b[^>]*\b(?:src|href)="(\/[^\"]+)"/g)].map((match) => match[1])
    await (await caches.open(SHELL_CACHE)).addAll([...new Set([...CORE_FILES, ...assets])])
    await self.skipWaiting()
  })())
})

self.addEventListener('activate', (event) => {
  event.waitUntil(Promise.all([
    caches.keys().then((names) => Promise.all(names.filter((name) => name.startsWith('vovremya-shell-') && name !== SHELL_CACHE).map((name) => caches.delete(name)))),
    self.clients.claim(),
  ]))
})

self.addEventListener('fetch', (event) => {
  const request = event.request
  const url = new URL(request.url)
  if (request.method !== 'GET' || url.origin !== self.location.origin || url.pathname.startsWith('/api/') || url.pathname === '/health' || url.pathname === '/sw.js') return

  if (request.mode === 'navigate') {
    event.respondWith((async () => {
      try {
        const response = await fetch(request)
        if (response.ok) await (await caches.open(SHELL_CACHE)).put('/', response.clone())
        return response
      } catch {
        return (await caches.match('/')) ?? Response.error()
      }
    })())
    return
  }

  event.respondWith((async () => {
    try {
      const response = await fetch(request)
      if (response.ok && response.type === 'basic') await (await caches.open(SHELL_CACHE)).put(request, response.clone())
      return response
    } catch {
      return (await caches.match(request)) ?? Response.error()
    }
  })())
})
