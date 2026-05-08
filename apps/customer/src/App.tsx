import { type FormEvent, type ReactNode, useCallback, useEffect, useMemo, useRef, useState } from 'react'
import './App.css'
import {
  Bike,
  Box,
  Camera,
  Car,
  ChevronLeft,
  ChevronRight,
  Clock,
  Gift,
  Headphones,
  Home,
  Image as ImageIcon,
  Mic,
  MessageCircle,
  MoreVertical,
  MapPin,
  Paperclip,
  Phone,
  SendHorizontal,
  ShieldCheck,
  ShoppingBag,
  Store,
  ThumbsUp,
  UserRound,
} from 'lucide-react'
import {
  API_BASE,
  createOrder,
  fetchHome,
  fetchKeywordParsers,
  fetchMe,
  fetchOrderMessages,
  fetchOrders,
  fetchPublicSettings,
  fetchServices,
  findDriver,
  fetchChatMessages,
  getApiErrorMessage,
  googleLoginUrl,
  login,
  logout,
  previewJojoBot,
  register,
  requestCancelOrder,
  sendChatMessage,
  sendOrderMessage,
  startOperatorChat,
  submitOperatorRating,
  submitOrderRating,
  updateProfile,
  updateUserLocation,
  type DynamicFormField,
  type DynamicFormSchema,
  type JojoBotPreview,
  type OrderPayload,
} from './services/api'
import { setupPushNotifications } from './services/push'
import { getEcho, resetEcho } from './services/realtime'
import { useCustomerStore } from './store/useCustomerStore'
import type { ChatConversation, ChatMessage, DynamicService, HomeData, HomeSectionItem, Order, OrderFeedback, PublicSettings } from './types'

type Screen = 'home' | 'order-chat' | 'driver-chat' | 'cs-chat' | 'history' | 'profile' | 'profile-setup' | 'login'
type JojoHistoryState = {
  jojoScreen?: Screen
}

type LocalMessage = {
  id: string
  from: 'bot' | 'user' | 'driver' | 'system'
  text?: string
  imageUrl?: string
  time: string
  csLink?: boolean
  order?: Order
  preview?: JojoBotPreview
}

type BeforeInstallPromptEvent = Event & {
  prompt: () => Promise<void>
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed'; platform: string }>
}

type RegistrationLocation = {
  lat: number
  lng: number
  location_lat: number
  location_lng: number
  location_accuracy?: number
  gps_timestamp: string
}

type SharedLocation = {
  lat: number
  lng: number
  url: string
  label: string
}

type ReplyTarget = {
  id: string
  text: string
}

const initialBotText = 'Silahkan kirim pesan secara manual atau gunakan layanan manual. Informasi lebih lanjut hubungi CS.'

function screenPath(screen: Screen) {
  return screen === 'profile-setup' ? '/profile/setup' : '/'
}

function screenFromHistoryState(state: unknown) {
  const maybeState = state as JojoHistoryState | null
  const value = maybeState?.jojoScreen
  return value && ['home', 'order-chat', 'driver-chat', 'cs-chat', 'history', 'profile', 'profile-setup', 'login'].includes(value)
    ? value
    : null
}

function guardScreenForSession(screen: Screen, token?: string | null, user?: ReturnType<typeof useCustomerStore.getState>['user']): Screen {
  if (!token) return 'login'
  if (!user) return screen === 'login' ? 'home' : screen
  if (!isProfileComplete(user ?? null)) return 'profile-setup'
  return screen === 'login' || screen === 'profile-setup' ? 'home' : screen
}

function readOAuthCallback() {
  const queryParams = new URLSearchParams(window.location.search)
  const hashParams = new URLSearchParams(window.location.hash.replace(/^#/, ''))
  const token = hashParams.get('auth_token')
    ?? hashParams.get('token')
    ?? queryParams.get('auth_token')
    ?? queryParams.get('token')
  const error = hashParams.get('error')
    ?? queryParams.get('error')
    ?? queryParams.get('message')

  return { token, error }
}

function todayLabel() {
  return new Intl.DateTimeFormat('id-ID', { day: '2-digit', month: 'long', year: 'numeric' }).format(new Date())
}

function greetingByTime() {
  const hour = new Date().getHours()
  if (hour >= 4 && hour < 11) return 'Pagi'
  if (hour >= 11 && hour < 15) return 'Siang'
  if (hour >= 15 && hour < 18) return 'Sore'
  return 'Malam'
}

function nowTime() {
  return new Intl.DateTimeFormat('id-ID', { hour: '2-digit', minute: '2-digit' }).format(new Date())
}

function formatRupiah(value?: number) {
  return new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0,
  }).format(value ?? 0)
}

function paymentMethodLabel(method?: string | null) {
  if (method === 'transfer') return 'Pembayaran Transfer'
  if (method === 'qris') return 'Pembayaran QRIS'
  return 'Pembayaran Cash'
}

function driverNameFromOrder(order?: Order | null) {
  return order?.driver?.user?.name ?? order?.driver_name ?? '-'
}

function isPurchaseOrder(order?: Order | null) {
  const service = String(order?.service_type ?? order?.service ?? '').toLowerCase()
  return ['do', 'delivery', 'belanja', 'gift_order', 'gift'].includes(service)
}

type ManualFormKind = 'belanja' | 'kurir' | 'ojek' | 'gift'

function normalizeServiceKeyword(value: string) {
  return value
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, ' ')
    .trim()
}

function manualFormKindForService(service: DynamicService): ManualFormKind | null {
  const code = service.code?.toUpperCase()
  const name = normalizeServiceKeyword(service.name)

  if (code === 'BL' || name.includes('belanja')) return 'belanja'
  if (code === 'KR' || name.includes('kurir')) return 'kurir'
  if (code === 'OJ' || name.includes('ojek')) return 'ojek'
  if (code === 'GO' || name.includes('gift')) return 'gift'

  return null
}

function serviceKeywords(service: DynamicService) {
  const code = normalizeServiceKeyword(service.code ?? '')
  const name = normalizeServiceKeyword(service.name)
  const words = new Set([code, name])

  if (service.code?.toUpperCase() === 'BL' || name.includes('belanja')) words.add('belanja')
  if (service.code?.toUpperCase() === 'DO' || name.includes('delivery')) {
    words.add('delivery')
    words.add('do')
  }
  if (service.code?.toUpperCase() === 'GO' || name.includes('gift')) {
    words.add('gift')
    words.add('gift order')
  }
  if (service.code?.toUpperCase() === 'JM' || name.includes('joker mobil') || name.includes('mobil')) {
    words.add('joker mobil')
    words.add('mobil')
  }
  if (service.code?.toUpperCase() === 'KR' || name.includes('kurir')) words.add('kurir')
  if (service.code?.toUpperCase() === 'OJ' || name.includes('ojek')) words.add('ojek')

  return [...words].filter(Boolean)
}

function findServiceByKeyword(text: string, services: DynamicService[]) {
  const keyword = normalizeServiceKeyword(text)
  if (!keyword) return null

  return services.find((service) => serviceKeywords(service).includes(keyword)) ?? null
}

function openServiceWhatsapp(
  service: Pick<DynamicService, 'name' | 'code' | 'service_type' | 'whatsapp_redirect_enabled' | 'whatsapp_number' | 'whatsapp_message_template'>,
  user: ReturnType<typeof useCustomerStore.getState>['user'],
  pushMessage: (message: Omit<LocalMessage, 'id' | 'time'>) => void,
) {
  if (!service.whatsapp_redirect_enabled || !service.whatsapp_number) {
    return false
  }

  const number = normalizeWhatsappNumber(service.whatsapp_number)
  if (!number) {
    pushMessage({ from: 'bot', text: `Nomor WhatsApp untuk layanan ${service.name} belum valid. Silakan hubungi operator.` })
    return true
  }

  const message = renderServiceWhatsappMessage(service, user)
  const url = `https://wa.me/${number}?text=${encodeURIComponent(message)}`
  pushMessage({ from: 'bot', text: `Layanan ${service.name} masih ditangani via WhatsApp. Saya arahkan ke admin layanan sekarang.` })
  window.open(url, '_blank', 'noopener,noreferrer')

  return true
}

function normalizeWhatsappNumber(value?: string | null) {
  const digits = String(value ?? '').replace(/\D+/g, '')
  if (!digits) return ''
  if (digits.startsWith('0')) return `62${digits.slice(1)}`

  return digits
}

function isOjekService(service?: string | null) {
  const value = String(service ?? '').toLowerCase()

  return value === 'ojek' || value === 'oj' || value.includes('ojek')
}

function renderServiceWhatsappMessage(
  service: Pick<DynamicService, 'name' | 'code' | 'service_type' | 'whatsapp_message_template'>,
  user: ReturnType<typeof useCustomerStore.getState>['user'],
) {
  const template = service.whatsapp_message_template?.trim()
    || 'Halo JojoApp, saya ingin pesan layanan {service_name}. Nama saya {customer_name}.'

  return template
    .replaceAll('{service_name}', service.name)
    .replaceAll('{service_code}', service.code)
    .replaceAll('{service_type}', service.service_type ?? service.code)
    .replaceAll('{customer_name}', user?.name ?? 'Customer Jojo')
    .replaceAll('{customer_phone}', user?.phone ?? '-')
}

