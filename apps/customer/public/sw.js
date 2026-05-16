const CACHE_NAME = 'jojo-customer-pwa-v4'
const APP_ASSETS = ['/logo.png', '/favicon.ico', '/manifest.json', '/manifest.webmanifest', '/apple-touch-icon.png', '/customernotif.mpeg']

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
  const title = notification.title || 'JOJO'
  const options = {
    body: notification.body || notification.message || '',
    icon: '/jojo192.png',
    badge: '/jojo192.png',
    data: payload.data || {},
  }

  event.waitUntil(Promise.all([
    self.registration.showNotification(title, options),
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
      clients.forEach((client) => client.postMessage({ type: 'customer_push_notification', data: options.data }))
    }),
  ]))
})

self.addEventListener('message', (event) => {
  if (event.data?.type === 'SKIP_WAITING') self.skipWaiting()
})

function notificationTarget(data = {}) {
  if (data.url) return data.url

  const params = new URLSearchParams()
  const type = data.type || data.notification_type
  const conversationId = data.conversation_id || data.conversationId
  const orderId = data.order_id || data.orderId

  if (type) params.set('notification_type', String(type))
  if (conversationId) params.set('conversation_id', String(conversationId))
  if (orderId) params.set('order_id', String(orderId))

  if (type === 'chat_message' || type === 'driver_accepted' || type === 'order_adjustment') {
    params.set('open', orderId ? 'driver-chat' : 'cs-chat')
  }

  if (type === 'order_cancelled' || type === 'order_auto_cancelled' || type === 'order_completed') {
    params.set('open', 'history')
  }

  return params.toString() ? `/?${params.toString()}` : '/'
}

self.addEventListener('notificationclick', (event) => {
  event.notification.close()
  const targetUrl = new URL(notificationTarget(event.notification.data || {}), self.location.origin).href

  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
      const client = clients.find((item) => new URL(item.url).origin === self.location.origin)

      if (client) {
        if ('navigate' in client) {
          return client.navigate(targetUrl).then((navigatedClient) => navigatedClient?.focus())
        }

        return client.focus()
      }

      return self.clients.openWindow(targetUrl)
    }),
  )
})
