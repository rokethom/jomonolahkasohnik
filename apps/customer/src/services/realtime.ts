import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import { API_BASE } from './api'

declare global {
  interface Window {
    Pusher?: typeof Pusher
  }
}

let echo: Echo<'reverb'> | null = null
let echoToken = ''

function isLocalHost(host?: string) {
  return !host || ['localhost', '127.0.0.1', '::1'].includes(host)
}

function reverbConfig() {
  const configuredHost = import.meta.env.VITE_REVERB_HOST
  const browserHost = window.location.hostname
  const isPublicHost = !isLocalHost(browserHost)
  const host = isPublicHost && isLocalHost(configuredHost) ? browserHost : (configuredHost || browserHost)
  const scheme = isPublicHost && isLocalHost(configuredHost)
    ? window.location.protocol.replace(':', '')
    : (import.meta.env.VITE_REVERB_SCHEME ?? window.location.protocol.replace(':', '') ?? 'http')
  const port = isPublicHost && isLocalHost(configuredHost) && scheme === 'https'
    ? 443
    : Number(import.meta.env.VITE_REVERB_PORT ?? (scheme === 'https' ? 443 : 8080))

  return { host, scheme, port }
}

export function getEcho() {
  const token = localStorage.getItem('customer_token') ?? ''
  if (echo && echoToken === token) return echo

  if (echo) {
    echo.disconnect()
    echo = null
  }

  window.Pusher = Pusher
  echoToken = token
  const realtime = reverbConfig()

  echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY ?? 'local',
    wsHost: realtime.host,
    wsPort: realtime.port,
    wssPort: realtime.port,
    forceTLS: realtime.scheme === 'https',
    enabledTransports: ['ws', 'wss'],
    authEndpoint: `${API_BASE.replace(/\/api$/, '')}/broadcasting/auth`,
    auth: {
      headers: {
        Authorization: `Bearer ${token}`,
        Accept: 'application/json',
      },
    },
  })

  return echo
}

export function resetEcho() {
  echo?.disconnect()
  echo = null
  echoToken = ''
}