function App() {
  const store = useCustomerStore()
  const token = useCustomerStore((state) => state.token)
  const setAuthToken = useCustomerStore((state) => state.setAuthToken)
  const setUserSession = useCustomerStore((state) => state.setUserSession)
  const clearSession = useCustomerStore((state) => state.clearSession)
  const setOrders = useCustomerStore((state) => state.setOrders)
  const addOrder = useCustomerStore((state) => state.addOrder)
  const showToast = useCustomerStore((state) => state.showToast)
  const [screen, setScreen] = useState<Screen>(() => token ? window.location.pathname === '/profile/setup' ? 'profile-setup' : 'home' : 'login')
  const [services, setServices] = useState<DynamicService[]>([])
  const [homeData, setHomeData] = useState<HomeData | null>(null)
  const [publicSettings, setPublicSettings] = useState<PublicSettings | null>(null)
  const [messages, setMessages] = useState<LocalMessage[]>([
    { id: crypto.randomUUID(), from: 'bot', text: initialBotText, time: nowTime(), csLink: true },
  ])
  const [typing, setTyping] = useState(false)
  const [pendingOrder, setPendingOrder] = useState<OrderPayload | null>(null)
  const [activeOrder, setActiveOrder] = useState<Order | null>(null)
  const [showBelanjaForm, setShowBelanjaForm] = useState(false)
  const [showKurirForm, setShowKurirForm] = useState(false)
  const [showOjekForm, setShowOjekForm] = useState(false)
  const [showGiftForm, setShowGiftForm] = useState(false)
  const [orderClosedMessage, setOrderClosedMessage] = useState('')
  const locationSyncTokenRef = useRef<string | null>(null)
  const [orderSubmitBlocked, setOrderSubmitBlocked] = useState(false)
  const isBrowserBackRef = useRef(false)
  const openOrder = () => {
    if (!token) {
      setScreen('login')
      return
    }

    if (!isProfileComplete(store.user)) {
      openProfileSetup()
      return
    }

    setScreen('order-chat')
  }
  const acceptedOrder = (activeOrder && isAcceptedOrder(activeOrder) ? activeOrder : null)
    ?? store.orders.find(isAcceptedOrder)
    ?? null

  const submitOrderPayload = async (payload: OrderPayload) => {
    const preferredVehicle = payload.preferred_vehicle_type ?? 'motor'
    const driverPreference = isOjekService(payload.service_type) ? (payload.driver_preference ?? 'general') : 'general'
    const order = await createOrder({
      ...payload,
      preferred_vehicle_type: preferredVehicle,
      driver_preference: driverPreference,
      service_payload: {
        ...(payload.service_payload ?? {}),
        preferred_vehicle_type: preferredVehicle,
        driver_preference: driverPreference,
      },
    })
    const driverResult = await findDriver(order.id)
    const assignedOrder = driverResult.data ?? order
    setActiveOrder(assignedOrder)
    addOrder(assignedOrder)
    pushMessage({
      from: 'bot',
      text: `Order berhasil dibuat.\nKode: ${assignedOrder.order_code ?? `#${assignedOrder.id}`}\nJOJOBOT sedang assign driver.`,
      order: assignedOrder,
    })
    if (isAcceptedOrder(assignedOrder)) setScreen('driver-chat')
    setPendingOrder(null)
    setOrderSubmitBlocked(false)

    return assignedOrder
  }

  useEffect(() => {
    const { token: oauthToken, error: oauthError } = readOAuthCallback()

    if (!oauthToken && !oauthError) return

    if (oauthError) {
      showToast('error', `Login Google gagal: ${oauthError}`)
      setScreen('login')
      window.history.replaceState({ jojoScreen: 'login' }, document.title, '/')
      return
    }

    if (!oauthToken) return

    setAuthToken(oauthToken)
    setScreen('home')
    window.history.replaceState({ jojoScreen: 'home' }, document.title, '/')
  }, [setAuthToken, showToast])

  useEffect(() => {
    const initialScreen = guardScreenForSession(
      screenFromHistoryState(window.history.state) ?? screen,
      useCustomerStore.getState().token,
      useCustomerStore.getState().user,
    )

    window.history.replaceState(
      { ...(window.history.state as JojoHistoryState | null), jojoScreen: initialScreen },
      document.title,
      screenPath(initialScreen),
    )
    if (initialScreen !== screen) setScreen(initialScreen)

    const handlePopState = (event: PopStateEvent) => {
      const nextScreen = guardScreenForSession(
        screenFromHistoryState(event.state) ?? 'home',
        useCustomerStore.getState().token,
        useCustomerStore.getState().user,
      )

      isBrowserBackRef.current = true
      setScreen(nextScreen)
    }

    window.addEventListener('popstate', handlePopState)

    return () => {
      window.removeEventListener('popstate', handlePopState)
    }
  }, [])

  useEffect(() => {
    const nextScreen = guardScreenForSession(screen, token, store.user)
    if (nextScreen !== screen) {
      setScreen(nextScreen)
      return
    }

    if (isBrowserBackRef.current) {
      isBrowserBackRef.current = false
      if (screenFromHistoryState(window.history.state) !== screen) {
        window.history.replaceState(
          { ...(window.history.state as JojoHistoryState | null), jojoScreen: screen },
          document.title,
          screenPath(screen),
        )
      }
      return
    }

    if (screenFromHistoryState(window.history.state) === screen) return

    window.history.pushState(
      { ...(window.history.state as JojoHistoryState | null), jojoScreen: screen },
      document.title,
      screenPath(screen),
    )
  }, [screen, store.user, token])

  useEffect(() => {
    void fetchPublicSettings()
      .then(setPublicSettings)
      .catch(() => undefined)
  }, [])

  useEffect(() => {
    if (!token || !publicSettings) return

    void setupPushNotifications(publicSettings, 'customer')
      .then((result) => {
        if (result.status === 'error' && result.message) showToast('error', result.message)
      })
      .catch((error) => {
        showToast('error', getApiErrorMessage(error, 'FCM gagal membuat device token.'))
      })
  }, [publicSettings, showToast, token])

  useEffect(() => {
    if (!token) setScreen('login')
  }, [token])

  useEffect(() => {
    if (!token) return
    if (locationSyncTokenRef.current === token) return
    locationSyncTokenRef.current = token

    void fetchMe()
      .then(async (user) => {
        setUserSession(user, token)
        if (!isProfileComplete(user)) {
          openProfileSetup()
          return
        }
        if (window.location.pathname === '/profile/setup') {
          window.history.replaceState({}, document.title, '/')
        }
        setScreen((current) => current === 'profile-setup' ? 'home' : current)
        await syncRealtimeUserLocation(token, setUserSession, showToast)
      })
      .catch((error) => {
        locationSyncTokenRef.current = null
        if (/unauthenticated/i.test(getApiErrorMessage(error, ''))) clearSession()
      })
    void fetchOrders()
      .then(setOrders)
      .catch(() => undefined)
  }, [clearSession, setOrders, setUserSession, showToast, token])

  const refreshCustomerOrders = useCallback(async () => {
    if (!useCustomerStore.getState().token) return

    try {
      const latestOrders = await fetchOrders()
      setOrders(latestOrders)
      setActiveOrder((current) => {
        if (!current) return latestOrders.find(isAcceptedOrder) ?? latestOrders.find((order) => !isCompletedStatus(order.status) && !isCancelledStatus(order.status)) ?? null

        return latestOrders.find((order) => order.id === current.id) ?? current
      })
    } catch (error) {
      if (/unauthenticated|401/i.test(getApiErrorMessage(error, ''))) {
        clearSession()
        setScreen('login')
      }
    }
  }, [clearSession, setOrders])

  useEffect(() => {
    if (!token) return

    const refresh = () => void refreshCustomerOrders()
    const interval = window.setInterval(refresh, 10000)
    const onVisible = () => {
      if (document.visibilityState === 'visible') refresh()
    }

    window.addEventListener('focus', refresh)
    document.addEventListener('visibilitychange', onVisible)

    return () => {
      window.clearInterval(interval)
      window.removeEventListener('focus', refresh)
      document.removeEventListener('visibilitychange', onVisible)
    }
  }, [refreshCustomerOrders, token])

  useEffect(() => {
    if (!token) return

    const pruneExpiredOrders = () => {
      const current = useCustomerStore.getState().orders
      const visible = current.filter((order) => !isExpiredUnacceptedOrder(order))
      if (visible.length !== current.length) setOrders(visible)
      setActiveOrder((order) => order && isExpiredUnacceptedOrder(order) ? null : order)
    }

    pruneExpiredOrders()
    const timer = window.setInterval(pruneExpiredOrders, 15000)

    return () => window.clearInterval(timer)
  }, [setOrders, token])

  useEffect(() => {
    void fetchServices()
      .then(setServices)
      .catch(() => setServices([]))
    void fetchHome()
      .then(setHomeData)
      .catch(() => setHomeData(null))
    void fetchKeywordParsers()
      .catch(() => undefined)
  }, [])

  useEffect(() => {
    if (screen !== 'driver-chat' || !activeOrder?.id) return

    const channel = getEcho().private(`order.${activeOrder.id}`)
    channel.listen('.order.status.updated', (event: { order?: Order; feedback?: OrderFeedback | null }) => {
      if (event.order) setActiveOrder({ ...event.order, feedback: event.feedback ?? event.order.feedback })
    })

    return () => {
      getEcho().leave(`order.${activeOrder.id}`)
    }
  }, [activeOrder?.id, screen])

  useEffect(() => {
    if (!token || !store.user?.id) return

    const channel = getEcho().private(`user.${store.user.id}`)
    channel.listen('.order.price.updated', (event: { order?: Order; actor_name?: string | null; message?: string | null }) => {
      const updatedOrder = event.order
      if (!updatedOrder) return
      setOrders(mergeOrderList(useCustomerStore.getState().orders, updatedOrder))
      setActiveOrder((current) => current?.id === updatedOrder.id ? { ...current, ...updatedOrder } : current)
      const code = updatedOrder.order_code ?? updatedOrder.code ?? ''
      const actor = event.actor_name?.trim()
      store.showToast('info', event.message ?? `Harga order ${code} diedit oleh ${actor || 'operator'}.`)
    })
    channel.listen('.order.status.updated', (event: { order?: Order; new_status?: string; feedback?: OrderFeedback | null }) => {
      const updatedOrder = event.order
      if (!updatedOrder) return
      const orderWithFeedback = { ...updatedOrder, feedback: event.feedback ?? updatedOrder.feedback }
      setOrders(mergeOrderList(useCustomerStore.getState().orders, orderWithFeedback))
      setActiveOrder((current) => current?.id === updatedOrder.id ? { ...current, ...orderWithFeedback } : current)
      if (isCancelledStatus(event.new_status ?? updatedOrder.status)) {
        const message = event.feedback?.message ?? cancelFeedbackMessage(orderWithFeedback)
        store.showToast('error', message)
        pushMessage({ from: 'bot', text: message, order: orderWithFeedback })
        return
      }
      if (isCompletedStatus(event.new_status ?? updatedOrder.status)) {
        const message = event.feedback?.message ?? `Order ${updatedOrder.order_code ?? updatedOrder.code ?? `#${updatedOrder.id}`} selesai.`
        store.showToast('success', message)
      }
    })
    channel.listen('.driver.accepted', (event: { order?: Order; feedback?: OrderFeedback | null }) => {
      const accepted = event.order
      if (!accepted) return
      const orderWithFeedback = { ...accepted, feedback: event.feedback ?? accepted.feedback }
      const message = event.feedback?.message ?? `Pesanan Anda telah diterima oleh ${driverNameFromOrder(accepted)}`
      setOrders(mergeOrderList(useCustomerStore.getState().orders, orderWithFeedback))
      setActiveOrder((current) => current?.id === accepted.id ? { ...current, ...orderWithFeedback } : orderWithFeedback)
      store.showToast('success', message)
      pushMessage({ from: 'bot', text: message, order: orderWithFeedback })
      setScreen('driver-chat')
    })

    return () => {
      getEcho().leave(`user.${store.user?.id}`)
    }
  }, [setOrders, store, store.user?.id, token])

  const pushMessage = (message: Omit<LocalMessage, 'id' | 'time'>) => {
    setMessages((rows) => [...rows, { ...message, id: crypto.randomUUID(), time: nowTime() }])
  }

  const closeManualForms = () => {
    setShowBelanjaForm(false)
    setShowKurirForm(false)
    setShowOjekForm(false)
    setShowGiftForm(false)
  }

  const openManualServiceForm = (service: DynamicService, options: { pushUser?: boolean } = {}) => {
    const kind = manualFormKindForService(service)
    if (!kind) return

    closeManualForms()
    setPendingOrder(null)
    setOrderSubmitBlocked(false)
    setShowBelanjaForm(kind === 'belanja')
    setShowKurirForm(kind === 'kurir')
    setShowOjekForm(kind === 'ojek')
    setShowGiftForm(kind === 'gift')

    if (options.pushUser ?? true) pushMessage({ from: 'user', text: service.name })
    pushMessage({ from: 'bot', text: `Baik, silakan lengkapi form ${service.name}.` })
  }

  const handleBotReply = async (rawText: string) => {
    const text = rawText.trim()
    if (!text) return

    pushMessage({ from: 'user', text })

    if (!token) {
      pushMessage({ from: 'bot', text: 'Silakan login dulu agar JOJOBOT bisa menghitung harga dan membuat order real.' })
      setScreen('login')
      return
    }

    if (!isProfileComplete(store.user)) {
      pushMessage({ from: 'bot', text: 'Lengkapi Nama, Phone, dan Alamat profile dulu agar JOJOBOT bisa auto isi order.' })
      openProfileSetup()
      return
    }

    const requestedService = findServiceByKeyword(text, services)
    if (requestedService) {
      closeManualForms()
      setPendingOrder(null)
      setOrderSubmitBlocked(false)

      if (openServiceWhatsapp(requestedService, store.user, pushMessage)) {
        return
      }

      if (manualFormKindForService(requestedService)) {
        openManualServiceForm(requestedService, { pushUser: false })
        return
      }
    }

    if (/^ya$/i.test(text) && pendingOrder) {
      if (orderSubmitBlocked) {
        pushMessage({ from: 'bot', text: 'Anda melebihi batas order aktif.\nSilakan selesaikan salah satu pesanan terlebih dahulu.' })
        return
      }
      setTyping(true)
      try {
        await submitOrderPayload(pendingOrder)
      } catch (error) {
        const message = getApiErrorMessage(error, 'Order gagal dikirim. Pastikan kamu sudah login.')
        const closedMessage = orderClosedMessageFromError(error)
        if (closedMessage) {
          setOrderClosedMessage(closedMessage)
          pushMessage({ from: 'bot', text: closedMessage })
        } else if (/unauthenticated/i.test(message)) {
          clearSession()
          setScreen('login')
          pushMessage({ from: 'bot', text: 'Sesi login habis. Silakan login ulang untuk melanjutkan order.' })
        } else {
          pushMessage({ from: 'bot', text: message })
          if (/melebihi batas order aktif|maksimal .*order aktif/i.test(message)) setOrderSubmitBlocked(true)
        }
      } finally {
        setTyping(false)
      }
      return
    }

    if (/^tidak$/i.test(text) && pendingOrder) {
      setPendingOrder(null)
      setOrderSubmitBlocked(false)
      pushMessage({ from: 'bot', text: 'Baik, kirim ulang detail pesanan atau pilih layanan manual.' })
      return
    }

    setTyping(true)
    try {
      const preview = await previewJojoBot(text)
      if (preview.order_payload) setPendingOrder(preview.order_payload)
      if (preview.order_payload) setOrderSubmitBlocked(false)
      pushMessage({ from: 'bot', text: preview.reply, preview })
    } catch (error) {
      const message = getApiErrorMessage(error, 'JOJOBOT belum bisa memproses pesan ini. Silakan pakai form manual.')
      const closedMessage = orderClosedMessageFromError(error)
      if (closedMessage) {
        setOrderClosedMessage(closedMessage)
        pushMessage({ from: 'bot', text: closedMessage })
      } else if (/unauthenticated/i.test(message)) {
        clearSession()
        setScreen('login')
        pushMessage({ from: 'bot', text: 'Sesi login habis. Silakan login ulang untuk melanjutkan chat order.' })
      } else if (isProfileSetupError(error, message)) {
        openProfileSetup()
        pushMessage({ from: 'bot', text: 'Lengkapi Nama, Phone, dan Alamat profile dulu agar JOJOBOT bisa auto isi order.' })
      } else {
        pushMessage({ from: 'bot', text: message })
      }
    } finally {
      setTyping(false)
    }
  }

  const handleDynamicFormPreview = async (rawText: string): Promise<JojoBotPreview | null> => {
    const text = rawText.trim()
    if (!text) return null

    pushMessage({ from: 'user', text })

    if (!token) {
      pushMessage({ from: 'bot', text: 'Silakan login dulu agar JOJOBOT bisa membuat order real.' })
      setScreen('login')
      return null
    }

    if (!isProfileComplete(store.user)) {
      pushMessage({ from: 'bot', text: 'Lengkapi Nama, Phone, dan Alamat profile dulu agar JOJOBOT bisa auto isi order.' })
      openProfileSetup()
      return null
    }

    setTyping(true)
    try {
      const preview = await previewJojoBot(text)
      if (!preview.order_payload) {
        pushMessage({
          from: 'bot',
          text: preview.reply || 'Detail form belum cukup untuk membuat order. Pastikan alamat jemput dan tujuan sudah terisi.',
          preview: { ...preview, form_schema: null },
        })
        return null
      }

      setPendingOrder(preview.order_payload)
      setOrderSubmitBlocked(false)
      pushMessage({
        from: 'bot',
        text: preview.reply,
        preview: { ...preview, form_schema: null },
      })

      return preview
    } catch (error) {
      const message = getApiErrorMessage(error, 'Order gagal dikirim. Pastikan detail order sudah lengkap.')
      const closedMessage = orderClosedMessageFromError(error)
      if (closedMessage) {
        setOrderClosedMessage(closedMessage)
        pushMessage({ from: 'bot', text: closedMessage })
      } else if (/unauthenticated/i.test(message)) {
        clearSession()
        setScreen('login')
        pushMessage({ from: 'bot', text: 'Sesi login habis. Silakan login ulang untuk melanjutkan order.' })
      } else {
        pushMessage({ from: 'bot', text: message })
        if (/melebihi batas order aktif|maksimal .*order aktif/i.test(message)) setOrderSubmitBlocked(true)
      }

      return null
    } finally {
      setTyping(false)
    }
  }

  const sendImage = (file: File) => {
    pushMessage({ from: 'user', imageUrl: URL.createObjectURL(file), text: file.name })
    pushMessage({ from: 'bot', text: 'Foto diterima. Tambahkan catatan bila foto ini bagian dari order.' })
  }

  const handleManualService = (service: DynamicService) => {
    if (openServiceWhatsapp(service, store.user, pushMessage)) {
      return
    }

    if (manualFormKindForService(service)) {
      openManualServiceForm(service)
      return
    }

    closeManualForms()
    void handleBotReply(service.name)
  }

  const menuAction = async (target: Screen | 'logout') => {
    if (target === 'logout') {
      try {
        await logout()
      } finally {
        resetEcho()
        store.clearSession()
        setScreen('login')
      }
      return
    }
    setScreen(target)
  }

  return (
    <ChatLayout
      screen={screen}
      title="JOJO"
      subtitle={screen === 'driver-chat' ? 'Chat dengan driver' : screen === 'cs-chat' ? 'Hubungi Operator' : screen === 'profile-setup' ? 'Lengkapi profile' : 'SI APLIKASI JOKER'}
      showCall={screen === 'driver-chat'}
      showBack={Boolean(token) && screen !== 'home'}
      onBack={() => setScreen(token ? isProfileComplete(store.user) ? 'home' : 'profile-setup' : 'login')}
      onHome={() => setScreen(token ? isProfileComplete(store.user) ? 'home' : 'profile-setup' : 'login')}
      onMenu={menuAction}
      showDriverChat={Boolean(acceptedOrder)}
      authenticated={Boolean(token)}
    >
      {screen === 'home' && <HomeScreen homeData={homeData} publicSettings={publicSettings} onOrder={openOrder} onOpen={(target) => setScreen(target)} />}
      {orderClosedMessage && <OrderClosedModal message={orderClosedMessage} onClose={() => setOrderClosedMessage('')} />}
      {screen === 'order-chat' && (
        <ChatOrderScreen
          messages={messages}
          typing={typing}
          services={services}
          onSend={handleBotReply}
          onDynamicFormOrder={handleDynamicFormPreview}
          onImage={sendImage}
          onService={handleManualService}
          onCs={() => setScreen('cs-chat')}
          showBelanjaForm={showBelanjaForm}
          showKurirForm={showKurirForm}
          showOjekForm={showOjekForm}
          showGiftForm={showGiftForm}
          onBelanjaPreview={(text) => {
            setShowBelanjaForm(false)
            void handleBotReply(text)
          }}
          onKurirPreview={(text) => {
            setShowKurirForm(false)
            void handleBotReply(text)
          }}
          onOjekPreview={(text) => {
            setShowOjekForm(false)
            void handleBotReply(text)
          }}
          onGiftPreview={(text) => {
            setShowGiftForm(false)
            void handleBotReply(text)
          }}
          pendingOrder={pendingOrder}
          onPendingOrderChange={setPendingOrder}
          publicSettings={publicSettings}
          submitBlocked={orderSubmitBlocked}
        />
      )}
      {screen === 'driver-chat' && <DriverChatScreen order={acceptedOrder} />}
      {screen === 'cs-chat' && <CsChatScreen />}
      {screen === 'history' && (
        <HistoryScreen
          orders={store.orders}
          onOpenDriverChat={(order) => {
            setActiveOrder(order)
            setScreen('driver-chat')
          }}
          onOrdersChanged={(orders) => setOrders(orders)}
        />
      )}
      {screen === 'profile' && <ProfileScreen />}
      {screen === 'profile-setup' && <ProfileScreen setupMode onDone={() => setScreen('home')} />}
      {screen === 'login' && <CustomerLoginScreen onDone={() => setScreen(isProfileComplete(useCustomerStore.getState().user) ? 'home' : 'profile-setup')} />}
    </ChatLayout>
  )

  function openProfileSetup() {
    window.history.replaceState({}, document.title, '/profile/setup')
    setScreen('profile-setup')
  }
}

