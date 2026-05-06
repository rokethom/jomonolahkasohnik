import { initializeApp, type FirebaseOptions } from 'firebase/app'
import { getMessaging, getToken, isSupported } from 'firebase/messaging'

type PublicSettings = {
  push?: {
    enabled: boolean
    vapid_key?: string | null
    firebase_config?: Record<string, string> | null
  }
}

let initialized = false
let lastRegisteredToken = ''

export type PushSetupResult = {
  status: 'registered' | 'skipped' | 'error'
  message?: string
  token?: string
}

export async function setupDriverPush(API_BASE: string, token: string, settings: PublicSettings | null): Promise<PushSetupResult> {
  if (!settings?.push?.enabled || !settings.push.firebase_config || !settings.push.vapid_key) {
    return { status: 'skipped', message: 'FCM belum aktif di System Settings.' }
  }
  if (settings.push.vapid_key.length < 70) {
    return { status: 'error', message: 'Firebase VAPID key belum valid. Isi Web Push certificate public key dari Firebase Console.' }
  }
  if (!('Notification' in window) || !('serviceWorker' in navigator)) {
    return { status: 'skipped', message: 'Browser belum mendukung notifikasi push.' }
  }
  if (!(await isSupported())) {
    return { status: 'skipped', message: 'Firebase Messaging tidak didukung di browser ini.' }
  }

  const permission = await Notification.requestPermission()
  if (permission !== 'granted') {
    return { status: 'skipped', message: 'Izin notifikasi belum diberikan.' }
  }

  const registration = await navigator.serviceWorker.register('/sw.js', { scope: '/' })
  await registration.update().catch(() => undefined)
  const readyRegistration = await navigator.serviceWorker.ready

  if (!initialized) {
    initializeApp(settings.push.firebase_config as FirebaseOptions)
    initialized = true
  }

  const messaging = getMessaging()
  const fcmToken = await getToken(messaging, {
    vapidKey: settings.push.vapid_key,
    serviceWorkerRegistration: readyRegistration,
  })

  if (!fcmToken) return { status: 'error', message: 'Firebase tidak mengembalikan device token.' }
  if (fcmToken === lastRegisteredToken) return { status: 'registered', token: fcmToken }

  await fetch(`${API_BASE}/push/device-token`, {
    method: 'POST',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      Authorization: `Bearer ${token}`,
    },
    body: JSON.stringify({ token: fcmToken, platform: 'web', app: 'driver' }),
  })

  lastRegisteredToken = fcmToken

  return { status: 'registered', token: fcmToken }
}
