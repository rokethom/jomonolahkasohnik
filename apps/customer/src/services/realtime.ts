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

export function getEcho() {
  const token = localStorage.getItem('customer_token') ?? ''
  if (echo && echoToken === token) return echo

  if (echo) {
    echo.disconnect()
    echo = null
  }

  window.Pusher = Pusher
  echoToken = token

  echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY ?? 'local',
    wsHost: import.meta.env.VITE_REVERB_HOST ?? window.location.hostname,
    wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
    wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'http') === 'https',
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