function ChatLayout({
  children,
  screen,
  title,
  subtitle,
  showCall,
  showBack,
  onBack,
  onHome,
  onMenu,
  showDriverChat,
  authenticated,
}: {
  children: React.ReactNode
  screen: Screen
  title: string
  subtitle: string
  showCall: boolean
  showBack: boolean
  onBack: () => void
  onHome: () => void
  onMenu: (target: Screen | 'logout') => void
  showDriverChat: boolean
  authenticated: boolean
}) {
  const [open, setOpen] = useState(false)

  return (
    <main className="jojo-shell">
      <header className="wa-header">
        <button className="icon-action" onClick={onBack} aria-label="Kembali">
          {showBack && <ChevronLeft size={30} />}
        </button>
        <div className="jojo-logo">
          <img src="/logo.png" alt="JojoApp" />
        </div>
        <button className="app-title" onClick={onHome} type="button">
          <strong>{title}</strong>
          <span>{subtitle}</span>
        </button>
        <button className="icon-action home-shortcut" onClick={onHome} aria-label="Home">
          <Home size={22} />
        </button>
        {showCall && (
          <button className="icon-action" aria-label="Telepon driver">
            <Phone size={24} />
          </button>
        )}
        {authenticated && <div className="menu-wrap">
          <button className="icon-action" onClick={() => setOpen((value) => !value)} aria-label="Menu">
            <MoreVertical size={25} />
          </button>
          {open && (
            <div className="dot-menu">
              <button onClick={() => { setOpen(false); onMenu('order-chat') }}>Order</button>
              {showDriverChat && <button onClick={() => { setOpen(false); onMenu('driver-chat') }}>Chat Driver</button>}
              <button onClick={() => { setOpen(false); onMenu('profile') }}>Profile</button>
              <button onClick={() => { setOpen(false); onMenu('history') }}>History Order</button>
              <button onClick={() => { setOpen(false); onMenu('cs-chat') }}>Hubungi Operator</button>
              <button onClick={() => { setOpen(false); onMenu('logout') }}>Logout</button>
            </div>
          )}
        </div>}
      </header>
      <section className={`jojo-stage ${screen === 'home' ? 'is-home' : ''}`}>{children}</section>
    </main>
  )
}

function HomeScreen({
  homeData,
  publicSettings,
  onOrder,
  onOpen,
}: {
  homeData: HomeData | null
  publicSettings: PublicSettings | null
  onOrder: () => void
  onOpen: (screen: Screen) => void
}) {
  const sliderSection = homeData?.sections.find((section) => section.type === 'slider' || /slider/i.test(section.name))
  const promoSection = homeData?.sections.find((section) => section.type === 'promo' || /promo/i.test(section.name))
  const announcement = homeData?.announcements[0]
  const customerName = useCustomerStore((state) => state.user?.name?.trim() || 'Customer')
  const greeting = greetingByTime()
  const complaintUrl = publicSettings?.support?.complaint_whatsapp_url ?? 'https://wa.me/6281299232918'

  return (
    <div className="home-screen">
      <section className="home-hero">
        <div className="hero-copy">
          <h1>Hai {customerName},<br />Selamat {greeting}</h1>
          <p>Pesan berbagai layanan cepat, aman dan terpercaya lewat <strong>JOJO si Aplikasi Joker</strong>.</p>
          <button className="order-cta" onClick={onOrder}>
            <MessageCircle size={19} />
            Order Sekarang
            <ChevronRight size={22} />
          </button>
          <PwaInstallButton />
        </div>
        <div className="rider-visual" aria-hidden="true">
          <div className="city-fade" />
          <div className="rider-head" />
          <div className="rider-body" />
          <div className="scooter">
            <span className="wheel front" />
            <span className="wheel back" />
            <span className="box">JO</span>
          </div>
        </div>
      </section>
      {(sliderSection?.items ?? []).length > 0 && (
        <section className="home-cms-slider" aria-label="Slider CMS">
          {sliderSection?.items.map((item) => <HomeSliderCard key={item.id} item={item} onClick={onOrder} />)}
        </section>
      )}
      {(promoSection?.items ?? []).length > 0 && (
        <section className="popular-row cms-promo-row" aria-label="Promo CMS">
          {promoSection?.items.map((item) => <PopularService key={item.id} item={item} onClick={onOrder} />)}
        </section>
      )}
      {announcement && (
        <section className="promo-banner">
          <span>Promo Spesial</span>
          <strong>{announcement.title}</strong>
          <p>{plainText(announcement.content)}</p>
          <div className="promo-bag">%</div>
        </section>
      )}
      <SectionTitle title="Kenapa Jojo App?" compact />
      <section className="reason-grid">
        <Reason icon={<ShieldCheck />} title="Aman & Terpercaya" />
        <Reason icon={<Clock />} title="Cepat & Tepat Waktu" />
        <Reason icon={<Headphones />} title="Operator Siap Bantu" />
        <Reason icon={<ThumbsUp />} title="Banyak Promo Menarik" />
      </section>
      <nav className="home-nav">
        <button onClick={() => onOpen('history')}>History Order</button>
        <button onClick={() => onOpen('profile')}>Profile</button>
        <button onClick={() => onOpen('cs-chat')}>Hubungi Operator</button>
        <button onClick={() => window.open(complaintUrl, '_blank', 'noopener,noreferrer')}>Laporkan Keluhan</button>
      </nav>
      <button className="bottom-order" onClick={onOrder}>
        <SendHorizontal size={24} />
        Order Sekarang
      </button>
    </div>
  )
}

function SectionTitle({ title, compact = false }: { title: string; compact?: boolean }) {
  return (
    <div className={`section-title ${compact ? 'compact' : ''}`}>
      <h2>{title}</h2>
      {!compact && (
        <button type="button">
          Lihat Semua
          <ChevronRight size={20} />
        </button>
      )}
    </div>
  )
}

function serviceMeta(input: { title?: string; name?: string }) {
  const name = (input.title ?? input.name ?? '').toLowerCase()
  if (name.includes('belanja')) return { icon: <ShoppingBag />, label: 'Belanja kebutuhan', tone: 'green' }
  if (name.includes('ojek')) return { icon: <Bike />, label: 'Perjalanan instan', tone: 'orange' }
  if (name.includes('kurir')) return { icon: <Box />, label: 'Kirim barang cepat', tone: 'purple' }
  if (name.includes('gift')) return { icon: <Gift />, label: 'Kirim hadiah', tone: 'pink' }
  if (name.includes('mobil')) return { icon: <Car />, label: 'Layanan mobil', tone: 'blue' }
  return { icon: <Store />, label: 'Layanan cepat', tone: 'green' }
}

function HomeSliderCard({ item, onClick }: { item: HomeSectionItem; onClick: () => void }) {
  const meta = serviceMeta(item)
  const image = item.image ? cmsAssetUrl(item.image) : ''
  return (
    <button className={`home-slider-card ${image ? 'has-image' : ''}`} onClick={onClick}>
      {image && <img className="home-slider-image" src={image} alt={item.title} loading="lazy" decoding="async" />}
      <span className="home-slider-overlay" />
      {!image && <span className={`service-icon ${meta.tone}`}>{item.icon ? <img src={cmsAssetUrl(item.icon)} alt="" loading="lazy" decoding="async" /> : meta.icon}</span>}
      <span className="home-slider-copy">
        <strong>{item.title}</strong>
        <small>{item.subtitle ?? meta.label}</small>
      </span>
    </button>
  )
}

function PopularService({ item, onClick }: { item: HomeSectionItem; onClick: () => void }) {
  const meta = serviceMeta(item)
  return (
    <button className="popular-card" onClick={onClick}>
      <span className={`popular-art ${meta.tone}`}>{item.image ? <img src={cmsAssetUrl(item.image)} alt={item.title} loading="lazy" decoding="async" /> : meta.icon}</span>
      <span className={`service-icon mini ${meta.tone}`}>{meta.icon}</span>
      <strong>{item.title}</strong>
      <small>{item.subtitle ?? meta.label}</small>
    </button>
  )
}

function Reason({ icon, title }: { icon: ReactNode; title: string }) {
  return (
    <div className="reason-card">
      <span>{icon}</span>
      <strong>{title}</strong>
    </div>
  )
}

function ChatOrderScreen({
  messages,
  typing,
  services,
  onSend,
  onDynamicFormOrder,
  onImage,
  onService,
  onCs,
  showBelanjaForm,
  showKurirForm,
  showOjekForm,
  showGiftForm,
  onBelanjaPreview,
  onKurirPreview,
  onOjekPreview,
  onGiftPreview,
  pendingOrder,
  onPendingOrderChange,
  publicSettings,
  submitBlocked,
}: {
  messages: LocalMessage[]
  typing: boolean
  services: DynamicService[]
  onSend: (text: string) => void
  onDynamicFormOrder: (text: string) => Promise<JojoBotPreview | null>
  onImage: (file: File) => void
  onService: (service: DynamicService) => void
  onCs: () => void
  showBelanjaForm: boolean
  showKurirForm: boolean
  showOjekForm: boolean
  showGiftForm: boolean
  onBelanjaPreview: (text: string) => void
  onKurirPreview: (text: string) => void
  onOjekPreview: (text: string) => void
  onGiftPreview: (text: string) => void
  pendingOrder: OrderPayload | null
  onPendingOrderChange: (payload: OrderPayload | null) => void
  publicSettings: PublicSettings | null
  submitBlocked: boolean
}) {
  const listRef = useRef<HTMLDivElement | null>(null)
  const [detailOrder, setDetailOrder] = useState<Order | null>(null)
  const user = useCustomerStore((state) => state.user)
  const hasManualFormOpen = showBelanjaForm || showKurirForm || showOjekForm || showGiftForm

  useEffect(() => {
    listRef.current?.scrollTo({ top: listRef.current.scrollHeight, behavior: 'smooth' })
  }, [hasManualFormOpen, messages.length, typing])

  return (
    <div className="chat-screen">
      <div className="chat-date">{todayLabel()}</div>
      <div className="message-list" ref={listRef}>
        {messages.map((message) => (
          <div key={message.id} className="chat-message-group">
            {shouldShowChatMessage(message) && <MessageBubble message={message} onCs={onCs} onOrderDetail={setDetailOrder} />}
            {message.from !== 'user' && !hasManualFormOpen && message.preview?.form_schema && (
              <DynamicFormInline
                schema={message.preview.form_schema}
                serviceType={message.preview.selected_service ?? message.preview.service_type ?? ''}
                user={user}
                onSubmitOrder={onDynamicFormOrder}
              />
            )}
          </div>
        ))}
        {typing && <TypingIndicator />}
        {showBelanjaForm && <BelanjaOrderForm user={user} onSend={onBelanjaPreview} />}
        {showKurirForm && <KurirOrderForm user={user} onSend={onKurirPreview} />}
        {showOjekForm && <OjekOrderForm user={user} onSend={onOjekPreview} />}
        {showGiftForm && <GiftOrderForm user={user} onSend={onGiftPreview} />}
        {(messages.at(-1)?.preview?.intent === 'service_menu' || messages.length === 1) && (
          <ManualServicePicker
            services={services}
            onService={onService}
            compact={hasManualFormOpen}
          />
        )}
        {messages.at(-1)?.preview?.intent === 'fallback_form' && <FallbackForm onSend={onSend} />}
        {messages.at(-1)?.preview?.intent === 'order_preview' && (
          <ChatOrderActions
            preview={messages.at(-1)?.preview}
            pendingOrder={pendingOrder}
            onPendingOrderChange={onPendingOrderChange}
            publicSettings={publicSettings}
            onConfirm={() => onSend('ya')}
            submitBlocked={submitBlocked}
          />
        )}
      </div>
      <InputBar onSend={onSend} onImage={onImage} />
      {detailOrder && <OrderDetailModal order={detailOrder} onClose={() => setDetailOrder(null)} />}
    </div>
  )
}

function shouldShowChatMessage(message: LocalMessage) {
  return !(message.from === 'bot' && message.preview?.intent === 'service_menu')
}

function ManualServicePicker({ services, onService, compact = false }: { services: DynamicService[]; onService: (service: DynamicService) => void; compact?: boolean }) {
  return (
    <div className={compact ? 'manual-service-panel compact' : 'manual-service-panel'}>
      <strong>Pilih layanan manual</strong>
      <div>
        {services.map((service, index) => (
          <button className={service.whatsapp_redirect_enabled ? 'wa-service' : undefined} key={service.id} onClick={() => onService(service)}>
            <span>{index + 1}</span>
            {service.name}
            {service.whatsapp_redirect_enabled && <small>WhatsApp</small>}
          </button>
        ))}
      </div>
    </div>
  )
}

