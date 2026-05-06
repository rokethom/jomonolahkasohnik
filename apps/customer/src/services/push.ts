import { initializeApp, type FirebaseOptions } from 'firebase/app'
import { getMessaging, getToken, isSupported } from 'firebase/messaging'
import type { PublicSettings } from '../types'
import { registerDeviceToken } from './api'

let initialized = false
let lastRegisteredToken = ''

export type PushSetupResult = {
  status: 'registered' | 'skipped' | 'error'
  message?: string
  token?: string
}

export async function setupPushNotifications(settings: PublicSettings | null | undefined, appName = 'customer'): Promise<PushSetupResult> {
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
  const token = await getToken(messaging, {
    vapidKey: settings.push.vapid_key,
    serviceWorkerRegistration: readyRegistration,
  })

  if (!token) return { status: 'error', message: 'Firebase tidak mengembalikan device token.' }
  if (token === lastRegisteredToken) return { status: 'registered', token }

  await registerDeviceToken({
    token,
    platform: 'web',
    app: appName,
  })
  lastRegisteredToken = token

  return { status: 'registered', token }
}
