const CACHE_NAME = 'jojo-driver-pwa-v2'
const APP_ASSETS = ['/logo.png', '/favicon.ico', '/manifest.webmanifest']

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE_NAME).then((cache) => cache.addAll(APP_ASSETS)).finally(() => self.skipWaiting()))
})

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key))))
      .then(() => self.clients.claim()),
  )
})

self.addEventListener('fetch', (event) => {
  if (event.request.method !== 'GET') return
  event.respondWith(fetch(event.request).catch(() => caches.match(event.request)))
})

self.addEventListener('push', (event) => {
  const payload = event.data ? event.data.json() : {}
  const notification = payload.notification || payload.data || {}
  const title = notification.title || 'JOJO Driver'
  const options = {
    body: notification.body || notification.message || '',
    icon: '/jojo_driver_192.png',
    badge: '/jojo_driver_192.png',
    data: payload.data || {},
  }

  event.waitUntil(self.registration.showNotification(title, options))
})

function notificationTarget(data = {}) {
  if (data.url) return data.url

  const params = new URLSearchParams()
  const type = data.type || data.notification_type
  const orderId = data.order_id || data.orderId

  if (type) params.set('notification_type', String(type))
  if (orderId) params.set('order_id', String(orderId))
  if (type === 'new_order' || type === 'dispatcher_broadcast_order') params.set('open', 'orders')

  return params.toString() ? `/?${params.toString()}` : '/'
}

self.addEventListener('notificationclick', (event) => {
  event.notification.close()
  const targetUrl = new URL(notificationTarget(event.notification.data || {}), self.location.origin).href

  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
      const client = clients.find((item) => new URL(item.url).origin === self.location.origin)

      if (client && 'navigate' in client) {
        return client.navigate(targetUrl).then((navigatedClient) => navigatedClient?.focus())
      }

      if (client) return client.focus()

      return self.clients.openWindow(targetUrl)
    }),
  )
})