function BelanjaOrderForm({ user, onSend }: { user: ReturnType<typeof useCustomerStore.getState>['user']; onSend: (text: string) => void }) {
  const [address, setAddress] = useState('')
  const [items, setItems] = useState('tahu,tempe')
  const [purchaseAddress, setPurchaseAddress] = useState('')
  const [points, setPoints] = useState<string[]>([])
  const parsedItems = parseShoppingItems(items)
  const hasGacoan = /gacoan/i.test(items)
  const area = user?.branch_display_name ?? user?.branch_name ?? user?.branch ?? 'Area cabang belum diset silahkan hubungi CS'
  const pointText = points.map((point, index) => `Titik ${index + 1}: ${point}`).join('\n')

  useEffect(() => {
    if (hasGacoan) setPurchaseAddress('Jl. Sucipto Situbondo')
  }, [hasGacoan])

  const previewText = [
    'Layanan: belanja',
    `Nama: ${user?.name ?? 'Customer Jojo'}`,
    `No. Hp: ${user?.phone ?? '-'}`,
    `Alamat Antar: ${address || '-'}`,
    `Lokasi Pembelian: ${purchaseAddress || '-'}`,
    '',
    'Belikan:',
    ...parsedItems.map((item) => `- ${item}`),
    '',
    `Alamat pembelian: ${purchaseAddress || '-'}`,
    `Area: ${area}`,
    pointText,
  ].join('\n')

  return (
    <form
      className="belanja-form"
      onSubmit={(event) => {
        event.preventDefault()
        onSend(previewText)
      }}
    >
      <strong>Form Belanja</strong>
      <div className="belanja-profile-block">
        <label>Nama<input value={user?.name ?? 'Customer Jojo'} readOnly /></label>
        <label>Hp / WhatsApp<input value={user?.phone ?? '-'} readOnly /></label>
        <label>Alamat antar<input value={address} onChange={(event) => setAddress(event.target.value)} placeholder="Tulis alamat antar manual" /></label>
      </div>
      <label>Belikan<textarea value={items} onChange={(event) => setItems(event.target.value)} placeholder="tahu,tempe" /></label>
      {parsedItems.length > 0 && <div className="shopping-item-preview">{parsedItems.map((item) => <span key={item}>- {item}</span>)}</div>}
      <label>Alamat pembelian<textarea value={purchaseAddress} readOnly={hasGacoan} onChange={(event) => setPurchaseAddress(event.target.value)} placeholder="Contoh: Pasar Panji, toko Bu Sari" /></label>
      {hasGacoan && <span className="locked-address-note">Alamat pembelian dikunci karena item berisi kata gacoan.</span>}
      <label>Area<input value={area} readOnly /></label>
      <div className="belanja-points">
        <strong>Tambah titik</strong>
        <span>Antar ke: {address || 'Belum diisi'}</span>
        <span>Pembelian: {purchaseAddress || 'Belum diisi'}</span>
        {points.map((point, index) => (
          <input
            key={index}
            value={point}
            onChange={(event) => setPoints(points.map((item, itemIndex) => itemIndex === index ? event.target.value : item))}
            placeholder={`Titik tambahan ${index + 1}`}
          />
        ))}
        <button type="button" className="add-point-button" disabled={points.length >= 5} onClick={() => setPoints([...points, ''])}>
          + Tambah titik
        </button>
      </div>
      <div className="belanja-preview">
        <strong>Preview order</strong>
        <p>{previewText}</p>
      </div>
      <button disabled={!items.trim() || !purchaseAddress.trim()}>Preview order</button>
    </form>
  )
}

function KurirOrderForm({ user, onSend }: { user: ReturnType<typeof useCustomerStore.getState>['user']; onSend: (text: string) => void }) {
  const [senderAddress, setSenderAddress] = useState('')
  const [receiver, setReceiver] = useState({ name: '', phone: '', address: '', itemType: '', price: '' })

  const previewText = [
    'Ada Pesanan Kurir untuk Aplikasi Joker',
    '',
    `Nama: ${user?.name ?? 'Customer Jojo'}`,
    `Hp / WhatsApp: ${user?.phone ?? '-'}`,
    `Alamat: ${senderAddress || '-'}`,
    '',
    'Antarkan barang ke',
    '',
    `Nama: ${receiver.name}`,
    `Hp / WhatsApp: ${receiver.phone}`,
    `Alamat tujuan: ${receiver.address}`,
    '',
    `Jenis barang: ${receiver.itemType}`,
    `Harga: ${receiver.price}`,
  ].join('\n')

  return (
    <form
      className="kurir-form"
      onSubmit={(event) => {
        event.preventDefault()
        onSend(previewText)
      }}
    >
      <strong>Form Kurir</strong>
      <div className="kurir-section">
        <span>Pengirim</span>
        <label>Nama<input value={user?.name ?? 'Customer Jojo'} readOnly /></label>
        <label>Hp / WhatsApp<input value={user?.phone ?? '-'} readOnly /></label>
        <label>Alamat<input value={senderAddress} onChange={(event) => setSenderAddress(event.target.value)} placeholder="Tulis alamat pengirim manual" /></label>
      </div>
      <div className="kurir-section">
        <span>Antarkan barang ke</span>
        <label>Nama<input value={receiver.name} onChange={(event) => setReceiver({ ...receiver, name: event.target.value })} /></label>
        <label>Hp / WhatsApp<input value={receiver.phone} onChange={(event) => setReceiver({ ...receiver, phone: event.target.value })} /></label>
        <label>Alamat<textarea value={receiver.address} onChange={(event) => setReceiver({ ...receiver, address: event.target.value })} /></label>
        <label>Jenis barang<input value={receiver.itemType} onChange={(event) => setReceiver({ ...receiver, itemType: event.target.value })} /></label>
        <label>Harga<input value={receiver.price} onChange={(event) => setReceiver({ ...receiver, price: event.target.value })} /></label>
      </div>
      <div className="kurir-preview">
        <strong>Preview order</strong>
        <p>{previewText}</p>
      </div>
      <button disabled={!receiver.name.trim() || !receiver.phone.trim() || !receiver.address.trim() || !receiver.itemType.trim()}>Preview Order</button>
    </form>
  )
}

function OjekOrderForm({ user, onSend }: { user: ReturnType<typeof useCustomerStore.getState>['user']; onSend: (text: string) => void }) {
  const [pickupAddress, setPickupAddress] = useState('')
  const [destination, setDestination] = useState('')
  const [passengers, setPassengers] = useState('1')
  const [notes, setNotes] = useState('')

  const previewText = [
    'Ada pesanan Ojek untuk Aplikasi Joker',
    '',
    `Nama: ${user?.name ?? 'Customer Jojo'}`,
    `Hp / WhatsApp: ${user?.phone ?? '-'}`,
    `Alamat Jemput: ${pickupAddress || '-'}`,
    '',
    `Alamat Antar: ${destination}`,
    `Jumlah penumpang: ${passengers}`,
    '',
    `Catatan: ${notes}`,
  ].join('\n')

  return (
    <form
      className="ojek-form"
      onSubmit={(event) => {
        event.preventDefault()
        onSend(previewText)
      }}
    >
      <strong>Form Ojek</strong>
      <div className="ojek-section">
        <label>Nama<input value={user?.name ?? 'Customer Jojo'} readOnly /></label>
        <label>Hp / WhatsApp<input value={user?.phone ?? '-'} readOnly /></label>
        <label>Alamat Jemput<input value={pickupAddress} onChange={(event) => setPickupAddress(event.target.value)} placeholder="Tulis alamat jemput manual" /></label>
      </div>
      <div className="ojek-section">
        <label>Alamat Antar<textarea value={destination} onChange={(event) => setDestination(event.target.value)} /></label>
        <label>Jumlah penumpang<input value={passengers} onChange={(event) => setPassengers(event.target.value)} inputMode="numeric" /></label>
        <label>Catatan<textarea value={notes} onChange={(event) => setNotes(event.target.value)} /></label>
      </div>
      <div className="ojek-preview">
        <strong>Preview order</strong>
        <p>{previewText}</p>
      </div>
      <button disabled={!destination.trim() || !passengers.trim()}>Preview Order</button>
    </form>
  )
}

function GiftOrderForm({ user, onSend }: { user: ReturnType<typeof useCustomerStore.getState>['user']; onSend: (text: string) => void }) {
  const [receiver, setReceiver] = useState({ name: '', phone: '', address: '' })
  const [items, setItems] = useState('')
  const [purchaseAddress, setPurchaseAddress] = useState('')
  const area = user?.branch_display_name ?? user?.branch_name ?? user?.branch ?? 'Area cabang belum diset silahkan hubungi CS'
  const parsedItems = parseShoppingItems(items)
  const hasGacoan = /gacoan/i.test(items)

  useEffect(() => {
    if (hasGacoan) setPurchaseAddress('Jl. Sucipto Situbondo')
  }, [hasGacoan])

  const previewText = [
    'Ada Pesanan Gift Order untuk Aplikasi Joker',
    '',
    'Konfirmasi ke',
    '',
    `Nama: ${user?.name ?? 'Customer Jojo'}`,
    `Hp / WhatsApp: ${user?.phone ?? '-'}`,
    '',
    'Diantar ke',
    '',
    `Nama: ${receiver.name}`,
    `Hp / WhatsApp: ${receiver.phone}`,
    `Alamat: ${receiver.address}`,
    '',
    'Belikan:',
    ...parsedItems.map((item) => `- ${item}`),
    '',
    `Alamat pembelian: ${purchaseAddress}`,
    `Area: ${area}`,
  ].join('\n')

  return (
    <form
      className="gift-form"
      onSubmit={(event) => {
        event.preventDefault()
        onSend(previewText)
      }}
    >
      <strong>Form Gift Order</strong>
      {mustUseGiftOrder(user) && <span className="gift-area-note">Area kamu di luar cabang aktif, layanan diarahkan ke Gift Order.</span>}
      <div className="gift-section">
        <span>Konfirmasi ke</span>
        <label>Nama<input value={user?.name ?? 'Customer Jojo'} readOnly /></label>
        <label>Hp / WhatsApp<input value={user?.phone ?? '-'} readOnly /></label>
      </div>
      <div className="gift-section">
        <span>Diantar ke</span>
        <label>Nama<input value={receiver.name} onChange={(event) => setReceiver({ ...receiver, name: event.target.value })} /></label>
        <label>Hp / WhatsApp<input value={receiver.phone} onChange={(event) => setReceiver({ ...receiver, phone: event.target.value })} /></label>
        <label>Alamat<textarea value={receiver.address} onChange={(event) => setReceiver({ ...receiver, address: event.target.value })} /></label>
      </div>
      <label>Belikan<textarea value={items} onChange={(event) => setItems(event.target.value)} placeholder="Contoh: kue ulang tahun, bunga, coklat" /></label>
      {parsedItems.length > 0 && <div className="shopping-item-preview">{parsedItems.map((item) => <span key={item}>- {item}</span>)}</div>}
      <label>Alamat pembelian<textarea value={purchaseAddress} readOnly={hasGacoan} onChange={(event) => setPurchaseAddress(event.target.value)} placeholder="Nama toko / alamat pembelian" /></label>
      {hasGacoan && <span className="locked-address-note">Alamat pembelian dikunci karena item berisi kata gacoan.</span>}
      <label>Area<input value={area} readOnly /></label>
      <div className="gift-preview">
        <strong>Preview order</strong>
        <p>{previewText}</p>
      </div>
      <button disabled={!receiver.name.trim() || !receiver.phone.trim() || !receiver.address.trim() || !items.trim() || !purchaseAddress.trim()}>
        Preview Order
      </button>
    </form>
  )
}

function DynamicFormInline({
  schema,
  serviceType,
  user,
  onSubmitOrder,
}: {
  schema: DynamicFormSchema
  serviceType: string
  user: ReturnType<typeof useCustomerStore.getState>['user']
  onSubmitOrder: (text: string) => Promise<JojoBotPreview | null>
}) {
  const normalizedSchema = useMemo(() => normalizeDynamicFormSchema(schema), [schema])
  const fields = normalizedSchema.fields ?? []
  const [values, setValues] = useState<Record<string, string>>(() => dynamicInitialValues(fields, user))
  const [points, setPoints] = useState<string[]>([])
  const [submitting, setSubmitting] = useState(false)
  const [submitted, setSubmitted] = useState(false)
  const pointText = points
    .map((point, index) => point.trim() ? `Titik ${index + 1}: ${point.trim()}` : '')
    .filter(Boolean)
    .join('\n')
  const previewText = [dynamicFormText(fields, values, serviceType), pointText].filter(Boolean).join('\n')
  const hasMissingRequired = fields.some((field) => field.required && !String(values[field.name] ?? '').trim())

  useEffect(() => {
    setValues(dynamicInitialValues(fields, user))
  }, [normalizedSchema, user?.address, user?.name, user?.phone])

  if (fields.length === 0) return null
  if (submitted) {
    return (
      <div className="dynamic-form-inline">
        <strong>Preview order ditampilkan</strong>
        <p>Cek harga dari JOJOBOT, lalu konfirmasi jika pesanan sudah benar.</p>
      </div>
    )
  }

  return (
    <form
      className="dynamic-form-inline"
      onSubmit={async (event) => {
        event.preventDefault()
        if (hasMissingRequired || submitting) return
        setSubmitting(true)
        try {
          const preview = await onSubmitOrder(previewText)
          if (preview?.order_payload) {
            setSubmitted(true)
          }
        } finally {
          setSubmitting(false)
        }
      }}
    >
      <strong>Lengkapi detail order</strong>
      <div className="dynamic-form-grid">
        {fields.map((field) => (
          <label key={field.name} className={field.type === 'textarea' ? 'wide' : ''}>
            <span>{field.label}{field.required ? ' *' : ''}</span>
            {field.type === 'textarea' ? (
              <textarea value={values[field.name] ?? ''} onChange={(event) => setValues({ ...values, [field.name]: event.target.value })} />
            ) : field.type === 'select' ? (
              <select value={values[field.name] ?? ''} onChange={(event) => setValues({ ...values, [field.name]: event.target.value })}>
                <option value="">Pilih</option>
                {(field.options ?? []).map((option) => <option key={option} value={option}>{option}</option>)}
              </select>
            ) : (
              <input
                type={field.type === 'number' ? 'number' : field.type === 'phone' ? 'tel' : 'text'}
                value={values[field.name] ?? ''}
                onChange={(event) => setValues({ ...values, [field.name]: event.target.value })}
              />
            )}
          </label>
        ))}
      </div>
      <div className="dynamic-form-preview">
        <span>Preview hasil input</span>
        <p>{previewText}</p>
      </div>
      {points.map((point, index) => (
        <input
          key={index}
          className="dynamic-point-input"
          placeholder={`Titik tambahan ${index + 1}`}
          value={point}
          onChange={(event) => setPoints(points.map((item, itemIndex) => itemIndex === index ? event.target.value : item))}
        />
      ))}
      <div className="dynamic-form-actions">
        <button type="button" className="add-point-button" disabled={points.length >= 5} onClick={() => setPoints([...points, ''])}>+ Tambah Titik</button>
        <button type="submit" disabled={hasMissingRequired || submitting}>{submitting ? 'Menghitung...' : 'Preview Order'}</button>
      </div>
    </form>
  )
}

function normalizeDynamicFormSchema(schema?: DynamicFormSchema | DynamicFormField[] | null): DynamicFormSchema {
  const rawFields = Array.isArray(schema) ? schema : schema?.fields
  if (!Array.isArray(rawFields)) return { fields: [] }

  return {
    fields: rawFields
      .filter((field): field is DynamicFormField => Boolean(field?.name && field?.label))
      .map((field) => ({
        ...field,
        type: ['text', 'textarea', 'number', 'select', 'phone'].includes(field.type) ? field.type : 'text',
        options: Array.isArray(field.options) ? field.options.filter(Boolean) : [],
      })),
  }
}

function ChatOrderActions({
  preview,
  pendingOrder,
  onPendingOrderChange,
  publicSettings,
  onConfirm,
  submitBlocked,
}: {
  preview?: JojoBotPreview
  pendingOrder: OrderPayload | null
  onPendingOrderChange: (payload: OrderPayload | null) => void
  publicSettings: PublicSettings | null
  onConfirm: () => void
  submitBlocked: boolean
}) {
  const [points, setPoints] = useState<string[]>([])
  const [showSummary, setShowSummary] = useState(preview?.intent === 'order_preview')
  const configuredPaymentMethods = publicSettings?.payment?.methods?.length
    ? publicSettings.payment.methods
    : [{ key: 'cash', label: 'Pembayaran Cash', description: 'Bayar manual ke driver.' }]
  const qrisImageUrl = publicSettings?.payment?.qris_image_url ? cmsAssetUrl(publicSettings.payment.qris_image_url) : null
  const paymentMethods = qrisImageUrl && !configuredPaymentMethods.some((method) => method.key === 'qris')
    ? [...configuredPaymentMethods, { key: 'qris', label: 'Pembayaran QRIS', description: 'Scan QRIS aplikasi.' }]
    : configuredPaymentMethods
  const selectedPayment = pendingOrder?.payment_method ?? paymentMethods[0]?.key ?? 'cash'
  const selectedVehicle = pendingOrder?.preferred_vehicle_type ?? 'motor'
  const isOjekOrder = isOjekService(pendingOrder?.service_type)
  const selectedDriverPreference = pendingOrder?.driver_preference ?? 'general'
  const transferAccounts = publicSettings?.payment?.transfer_accounts?.length
    ? publicSettings.payment.transfer_accounts
    : publicSettings?.payment?.transfer_account
      ? [publicSettings.payment.transfer_account]
      : []
  const selectedPaymentMethod = paymentMethods.find((method) => method.key === selectedPayment)
  const updateVehicle = (vehicle: 'motor' | 'mobil') => {
    if (!pendingOrder) return
    onPendingOrderChange({
      ...pendingOrder,
      preferred_vehicle_type: vehicle,
      service_payload: {
        ...(pendingOrder.service_payload ?? {}),
        preferred_vehicle_type: vehicle,
      },
    })
  }
  const updateDriverPreference = (preference: 'general' | 'ladies') => {
    if (!pendingOrder) return
    onPendingOrderChange({
      ...pendingOrder,
      driver_preference: preference,
      service_payload: {
        ...(pendingOrder.service_payload ?? {}),
        driver_preference: preference,
      },
    })
  }

  const updatePoint = (index: number, value: string) => {
    const nextPoints = points.map((point, pointIndex) => pointIndex === index ? value : point)
    setPoints(nextPoints)
    syncPendingPoints(nextPoints)
  }

  const addPoint = () => {
    const nextPoints = [...points, '']
    setPoints(nextPoints)
    syncPendingPoints(nextPoints)
  }

  const syncPendingPoints = (nextPoints: string[]) => {
    if (!pendingOrder) return
    const cleanPoints = nextPoints.map((point, index) => ({ label: `Titik ${index + 1}`, address: point.trim() })).filter((point) => point.address)
    onPendingOrderChange({ ...pendingOrder, points: cleanPoints, stops: Math.max(1, 1 + cleanPoints.length) })
  }

  if (!preview?.actions?.includes('add_point') && !preview?.actions?.includes('preview_order')) {
    return null
  }

  return (
    <div className="chat-action-panel">
      {points.map((point, index) => (
        <input key={index} value={point} onChange={(event) => updatePoint(index, event.target.value)} placeholder={`Titik tambahan ${index + 1}`} />
      ))}
      <div className="chat-action-row">
        <button type="button" onClick={addPoint}>+ Tambah Titik</button>
        {!showSummary && <button type="button" onClick={() => setShowSummary(true)}>Preview Order</button>}
      </div>
      {showSummary && (
        <div className="final-preview-card">
          <strong>Summary final</strong>
          <p>{preview.reply}</p>
          <div className="payment-choice">
            {isOjekOrder && (
              <div className="ladies-choice">
                <span>Pilihan driver</span>
                <div>
                  <button type="button" className={selectedDriverPreference !== 'ladies' ? 'active' : ''} onClick={() => updateDriverPreference('general')}>Umum</button>
                  <button type="button" className={selectedDriverPreference === 'ladies' ? 'active ladies' : ''} onClick={() => updateDriverPreference('ladies')}>Ladies</button>
                </div>
                <small>{selectedDriverPreference === 'ladies' ? 'Order hanya dikirim ke driver Ladies area kamu.' : 'Order dapat diterima driver area yang tersedia.'}</small>
              </div>
            )}
            <label>
              <span>Pilih kendaraan</span>
              <select value={selectedVehicle} onChange={(event) => updateVehicle(event.target.value as 'motor' | 'mobil')}>
                <option value="motor">Motor</option>
                <option value="mobil">Mobil</option>
              </select>
            </label>
            <small>{selectedVehicle === 'mobil' ? 'Order akan diberi catatan prioritas driver mobil.' : 'Default untuk ojek, delivery, kurir, dan belanja ringan.'}</small>
            <label>
              <span>Metode pembayaran</span>
              <select value={selectedPayment} onChange={(event) => pendingOrder && onPendingOrderChange({ ...pendingOrder, payment_method: event.target.value })}>
                {paymentMethods.map((method) => <option key={method.key} value={method.key}>{method.label}</option>)}
              </select>
            </label>
            {selectedPaymentMethod?.description && <small>{selectedPaymentMethod.description}</small>}
          </div>
          {selectedPayment === 'transfer' && (
            <div className="payment-transfer-panel">
              {transferAccounts.length > 0 && (
                <div className="payment-bank-list">
                  {transferAccounts.map((account, index) => (
                    <button
                      key={`${account.bank}-${account.account_number}-${index}`}
                      type="button"
                      onClick={() => account.account_number && navigator.clipboard?.writeText(account.account_number).catch(() => undefined)}
                    >
                      <span>{account.bank || 'Bank'}</span>
                      <strong>{account.account_number || '-'}</strong>
                      <small>a.n. {account.account_name || 'JojoApp'}</small>
                    </button>
                  ))}
                </div>
              )}
              {qrisImageUrl && (
                <div className="payment-qris">
                  <span>QRIS Aplikasi</span>
                  <img src={qrisImageUrl} alt="QRIS pembayaran JojoApp" />
                </div>
              )}
              {transferAccounts.length === 0 && !qrisImageUrl && <p className="payment-account">Rekening transfer belum disetting admin.</p>}
            </div>
          )}
          {selectedPayment === 'qris' && (
            <div className="payment-transfer-panel">
              {qrisImageUrl ? (
                <div className="payment-qris">
                  <span>QRIS Aplikasi</span>
                  <img src={qrisImageUrl} alt="QRIS pembayaran JojoApp" />
                  <a href={qrisImageUrl} download target="_blank" rel="noreferrer">Download QRIS</a>
                </div>
              ) : <p className="payment-account">QRIS belum disetting admin.</p>}
            </div>
          )}
          <div className="payment-order-note">
            <span>Pembayaran order</span>
            <strong>{selectedPaymentMethod?.label ?? selectedPayment}</strong>
          </div>
          <div className="payment-order-note vehicle-note">
            <span>Kendaraan</span>
            <strong>{selectedVehicle === 'mobil' ? 'Mobil' : 'Motor'}</strong>
          </div>
          {isOjekOrder && (
            <div className="payment-order-note ladies-note">
              <span>Driver</span>
              <strong>{selectedDriverPreference === 'ladies' ? 'Ladies' : 'Umum'}</strong>
            </div>
          )}
          {submitBlocked && <p>Anda melebihi batas order aktif. Silakan selesaikan salah satu pesanan terlebih dahulu.</p>}
          {points.filter(Boolean).length > 0 && <p>{points.filter(Boolean).map((point, index) => `Titik ${index + 1}: ${point}`).join('\n')}</p>}
          <div>
            <button type="button" disabled={submitBlocked} onClick={onConfirm}>YA KIRIM</button>
            <button type="button" onClick={() => setShowSummary(false)}>EDIT</button>
          </div>
        </div>
      )}
    </div>
  )
}

function MessageBubble({ message, onCs, onOrderDetail, onImageClick, onReply }: { message: LocalMessage; onCs?: () => void; onOrderDetail?: (order: Order) => void; onImageClick?: (imageUrl: string) => void; onReply?: (reply: ReplyTarget) => void }) {
  const side = message.from === 'user' ? 'out' : 'in'
  const total = message.preview?.quote?.total_price ?? message.preview?.quote?.final_price
  const sharedLocation = parseSharedLocation(message.text)
  const replyText = message.text || (message.imageUrl ? 'Foto' : 'Pesan')

  return (
    <article className={`message-bubble ${side}`}>
      {message.imageUrl && <button className="chat-image-button" type="button" onClick={() => onImageClick?.(message.imageUrl!)}><img src={message.imageUrl} alt="Lampiran customer" /></button>}
      {sharedLocation ? <SharedLocationBubble location={sharedLocation} from={message.from} /> : message.text && <p>{message.text}</p>}
      {message.csLink && <button className="bubble-link" onClick={onCs}>Hubungi Operator</button>}
      {message.order && <button className="bubble-link order-detail-link" onClick={() => onOrderDetail?.(message.order!)}>Detail {message.order.order_code ?? `#${message.order.id}`}</button>}
      {total && <strong className="bubble-total">Total {formatRupiah(total)}</strong>}
      {onReply && <button className="bubble-reply" type="button" onClick={() => onReply({ id: message.id, text: replyText })}>Balas</button>}
      <time>{message.time}</time>
    </article>
  )
}

function SharedLocationBubble({ location, from }: { location: SharedLocation; from: LocalMessage['from'] }) {
  const title = from === 'driver'
    ? 'Lokasi driver'
    : from === 'user'
      ? 'Lokasi customer'
      : 'Lokasi dibagikan'

  return (
    <div className="shared-location-card">
      <div className="shared-location-icon"><MapPin size={20} /></div>
      <div>
        <strong>{title}</strong>
        <p>{location.label}</p>
        <a href={location.url} target="_blank" rel="noreferrer" onClick={(event) => event.stopPropagation()}>
          Buka Google Maps
        </a>
      </div>
    </div>
  )
}

function InputBar({ onSend, onImage, onLocation, replyTarget, onClearReply }: { onSend: (text: string) => void | Promise<void>; onImage: (file: File, caption?: string) => void | Promise<void>; onLocation?: () => void | Promise<void>; replyTarget?: ReplyTarget | null; onClearReply?: () => void }) {
  const store = useCustomerStore()
  const [text, setText] = useState('')
  const [sending, setSending] = useState(false)
  const [attachmentOpen, setAttachmentOpen] = useState(false)
  const [draftImage, setDraftImage] = useState<{ file: File; url: string } | null>(null)
  const textareaRef = useRef<HTMLTextAreaElement | null>(null)
  const galleryInputRef = useRef<HTMLInputElement | null>(null)
  const cameraInputRef = useRef<HTMLInputElement | null>(null)
  const hasText = text.trim().length > 0

  const submit = async (event?: FormEvent) => {
    event?.preventDefault()
    if (!hasText || sending) return
    const value = withReplyPrefix(text, replyTarget)
    setSending(true)
    try {
      await onSend(value)
      setText('')
      onClearReply?.()
      setAttachmentOpen(false)
      requestAnimationFrame(() => textareaRef.current && autoResizeTextarea(textareaRef.current))
    } finally {
      setSending(false)
    }
  }

  const sendLocation = async () => {
    if (sending) return
    setAttachmentOpen(false)
    if (onLocation) {
      await onLocation()
      return
    }
    if (!navigator.geolocation) {
      store.showToast('error', 'GPS tidak tersedia di perangkat ini.')
      return
    }

    setSending(true)
    try {
      const position = await new Promise<GeolocationPosition>((resolve, reject) => {
        navigator.geolocation.getCurrentPosition(resolve, reject, {
          enableHighAccuracy: true,
          timeout: 10000,
          maximumAge: 0,
        })
      })
      await onSend(withReplyPrefix(`Lokasi customer saat ini:\nhttps://www.google.com/maps?q=${position.coords.latitude},${position.coords.longitude}`, replyTarget))
      onClearReply?.()
      store.showToast('success', 'Lokasi terkirim.')
    } catch (error) {
      store.showToast('error', getApiErrorMessage(error, 'Gagal mengambil lokasi. Pastikan GPS aktif.'))
    } finally {
      setSending(false)
    }
  }

  const sendContact = async () => {
    const user = store.user
    const phone = user?.phone || 'Nomor belum tersedia'
    setAttachmentOpen(false)
    setSending(true)
    try {
      await onSend(withReplyPrefix(`Kontak customer:\n${user?.name ?? 'Customer Jojo'}\n${phone}`, replyTarget))
      onClearReply?.()
    } finally {
      setSending(false)
    }
  }

  return (
    <form className={`input-bar ${hasText ? 'is-typing' : ''}`} onSubmit={submit}>
      {attachmentOpen && (
        <AttachmentPanel
          onGallery={() => galleryInputRef.current?.click()}
          onCamera={() => cameraInputRef.current?.click()}
          onLocation={() => void sendLocation()}
          onContact={() => void sendContact()}
        />
      )}
      <input ref={galleryInputRef} type="file" accept="image/*" hidden onChange={(event) => {
        const file = event.target.files?.[0]
        if (file) setDraftImage({ file, url: URL.createObjectURL(file) })
        event.currentTarget.value = ''
      }} />
      <input ref={cameraInputRef} type="file" accept="image/*" capture="environment" hidden onChange={(event) => {
        const file = event.target.files?.[0]
        if (file) setDraftImage({ file, url: URL.createObjectURL(file) })
        event.currentTarget.value = ''
      }} />
      {replyTarget && (
        <div className="reply-preview">
          <span>Balas</span>
          <strong>{replyTarget.text}</strong>
          <button type="button" onClick={onClearReply}>x</button>
        </div>
      )}
      <div className="input-pill">
        <button className="input-icon attachment-trigger" type="button" disabled={sending} onClick={() => setAttachmentOpen((open) => !open)} aria-label="Lampiran">
          <Paperclip size={23} />
        </button>
        <textarea
          ref={textareaRef}
          value={text}
          onChange={(event) => {
            setText(event.target.value)
            autoResizeTextarea(event.currentTarget)
          }}
          onKeyDown={(event) => {
            if (event.key !== 'Enter' || event.ctrlKey || event.metaKey) {
              if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
                event.preventDefault()
                void submit()
              }
              return
            }
            event.preventDefault()
            const target = event.currentTarget
            const start = target.selectionStart
            const end = target.selectionEnd
            const nextText = `${text.slice(0, start)}\n${text.slice(end)}`
            setText(nextText)
            requestAnimationFrame(() => {
              target.selectionStart = start + 1
              target.selectionEnd = start + 1
              autoResizeTextarea(target)
            })
          }}
          enterKeyHint="enter"
          rows={1}
          placeholder="Ketik pesan"
        />
        <VoiceRecorder onTranscript={(value) => setText(value)} hidden={hasText} compact />
      </div>
      <button className="send-button" aria-label="Kirim" disabled={!hasText || sending} aria-busy={sending}>
        {sending ? <span className="send-spinner" /> : <SendHorizontal size={25} />}
      </button>
      {draftImage && (
        <ImageEditorModal
          imageUrl={draftImage.url}
          onClose={() => {
            URL.revokeObjectURL(draftImage.url)
            setDraftImage(null)
          }}
          onSend={async (file, caption) => {
            setSending(true)
            try {
              await onImage(file, withReplyPrefix(caption || draftImage.file.name, replyTarget))
              onClearReply?.()
              URL.revokeObjectURL(draftImage.url)
              setDraftImage(null)
            } finally {
              setSending(false)
            }
          }}
        />
      )}
    </form>
  )
}

function AttachmentPanel({ onGallery, onCamera, onLocation, onContact }: { onGallery: () => void; onCamera: () => void; onLocation: () => void; onContact: () => void }) {
  return (
    <div className="attachment-panel">
      <button type="button" onClick={onGallery}><span className="gallery"><ImageIcon size={27} /></span><b>Galeri</b></button>
      <button type="button" onClick={onCamera}><span className="camera"><Camera size={27} /></span><b>Kamera</b></button>
      <button type="button" onClick={onLocation}><span className="location"><MapPin size={27} /></span><b>Lokasi</b></button>
      <button type="button" onClick={onContact}><span className="contact"><UserRound size={27} /></span><b>Kontak</b></button>
    </div>
  )
}

function ImageEditorModal({ imageUrl, onClose, onSend }: { imageUrl: string; onClose: () => void; onSend: (file: File, caption: string) => void | Promise<void> }) {
  const canvasRef = useRef<HTMLCanvasElement | null>(null)
  const drawingRef = useRef(false)
  const [caption, setCaption] = useState('')

  useEffect(() => {
    const canvas = canvasRef.current
    if (!canvas) return
    const context = canvas.getContext('2d')
    const image = new Image()
    image.onload = () => {
      const maxWidth = 900
      const scale = Math.min(1, maxWidth / image.width)
      canvas.width = Math.max(1, Math.round(image.width * scale))
      canvas.height = Math.max(1, Math.round(image.height * scale))
      context?.drawImage(image, 0, 0, canvas.width, canvas.height)
    }
    image.src = imageUrl
  }, [imageUrl])

  const draw = (event: React.PointerEvent<HTMLCanvasElement>) => {
    const canvas = canvasRef.current
    const context = canvas?.getContext('2d')
    if (!canvas || !context || !drawingRef.current) return
    const rect = canvas.getBoundingClientRect()
    context.lineWidth = 7
    context.lineCap = 'round'
    context.strokeStyle = '#ef1f1f'
    context.lineTo((event.clientX - rect.left) * (canvas.width / rect.width), (event.clientY - rect.top) * (canvas.height / rect.height))
    context.stroke()
  }

  const send = async () => {
    const canvas = canvasRef.current
    if (!canvas) return
    const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.9))
    if (!blob) return
    await onSend(new File([blob], `jojo-chat-${Date.now()}.jpg`, { type: 'image/jpeg' }), caption.trim())
  }

  return (
    <div className="image-editor-backdrop">
      <div className="image-editor">
        <div className="image-editor-toolbar">
          <button type="button" onClick={onClose}>x</button>
          <strong>Edit gambar</strong>
          <button type="button" onClick={() => void send()}><SendHorizontal size={22} /></button>
        </div>
        <canvas
          ref={canvasRef}
          onPointerDown={(event) => {
            drawingRef.current = true
            const context = event.currentTarget.getContext('2d')
            const rect = event.currentTarget.getBoundingClientRect()
            context?.beginPath()
            context?.moveTo((event.clientX - rect.left) * (event.currentTarget.width / rect.width), (event.clientY - rect.top) * (event.currentTarget.height / rect.height))
          }}
          onPointerMove={draw}
          onPointerUp={() => { drawingRef.current = false }}
          onPointerLeave={() => { drawingRef.current = false }}
        />
        <input value={caption} onChange={(event) => setCaption(event.target.value)} placeholder="Tambah keterangan..." />
      </div>
    </div>
  )
}

function VoiceRecorder({ onTranscript, compact = false, hidden = false }: { onTranscript: (text: string) => void; compact?: boolean; hidden?: boolean }) {
  const [listening, setListening] = useState(false)
  const recognitionRef = useRef<BrowserSpeechRecognition | null>(null)

  const start = () => {
    const SpeechRecognitionApi = window.SpeechRecognition ?? window.webkitSpeechRecognition
    if (!SpeechRecognitionApi) {
      onTranscript('Voice tidak didukung browser ini')
      return
    }

    const recognition = new SpeechRecognitionApi()
    recognition.lang = 'id-ID'
    recognition.interimResults = false
    recognition.onresult = (event: BrowserSpeechRecognitionEvent) => {
      onTranscript(event.results[0]?.[0]?.transcript ?? '')
    }
    recognition.onend = () => setListening(false)
    recognitionRef.current = recognition
    setListening(true)
    recognition.start()
  }

  return (
    <button type="button" className={`${compact ? 'input-icon voice-inline' : 'mic-button'} ${listening ? 'recording' : ''} ${hidden ? 'is-hidden' : ''}`} onClick={start} aria-label="Voice input" tabIndex={hidden ? -1 : 0} aria-hidden={hidden}>
      <Mic size={compact ? 25 : 28} />
    </button>
  )
}

function TypingIndicator() {
  return (
    <div className="typing-indicator">
      <span />
      <span />
      <span />
    </div>
  )
}

function FallbackForm({ onSend }: { onSend: (text: string) => void }) {
  const [form, setForm] = useState({ name: '', phone: '', pickup: '', destination: '' })
  const [points, setPoints] = useState<string[]>([])

  return (
    <form
      className="fallback-form"
      onSubmit={(event) => {
        event.preventDefault()
        const pointText = points.map((point, index) => `Titik ${index + 1}: ${point}`).join('\n')
        onSend(`Nama: ${form.name}\nNo. Hp: ${form.phone}\nAlamat jemput: ${form.pickup}\nAlamat tujuan: ${form.destination}${pointText ? `\n${pointText}` : ''}`)
      }}
    >
      <input placeholder="Nama" value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} />
      <input placeholder="No. Hp" value={form.phone} onChange={(event) => setForm({ ...form, phone: event.target.value })} />
      <input placeholder="Alamat jemput" value={form.pickup} onChange={(event) => setForm({ ...form, pickup: event.target.value })} />
      <input placeholder="Alamat tujuan" value={form.destination} onChange={(event) => setForm({ ...form, destination: event.target.value })} />
      {points.map((point, index) => (
        <input
          key={index}
          placeholder={`Titik tambahan ${index + 1}`}
          value={point}
          onChange={(event) => setPoints(points.map((item, itemIndex) => itemIndex === index ? event.target.value : item))}
        />
      ))}
      <button type="button" className="add-point-button" disabled={points.length >= 5} onClick={() => setPoints([...points, ''])}>
        + Tambah titik
      </button>
      <button>Preview order</button>
    </form>
  )
}

function DriverChatScreen({ order }: { order: Order | null }) {
  const store = useCustomerStore()
  const [messages, setMessages] = useState<ChatMessage[]>([])
  const [replyTarget, setReplyTarget] = useState<ReplyTarget | null>(null)
  const [previewImage, setPreviewImage] = useState<string | null>(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const listRef = useRef<HTMLDivElement | null>(null)

  const loadMessages = useCallback(async (silent = false) => {
    if (!order?.id) return
    if (!silent) setLoading(true)
    setError('')
    try {
      const response = await fetchOrderMessages(order.id)
      setMessages((current) => mergeRemoteChatMessages(current, response.data))
    } catch (err) {
      setError(getApiErrorMessage(err, 'Chat driver belum tersedia.'))
    } finally {
      if (!silent) setLoading(false)
    }
  }, [order?.id])

  useEffect(() => {
    void loadMessages()
  }, [loadMessages])

  useEffect(() => {
    if (!order?.id) return
    const interval = window.setInterval(() => void loadMessages(true), 8000)
    return () => window.clearInterval(interval)
  }, [loadMessages, order?.id])

  useEffect(() => {
    if (!order?.id) return
    const channel = getEcho().private(`chat.order.${order.id}`)
    channel.listen('.message.sent', (event: { message?: ChatMessage }) => {
      const incoming = event.message
      if (!incoming) return
      setMessages((rows) => rows.some((row) => String(row.id) === String(incoming.id)) ? rows : [...rows, incoming])
    })
    return () => {
      getEcho().leave(`chat.order.${order.id}`)
    }
  }, [order?.id])

  useEffect(() => {
    listRef.current?.scrollTo({ top: listRef.current.scrollHeight, behavior: 'smooth' })
  }, [messages.length, loading, error])

  const sendDriverChat = async (text: string, image?: File | null) => {
    if (!order?.id) return
    try {
      const response = await sendOrderMessage(order.id, { message: text, image })
      setMessages((rows) => rows.some((row) => String(row.id) === String(response.data.id)) ? rows : [...rows, response.data])
    } catch (err) {
      store.showToast('error', getApiErrorMessage(err, 'Pesan gagal dikirim'))
      throw err
    }
  }

  const shareCustomerLocation = async () => {
    if (!navigator.geolocation) {
      store.showToast('error', 'GPS tidak tersedia di perangkat ini.')
      return
    }

    try {
      const position = await new Promise<GeolocationPosition>((resolve, reject) => {
        navigator.geolocation.getCurrentPosition(resolve, reject, {
          enableHighAccuracy: true,
          timeout: 10000,
          maximumAge: 0,
        })
      })
      const { latitude, longitude } = position.coords
      await sendDriverChat(`Lokasi customer saat ini:\nhttps://www.google.com/maps?q=${latitude},${longitude}`)
      store.showToast('success', 'Lokasi terkirim ke driver.')
    } catch (error) {
      store.showToast('error', getApiErrorMessage(error, 'Gagal mengambil lokasi. Pastikan GPS aktif.'))
    }
  }

  return (
    <div className="driver-chat">
      <div className="chat-date">{todayLabel()}</div>
      <div className="message-list" ref={listRef}>
        {!order && <MessageBubble message={{ id: 'no-order', from: 'system', text: 'Belum ada order yang diterima driver.', time: nowTime() }} />}
        {loading && <TypingIndicator />}
        {error && <MessageBubble message={{ id: 'driver-chat-error', from: 'system', text: error, time: nowTime() }} />}
        {!loading && !error && order && messages.length === 0 && (
          <MessageBubble
            message={{
              id: 'driver-welcome',
              from: 'driver',
              text: `Order ${order.order_code ?? `#${order.id}`} sudah diterima driver. Silakan mulai chat.`,
              time: nowTime(),
            }}
          />
        )}
        {messages.map((message) => {
          const from = message.sender_type === 'driver' ? 'driver' : message.sender_type === 'customer' || message.sender_id === store.user?.id ? 'user' : 'system'
          return (
            <MessageBubble
              key={message.id}
              message={{
                id: String(message.id),
                from,
                text: message.message ?? message.text,
                imageUrl: message.image_url ? assetUrl(message.image_url) : undefined,
                time: formatMessageTime(message.created_at),
              }}
              onImageClick={setPreviewImage}
              onReply={(reply) => setReplyTarget(reply)}
            />
          )
        })}
      </div>
      <InputBar onSend={(text) => void sendDriverChat(text)} onImage={(file, caption) => void sendDriverChat(caption || file.name, file)} onLocation={shareCustomerLocation} replyTarget={replyTarget} onClearReply={() => setReplyTarget(null)} />
      {previewImage && <ImagePreviewModal imageUrl={previewImage} onClose={() => setPreviewImage(null)} />}
    </div>
  )
}

function CsChatScreen() {
  const store = useCustomerStore()
  const [conversationId, setConversationId] = useState<number | null>(null)
  const [conversation, setConversation] = useState<ChatConversation | null>(null)
  const [messages, setMessages] = useState<ChatMessage[]>([])
  const [replyTarget, setReplyTarget] = useState<ReplyTarget | null>(null)
  const [previewImage, setPreviewImage] = useState<string | null>(null)
  const [selectedOrder, setSelectedOrder] = useState<Order | null>(null)
  const [cancelReason, setCancelReason] = useState('')
  const [cancelLoading, setCancelLoading] = useState(false)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const listRef = useRef<HTMLDivElement | null>(null)
  const cancellableOrders = store.orders.filter((order) => !['cancelled', 'CANCELLED', 'completed', 'COMPLETED', 'pending_cancel', 'PENDING_CANCEL'].includes(String(order.status)))

  const loadCsMessages = useCallback(async (silent = false) => {
    if (!silent) setLoading(true)
    setError('')
    try {
      const conversation = conversationId
        ? { id: conversationId }
        : await startOperatorChat()
      if (!conversationId) setConversationId(conversation.id)
      const latest = await fetchChatMessages(conversation.id)
      if (latest.conversation) setConversation(latest.conversation)
      setMessages((current) => mergeRemoteChatMessages(current, latest.messages))
    } catch (err) {
      setError(getApiErrorMessage(err, 'Hubungi Operator belum bisa dibuka.'))
    } finally {
      if (!silent) setLoading(false)
    }
  }, [conversationId])

  useEffect(() => {
    void loadCsMessages()
  }, [loadCsMessages])

  useEffect(() => {
    if (!conversationId) return
    const interval = window.setInterval(() => void loadCsMessages(true), 8000)
    return () => window.clearInterval(interval)
  }, [conversationId, loadCsMessages])

  useEffect(() => {
    if (!conversationId) return
    const channel = getEcho().private(`chat.${conversationId}`)
    channel.listen('.message.sent', (event: { message?: ChatMessage }) => {
      const incoming = event.message
      if (!incoming) return
      setMessages((rows) => rows.some((row) => String(row.id) === String(incoming.id)) ? rows : [...rows, incoming])
    })
    return () => {
      getEcho().leave(`chat.${conversationId}`)
    }
  }, [conversationId])

  useEffect(() => {
    listRef.current?.scrollTo({ top: listRef.current.scrollHeight, behavior: 'smooth' })
  }, [messages.length, loading, error, selectedOrder?.id])

  const sendCsChat = async (text: string, image?: File | null) => {
    if (!conversationId) return
    try {
      const message = await sendChatMessage(conversationId, { message: text, image })
      setMessages((rows) => rows.some((row) => String(row.id) === String(message.id)) ? rows : [...rows, message])
    } catch (err) {
      store.showToast('error', getApiErrorMessage(err, 'Pesan CS gagal dikirim'))
      throw err
    }
  }

  const submitCancelRequest = async () => {
    if (!selectedOrder || !cancelReason.trim()) return
    setCancelLoading(true)
    try {
      await requestCancelOrder(selectedOrder.id, { reason: cancelReason.trim(), chat_conversation_id: conversationId })
      store.showToast('success', 'Request cancel dikirim ke Operator/SPV')
      store.setOrders(await fetchOrders())
      setCancelReason('')
    } catch (err) {
      store.showToast('error', getApiErrorMessage(err, 'Request cancel gagal dikirim'))
    } finally {
      setCancelLoading(false)
    }
  }

  return (
    <div className="cs-chat">
      <div className="chat-date">{todayLabel()}</div>
      <div className="message-list" ref={listRef}>
        {conversation?.rating_requested && !conversation.operator_rating && (
          <OperatorRatingCard
            onRate={async (rating) => {
              if (!conversationId) return
              const updated = await submitOperatorRating(conversationId, { rating })
              setConversation(updated)
              store.showToast('success', 'Rating operator tersimpan. Terima kasih.')
            }}
          />
        )}
        {cancellableOrders.length > 0 && (
          <div className="cs-order-picker">
            <strong>Pilih kode order</strong>
            <div>
              {cancellableOrders.map((order) => (
                <button key={order.id} className={selectedOrder?.id === order.id ? 'active' : ''} onClick={() => setSelectedOrder(order)}>
                  {order.order_code ?? order.code ?? `#${order.id}`}
                </button>
              ))}
            </div>
          </div>
        )}
        {selectedOrder && (
          <div className="cancel-order-card">
            <div>
              <strong>{selectedOrder.order_code ?? selectedOrder.code ?? `#${selectedOrder.id}`}</strong>
              <span>{selectedOrder.pickup_address ?? 'Pickup'} ke {selectedOrder.destination_address ?? 'Tujuan'}</span>
            </div>
            <textarea value={cancelReason} onChange={(event) => setCancelReason(event.target.value)} placeholder="Alasan cancel order" />
            <button disabled={cancelLoading || !cancelReason.trim()} onClick={() => void submitCancelRequest()}>
              {cancelLoading ? 'Mengirim...' : 'Cancel Order'}
            </button>
          </div>
        )}
        {loading && <TypingIndicator />}
        {error && <MessageBubble message={{ id: 'cs-error', from: 'system', text: error, time: nowTime() }} />}
        {!loading && !error && messages.length === 0 && (
          <div className="cs-topic-grid">
            {['Order berjalan', 'Pembayaran', 'Promo', 'Komplain driver'].map((topic) => (
              <button key={topic} onClick={() => void sendCsChat(topic)}>{topic}</button>
            ))}
          </div>
        )}
        {messages.map((message) => {
          const from = message.sender_type === 'customer' || message.sender_id === store.user?.id ? 'user' : 'system'
          return (
            <MessageBubble
              key={message.id}
              message={{
                id: String(message.id),
                from,
                text: message.message ?? message.text,
                imageUrl: message.image_url ? assetUrl(message.image_url) : undefined,
                time: formatMessageTime(message.created_at),
              }}
              onImageClick={setPreviewImage}
              onReply={(reply) => setReplyTarget(reply)}
            />
          )
        })}
      </div>
      <InputBar onSend={(text) => void sendCsChat(text)} onImage={(file, caption) => void sendCsChat(caption || file.name, file)} replyTarget={replyTarget} onClearReply={() => setReplyTarget(null)} />
      {previewImage && <ImagePreviewModal imageUrl={previewImage} onClose={() => setPreviewImage(null)} />}
    </div>
  )
}

function OperatorRatingCard({ onRate }: { onRate: (rating: number) => Promise<void> }) {
  const [saving, setSaving] = useState(false)
  const [selected, setSelected] = useState(0)

  const rate = async (rating: number) => {
    if (saving) return
    setSelected(rating)
    setSaving(true)
    try {
      await onRate(rating)
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="operator-rating-card">
      <strong>Beri rating operator</strong>
      <span>Nilai bantuan operator pada sesi chat ini.</span>
      <div>
        {[1, 2, 3, 4, 5].map((rating) => (
          <button key={rating} type="button" disabled={saving} className={rating <= selected ? 'active' : ''} onClick={() => void rate(rating)}>
            ★
          </button>
        ))}
      </div>
    </div>
  )
}

function ImagePreviewModal({ imageUrl, onClose }: { imageUrl: string; onClose: () => void }) {
  return (
    <div className="image-editor-backdrop" onClick={onClose}>
      <div className="image-preview" onClick={(event) => event.stopPropagation()}>
        <button type="button" onClick={onClose}>x</button>
        <img src={imageUrl} alt="Preview lampiran" />
      </div>
    </div>
  )
}

function OrderDetailModal({ order, onClose }: { order: Order; onClose: () => void }) {
  const driverName = driverNameFromOrder(order)
  const purchaseOrder = isPurchaseOrder(order)
  const pickupLabel = purchaseOrder ? 'Pembelian' : 'Jemput'
  const destinationLabel = purchaseOrder ? 'Alamat Antar' : 'Tujuan'

  return (
    <div className="mini-modal-backdrop" onClick={onClose}>
      <section className="mini-modal" onClick={(event) => event.stopPropagation()}>
        <header>
          <div>
            <span>Kode Order</span>
            <strong>{order.order_code ?? order.code ?? `#${order.id}`}</strong>
          </div>
          <button onClick={onClose}>x</button>
        </header>
        <div className="order-detail-list">
          <p><span>Layanan</span><strong>{String(order.service_type ?? order.service ?? '-')}</strong></p>
          <p><span>Tanggal</span><strong>{formatOrderDate(order.created_at)} {formatOrderTime(order.created_at)}</strong></p>
          <p><span>Status</span><strong>{String(order.status)}</strong></p>
          <p><span>{pickupLabel}</span><strong>{order.pickup_address ?? '-'}</strong></p>
          <p><span>{destinationLabel}</span><strong>{order.destination_address ?? '-'}</strong></p>
          <p><span>Driver</span><strong>Nama: {driverName}</strong></p>
          <p><span>Pembayaran</span><strong>{order.payment_label ?? paymentMethodLabel(order.payment_method)}</strong></p>
          <p><span>Total</span><strong>{formatRupiah(order.total_price ?? order.total)}</strong></p>
        </div>
      </section>
    </div>
  )
}

function OrderClosedModal({ message, onClose }: { message: string; onClose: () => void }) {
  return (
    <div className="mini-modal-backdrop" onClick={onClose}>
      <section className="mini-modal order-closed-modal" onClick={(event) => event.stopPropagation()}>
        <header>
          <div>
            <span>Sistem Order Tutup</span>
            <strong>Order belum bisa dibuat</strong>
          </div>
          <button onClick={onClose}>x</button>
        </header>
        <div className="order-detail-list">
          <p><span>Info</span><strong>{message}</strong></p>
        </div>
        <button className="primary-action" type="button" onClick={onClose}>Mengerti</button>
      </section>
    </div>
  )
}

function HistoryScreen({
  orders,
  onOpenDriverChat,
  onOrdersChanged,
}: {
  orders: Order[]
  onOpenDriverChat: (order: Order) => void
  onOrdersChanged: (orders: Order[]) => void
}) {
  const store = useCustomerStore()
  const [detailOrder, setDetailOrder] = useState<Order | null>(null)
  const [selectedMonth, setSelectedMonth] = useState(() => monthKey(new Date().toISOString()))
  const sortedOrders = useMemo(() => [...orders].sort((first, second) => {
    const firstTime = first.created_at ? new Date(first.created_at).getTime() : 0
    const secondTime = second.created_at ? new Date(second.created_at).getTime() : 0

    return secondTime - firstTime || second.id - first.id
  }), [orders])
  const monthOptions = useMemo(() => {
    const keys = new Set(sortedOrders.map((order) => monthKey(order.created_at)).filter(Boolean))
    recentMonthKeys(12).forEach((key) => keys.add(key))

    return [...keys].sort().reverse()
  }, [sortedOrders])
  const visibleOrders = sortedOrders.filter((order) => monthKey(order.created_at) === selectedMonth)

  useEffect(() => {
    if (monthOptions.length > 0 && !monthOptions.includes(selectedMonth)) {
      setSelectedMonth(monthOptions[0])
    }
  }, [monthOptions, selectedMonth])

  useEffect(() => {
    void fetchOrders(100, selectedMonth)
      .then((nextOrders) => onOrdersChanged(nextOrders))
      .catch(() => undefined)
  }, [selectedMonth])

  return (
    <div className="simple-page">
      <div className="history-head">
        <div>
          <h1>History Order</h1>
          <p>{visibleOrders.length} order pada {formatMonthLabel(selectedMonth)}</p>
        </div>
        <select value={selectedMonth} onChange={(event) => setSelectedMonth(event.target.value)}>
          {monthOptions.map((key) => <option key={key} value={key}>{formatMonthLabel(key)}</option>)}
        </select>
      </div>
      {orders.length === 0 && <p>Belum ada order.</p>}
      {orders.length > 0 && visibleOrders.length === 0 && <p>Tidak ada order pada periode ini.</p>}
      {visibleOrders.map((order) => (
        <article className="history-row" key={order.id}>
          <button className="history-row-main" type="button" onClick={() => setDetailOrder(order)}>
            <div className="history-time">
              <strong>{formatOrderTime(order.created_at)}</strong>
              <span>{formatOrderDate(order.created_at)}</span>
            </div>
            <div className="history-detail">
              <span>{order.order_code ?? `#${order.id}`}</span>
              <small>{order.pickup_address ?? 'Pickup'} ke {order.destination_address ?? 'Tujuan'}</small>
            </div>
            <div className="history-price">
              <strong>{formatRupiah(order.total_price ?? order.total)}</strong>
              <small>{statusLabel(order.status)}</small>
            </div>
          </button>
          <div className="history-actions">
            {isAcceptedOrder(order) && (
              <button className="history-action chat" type="button" onClick={() => onOpenDriverChat(order)}>
                Chat Driver
              </button>
            )}
            {isCompletedStatus(order.status) && (
              <HistoryRating
                order={order}
                onRated={async () => {
                  const nextOrders = await fetchOrders()
                  onOrdersChanged(nextOrders)
                }}
                onError={(message) => store.showToast('error', message)}
                onSuccess={(message) => store.showToast('success', message)}
              />
            )}
          </div>
        </article>
      ))}
      {detailOrder && <OrderDetailModal order={detailOrder} onClose={() => setDetailOrder(null)} />}
    </div>
  )
}

function HistoryRating({
  order,
  onRated,
  onError,
  onSuccess,
}: {
  order: Order
  onRated: () => Promise<void>
  onError: (message: string) => void
  onSuccess: (message: string) => void
}) {
  const [selected, setSelected] = useState(order.rating?.rating ?? 0)
  const [saving, setSaving] = useState(false)
  const savedRating = order.rating?.rating

  const rate = async (rating: number) => {
    if (saving) return
    setSelected(rating)
    setSaving(true)
    try {
      await submitOrderRating(order.id, { rating })
      await onRated()
      onSuccess('Rating driver tersimpan. Terima kasih.')
    } catch (error) {
      setSelected(savedRating ?? 0)
      onError(getApiErrorMessage(error, 'Rating gagal disimpan.'))
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="history-rating" aria-label={`Rating order ${order.order_code ?? order.id}`}>
      <span>{savedRating ? 'Rating Anda' : 'Beri rating'}</span>
      <div>
        {[1, 2, 3, 4, 5].map((rating) => (
          <button
            key={rating}
            type="button"
            disabled={saving}
            className={rating <= selected ? 'star active' : 'star'}
            aria-label={`${rating} bintang`}
            onClick={() => void rate(rating)}
          >
            ★
          </button>
        ))}
      </div>
    </div>
  )
}

function ProfileScreen({ setupMode = false, onDone }: { setupMode?: boolean; onDone?: () => void }) {
  const store = useCustomerStore()
  const user = store.user
  const [name, setName] = useState(user?.name ?? '')
  const [phone, setPhone] = useState(user?.phone ?? '')
  const [address, setAddress] = useState(user?.address ?? '')
  const [profilePhoto, setProfilePhoto] = useState<File | null>(null)
  const [profilePhotoPreview, setProfilePhotoPreview] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    setName(user?.name ?? '')
    setPhone(user?.phone ?? '')
    setAddress(user?.address ?? '')
  }, [user?.address, user?.name, user?.phone])

  return (
    <div className="simple-page">
      <div className="profile-avatar">
        {profilePhotoPreview || user?.profile_photo_url ? <img src={profilePhotoPreview ?? cmsAssetUrl(user?.profile_photo_url ?? '')} alt="Foto profile" /> : <UserRound size={34} />}
      </div>
      <h1>{user?.name ?? 'Customer Jojo'}</h1>
      <p>{user?.email ?? 'Belum login'}</p>
      <p>{user?.phone ?? 'Nomor HP belum diisi'}</p>
      {setupMode && <p>Nama, Phone, dan Alamat wajib diisi sebelum membuat order.</p>}
      <form
        className="profile-edit-form"
        onSubmit={async (event) => {
          event.preventDefault()
          setSaving(true)
          try {
            const updated = await updateProfile({ name, phone, address, profile_photo: profilePhoto })
            store.setUserSession(updated, store.token)
            if (profilePhotoPreview) URL.revokeObjectURL(profilePhotoPreview)
            setProfilePhoto(null)
            setProfilePhotoPreview(null)
            store.showToast('success', 'Profile tersimpan')
            window.history.replaceState({}, document.title, '/')
            onDone?.()
          } catch (error) {
            store.showToast('error', getApiErrorMessage(error, 'Profile gagal disimpan'))
          } finally {
            setSaving(false)
          }
        }}
      >
        <h2>Edit Profile</h2>
        <label>
          Foto Profile
          <input type="file" accept="image/*" onChange={(event) => {
            const file = event.target.files?.[0] ?? null
            if (profilePhotoPreview) URL.revokeObjectURL(profilePhotoPreview)
            setProfilePhoto(file)
            setProfilePhotoPreview(file ? URL.createObjectURL(file) : null)
          }} />
        </label>
        <label>
          Nama
          <input value={name} onChange={(event) => setName(event.target.value)} />
        </label>
        <label>
          Nomor HP
          <input value={phone} onChange={(event) => setPhone(event.target.value)} />
        </label>
        <label>
          Alamat
          <textarea value={address} onChange={(event) => setAddress(event.target.value)} />
        </label>
        <details className="profile-address-note">
          <summary>
            <ShieldCheck size={16} />
            <span>Info alamat & GPS</span>
          </summary>
          <p>Alamat ini hanya untuk display dan tujuan. Lokasi validasi tetap memakai GPS yang tersimpan.</p>
        </details>
        <button disabled={saving || !name.trim() || !phone.trim() || !address.trim()}>{saving ? 'Menyimpan...' : 'Simpan Profile'}</button>
      </form>
    </div>
  )
}

function CustomerLoginScreen({ onDone }: { onDone: () => void }) {
  const store = useCustomerStore()
  const [mode, setMode] = useState<'login' | 'register'>('login')
  const [name, setName] = useState('')
  const [phone, setPhone] = useState('')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [loading, setLoading] = useState(false)
  const [gpsStatus, setGpsStatus] = useState('')
  const [locationLoading, setLocationLoading] = useState(false)
  const [locationError, setLocationError] = useState('')
  const [registrationLocation, setRegistrationLocation] = useState<RegistrationLocation | null>(null)

  useEffect(() => {
    if (mode !== 'register') return

    let active = true
    setLocationLoading(true)
    setLocationError('')
    setGpsStatus('Mendeteksi lokasi Anda...')

    void getRegistrationLocation(setGpsStatus)
      .then((location) => {
        if (!active) return
        setRegistrationLocation(location)
        if (!location) setLocationError('Aktifkan GPS untuk melanjutkan')
      })
      .finally(() => {
        if (active) setLocationLoading(false)
      })

    return () => {
      active = false
    }
  }, [mode])

  return (
    <div className="simple-page login-page">
      <div className="login-heading">
        <h1>Login</h1>
      </div>
      <PwaInstallButton />
      <div className="login-tabs">
        <button className={mode === 'login' ? 'active' : ''} onClick={() => setMode('login')} type="button">Login</button>
        <button className={mode === 'register' ? 'active' : ''} onClick={() => setMode('register')} type="button">Register</button>
      </div>
      <form
        className="profile-edit-form"
        onSubmit={async (event) => {
          event.preventDefault()
          if (mode === 'register' && !registrationLocation) {
            setLocationError('Aktifkan GPS untuk melanjutkan')
            store.showToast('error', 'Aktifkan GPS untuk melanjutkan register.')
            return
          }
          setLoading(true)
          try {
            const response = mode === 'login'
              ? await login({ email, password })
              : await register({
                name,
                phone,
                email,
                username: email,
                password,
                password_confirmation: password,
                ...(registrationLocation as RegistrationLocation),
              })
            store.setUserSession(response.user, response.token)
            window.history.replaceState({}, document.title, '/')
            if (mode === 'register' && !response.user.branch_id) {
              store.showToast('info', 'Area cabang belum diset silahkan hubungi CS')
            }
            onDone()
          } catch (error) {
            store.showToast('error', mode === 'login' ? getLoginErrorMessage(error) : getApiErrorMessage(error, 'Register gagal'))
          } finally {
            setLoading(false)
          }
        }}
      >
        {mode === 'register' && (
          <>
            <label>
              Nama
              <input value={name} onChange={(event) => setName(event.target.value)} required />
            </label>
            <label>
              Nomor HP
              <input value={phone} onChange={(event) => setPhone(event.target.value)} required />
            </label>
            <div className="gps-register-note">
              {locationLoading
                ? 'Mendeteksi lokasi Anda...'
                : locationError || gpsStatus || 'Lokasi GPS dipakai untuk menentukan area cabang otomatis saat register.'}
            </div>
            {registrationLocation && <div className="gps-register-note success">Lokasi ditemukan</div>}
          </>
        )}
        <label>
          Email
          <input type="email" value={email} onChange={(event) => setEmail(event.target.value)} required />
        </label>
        <label>
          Password
          <input type="password" value={password} onChange={(event) => setPassword(event.target.value)} required />
        </label>
        <button disabled={loading || (mode === 'register' && (!registrationLocation || locationLoading))}>{loading ? 'Memproses...' : mode === 'login' ? 'Masuk & lanjut order' : 'Daftar & lanjut order'}</button>
        <button type="button" className="google-login-button" onClick={() => { window.location.href = googleLoginUrl() }}>
          Login by Google
        </button>
      </form>
    </div>
  )
}

function PwaInstallButton() {
  const [installEvent, setInstallEvent] = useState<BeforeInstallPromptEvent | null>(null)
  const [installed, setInstalled] = useState(() => window.matchMedia('(display-mode: standalone)').matches)

  useEffect(() => {
    const onPrompt = (event: Event) => {
      event.preventDefault()
      setInstallEvent(event as BeforeInstallPromptEvent)
    }
    const onInstalled = () => {
      setInstalled(true)
      setInstallEvent(null)
    }

    window.addEventListener('beforeinstallprompt', onPrompt)
    window.addEventListener('appinstalled', onInstalled)

    return () => {
      window.removeEventListener('beforeinstallprompt', onPrompt)
      window.removeEventListener('appinstalled', onInstalled)
    }
  }, [])

  if (installed || !installEvent) return null

  return (
    <button
      className="pwa-install-button"
      type="button"
      onClick={async () => {
        await installEvent.prompt()
        const choice = await installEvent.userChoice
        if (choice.outcome === 'accepted') setInstalled(true)
        setInstallEvent(null)
      }}
    >
      <img src="/logo.png" alt="" />
      <span>Install Aplikasi</span>
    </button>
  )
}

function getLoginErrorMessage(error: unknown) {
  const message = getApiErrorMessage(error, 'Login gagal')
  return message === 'Unauthorized' || /401|unauthorized/i.test(message) ? 'Email atau password salah.' : message
}

function orderClosedMessageFromError(error: unknown) {
  const maybe = error as { response?: { data?: { order_closed?: boolean; message?: string; jojobot_message?: string } } }
  const data = maybe.response?.data

  return data?.order_closed ? data.jojobot_message ?? data.message ?? 'Sistem order sedang tutup.' : ''
}

async function syncRealtimeUserLocation(
  token: string,
  setUserSession: (user: Awaited<ReturnType<typeof fetchMe>>, token: string) => void,
  showToast: (type: 'success' | 'error' | 'info', message: string) => void,
) {
  const location = await getBrowserLocation()

  if (!location) {
    showToast('info', 'GPS belum aktif. Area cabang akan dicek lagi setelah lokasi diizinkan.')
    return
  }

  try {
    const result = await updateUserLocation(location)
    setUserSession(result.user, token)

    if (result.inside_branch && result.branch) {
      showToast('success', `Area terdeteksi: ${result.branch.display_name ?? result.branch.name}`)
    } else {
      showToast('info', 'Di luar area cabang. Silahkan hubungi CS jika lokasi kamu sudah benar.')
    }
  } catch {
    showToast('info', 'Lokasi belum bisa divalidasi. Coba aktifkan GPS lalu refresh aplikasi.')
  }
}

async function getBrowserLocation() {
  if (!('geolocation' in navigator)) return null

  try {
    const position = await new Promise<GeolocationPosition>((resolve, reject) => {
      navigator.geolocation.getCurrentPosition(resolve, reject, {
        enableHighAccuracy: true,
        timeout: 10000,
        maximumAge: 0,
      })
    })

    return {
      lat: position.coords.latitude,
      lng: position.coords.longitude,
      accuracy: position.coords.accuracy,
      gps_timestamp: new Date(position.timestamp).toISOString(),
    }
  } catch {
    return null
  }
}

async function getRegistrationLocation(setStatus: (status: string) => void) {
  if (!('geolocation' in navigator)) {
    setStatus('GPS browser tidak tersedia. Cabang bisa diset oleh CS.')
    return null
  }

  setStatus('Mengambil lokasi GPS untuk menentukan cabang...')

  try {
    const position = await new Promise<GeolocationPosition>((resolve, reject) => {
      navigator.geolocation.getCurrentPosition(resolve, reject, {
        enableHighAccuracy: true,
        timeout: 9000,
        maximumAge: 0,
      })
    })

    setStatus('Lokasi GPS diterima. Cabang akan dipilih otomatis.')

    return {
      lat: position.coords.latitude,
      lng: position.coords.longitude,
      location_lat: position.coords.latitude,
      location_lng: position.coords.longitude,
      location_accuracy: position.coords.accuracy,
      gps_timestamp: new Date(position.timestamp).toISOString(),
    }
  } catch {
    setStatus('GPS tidak diizinkan. Area cabang belum diset silahkan hubungi CS.')
    return null
  }
}

function mergeOrderList(orders: Order[], updated: Order) {
  const exists = orders.some((order) => order.id === updated.id)
  if (!exists) return [updated, ...orders]
  return orders.map((order) => order.id === updated.id ? { ...order, ...updated } : order)
}

function mergeRemoteChatMessages(current: ChatMessage[], incoming: ChatMessage[]) {
  const byId = new Map(current.map((message) => [String(message.id), message]))
  incoming.forEach((message) => byId.set(String(message.id), message))

  return [...byId.values()].sort((a, b) => new Date(a.created_at ?? '').getTime() - new Date(b.created_at ?? '').getTime())
}

function formatOrderTime(value?: string) {
  if (!value) return '--:--'
  return new Intl.DateTimeFormat('id-ID', { hour: '2-digit', minute: '2-digit' }).format(new Date(value))
}

function formatOrderDate(value?: string) {
  if (!value) return todayLabel()
  return new Intl.DateTimeFormat('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }).format(new Date(value))
}

function monthKey(value?: string | null) {
  const date = value ? new Date(value) : new Date()
  if (Number.isNaN(date.getTime())) return ''

  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`
}

function recentMonthKeys(count: number) {
  const now = new Date()

  return Array.from({ length: count }, (_, index) => {
    const date = new Date(now.getFullYear(), now.getMonth() - index, 1)

    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`
  })
}

function formatMonthLabel(key: string) {
  const [year, month] = key.split('-').map(Number)
  if (!year || !month) return 'Periode tidak diketahui'

  return new Intl.DateTimeFormat('id-ID', { month: 'long', year: 'numeric' }).format(new Date(year, month - 1, 1))
}

function statusLabel(status?: string) {
  const value = String(status ?? '').toUpperCase()

  return {
    CREATED: 'Menunggu',
    SEARCHING_DRIVER: 'Cari driver',
    DRIVER_ACCEPTED: 'Driver accepted',
    DRIVER_ON_THE_WAY: 'Driver menuju pickup',
    ARRIVED_PICKUP: 'Driver tiba',
    ON_GOING: 'Dalam perjalanan',
    PENDING_CANCEL: 'Request cancel',
    COMPLETED: 'Selesai',
    CANCELLED: 'Dibatalkan',
  }[value] ?? value
}

function isAcceptedOrder(order: Order) {
  return ['accepted', 'driver_accepted', 'assigned', 'driver_on_the_way', 'arrived_pickup', 'on_going'].includes(String(order.status).toLowerCase())
}

function isCompletedStatus(status?: string) {
  return ['completed', 'done'].includes(String(status ?? '').toLowerCase())
}

function isCancelledStatus(status?: string) {
  return ['cancelled', 'canceled'].includes(String(status ?? '').toLowerCase())
}

function cancelFeedbackMessage(order: Order) {
  return order.feedback?.message
    ?? order.cancel_reason
    ?? 'Maaf, order Anda dibatalkan. Silakan buat order ulang atau hubungi CS.'
}

function isExpiredUnacceptedOrder(order: Order) {
  const status = String(order.status).toUpperCase()
  if (!['CREATED', 'SEARCHING_DRIVER'].includes(status)) return false

  const expiresAt = order.expired_at
    ? new Date(order.expired_at).getTime()
    : order.created_at
      ? new Date(order.created_at).getTime() + 10 * 60 * 1000
      : null

  return expiresAt !== null && expiresAt <= Date.now()
}

function mustUseGiftOrder(user: ReturnType<typeof useCustomerStore.getState>['user']) {
  return Boolean(user && !user.branch_id)
}

function parseShoppingItems(value: string) {
  return value
    .split(/[\n,]+/)
    .map((item) => item.trim())
    .filter(Boolean)
}

function dynamicInitialValues(fields: DynamicFormSchema['fields'] = [], user: ReturnType<typeof useCustomerStore.getState>['user']) {
  return fields.reduce<Record<string, string>>((values, field) => {
    values[field.name] = autoFillValue(field.name, field.label, user)
    return values
  }, {})
}

function autoFillValue(name: string, label: string, user: ReturnType<typeof useCustomerStore.getState>['user']) {
  const key = `${name} ${label}`.toLowerCase()
  if (/\b(nama|name)\b/.test(key)) return user?.name ?? ''
  if (/\b(hp|phone|telepon|whatsapp|wa)\b/.test(key)) return user?.phone ?? ''
  if (/pembelian|lokasi|toko|store|warung|resto|pasar|belikan|item|barang|produk/.test(key)) return ''
  if (/\b(alamat|address|jemput|tujuan|antar|destination|pickup)\b/.test(key)) return ''

  return ''
}

function dynamicFormText(fields: DynamicFormSchema['fields'] = [], values: Record<string, string>, serviceType: string) {
  return [
    serviceType ? `Layanan: ${serviceType}` : null,
    ...fields.map((field) => `${field.label}: ${values[field.name] ?? ''}`),
  ].filter(Boolean).join('\n')
}

function isProfileComplete(user: ReturnType<typeof useCustomerStore.getState>['user']) {
  if (user?.profile_completed === true) return true

  return Boolean(user?.name?.trim() && user?.phone?.trim() && user?.address?.trim())
}

function isProfileSetupError(error: unknown, message: string) {
  const maybeResponse = error as { response?: { status?: number; data?: { code?: string; redirect_to?: string } } }

  return maybeResponse.response?.status === 409
    && (maybeResponse.response.data?.code === 'profile_setup_required'
      || maybeResponse.response.data?.redirect_to === '/profile/setup'
      || /lengkapi profile/i.test(message))
}

function assetUrl(path: string) {
  if (!path) return ''
  if (/^https?:\/\//i.test(path)) return normalizeRemoteAsset(path)

  const cleanPath = path.startsWith('/') ? path : `/${path}`
  return `${API_BASE.replace(/\/api$/, '')}${cleanPath}`
}

function cmsAssetUrl(path: string) {
  if (!/^https?:\/\//i.test(path)) return assetUrl(path)

  return normalizeRemoteAsset(path)
}

function normalizeRemoteAsset(path: string) {
  try {
    const url = new URL(path)
    if (['localhost', '127.0.0.1'].includes(url.hostname) || url.pathname.startsWith('/storage/') || url.pathname.startsWith('/api/media/')) {
      return `${API_BASE.replace(/\/api$/, '')}${url.pathname}${url.search}${url.hash}`
    }
  } catch {
    return path
  }

  return path
}

function plainText(value?: string) {
  return String(value ?? '')
    .replace(/<[^>]+>/g, ' ')
    .replace(/\s+/g, ' ')
    .trim()
}

function formatMessageTime(value?: string) {
  return value ? new Intl.DateTimeFormat('id-ID', { hour: '2-digit', minute: '2-digit' }).format(new Date(value)) : nowTime()
}

function autoResizeTextarea(textarea: HTMLTextAreaElement) {
  textarea.style.height = 'auto'
  textarea.style.height = `${Math.min(textarea.scrollHeight, 118)}px`
}

function withReplyPrefix(text: string, reply?: ReplyTarget | null) {
  if (!reply) return text

  return `Membalas:\n> ${plainText(reply.text).slice(0, 90)}\n\n${text}`
}

function parseSharedLocation(message?: string | null): SharedLocation | null {
  if (!message) return null

  const mapsMatch = message.match(/https?:\/\/(?:www\.)?(?:google\.com\/maps|maps\.google\.com)[^\s]*[?&]q=(-?\d+(?:\.\d+)?),\s*(-?\d+(?:\.\d+)?)/i)
  const coordMatch = mapsMatch ?? message.match(/(-?\d{1,2}(?:\.\d+)?)\s*,\s*(-?\d{1,3}(?:\.\d+)?)/)
  if (!coordMatch) return null

  const lat = Number(coordMatch[1])
  const lng = Number(coordMatch[2])
  if (!Number.isFinite(lat) || !Number.isFinite(lng) || Math.abs(lat) > 90 || Math.abs(lng) > 180) return null

  return {
    lat,
    lng,
    url: `https://www.google.com/maps/search/?api=1&query=${lat},${lng}`,
    label: `${lat.toFixed(6)}, ${lng.toFixed(6)}`,
  }
}

declare global {
  interface Window {
    SpeechRecognition?: BrowserSpeechRecognitionConstructor
    webkitSpeechRecognition?: BrowserSpeechRecognitionConstructor
  }
}

type BrowserSpeechRecognitionEvent = {
  results: ArrayLike<ArrayLike<{ transcript: string }>>
}

type BrowserSpeechRecognition = {
  lang: string
  interimResults: boolean
  onresult: ((event: BrowserSpeechRecognitionEvent) => void) | null
  onend: (() => void) | null
  start: () => void
}

type BrowserSpeechRecognitionConstructor = new () => BrowserSpeechRecognition

export default App
