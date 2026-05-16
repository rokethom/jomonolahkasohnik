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
  Square,
  Store,
  ThumbsUp,
  UserRound,
} from 'lucide-react'
import {
  API_BASE,
  createOrder,
  fetchBranches,
  fetchHome,
  fetchKeywordParsers,
  fetchMe,
  fetchOrderMessages,
  fetchOrders,
  fetchPublicSettings,
  fetchServices,
  findDriver,
  fetchChatMessages,
  extendOrderWait,
  getApiErrorMessage,
  googleLoginUrl,
  logout,
  previewJojoBot,
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
import type { Banner, Branch, ChatConversation, ChatMessage, DynamicService, HomeData, HomeSectionItem, Order, OrderFeedback, PriceQuote, PublicSettings, User } from './types'

type Screen = 'home' | 'order-chat' | 'driver-chat' | 'cs-chat' | 'history' | 'profile' | 'profile-setup' | 'login'
type JojoHistoryState = {
  jojoScreen?: Screen
}

type NotificationOpenTarget = {
  screen: 'driver-chat' | 'cs-chat' | 'history'
  conversationId?: number
  orderId?: number
}

type LocalMessage = {
  id: string
  from: 'bot' | 'user' | 'driver' | 'system'
  senderLabel?: string
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

const DEFAULT_CUSTOMER_NOTIFICATION_SOUND = '/customernotif.mpeg'
const CUSTOMER_SOUND_DB = 'jojo-customer-settings'
const CUSTOMER_SOUND_STORE = 'notification-sound'
const CUSTOMER_SOUND_KEY = 'custom'

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

function numericParam(value: string | null) {
  const numberValue = Number(value)
  return Number.isFinite(numberValue) && numberValue > 0 ? numberValue : undefined
}

function readNotificationOpenTarget(): NotificationOpenTarget | null {
  const params = new URLSearchParams(window.location.search)
  const open = params.get('open') ?? params.get('screen')
  const type = params.get('notification_type') ?? params.get('type')
  const conversationId = numericParam(params.get('conversation_id'))
  const orderId = numericParam(params.get('order_id'))

  if (open === 'history' || ['order_cancelled', 'order_auto_cancelled', 'order_completed'].includes(String(type))) {
    return { screen: 'history', conversationId, orderId }
  }

  if (open === 'driver-chat' || (orderId && ['chat_message', 'driver_accepted', 'order_adjustment'].includes(String(type)))) {
    return { screen: 'driver-chat', conversationId, orderId }
  }

  if (open === 'cs-chat' || (type === 'chat_message' && conversationId)) {
    return { screen: 'cs-chat', conversationId }
  }

  return null
}

function clearNotificationOpenParams(screen: Screen) {
  window.history.replaceState(
    { ...(window.history.state as JojoHistoryState | null), jojoScreen: screen },
    document.title,
    screenPath(screen),
  )
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

function isLikelyAuthToken(token: string) {
  return /^[A-Za-z0-9._|:-]{20,}$/.test(token)
}

function safeWhatsappUrl(value?: string | null) {
  const fallback = 'https://wa.me/6281299232918'
  const raw = value?.trim() || fallback

  try {
    const url = new URL(raw)
    const host = url.hostname.toLowerCase()
    const allowedHosts = new Set(['wa.me', 'api.whatsapp.com', 'www.api.whatsapp.com', 'wa.link'])

    return url.protocol === 'https:' && allowedHosts.has(host) ? url.toString() : fallback
  } catch {
    return fallback
  }
}

const MAX_CUSTOMER_IMAGE_BYTES = 4 * 1024 * 1024

function imageFileError(file: File, label = 'Gambar') {
  if (!file.type.startsWith('image/')) return `${label} harus berupa file gambar.`
  if (file.size > MAX_CUSTOMER_IMAGE_BYTES) return `${label} maksimal 4MB.`
  return ''
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

function defaultVehicleForService(serviceType?: string | null): 'motor' | 'mobil' {
  const value = String(serviceType ?? '').toLowerCase().replace(/[\s-]+/g, '_')
  return ['jm', 'joker_mobil', 'joker', 'mobil'].includes(value) || value.includes('mobil') ? 'mobil' : 'motor'
}

function normalizedServiceType(serviceType?: string | null) {
  return String(serviceType ?? '').toLowerCase().replace(/[\s-]+/g, '_')
}

function hidesVehicleChoiceForService(serviceType?: string | null) {
  return ['delivery', 'do', 'belanja', 'kurir'].includes(normalizedServiceType(serviceType))
}

function shouldSendVehiclePreference(serviceType?: string | null) {
  return isOjekService(serviceType) || isJokerMobilService(serviceType) || !hidesVehicleChoiceForService(serviceType)
}

function passengerCountFromPayload(payload?: OrderPayload | null) {
  const raw = payload?.service_payload?.passengers ?? payload?.service_payload?.jumlah_penumpang ?? payload?.service_payload?.passenger_count
  const value = Number(String(raw ?? 1).replace(/\D+/g, ''))
  return Number.isFinite(value) && value > 0 ? value : 1
}

function containsTartKeyword(payload?: OrderPayload | null) {
  const source = [
    payload?.notes,
    payload?.pickup_address,
    payload?.destination_address,
    payload?.destination_text,
    JSON.stringify(payload?.service_payload ?? {}),
    ...(payload?.items ?? []).map((item) => `${item.name} ${item.notes ?? ''}`),
  ].join(' ').toLowerCase()

  return /\b(?:kue\s*)?tart\b/u.test(source)
}

function validCoordinate(value: unknown, limit: number) {
  const numberValue = Number(value)
  return Number.isFinite(numberValue) && Math.abs(numberValue) <= limit
}

function userDeviceLocation(user?: User | null) {
  if (!user || !validCoordinate(user.lat, 90) || !validCoordinate(user.lng, 180)) return null

  return {
    lat: Number(user.lat),
    lng: Number(user.lng),
  }
}

function needsCustomerLocation(text: string) {
  return /\b(?:rumah\s+saya|rumahku|rumah|lokasi\s+saya|alamat\s+saya|home)\b/i.test(text)
}

async function previewDeviceLocation(text: string, user?: User | null) {
  const saved = userDeviceLocation(user)
  if (saved || !needsCustomerLocation(text)) return saved

  return getBrowserLocation({ enableHighAccuracy: false, timeout: 2000, maximumAge: 300000 })
}

function validateOrderPayload(payload: OrderPayload) {
  const missingFields = [
    payload.service_type ? '' : 'layanan',
    payload.pickup_address?.trim() ? '' : 'alamat jemput',
    payload.destination_address?.trim() ? '' : 'alamat tujuan',
    validCoordinate(payload.pickup_lat, 90) && validCoordinate(payload.pickup_lng, 180) ? '' : 'titik jemput',
    validCoordinate(payload.destination_lat, 90) && validCoordinate(payload.destination_lng, 180) ? '' : 'titik tujuan',
  ].filter(Boolean)

  if (missingFields.length > 0) {
    throw new Error(`Data order belum lengkap: ${missingFields.join(', ')}.`)
  }

  if ((payload.points?.length ?? 0) > 5 || Number(payload.stops ?? 1) > 6) {
    throw new Error('Titik tambahan maksimal 5.')
  }
}

function orderSummaryText(payload?: OrderPayload | null, preview?: JojoBotPreview) {
  const quote = preview?.quote
  const purchase = isPurchasePayload(payload, preview)
  const lines = [
    'Pesanan Anda:',
    '',
    `Layanan: ${serviceDisplayLabel(payload?.service_type ?? preview?.service_type)}`,
    passengerCountFromPayload(payload) > 1 ? `Jumlah penumpang: ${passengerCountFromPayload(payload)}` : null,
    isJokerMobilService(payload?.service_type ?? preview?.service_type) ? `Seat / baris mobil: ${payload?.vehicle_seat_rows === 3 ? 3 : 2} baris` : null,
    '',
    purchase ? 'Lokasi pembelian:' : 'Alamat jemput:',
    payload?.pickup_address ?? preview?.parsed?.pickup_address ?? '-',
    '',
    purchase ? 'Alamat antar:' : 'Alamat tujuan:',
    payload?.destination_address ?? preview?.parsed?.destination_address ?? '-',
    '',
    quote ? 'Breakdown harga:' : null,
    quote ? `Tarif: ${formatRupiah(quote.tarif ?? quote.price ?? 0)}` : null,
    quote ? `Service fee: ${formatRupiah(quote.service_fee ?? quote.service_charge ?? 0)}` : null,
    quote ? `Tambahan: ${formatRupiah(quote.extra_charge ?? 0)}` : null,
    helperFeeFromQuote(quote) > 0 ? `${helperLabelFromQuote(quote)}: ${formatRupiah(helperFeeFromQuote(quote))}` : null,
    quote ? `Total: ${formatRupiah(quote.total_price ?? quote.final_price ?? 0)}` : null,
  ]

  return lines.filter((line) => line !== null).join('\n')
}

function helperFeeFromQuote(quote?: PriceQuote | null) {
  if (!quote) return 0

  return Number(quote.crew_helper_fee ?? quote.helper_service_charge ?? quote.crew_decision?.helper_fee ?? quote.crew_decision?.helper_service_charge ?? 0)
}

function helperLabelFromQuote(quote?: PriceQuote | null) {
  return quote?.crew_decision?.helper_label || 'Jasa helper'
}

function isPurchasePayload(payload?: OrderPayload | null, preview?: JojoBotPreview) {
  const service = String(payload?.service_type ?? preview?.service_type ?? preview?.selected_service ?? '').toLowerCase()

  return ['do', 'delivery', 'belanja', 'gift_order', 'gift'].includes(service)
}

function serviceDisplayLabel(service?: string | null) {
  const value = String(service ?? '').toLowerCase()
  if (value.includes('joker_mobil') || value.includes('mobil') || value === 'jm') return 'Joker Mobil'
  if (value.includes('ojek')) return 'Ojek'
  if (value.includes('belanja') || value === 'bl') return 'Belanja'
  if (value.includes('kurir') || value === 'kr') return 'Kurir'
  if (value.includes('gift') || value === 'go') return 'Gift Order'
  return service || '-'
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
  const type = normalizeServiceKeyword(service.service_type ?? '')
  const name = normalizeServiceKeyword(service.name)

  if (code === 'BL' || name.includes('belanja')) return 'belanja'
  if (code === 'DO' || type.includes('delivery') || name.includes('delivery')) return 'belanja'
  if (code === 'KR' || name.includes('kurir')) return 'kurir'
  if (code === 'OJ' || name.includes('ojek')) return 'ojek'
  if (code === 'JM' || type.includes('mobil') || name.includes('joker mobil') || name.includes('mobil')) return 'ojek'
  if (code === 'GO' || name.includes('gift')) return 'gift'

  return null
}

function fallbackServiceFromType(serviceType: string | null | undefined): DynamicService | null {
  const type = normalizeServiceKeyword(String(serviceType ?? ''))
  if (!type) return null

  if (type === 'do' || type.includes('delivery')) {
    return { id: -1, code: 'DO', name: 'Delivery', service_type: 'delivery' }
  }

  if (type.includes('belanja')) {
    return { id: -2, code: 'BL', name: 'Belanja', service_type: 'belanja' }
  }

  if (type.includes('kurir')) {
    return { id: -3, code: 'KR', name: 'Kurir', service_type: 'kurir' }
  }

  if (type.includes('ojek')) {
    return { id: -4, code: 'OJ', name: 'Ojek', service_type: 'ojek' }
  }

  if (type.includes('mobil')) {
    return { id: -5, code: 'JM', name: 'Joker Mobil', service_type: 'joker_mobil' }
  }

  if (type.includes('gift')) {
    return { id: -6, code: 'GO', name: 'Gift Order', service_type: 'gift_order' }
  }

  return null
}

function isGiftService(service: DynamicService) {
  const code = service.code?.toUpperCase()
  const type = normalizeServiceKeyword(service.service_type ?? '')
  const name = normalizeServiceKeyword(service.name)

  return code === 'GO' || type.includes('gift') || name.includes('gift')
}

function isOutsideAreaService(service: DynamicService) {
  return Boolean(service.outside_area_only) || isGiftService(service)
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

function findServiceByType(serviceType: string | null | undefined, services: DynamicService[]) {
  const keyword = normalizeServiceKeyword(String(serviceType ?? ''))
  if (!keyword) return null

  return services.find((service) => {
    const code = normalizeServiceKeyword(service.code ?? '')
    const type = normalizeServiceKeyword(service.service_type ?? '')
    return code === keyword || type === keyword || serviceKeywords(service).includes(keyword)
  }) ?? null
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

function isJokerMobilService(service?: string | null) {
  const value = String(service ?? '').toLowerCase().replace(/[\s-]+/g, '_')

  return value === 'jm' || value === 'joker_mobil' || value.includes('joker_mobil') || value.includes('mobil')
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
  const [notificationTarget, setNotificationTarget] = useState<NotificationOpenTarget | null>(() => readNotificationOpenTarget())
  const [csConversationFromNotification, setCsConversationFromNotification] = useState<number | null>(() => {
    const target = readNotificationOpenTarget()
    return target?.screen === 'cs-chat' ? target.conversationId ?? null : null
  })
  const [screen, setScreen] = useState<Screen>(() => token ? window.location.pathname === '/profile/setup' ? 'profile-setup' : 'home' : 'login')
  const [services, setServices] = useState<DynamicService[]>([])
  const [branches, setBranches] = useState<Branch[]>([])
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
  const [activeManualService, setActiveManualService] = useState<DynamicService | null>(null)
  const [orderClosedMessage, setOrderClosedMessage] = useState('')
  const locationSyncTokenRef = useRef<string | null>(null)
  const [orderSubmitBlocked, setOrderSubmitBlocked] = useState(false)
  const [orderSubmitting, setOrderSubmitting] = useState(false)
  const orderSubmittingRef = useRef(false)
  const isBrowserBackRef = useRef(false)
  const updateInfo = useBuildUpdate('customer')
  const outsideAreaServices = useMemo(() => services.filter(isOutsideAreaService), [services])
  const visibleServices = useMemo(
    () => mustUseGiftOrder(store.user) ? outsideAreaServices.length > 0 ? outsideAreaServices : services.filter(isGiftService) : services,
    [outsideAreaServices, services, store.user],
  )

  useEffect(() => {
    const root = document.documentElement

    const syncViewportHeight = () => {
      const height = window.visualViewport?.height ?? window.innerHeight
      root.style.setProperty('--jojo-viewport-height', `${Math.round(height)}px`)
    }

    syncViewportHeight()
    window.visualViewport?.addEventListener('resize', syncViewportHeight)
    window.visualViewport?.addEventListener('scroll', syncViewportHeight)
    window.addEventListener('resize', syncViewportHeight)

    return () => {
      window.visualViewport?.removeEventListener('resize', syncViewportHeight)
      window.visualViewport?.removeEventListener('scroll', syncViewportHeight)
      window.removeEventListener('resize', syncViewportHeight)
    }
  }, [])

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
    validateOrderPayload(payload)
    const sendVehiclePreference = shouldSendVehiclePreference(payload.service_type)
    const preferredVehicle = sendVehiclePreference ? (payload.preferred_vehicle_type ?? defaultVehicleForService(payload.service_type)) : undefined
    const vehicleSeatRows = preferredVehicle === 'mobil' ? (payload.vehicle_seat_rows === 3 ? 3 : 2) : undefined
    const driverPreference = isOjekService(payload.service_type) ? (payload.driver_preference ?? 'general') : 'general'
    const passengers = passengerCountFromPayload(payload)
    const ojekDoubleOrderCount = isOjekService(payload.service_type) && passengers === 2 && payload.service_payload?.confirm_double_order === true ? 2 : 1
    const orderCount = ojekDoubleOrderCount
    const createdOrders: Order[] = []

    for (let index = 0; index < orderCount; index += 1) {
      const crewNote = containsTartKeyword(payload) ? 'Crew rule: kue tart membutuhkan helper' : null
      const order = await createOrder({
        ...payload,
        notes: [
          payload.notes,
          ojekDoubleOrderCount > 1 ? `Order penumpang ${index + 1} dari ${ojekDoubleOrderCount}` : null,
          crewNote,
        ].filter(Boolean).join('\n'),
        ...(preferredVehicle ? { preferred_vehicle_type: preferredVehicle } : {}),
        ...(vehicleSeatRows ? { vehicle_seat_rows: vehicleSeatRows } : {}),
        driver_preference: driverPreference,
        service_payload: {
          ...(payload.service_payload ?? {}),
          ...(ojekDoubleOrderCount > 1 ? { passenger_order_index: index + 1, passenger_order_count: ojekDoubleOrderCount } : {}),
          ...(crewNote ? { crew_decision_hint: 'kue_tart_helper' } : {}),
          ...(preferredVehicle ? { preferred_vehicle_type: preferredVehicle } : {}),
          ...(vehicleSeatRows ? { vehicle_seat_rows: vehicleSeatRows } : {}),
          driver_preference: driverPreference,
        },
      })
      const driverResult = await findDriver(order.id)
      const assignedOrder = driverResult.data ?? order
      createdOrders.push(assignedOrder)
      addOrder(assignedOrder)
    }

    const assignedOrder = createdOrders[0]
    setActiveOrder(assignedOrder)
    pushMessage({
      from: 'bot',
      text: createdOrders.length > 1
        ? `Order berhasil dibuat ${createdOrders.length} order.\nKode: ${createdOrders.map((order) => order.order_code ?? `#${order.id}`).join(', ')}\nJOJOBOT sedang assign driver.`
        : `Order berhasil dibuat.\nKode: ${assignedOrder.order_code ?? `#${assignedOrder.id}`}\nJOJOBOT sedang assign driver.`,
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
    if (!isLikelyAuthToken(oauthToken)) {
      showToast('error', 'Token login Google tidak valid. Silakan login ulang.')
      setScreen('login')
      window.history.replaceState({ jojoScreen: 'login' }, document.title, '/')
      return
    }

    setAuthToken(oauthToken)
    setScreen('home')
    window.history.replaceState({ jojoScreen: 'home' }, document.title, '/')
  }, [setAuthToken, showToast])

  useEffect(() => {
    const handleAuthExpired = () => {
      resetEcho()
      clearSession()
      setScreen('login')
      showToast('error', 'Sesi login berakhir. Silakan login ulang.')
    }

    window.addEventListener('jojo:auth-expired', handleAuthExpired)
    return () => window.removeEventListener('jojo:auth-expired', handleAuthExpired)
  }, [clearSession, showToast])

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
    if (!token) return

    const onServiceWorkerMessage = (event: MessageEvent) => {
      if (event.data?.type === 'customer_push_notification') {
        void playCustomerNotificationSound()
      }
    }

    navigator.serviceWorker?.addEventListener('message', onServiceWorkerMessage)

    return () => navigator.serviceWorker?.removeEventListener('message', onServiceWorkerMessage)
  }, [token])

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
        setScreen((current) => current === 'profile-setup' && !notificationTarget ? 'home' : current)
        await syncRealtimeUserLocation(token, setUserSession, showToast)
      })
      .catch((error) => {
        locationSyncTokenRef.current = null
        if (/unauthenticated/i.test(getApiErrorMessage(error, ''))) clearSession()
      })
    void fetchOrders()
      .then(setOrders)
      .catch(() => undefined)
  }, [clearSession, notificationTarget, setOrders, setUserSession, showToast, token])

  useEffect(() => {
    if (!token || !store.user || !notificationTarget || !isProfileComplete(store.user)) return

    if (notificationTarget.screen === 'cs-chat') {
      setCsConversationFromNotification(notificationTarget.conversationId ?? null)
      setScreen('cs-chat')
      clearNotificationOpenParams('cs-chat')
      setNotificationTarget(null)
      return
    }

    if (notificationTarget.screen === 'history') {
      setScreen('history')
      clearNotificationOpenParams('history')
      setNotificationTarget(null)
      return
    }

    const openDriverChat = (orders: Order[]) => {
      const order = orders.find((item) => item.id === notificationTarget.orderId)

      if (!order) {
        showToast('info', 'Chat order belum ditemukan. Membuka riwayat order.')
        setScreen('history')
        clearNotificationOpenParams('history')
        setNotificationTarget(null)
        return
      }

      setActiveOrder(order)
      setScreen('driver-chat')
      clearNotificationOpenParams('driver-chat')
      setNotificationTarget(null)
    }

    if (notificationTarget.orderId) {
      const existingOrder = store.orders.find((item) => item.id === notificationTarget.orderId)
      if (existingOrder) {
        openDriverChat(store.orders)
        return
      }

      void fetchOrders()
        .then((orders) => {
          setOrders(orders)
          openDriverChat(orders)
        })
        .catch(() => {
          showToast('error', 'Gagal membuka chat order dari notifikasi.')
          setNotificationTarget(null)
        })
    }
  }, [notificationTarget, setOrders, showToast, store.orders, store.user, token])

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
    void fetchBranches()
      .then(setBranches)
      .catch(() => setBranches([]))
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
    channel.listen('.order.price.updated', (event: { order?: Order; actor_name?: string | null; message?: string | null; change?: { amount?: number; reason?: string | null; type?: string } }) => {
      const updatedOrder = event.order
      if (!updatedOrder) return
      setOrders(mergeOrderList(useCustomerStore.getState().orders, updatedOrder))
      setActiveOrder((current) => current?.id === updatedOrder.id ? { ...current, ...updatedOrder } : current)
      const code = updatedOrder.order_code ?? updatedOrder.code ?? ''
      const actor = event.actor_name?.trim()
      const reason = event.change?.reason?.trim()
      const message = reason && event.change?.type === 'driver_adjustment'
        ? `Tambahan service charge order ${code}: ${formatRupiah(event.change.amount ?? 0)}. Alasan: ${reason}`
        : event.message ?? `Harga order ${code} diedit oleh ${actor || 'operator'}.`
      store.showToast('info', message)
      if (reason) pushMessage({ from: 'bot', text: message, order: updatedOrder })
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
        return
      }
      if (event.feedback?.message) {
        store.showToast(event.feedback.tone === 'error' ? 'error' : 'info', event.feedback.message)
        pushMessage({ from: 'bot', text: event.feedback.message, order: orderWithFeedback })
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
    setActiveManualService(null)
  }

  const openManualServiceForm = (service: DynamicService, options: { pushUser?: boolean } = {}) => {
    const kind = manualFormKindForService(service)
    if (!kind) return

    closeManualForms()
    setPendingOrder(null)
    setOrderSubmitBlocked(false)
    setActiveManualService(service)
    setShowBelanjaForm(kind === 'belanja')
    setShowKurirForm(kind === 'kurir')
    setShowOjekForm(kind === 'ojek')
    setShowGiftForm(kind === 'gift')

    if (options.pushUser ?? true) pushMessage({ from: 'user', text: service.name })
    pushMessage({ from: 'bot', text: `Baik, silakan lengkapi form ${service.name}.` })
  }

  const editPendingOrder = () => {
    const service = findServiceByType(pendingOrder?.service_type, services) ?? fallbackServiceFromType(pendingOrder?.service_type)
    if (service && manualFormKindForService(service)) {
      openManualServiceForm(service, { pushUser: false })
      return
    }

    setPendingOrder(null)
    setOrderSubmitBlocked(false)
    pushMessage({ from: 'bot', text: 'Silakan isi ulang detail order dari form layanan.' })
  }

  const cancelPendingOrder = () => {
    setPendingOrder(null)
    setOrderSubmitBlocked(false)
    closeManualForms()
    pushMessage({ from: 'bot', text: 'Order belum dikirim dan sudah dibatalkan. Ketik menu untuk memilih layanan lagi.' })
  }

  const extendTimeoutOrderWait = async (order: Order) => {
    try {
      const response = await extendOrderWait(order.id)
      const updatedOrder = response.data
      setOrders(mergeOrderList(useCustomerStore.getState().orders, updatedOrder))
      setActiveOrder((current) => current?.id === updatedOrder.id ? { ...current, ...updatedOrder } : current)
      showToast('success', response.message ?? 'Waktu tunggu driver ditambah 10 menit.')
      pushMessage({
        from: 'bot',
        text: `Baik, JOJOBOT akan mencari driver 10 menit lagi untuk order ${updatedOrder.order_code ?? updatedOrder.code ?? `#${updatedOrder.id}`}.`,
        order: updatedOrder,
      })
    } catch (error) {
      showToast('error', getApiErrorMessage(error, 'Gagal menambah waktu tunggu order.'))
    }
  }

  const keepTimeoutOrderCancelled = (order: Order) => {
    showToast('info', `Order ${order.order_code ?? order.code ?? `#${order.id}`} tetap dibatalkan.`)
    pushMessage({ from: 'bot', text: 'Baik, order tetap dibatalkan. Kamu bisa membuat order baru kapan saja.' })
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

      if (mustUseGiftOrder(store.user) && !isOutsideAreaService(requestedService)) {
        const outsideService = visibleServices[0]
        pushMessage({ from: 'bot', text: 'Area kamu berada di luar cabang/geofence aktif. Saat ini hanya layanan khusus luar area yang tersedia.' })
        if (outsideService) openManualServiceForm(outsideService, { pushUser: false })
        return
      }

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
      if (orderSubmittingRef.current) {
        pushMessage({ from: 'bot', text: 'Order sedang dikirim. Tunggu sebentar ya.' })
        return
      }
      orderSubmittingRef.current = true
      setOrderSubmitting(true)
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
        orderSubmittingRef.current = false
        setOrderSubmitting(false)
        setTyping(false)
      }
      return
    }

    if (/^tidak$/i.test(text) && pendingOrder) {
      setPendingOrder(null)
      setOrderSubmitBlocked(false)
      pushMessage({
        from: 'bot',
        text: 'Baik, kirim ulang detail pesanan dengan cara ketik "menu" atau klik menu layanan di bawah.',
        preview: {
          intent: 'service_menu',
          services: visibleServices.map((service) => ({ ...service, service_type: service.service_type ?? service.code })),
          selected_service: null,
          service_type: null,
          parsed: {},
          quote: null,
          order_payload: null,
          reply: '',
        },
      })
      return
    }

    setTyping(true)
    try {
      const preview = await previewJojoBot(text, await previewDeviceLocation(text, store.user) ?? undefined)
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
      const preview = await previewJojoBot(text, await previewDeviceLocation(text, store.user) ?? undefined)
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
    const error = imageFileError(file)
    if (error) {
      showToast('error', error)
      return
    }

    pushMessage({ from: 'user', imageUrl: URL.createObjectURL(file), text: file.name })
    pushMessage({ from: 'bot', text: 'Foto diterima. Tambahkan catatan bila foto ini bagian dari order.' })
  }

  const handleManualService = (service: DynamicService) => {
    if (mustUseGiftOrder(store.user) && !isOutsideAreaService(service)) {
      const outsideService = visibleServices[0]
      pushMessage({ from: 'bot', text: 'Area kamu berada di luar cabang/geofence aktif. Silakan gunakan layanan khusus luar area.' })
      if (outsideService) openManualServiceForm(outsideService)
      return
    }

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
      subtitle={screen === 'driver-chat' ? `Chat dengan ${driverNameFromOrder(acceptedOrder) !== '-' ? driverNameFromOrder(acceptedOrder) : 'driver'}` : screen === 'cs-chat' ? 'Hubungi Operator' : screen === 'profile-setup' ? 'Lengkapi profile' : 'SI APLIKASI JOKER'}
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
          visibleServices={visibleServices}
          branches={branches}
          onSend={handleBotReply}
          onDynamicFormOrder={handleDynamicFormPreview}
          onImage={sendImage}
          onService={handleManualService}
          onCs={() => setScreen('cs-chat')}
          showBelanjaForm={showBelanjaForm}
          showKurirForm={showKurirForm}
          showOjekForm={showOjekForm}
          showGiftForm={showGiftForm}
          activeManualService={activeManualService}
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
          submitting={orderSubmitting}
          onEdit={editPendingOrder}
          onCancel={cancelPendingOrder}
          onExtendWait={(order) => void extendTimeoutOrderWait(order)}
          onKeepCancelled={keepTimeoutOrderCancelled}
        />
      )}
      {screen === 'driver-chat' && <DriverChatScreen order={acceptedOrder} />}
      {screen === 'cs-chat' && <CsChatScreen initialConversationId={csConversationFromNotification} />}
      {screen === 'history' && (
        <HistoryScreen
          orders={store.orders}
          onOpenDriverChat={(order) => {
            setActiveOrder(order)
            setScreen('driver-chat')
          }}
          onOrdersChanged={(orders) => setOrders(orders)}
          onExtendWait={(order) => void extendTimeoutOrderWait(order)}
          onKeepCancelled={keepTimeoutOrderCancelled}
        />
      )}
      {screen === 'profile' && <ProfileScreen />}
      {screen === 'profile-setup' && <ProfileScreen setupMode onDone={() => setScreen('home')} />}
      {screen === 'login' && <CustomerLoginScreen onDone={() => setScreen(isProfileComplete(useCustomerStore.getState().user) ? 'home' : 'profile-setup')} />}
      <AppUpdateNotice update={updateInfo} />
    </ChatLayout>
  )

  function openProfileSetup() {
    window.history.replaceState({}, document.title, '/profile/setup')
    setScreen('profile-setup')
  }
}

type BuildInfo = {
  app: string
  sha: string
  full_sha?: string
  built_at?: string
}

function useBuildUpdate(appName: string) {
  const [update, setUpdate] = useState<BuildInfo | null>(null)
  const currentVersionRef = useRef<BuildInfo | null>(null)

  useEffect(() => {
    let active = true

    const check = async () => {
      try {
        const response = await fetch(`/version.json?t=${Date.now()}`, { cache: 'no-store' })
        if (!response.ok) return
        const latest = await response.json() as BuildInfo
        if (!active || latest.app !== appName || !latest.sha) return
        if (!currentVersionRef.current) {
          currentVersionRef.current = latest
          return
        }
        if (latest.sha === currentVersionRef.current.sha) return

        setUpdate(latest)
      } catch {
        // Version polling must never disturb an active order.
      }
    }

    void check()
    const interval = window.setInterval(check, 45000)

    return () => {
      active = false
      window.clearInterval(interval)
    }
  }, [appName])

  return update
}

function AppUpdateNotice({ update }: { update: BuildInfo | null }) {
  if (!update) return null

  return (
    <div className="app-update-notice">
      <div>
        <strong>Update aplikasi tersedia</strong>
        <span>Versi terbaru telah tersedia.</span>
      </div>
      <button type="button" onClick={() => window.location.reload()}>Refresh</button>
    </div>
  )
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
  const banners = homeData?.banners ?? []
  const announcement = homeData?.announcements[0]
  const customer = useCustomerStore((state) => state.user)
  const customerName = customer?.name?.trim() || 'Customer'
  const greeting = greetingByTime()
  const complaintUrl = safeWhatsappUrl(publicSettings?.support?.complaint_whatsapp_url)

  return (
    <div className="home-screen">
      <section className="home-hero">
        <CustomerHomeAvatar name={customerName} photoUrl={customer?.profile_photo_url} />
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
          <img src="/jojohome.webp" alt="" loading="eager" decoding="async" />
        </div>
      </section>
      {banners.length > 0 && (
        <section className="home-cms-slider home-banner-slider" aria-label="Banner CMS">
          {banners.map((banner) => <HomeBannerCard key={banner.id} banner={banner} onClick={onOrder} />)}
        </section>
      )}
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

function HomeBannerCard({ banner, onClick }: { banner: Banner; onClick: () => void }) {
  const imagePath = banner.image_original || banner.image || ''
  const image = imagePath ? cmsAssetUrl(imagePath) : ''

  return (
    <button className={`home-slider-card home-banner-card ${image ? 'has-image' : ''}`} onClick={onClick}>
      {image && <img className="home-slider-image" src={image} alt={banner.title} loading="lazy" decoding="async" />}
      <span className="home-slider-overlay" />
      <span className="home-slider-copy">
        <strong>{banner.title}</strong>
        <small>{banner.link ? 'Tap untuk mulai order' : 'Promo dari JOJO'}</small>
      </span>
    </button>
  )
}

function CustomerHomeAvatar({ name, photoUrl }: { name: string; photoUrl?: string | null }) {
  const [failed, setFailed] = useState(false)
  const image = photoUrl ? cmsAssetUrl(photoUrl) : ''

  return (
    <div className="home-profile-badge" aria-label={`Profile ${name}`}>
      {image && !failed ? (
        <img src={image} alt={name} loading="eager" decoding="async" onError={() => setFailed(true)} />
      ) : (
        <UserRound size={18} />
      )}
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
  visibleServices,
  branches,
  onSend,
  onDynamicFormOrder,
  onImage,
  onService,
  onCs,
  showBelanjaForm,
  showKurirForm,
  showOjekForm,
  showGiftForm,
  activeManualService,
  onBelanjaPreview,
  onKurirPreview,
  onOjekPreview,
  onGiftPreview,
  pendingOrder,
  onPendingOrderChange,
  publicSettings,
  submitBlocked,
  submitting,
  onEdit,
  onCancel,
  onExtendWait,
  onKeepCancelled,
}: {
  messages: LocalMessage[]
  typing: boolean
  visibleServices: DynamicService[]
  branches: Branch[]
  onSend: (text: string) => void
  onDynamicFormOrder: (text: string) => Promise<JojoBotPreview | null>
  onImage: (file: File) => void
  onService: (service: DynamicService) => void
  onCs: () => void
  showBelanjaForm: boolean
  showKurirForm: boolean
  showOjekForm: boolean
  showGiftForm: boolean
  activeManualService: DynamicService | null
  onBelanjaPreview: (text: string) => void
  onKurirPreview: (text: string) => void
  onOjekPreview: (text: string) => void
  onGiftPreview: (text: string) => void
  pendingOrder: OrderPayload | null
  onPendingOrderChange: (payload: OrderPayload | null) => void
  publicSettings: PublicSettings | null
  submitBlocked: boolean
  submitting: boolean
  onEdit: () => void
  onCancel: () => void
  onExtendWait: (order: Order) => void
  onKeepCancelled: (order: Order) => void
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
            {shouldShowChatMessage(message) && (
              <MessageBubble
                message={message}
                onCs={onCs}
                onOrderDetail={setDetailOrder}
                onExtendWait={onExtendWait}
                onKeepCancelled={onKeepCancelled}
              />
            )}
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
        {showBelanjaForm && <BelanjaOrderForm key={activeManualService?.code ?? 'belanja'} user={user} service={activeManualService} onSend={onBelanjaPreview} />}
        {showKurirForm && <KurirOrderForm user={user} onSend={onKurirPreview} />}
        {showOjekForm && <OjekOrderForm key={activeManualService?.code ?? 'ojek'} user={user} service={activeManualService} onSend={onOjekPreview} />}
        {showGiftForm && <GiftOrderForm user={user} branches={branches} onSend={onGiftPreview} />}
        {(messages.at(-1)?.preview?.intent === 'service_menu' || messages.length === 1) && (
          <ManualServicePicker
            services={visibleServices}
            onService={onService}
            compact={hasManualFormOpen}
          />
        )}
        {messages.at(-1)?.preview?.intent === 'fallback_form' && <FallbackForm onSend={onSend} />}
        {messages.at(-1)?.preview?.intent === 'order_preview' && !hasManualFormOpen && (
          <ChatOrderActions
            preview={messages.at(-1)?.preview}
            pendingOrder={pendingOrder}
            onPendingOrderChange={onPendingOrderChange}
            publicSettings={publicSettings}
            onConfirm={() => onSend('ya')}
            submitBlocked={submitBlocked}
            submitting={submitting}
            onEdit={onEdit}
            onCancel={onCancel}
          />
        )}
      </div>
      <InputBar onSend={onSend} onImage={onImage} />
      {detailOrder && (
        <OrderDetailModal
          order={detailOrder}
          onClose={() => setDetailOrder(null)}
          onExtendWait={onExtendWait}
          onKeepCancelled={onKeepCancelled}
        />
      )}
    </div>
  )
}

function shouldShowChatMessage(message: LocalMessage) {
  return !(message.from === 'bot' && (message.preview?.intent === 'service_menu' || message.preview?.intent === 'order_preview'))
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

function BelanjaOrderForm({ user, service, onSend }: { user: ReturnType<typeof useCustomerStore.getState>['user']; service: DynamicService | null; onSend: (text: string) => void }) {
  const [address, setAddress] = useState('')
  const [items, setItems] = useState('')
  const [purchaseAddress, setPurchaseAddress] = useState('')
  const [points, setPoints] = useState<string[]>([])
  const serviceType = service?.service_type ?? (service?.code?.toUpperCase() === 'DO' ? 'delivery' : 'belanja')
  const serviceLabel = serviceDisplayLabel(serviceType)
  const parsedItems = parseShoppingItems(items)
  const hasGacoan = /gacoan/i.test(items)
  const area = user?.branch_display_name ?? user?.branch_name ?? user?.branch ?? 'Area cabang belum diset silahkan hubungi CS'
  const pointText = points.map((point, index) => `Titik ${index + 1}: ${point}`).join('\n')

  useEffect(() => {
    if (hasGacoan) setPurchaseAddress('Jl. Sucipto Situbondo')
  }, [hasGacoan])

  const previewText = [
    `Layanan: ${serviceType}`,
    `Nama: ${user?.name ?? 'Customer Jojo'}`,
    `No. Hp: ${user?.phone ?? '-'}`,
    `Alamat Antar: ${address || '-'}`,
    `Lokasi Pembelian: ${purchaseAddress || '-'}`,
    '',
    'Pembelian:',
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
      <strong>Form {serviceLabel}</strong>
      <div className="belanja-profile-block">
        <label>Nama<input value={user?.name ?? 'Customer Jojo'} readOnly /></label>
        <label>Hp / WhatsApp<input value={user?.phone ?? '-'} readOnly /></label>
        <label>Alamat antar<input value={address} onChange={(event) => setAddress(event.target.value)} placeholder="Tulis alamat antar manual" /></label>
      </div>
      <label>Pembelian<textarea value={items} onChange={(event) => setItems(event.target.value)} placeholder="Tulis item yang ingin dibeli" /></label>
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

function OjekOrderForm({ user, service, onSend }: { user: ReturnType<typeof useCustomerStore.getState>['user']; service: DynamicService | null; onSend: (text: string) => void }) {
  const [pickupAddress, setPickupAddress] = useState('')
  const [destination, setDestination] = useState('')
  const [passengers, setPassengers] = useState('1')
  const [notes, setNotes] = useState('')
  const [seatRows, setSeatRows] = useState<2 | 3>(2)
  const [driverPreference, setDriverPreference] = useState<'general' | 'ladies'>('general')
  const [points, setPoints] = useState<string[]>([])
  const serviceType = service?.service_type ?? (service?.code?.toUpperCase() === 'JM' ? 'joker_mobil' : 'ojek')
  const serviceLabel = serviceDisplayLabel(serviceType)
  const jokerMobil = isJokerMobilService(serviceType)
  const pointText = points
    .map((point, index) => ({ label: `Titik ${index + 1}`, address: point.trim() }))
    .filter((point) => point.address)
    .map((point) => `${point.label}: ${point.address}`)
    .join('\n')

  const previewText = [
    `Ada pesanan ${serviceLabel} untuk Aplikasi Joker`,
    '',
    `Nama: ${user?.name ?? 'Customer Jojo'}`,
    `Hp / WhatsApp: ${user?.phone ?? '-'}`,
    `Alamat Jemput: ${pickupAddress || '-'}`,
    '',
    `Alamat Antar: ${destination}`,
    `Jumlah penumpang: ${passengers}`,
    jokerMobil ? `Seat / baris mobil: ${seatRows} baris` : `Preferensi driver: ${driverPreference === 'ladies' ? 'Ladies' : 'Umum'}`,
    '',
    `Catatan: ${notes}`,
    pointText,
  ].join('\n')

  return (
    <form
      className="ojek-form"
      onSubmit={(event) => {
        event.preventDefault()
        onSend(previewText)
      }}
    >
      <strong>Form {serviceLabel}</strong>
      <div className="ojek-section">
        <label>Nama<input value={user?.name ?? 'Customer Jojo'} readOnly /></label>
        <label>Hp / WhatsApp<input value={user?.phone ?? '-'} readOnly /></label>
        <label>Alamat Jemput<input value={pickupAddress} onChange={(event) => setPickupAddress(event.target.value)} placeholder="Tulis alamat jemput manual" /></label>
      </div>
      <div className="ojek-section">
        <label>Alamat Antar<textarea value={destination} onChange={(event) => setDestination(event.target.value)} /></label>
        <label>Jumlah penumpang<input value={passengers} onChange={(event) => setPassengers(event.target.value)} inputMode="numeric" /></label>
        {!jokerMobil && <div className="ladies-choice">
          <span>Pilihan driver</span>
          <div>
            <button type="button" className={driverPreference === 'general' ? 'active' : ''} onClick={() => setDriverPreference('general')}>Umum</button>
            <button type="button" className={driverPreference === 'ladies' ? 'active ladies' : ''} onClick={() => setDriverPreference('ladies')}>Ladies</button>
          </div>
          <small>{driverPreference === 'ladies' ? 'Order hanya dikirim ke driver Ladies area kamu.' : 'Order dapat diterima driver area yang tersedia.'}</small>
        </div>}
        {jokerMobil && (
          <div className="vehicle-seat-choice">
            <span>Tempat duduk Joker Mobil</span>
            <div>
              <button type="button" className={seatRows === 2 ? 'active' : ''} onClick={() => setSeatRows(2)}>
                <b>2 baris</b>
                <small>Citycar / umum</small>
              </button>
              <button type="button" className={seatRows === 3 ? 'active' : ''} onClick={() => setSeatRows(3)}>
                <b>3 baris</b>
                <small>MPV / keluarga</small>
              </button>
            </div>
          </div>
        )}
        <div className="belanja-points">
          <strong>Tambah titik</strong>
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

function GiftOrderForm({ user, branches, onSend }: { user: ReturnType<typeof useCustomerStore.getState>['user']; branches: Branch[]; onSend: (text: string) => void }) {
  const [receiver, setReceiver] = useState({ name: '', phone: '', address: '' })
  const [items, setItems] = useState('')
  const [purchaseAddress, setPurchaseAddress] = useState('')
  const [selectedBranchId, setSelectedBranchId] = useState('')
  const selectedBranch = branches.find((branch) => String(branch.id) === selectedBranchId) ?? null
  const area = selectedBranch ? branchDisplayLabel(selectedBranch) : ''
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
    `Area: ${area || '-'}`,
    selectedBranch ? `Branch ID: ${selectedBranch.id}` : null,
  ].filter((line): line is string => line !== null).join('\n')

  const canPreview = Boolean(
    selectedBranch
      && receiver.name.trim()
      && receiver.phone.trim()
      && receiver.address.trim()
      && items.trim()
      && purchaseAddress.trim(),
  )

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
      <label>
        Pilih area
        <select value={selectedBranchId} onChange={(event) => setSelectedBranchId(event.target.value)} required>
          <option value="">Pilih area tujuan order</option>
          {branches.map((branch) => <option key={branch.id} value={branch.id}>{branchDisplayLabel(branch)}</option>)}
        </select>
      </label>
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
      <div className="gift-preview">
        <strong>Preview order</strong>
        <p>{previewText}</p>
      </div>
      <button disabled={!canPreview}>
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
  const [vehicleSeatRows, setVehicleSeatRows] = useState<2 | 3>(2)
  const [submitting, setSubmitting] = useState(false)
  const [submitted, setSubmitted] = useState(false)
  const isJokerMobilForm = isJokerMobilService(serviceType)
  const pointText = points
    .map((point, index) => point.trim() ? `Titik ${index + 1}: ${point.trim()}` : '')
    .filter(Boolean)
    .join('\n')
  const vehicleText = isJokerMobilForm ? `Seat / baris mobil: ${vehicleSeatRows} baris` : ''
  const previewText = [dynamicFormText(fields, values, serviceType), vehicleText, pointText].filter(Boolean).join('\n')
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
      {isJokerMobilForm && (
        <div className="vehicle-seat-choice inline-service-choice">
          <span>Pilih seat / baris Joker Mobil</span>
          <div>
            <button type="button" className={vehicleSeatRows === 2 ? 'active' : ''} onClick={() => setVehicleSeatRows(2)}>
              <b>2 baris</b>
              <small>Citycar / umum</small>
            </button>
            <button type="button" className={vehicleSeatRows === 3 ? 'active' : ''} onClick={() => setVehicleSeatRows(3)}>
              <b>3 baris</b>
              <small>MPV / keluarga</small>
            </button>
          </div>
        </div>
      )}
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
  submitting,
  onEdit,
  onCancel,
}: {
  preview?: JojoBotPreview
  pendingOrder: OrderPayload | null
  onPendingOrderChange: (payload: OrderPayload | null) => void
  publicSettings: PublicSettings | null
  onConfirm: () => void
  submitBlocked: boolean
  submitting: boolean
  onEdit: () => void
  onCancel: () => void
}) {
  const [points, setPoints] = useState<string[]>([])
  const [showSummary, setShowSummary] = useState(preview?.intent === 'order_preview')
  const [qrisPreviewOpen, setQrisPreviewOpen] = useState(false)
  const configuredPaymentMethods = publicSettings?.payment?.methods?.length
    ? publicSettings.payment.methods
    : [{ key: 'cash', label: 'Pembayaran Cash', description: 'Bayar manual ke driver.' }]
  const qrisImageUrl = publicSettings?.payment?.qris_image_url ? cmsAssetUrl(publicSettings.payment.qris_image_url) : null
  const paymentMethods = qrisImageUrl && !configuredPaymentMethods.some((method) => method.key === 'qris')
    ? [...configuredPaymentMethods, { key: 'qris', label: 'Pembayaran QRIS', description: 'Scan QRIS aplikasi.' }]
    : configuredPaymentMethods
  const selectedPayment = pendingOrder?.payment_method ?? paymentMethods[0]?.key ?? 'cash'
  const defaultVehicle = defaultVehicleForService(pendingOrder?.service_type)
  const selectedVehicle = pendingOrder?.preferred_vehicle_type ?? defaultVehicle
  const selectedSeatRows = pendingOrder?.vehicle_seat_rows === 3 ? 3 : 2
  const isOjekOrder = isOjekService(pendingOrder?.service_type)
  const isJokerMobilOrder = isJokerMobilService(pendingOrder?.service_type)
  const showVehicleChoice = !isJokerMobilOrder && !isOjekOrder && !hidesVehicleChoiceForService(pendingOrder?.service_type)
  const showVehicleSummary = isJokerMobilOrder || isOjekOrder || showVehicleChoice
  const passengerCount = passengerCountFromPayload(pendingOrder)
  const doubleOrderConfirmed = pendingOrder?.service_payload?.confirm_double_order === true
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
      vehicle_seat_rows: vehicle === 'mobil' ? (pendingOrder.vehicle_seat_rows === 3 ? 3 : 2) : undefined,
      service_payload: {
        ...(pendingOrder.service_payload ?? {}),
        preferred_vehicle_type: vehicle,
        vehicle_seat_rows: vehicle === 'mobil' ? (pendingOrder.vehicle_seat_rows === 3 ? 3 : 2) : undefined,
      },
    })
  }
  const updateSeatRows = (rows: 2 | 3) => {
    if (!pendingOrder) return
    onPendingOrderChange({
      ...pendingOrder,
      preferred_vehicle_type: 'mobil',
      vehicle_seat_rows: rows,
      service_payload: {
        ...(pendingOrder.service_payload ?? {}),
        preferred_vehicle_type: 'mobil',
        vehicle_seat_rows: rows,
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
    if (points.length >= 5) return
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
      {!showSummary && (
        <>
          {points.map((point, index) => (
            <input key={index} value={point} onChange={(event) => updatePoint(index, event.target.value)} placeholder={`Titik tambahan ${index + 1}`} />
          ))}
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
          {isJokerMobilOrder && (
            <div className="vehicle-seat-choice">
              <span>Tempat duduk Joker Mobil</span>
              <div>
                <button type="button" className={selectedSeatRows === 2 ? 'active' : ''} onClick={() => updateSeatRows(2)}>
                  <b>2 baris</b>
                  <small>Citycar / umum</small>
                </button>
                <button type="button" className={selectedSeatRows === 3 ? 'active' : ''} onClick={() => updateSeatRows(3)}>
                  <b>3 baris</b>
                  <small>MPV / keluarga</small>
                </button>
              </div>
            </div>
          )}
          <div className="chat-action-row">
            <button type="button" disabled={points.length >= 5} onClick={addPoint}>+ Tambah Titik</button>
          </div>
          <div className="chat-action-row">
            <button type="button" onClick={() => setShowSummary(true)}>Preview Order</button>
          </div>
        </>
      )}
      {showSummary && (
        <div className="final-preview-card">
          <strong>Summary final</strong>
          <p>{orderSummaryText(pendingOrder, preview)}</p>
          <div className="payment-choice">
            {isOjekOrder && passengerCount > 2 && (
              <div className="passenger-warning">
                Penumpang lebih dari 2 orang. Silakan pilih layanan Mobil agar order lebih aman.
              </div>
            )}
            {isOjekOrder && passengerCount === 2 && (
              <label className="double-order-confirm">
                <input
                  type="checkbox"
                  checked={doubleOrderConfirmed}
                  onChange={(event) => pendingOrder && onPendingOrderChange({
                    ...pendingOrder,
                    service_payload: {
                      ...(pendingOrder.service_payload ?? {}),
                      confirm_double_order: event.target.checked,
                    },
                  })}
                />
                <span>Buat 2 order ojek dengan detail yang sama untuk 2 penumpang.</span>
              </label>
            )}
            {showVehicleChoice && (
              <>
                <label>
                  <span>Pilih kendaraan</span>
                  <select value={selectedVehicle} onChange={(event) => updateVehicle(event.target.value as 'motor' | 'mobil')}>
                    <option value="motor">Motor</option>
                    <option value="mobil">Mobil</option>
                  </select>
                </label>
                <small>{selectedVehicle === 'mobil' ? 'Order akan diberi catatan prioritas driver mobil.' : 'Default untuk ojek, delivery, kurir, dan belanja ringan.'}</small>
              </>
            )}
            {selectedVehicle === 'mobil' && showVehicleChoice && (
              <div className="vehicle-seat-choice">
                <span>Tempat duduk</span>
                <div>
                  <button type="button" className={selectedSeatRows === 2 ? 'active' : ''} onClick={() => updateSeatRows(2)}>
                    <b>2 baris</b>
                    <small>Citycar / umum</small>
                  </button>
                  <button type="button" className={selectedSeatRows === 3 ? 'active' : ''} onClick={() => updateSeatRows(3)}>
                    <b>3 baris</b>
                    <small>MPV / keluarga</small>
                  </button>
                </div>
              </div>
            )}
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
                  <div className="payment-qris-head">
                    <span>QRIS Aplikasi</span>
                    <button type="button" className="payment-qris-download" onClick={() => void downloadAsset(qrisImageUrl, 'qris-jojo.png')}>Download QRIS</button>
                  </div>
                  <button type="button" className="payment-qris-image-button" onClick={() => setQrisPreviewOpen(true)}>
                    <img src={qrisImageUrl} alt="QRIS pembayaran JojoApp" />
                  </button>
                </div>
              )}
              {transferAccounts.length === 0 && !qrisImageUrl && <p className="payment-account">Rekening transfer belum disetting admin.</p>}
            </div>
          )}
          {selectedPayment === 'qris' && (
            <div className="payment-transfer-panel">
              {qrisImageUrl ? (
                <div className="payment-qris">
                  <div className="payment-qris-head">
                    <span>QRIS Aplikasi</span>
                    <button type="button" className="payment-qris-download" onClick={() => void downloadAsset(qrisImageUrl, 'qris-jojo.png')}>Download QRIS</button>
                  </div>
                  <button type="button" className="payment-qris-image-button" onClick={() => setQrisPreviewOpen(true)}>
                    <img src={qrisImageUrl} alt="QRIS pembayaran JojoApp" />
                  </button>
                </div>
              ) : <p className="payment-account">QRIS belum disetting admin.</p>}
            </div>
          )}
          <div className="payment-order-note">
            <span>Pembayaran order</span>
            <strong>{selectedPaymentMethod?.label ?? selectedPayment}</strong>
          </div>
          {showVehicleSummary && (
            <div className="payment-order-note vehicle-note">
              <span>Kendaraan</span>
              <strong>{selectedVehicle === 'mobil' ? `Mobil ${selectedSeatRows} baris` : 'Motor'}</strong>
            </div>
          )}
          {isOjekOrder && (
            <div className="payment-order-note ladies-note">
              <span>Driver</span>
              <strong>{selectedDriverPreference === 'ladies' ? 'Ladies' : 'Umum'}</strong>
            </div>
          )}
          {submitBlocked && <p>Anda melebihi batas order aktif. Silakan selesaikan salah satu pesanan terlebih dahulu.</p>}
          {points.filter(Boolean).length > 0 && <p>{points.filter(Boolean).map((point, index) => `Titik ${index + 1}: ${point}`).join('\n')}</p>}
          <div>
            <button type="button" disabled={submitting || submitBlocked || (isOjekOrder && passengerCount > 2) || (isOjekOrder && passengerCount === 2 && !doubleOrderConfirmed)} onClick={onConfirm}>{submitting ? 'MENGIRIM...' : 'YA KIRIM'}</button>
            <button type="button" onClick={onEdit}>EDIT</button>
            <button type="button" className="cancel-order-preview" onClick={onCancel}>BATAL</button>
          </div>
          {qrisPreviewOpen && qrisImageUrl && <ImagePreviewModal imageUrl={qrisImageUrl} onClose={() => setQrisPreviewOpen(false)} downloadLabel="Download QRIS" />}
        </div>
      )}
    </div>
  )
}

function MessageBubble({
  message,
  onCs,
  onOrderDetail,
  onImageClick,
  onReply,
  onExtendWait,
  onKeepCancelled,
}: {
  message: LocalMessage
  onCs?: () => void
  onOrderDetail?: (order: Order) => void
  onImageClick?: (imageUrl: string) => void
  onReply?: (reply: ReplyTarget) => void
  onExtendWait?: (order: Order) => void
  onKeepCancelled?: (order: Order) => void
}) {
  const side = message.from === 'user' ? 'out' : 'in'
  const total = message.preview?.quote?.total_price ?? message.preview?.quote?.final_price
  const replyText = message.text || (message.imageUrl ? 'Foto' : 'Pesan')
  const canExtendWait = message.order ? isDriverTimeoutCancelledOrder(message.order) : false

  return (
    <article className={`message-bubble ${side}`}>
      {message.senderLabel && side === 'in' && <strong className="message-sender-label">{message.senderLabel}</strong>}
      {message.imageUrl && <button className="chat-image-button" type="button" onClick={() => onImageClick?.(message.imageUrl!)}><img src={message.imageUrl} alt="Lampiran customer" /></button>}
      {message.text && <p>{redactMapText(message.text)}</p>}
      {message.csLink && <button className="bubble-link" onClick={onCs}>Hubungi Operator</button>}
      {message.order && <button className="bubble-link order-detail-link" onClick={() => onOrderDetail?.(message.order!)}>Detail {message.order.order_code ?? `#${message.order.id}`}</button>}
      {message.order && canExtendWait && (
        <TimeoutChoiceActions
          order={message.order}
          onExtendWait={onExtendWait}
          onKeepCancelled={onKeepCancelled}
        />
      )}
      {total && <strong className="bubble-total">Total {formatRupiah(total)}</strong>}
      {onReply && <button className="bubble-reply" type="button" onClick={() => onReply({ id: message.id, text: replyText })}>Balas</button>}
      <time>{message.time}</time>
    </article>
  )
}

function InputBar({ onSend, onImage, replyTarget, onClearReply }: { onSend: (text: string) => void | Promise<void>; onImage: (file: File, caption?: string) => void | Promise<void>; replyTarget?: ReplyTarget | null; onClearReply?: () => void }) {
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

  const chooseDraftImage = (file?: File | null) => {
    if (!file) return
    const error = imageFileError(file)
    if (error) {
      store.showToast('error', error)
      return
    }

    if (draftImage) URL.revokeObjectURL(draftImage.url)
    setDraftImage({ file, url: URL.createObjectURL(file) })
  }

  return (
    <form className={`input-bar ${hasText ? 'is-typing' : ''}`} onSubmit={submit}>
      {attachmentOpen && (
        <AttachmentPanel
          onGallery={() => galleryInputRef.current?.click()}
          onCamera={() => cameraInputRef.current?.click()}
          onContact={() => void sendContact()}
        />
      )}
      <input ref={galleryInputRef} type="file" accept="image/*" hidden onChange={(event) => {
        const file = event.target.files?.[0]
        chooseDraftImage(file)
        event.currentTarget.value = ''
      }} />
      <input ref={cameraInputRef} type="file" accept="image/*" capture="environment" hidden onChange={(event) => {
        const file = event.target.files?.[0]
        chooseDraftImage(file)
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
        <VoiceRecorder
          onTranscript={(value) => {
            setText(value)
            requestAnimationFrame(() => textareaRef.current && autoResizeTextarea(textareaRef.current))
          }}
          hidden={hasText}
          compact
        />
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

function AttachmentPanel({ onGallery, onCamera, onContact }: { onGallery: () => void; onCamera: () => void; onContact: () => void }) {
  return (
    <div className="attachment-panel">
      <button type="button" onClick={onGallery}><span className="gallery"><ImageIcon size={27} /></span><b>Galeri</b></button>
      <button type="button" onClick={onCamera}><span className="camera"><Camera size={27} /></span><b>Kamera</b></button>
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
  const [status, setStatus] = useState<'idle' | 'listening' | 'processing' | 'stopped'>('idle')
  const recognitionRef = useRef<BrowserSpeechRecognition | null>(null)
  const shouldListenRef = useRef(false)
  const finalTranscriptRef = useRef('')
  const lastEmittedTranscriptRef = useRef('')
  const restartTimerRef = useRef<number | null>(null)

  useEffect(() => {
    return () => {
      shouldListenRef.current = false
      if (restartTimerRef.current) window.clearTimeout(restartTimerRef.current)
      recognitionRef.current?.abort?.()
      recognitionRef.current = null
    }
  }, [])

  const emitTranscript = useCallback((text: string) => {
    const corrected = correctSpeechText(text)
    if (corrected && corrected !== lastEmittedTranscriptRef.current) {
      lastEmittedTranscriptRef.current = corrected
      onTranscript(corrected)
    }
  }, [onTranscript])

  const start = useCallback(() => {
    const SpeechRecognitionApi = window.SpeechRecognition ?? window.webkitSpeechRecognition
    if (!SpeechRecognitionApi) {
      onTranscript('Voice tidak didukung browser ini')
      return
    }

    if (restartTimerRef.current) {
      window.clearTimeout(restartTimerRef.current)
      restartTimerRef.current = null
    }

    recognitionRef.current?.abort?.()

    const recognition = new SpeechRecognitionApi()
    recognition.lang = 'id-ID'
    recognition.continuous = true
    recognition.interimResults = true
    recognition.maxAlternatives = 1
    setStatus('listening')
    recognition.onresult = (event: BrowserSpeechRecognitionEvent) => {
      let interimTranscript = ''

      for (let index = event.resultIndex ?? 0; index < event.results.length; index += 1) {
        const transcript = event.results[index]?.[0]?.transcript ?? ''
        if (!transcript) continue

        if (event.results[index]?.isFinal) {
          finalTranscriptRef.current = appendSpeechSegment(finalTranscriptRef.current, transcript)
        } else {
          interimTranscript = appendSpeechSegment(interimTranscript, transcript)
        }
      }

      const nextText = appendSpeechSegment(finalTranscriptRef.current, interimTranscript)
      setStatus(interimTranscript ? 'processing' : 'listening')
      emitTranscript(nextText)
    }
    recognition.onerror = (event: BrowserSpeechRecognitionErrorEvent) => {
      if (!shouldListenRef.current) {
        setListening(false)
        setStatus('stopped')
        return
      }

      if (['no-speech', 'network', 'aborted'].includes(event.error)) {
        setStatus('listening')
        if (restartTimerRef.current) window.clearTimeout(restartTimerRef.current)
        restartTimerRef.current = window.setTimeout(() => {
          restartTimerRef.current = null
          if (shouldListenRef.current) start()
        }, event.error === 'network' ? 700 : 420)
        return
      }

      shouldListenRef.current = false
      setListening(false)
      setStatus('stopped')
      emitTranscript(finalTranscriptRef.current)
    }
    recognition.onend = () => {
      if (!shouldListenRef.current) {
        setListening(false)
        setStatus('stopped')
        return
      }

      setStatus('processing')
      if (restartTimerRef.current) window.clearTimeout(restartTimerRef.current)
      restartTimerRef.current = window.setTimeout(() => {
        restartTimerRef.current = null
        if (shouldListenRef.current) start()
      }, 320)
    }
    recognitionRef.current = recognition
    setListening(true)
    try {
      recognition.start()
    } catch {
      setListening(false)
      setStatus('stopped')
    }
  }, [emitTranscript, onTranscript])

  const toggle = () => {
    if (listening) {
      shouldListenRef.current = false
      if (restartTimerRef.current) window.clearTimeout(restartTimerRef.current)
      recognitionRef.current?.stop()
      setListening(false)
      setStatus('stopped')
      emitTranscript(finalTranscriptRef.current)
      return
    }

    finalTranscriptRef.current = ''
    lastEmittedTranscriptRef.current = ''
    shouldListenRef.current = true
    start()
  }

  return (
    <div className={`voice-control ${listening ? 'recording' : ''} ${hidden && !listening ? 'is-hidden' : ''}`} data-status={status}>
      <button type="button" className={`${compact ? 'input-icon voice-inline' : 'mic-button'} ${listening ? 'recording' : ''}`} onClick={toggle} aria-label={listening ? 'Stop voice input' : 'Voice input'} tabIndex={hidden && !listening ? -1 : 0} aria-hidden={hidden && !listening}>
        {listening ? (
          <span className="voice-wave" aria-hidden="true">
            <span />
            <span />
            <span />
          </span>
        ) : <Mic size={compact ? 25 : 28} />}
        {listening && <Square className="voice-stop-icon" size={compact ? 13 : 16} fill="currentColor" />}
      </button>
    </div>
  )
}

function appendSpeechSegment(current: string, segment: string) {
  const cleanSegment = correctSpeechText(segment)
  if (!cleanSegment) return correctSpeechText(current)

  const currentText = correctSpeechText(current)
  if (!currentText) return cleanSegment

  const currentWords = currentText.split(/\s+/u)
  const segmentWords = cleanSegment.split(/\s+/u)
  const maxOverlap = Math.min(12, currentWords.length, segmentWords.length)

  for (let size = maxOverlap; size > 0; size -= 1) {
    const tail = currentWords.slice(-size).join(' ').toLowerCase()
    const head = segmentWords.slice(0, size).join(' ').toLowerCase()
    if (tail === head) {
      return correctSpeechText([...currentWords, ...segmentWords.slice(size)].join(' '))
    }
  }

  const normalizedCurrent = currentText.toLowerCase()
  const normalizedSegment = cleanSegment.toLowerCase()
  if (normalizedCurrent.endsWith(normalizedSegment)) return currentText

  return correctSpeechText(`${currentText} ${cleanSegment}`)
}

function correctSpeechText(value: string) {
  let words = value
    .replace(/\s+/gu, ' ')
    .trim()
    .split(' ')
    .filter(Boolean)

  if (words.length === 0) return ''

  words = words.filter((word, index) => index === 0 || word.toLowerCase() !== words[index - 1]?.toLowerCase())

  let changed = true
  while (changed) {
    changed = false
    for (let phraseSize = Math.min(10, Math.floor(words.length / 2)); phraseSize >= 2; phraseSize -= 1) {
      for (let index = 0; index <= words.length - phraseSize * 2; index += 1) {
        const first = words.slice(index, index + phraseSize).join(' ').toLowerCase()
        const second = words.slice(index + phraseSize, index + phraseSize * 2).join(' ').toLowerCase()
        if (first !== second) continue
        words.splice(index + phraseSize, phraseSize)
        changed = true
        index = Math.max(-1, index - phraseSize)
      }
    }
  }

  for (let phraseSize = Math.min(8, Math.floor(words.length / 2)); phraseSize >= 2; phraseSize -= 1) {
    for (let index = 0; index <= words.length - phraseSize * 2; index += 1) {
      const first = words.slice(index, index + phraseSize).join(' ').toLowerCase()
      const second = words.slice(index + phraseSize, index + phraseSize * 2).join(' ').toLowerCase()
      if (first === second) {
        words.splice(index + phraseSize, phraseSize)
        index = Math.max(-1, index - phraseSize)
      }
    }
  }

  return words.join(' ').trim()
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
  const driverName = driverNameFromOrder(order)
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
      setMessages((rows) => {
        if (rows.some((row) => String(row.id) === String(incoming.id))) return rows
        if (incoming.sender_type !== 'customer' && incoming.sender_id !== store.user?.id) void playCustomerNotificationSound()

        return [...rows, incoming]
      })
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

  return (
    <div className="driver-chat">
      <div className="chat-date">{todayLabel()}</div>
      {order && (
        <div className="chat-participant-card">
          <span>Driver</span>
          <strong>{driverName !== '-' ? driverName : 'Driver belum tersedia'}</strong>
        </div>
      )}
      <div className="message-list" ref={listRef}>
        {!order && <MessageBubble message={{ id: 'no-order', from: 'system', text: 'Belum ada order yang diterima driver.', time: nowTime() }} />}
        {loading && <TypingIndicator />}
        {error && <MessageBubble message={{ id: 'driver-chat-error', from: 'system', text: error, time: nowTime() }} />}
        {!loading && !error && order && messages.length === 0 && (
          <MessageBubble
            message={{
              id: 'driver-welcome',
              from: 'driver',
              senderLabel: driverName !== '-' ? `${driverName} (Driver)` : 'Driver',
              text: `Order ${order.order_code ?? `#${order.id}`} sudah diterima ${driverName !== '-' ? driverName : 'driver'}. Silakan mulai chat.`,
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
                senderLabel: from === 'user' ? undefined : chatParticipantLabel(message, driverName),
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
      <InputBar onSend={(text) => void sendDriverChat(text)} onImage={(file, caption) => void sendDriverChat(caption || file.name, file)} replyTarget={replyTarget} onClearReply={() => setReplyTarget(null)} />
      {previewImage && <ImagePreviewModal imageUrl={previewImage} onClose={() => setPreviewImage(null)} />}
    </div>
  )
}

function CsChatScreen({ initialConversationId }: { initialConversationId?: number | null }) {
  const store = useCustomerStore()
  const [conversationId, setConversationId] = useState<number | null>(initialConversationId ?? null)
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

  useEffect(() => {
    if (!initialConversationId || initialConversationId === conversationId) return

    setConversationId(initialConversationId)
    setConversation(null)
    setMessages([])
    setError('')
  }, [conversationId, initialConversationId])

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
      setMessages((rows) => {
        if (rows.some((row) => String(row.id) === String(incoming.id))) return rows
        if (incoming.sender_type !== 'customer' && incoming.sender_id !== store.user?.id) void playCustomerNotificationSound()

        return [...rows, incoming]
      })
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
                senderLabel: from === 'user' ? undefined : chatParticipantLabel(message),
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

function ImagePreviewModal({ imageUrl, onClose, downloadLabel }: { imageUrl: string; onClose: () => void; downloadLabel?: string }) {
  return (
    <div className="image-editor-backdrop" onClick={onClose}>
      <div className="image-preview" onClick={(event) => event.stopPropagation()}>
        <button type="button" onClick={onClose}>x</button>
        {downloadLabel && <button type="button" className="image-preview-download" onClick={() => void downloadAsset(imageUrl, 'qris-jojo.png')}>{downloadLabel}</button>}
        <img src={imageUrl} alt="Preview lampiran" />
      </div>
    </div>
  )
}

function OrderDetailModal({
  order,
  onClose,
  onExtendWait,
  onKeepCancelled,
}: {
  order: Order
  onClose: () => void
  onExtendWait?: (order: Order) => void
  onKeepCancelled?: (order: Order) => void
}) {
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
          {orderHelperRows(order).map((helper) => (
            <p key={`${helper.label}-${helper.driver}`}>
              <span>{helper.label}</span>
              <strong>{helper.driver}{helper.fee > 0 ? ` - ${formatRupiah(helper.fee)}` : ''}</strong>
            </p>
          ))}
          <p><span>Pembayaran</span><strong>{order.payment_label ?? paymentMethodLabel(order.payment_method)}</strong></p>
          <p><span>Total</span><strong>{formatRupiah(order.total_price ?? order.total)}</strong></p>
        </div>
        <OrderReasonNote order={order} />
        {isDriverTimeoutCancelledOrder(order) && (
          <TimeoutChoiceActions
            order={order}
            onExtendWait={onExtendWait}
            onKeepCancelled={onKeepCancelled}
            block
          />
        )}
      </section>
    </div>
  )
}

function TimeoutChoiceActions({
  order,
  onExtendWait,
  onKeepCancelled,
  block = false,
}: {
  order: Order
  onExtendWait?: (order: Order) => void
  onKeepCancelled?: (order: Order) => void
  block?: boolean
}) {
  return (
    <div className={block ? 'timeout-choice-actions block' : 'timeout-choice-actions'}>
      <button type="button" className="wait" onClick={() => onExtendWait?.(order)}>
        Menunggu 10 menit lagi
      </button>
      <button type="button" className="cancel" onClick={() => onKeepCancelled?.(order)}>
        Cancel
      </button>
    </div>
  )
}

function orderHelperRows(order: Order) {
  const crewRows = (order.crews ?? [])
    .filter((crew) => crew.role !== 'rider')
    .map((crew) => ({
      label: crew.label || 'Helper',
      driver: crew.driver || (crew.status === 'pending' ? 'Menunggu helper' : '-'),
      fee: Number(crew.service_charge ?? 0),
    }))

  if (crewRows.length > 0) return crewRows

  const fee = Number(order.pricing_breakdown?.crew_helper_fee ?? order.pricing_breakdown?.helper_service_charge ?? order.crew_decision?.helper_fee ?? order.crew_decision?.helper_service_charge ?? 0)
  if (fee <= 0) return []

  return [{
    label: order.crew_decision?.helper_label || order.pricing_breakdown?.crew_decision?.helper_label || 'Jasa helper',
    driver: 'Menunggu helper',
    fee,
  }]
}

function OrderReasonNote({ order, compact = false }: { order: Order; compact?: boolean }) {
  const reasons = orderReasonItems(order)
  if (reasons.length === 0) return null

  return (
    <div className={compact ? 'order-reason-note compact' : 'order-reason-note'}>
      {reasons.map((item) => (
        <p key={`${item.label}-${item.text}`}>
          <span>{item.label}</span>
          <strong>{item.text}</strong>
        </p>
      ))}
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
  onExtendWait,
  onKeepCancelled,
}: {
  orders: Order[]
  onOpenDriverChat: (order: Order) => void
  onOrdersChanged: (orders: Order[]) => void
  onExtendWait: (order: Order) => void
  onKeepCancelled: (order: Order) => void
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
    <div className="simple-page history-page">
      <div className="history-head">
        <div>
          <h1>History Order</h1>
          <p>{visibleOrders.length} order pada {formatMonthLabel(selectedMonth)}</p>
        </div>
        <select value={selectedMonth} onChange={(event) => setSelectedMonth(event.target.value)}>
          {monthOptions.map((key) => <option key={key} value={key}>{formatMonthLabel(key)}</option>)}
        </select>
      </div>
      <div className="history-scroll">
        {orders.length === 0 && <p className="history-empty">Belum ada order.</p>}
        {orders.length > 0 && visibleOrders.length === 0 && <p className="history-empty">Tidak ada order pada periode ini.</p>}
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
                <OrderReasonNote order={order} compact />
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
              {isDriverTimeoutCancelledOrder(order) && (
                <TimeoutChoiceActions
                  order={order}
                  onExtendWait={onExtendWait}
                  onKeepCancelled={onKeepCancelled}
                />
              )}
            </div>
          </article>
        ))}
      </div>
      {detailOrder && (
        <OrderDetailModal
          order={detailOrder}
          onClose={() => setDetailOrder(null)}
          onExtendWait={onExtendWait}
          onKeepCancelled={onKeepCancelled}
        />
      )}
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
  const token = useCustomerStore((state) => state.token)
  const setUserSession = useCustomerStore((state) => state.setUserSession)
  const user = store.user
  const fileInputRef = useRef<HTMLInputElement | null>(null)
  const formDirtyRef = useRef(false)
  const syncedUserIdRef = useRef<number | null>(user?.id ?? null)
  const [name, setName] = useState(profileText(user?.name))
  const [phone, setPhone] = useState(profileText(user?.phone))
  const [address, setAddress] = useState(profileText(user?.address))
  const [profilePhoto, setProfilePhoto] = useState<File | null>(null)
  const [profilePhotoPreview, setProfilePhotoPreview] = useState<string | null>(null)
  const [photoVersion, setPhotoVersion] = useState(() => Date.now())
  const [imageFailed, setImageFailed] = useState(false)
  const [saving, setSaving] = useState(false)
  const [loadError, setLoadError] = useState('')
  const [hasCustomSound, setHasCustomSound] = useState(false)
  const branchLabel = firstProfileText([user?.branch_display_name, user?.branch_name, user?.branch], 'Cabang belum diset')
  const areaStatus = user?.area_status === 'inside_branch'
    ? 'Di area cabang'
    : user?.area_status === 'outside_branch'
      ? 'Di luar area cabang'
      : 'Area belum tervalidasi'
  const photoUrl = useMemo(() => {
    if (profilePhotoPreview) return profilePhotoPreview
    if (!user?.profile_photo_url) return ''

    const url = cmsAssetUrl(user.profile_photo_url)
    const separator = url.includes('?') ? '&' : '?'
    return `${url}${separator}v=${photoVersion}`
  }, [photoVersion, profilePhotoPreview, user?.profile_photo_url])

  useEffect(() => {
    if (!token || user) return

    let active = true
    setLoadError('')

    void fetchMe()
      .then((freshUser) => {
        if (!active) return
        setUserSession(freshUser, token)
      })
      .catch((error) => {
        if (!active) return
        setLoadError(getApiErrorMessage(error, 'Profile gagal dimuat. Silakan buka ulang halaman.'))
      })

    return () => {
      active = false
    }
  }, [setUserSession, token, user])

  useEffect(() => {
    if (syncedUserIdRef.current === user?.id && formDirtyRef.current) return

    syncedUserIdRef.current = user?.id ?? null
    setName(profileText(user?.name))
    setPhone(profileText(user?.phone))
    setAddress(profileText(user?.address))
    setImageFailed(false)
  }, [user?.id, user?.address, user?.name, user?.phone, user?.profile_photo_url])

  useEffect(() => () => {
    if (profilePhotoPreview) URL.revokeObjectURL(profilePhotoPreview)
  }, [profilePhotoPreview])

  useEffect(() => {
    void hasCustomCustomerNotificationSound().then(setHasCustomSound)
  }, [])

  const choosePhoto = (file: File | null) => {
    if (file) {
      const error = imageFileError(file, 'Foto profil')
      if (error) {
        store.showToast('error', error)
        return
      }
    }

    if (profilePhotoPreview) URL.revokeObjectURL(profilePhotoPreview)
    formDirtyRef.current = true
    setImageFailed(false)
    setProfilePhoto(file)
    setProfilePhotoPreview(file ? URL.createObjectURL(file) : null)
  }

  const updateField = (setter: (value: string) => void) => (value: string) => {
    formDirtyRef.current = true
    setter(value)
  }

  if (!user) {
    return (
      <div className="profile-page">
        <section className="wa-profile-empty">
          <span><UserRound size={34} /></span>
          <strong>{loadError ? 'Profile belum bisa dimuat' : 'Memuat profile'}</strong>
          <p>{loadError || 'Data akun sedang disinkronkan. Tunggu sebentar.'}</p>
        </section>
      </div>
    )
  }

  return (
    <div className="profile-page">
      <section className="wa-profile-hero">
        <button className="profile-photo-button" type="button" onClick={() => fileInputRef.current?.click()} aria-label="Ganti foto profile">
          {photoUrl && !imageFailed ? <img src={photoUrl} alt="Foto profile" loading="eager" decoding="async" onError={() => setImageFailed(true)} /> : <UserRound size={58} />}
          <span><Camera size={18} /></span>
        </button>
        <button type="button" className="wa-edit-link" onClick={() => fileInputRef.current?.click()}>Edit</button>
        <div className="wa-profile-summary">
          <strong>{name.trim() || profileText(user.name) || 'Customer JojoApp'}</strong>
          <span>{phone.trim() || profileText(user.phone) || 'Nomor belum diisi'}</span>
        </div>
        <div className="wa-profile-status-grid">
          <span className={user?.area_status === 'inside_branch' ? 'ok' : user?.area_status === 'outside_branch' ? 'warn' : ''}>
            <MapPin size={15} />
            {areaStatus}
          </span>
          <span>
            <Store size={15} />
            {branchLabel}
          </span>
        </div>
        {profilePhoto && <small className="wa-photo-selected">{profilePhoto.name}</small>}
        <input ref={fileInputRef} className="sr-only-file" type="file" accept="image/*" onChange={(event) => choosePhoto(event.target.files?.[0] ?? null)} />
      </section>
      {setupMode && <div className="profile-setup-alert">Nama, phone, dan alamat wajib diisi sebelum membuat order.</div>}
      <section className="wa-profile-sound">
        <div>
          <strong>Suara Notifikasi</strong>
          <span>{hasCustomSound ? 'Custom aktif di perangkat ini' : 'Default customernotif aktif'}</span>
        </div>
        <div>
          <button type="button" onClick={() => void playCustomerNotificationSound()}>Tes Suara</button>
          <label>
            Upload Custom
            <input
              type="file"
              accept="audio/*"
              hidden
              onChange={(event) => {
                const file = event.target.files?.[0] ?? null
                event.currentTarget.value = ''
                if (!file) return
                void saveCustomCustomerNotificationSound(file)
                  .then(() => {
                    setHasCustomSound(true)
                    store.showToast('success', 'Suara notifikasi custom tersimpan di perangkat ini')
                  })
                  .catch((error) => store.showToast('error', getApiErrorMessage(error, 'Gagal menyimpan audio custom')))
              }}
            />
          </label>
          {hasCustomSound && <button type="button" onClick={() => void clearCustomCustomerNotificationSound().then(() => { setHasCustomSound(false); store.showToast('success', 'Kembali ke suara default') })}>Default</button>}
        </div>
      </section>
      <form
        className="wa-profile-form"
        onSubmit={async (event) => {
          event.preventDefault()
          setSaving(true)
          try {
            const updated = await updateProfile({ name, phone, address, profile_photo: profilePhoto })
            formDirtyRef.current = false
            syncedUserIdRef.current = updated.id
            store.setUserSession(updated, store.token)
            setName(profileText(updated.name))
            setPhone(profileText(updated.phone))
            setAddress(profileText(updated.address))
            setPhotoVersion(Date.now())
            setImageFailed(false)
            setProfilePhoto(null)
            if (profilePhotoPreview) URL.revokeObjectURL(profilePhotoPreview)
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
        <ProfileField icon={<UserRound size={25} />} label="Nama" hint="Nama ini terlihat oleh operator dan driver.">
          <input value={name} onChange={(event) => updateField(setName)(event.target.value)} placeholder="Nama customer" autoComplete="name" />
        </ProfileField>
        <ProfileField icon={<Phone size={25} />} label="Telepon" hint="Nomor aktif untuk konfirmasi order.">
          <input value={phone} onChange={(event) => updateField(setPhone)(event.target.value)} placeholder="+62..." inputMode="tel" autoComplete="tel" />
        </ProfileField>
        <ProfileField icon={<MessageCircle size={25} />} label="Email" hint="Dipakai untuk login dan notifikasi akun.">
          <input value={profileText(user.email, '-')} readOnly />
        </ProfileField>
        <ProfileField icon={<MapPin size={25} />} label="Alamat" hint="Alamat profil, alamat order tetap bisa diisi manual.">
          <textarea value={address} onChange={(event) => updateField(setAddress)(event.target.value)} placeholder="Alamat utama" autoComplete="street-address" />
        </ProfileField>
        <button className="wa-save-button" disabled={saving || !name.trim() || !phone.trim() || !address.trim()}>{saving ? 'Menyimpan...' : 'Simpan Profile'}</button>
      </form>
    </div>
  )
}

function ProfileField({ icon, label, hint, children }: { icon: ReactNode; label: string; hint: string; children: ReactNode }) {
  return (
    <label className="wa-profile-field">
      <span className="wa-profile-icon">{icon}</span>
      <span className="wa-profile-control">
        <strong>{label}</strong>
        {children}
        <small>{hint}</small>
      </span>
    </label>
  )
}

function CustomerLoginScreen(_props: { onDone: () => void }) {
  const googleHref = googleLoginUrl()

  return (
    <div className="simple-page login-page">
      <div className="login-heading">
        <h1>Login Customer</h1>
        <p>Masuk atau daftar customer cukup menggunakan akun Google.</p>
      </div>
      <PwaInstallButton />
      <div className="profile-edit-form">
        <a className="google-login-button" href={googleHref}>
          Login / Register by Google
        </a>
        <small>Akun customer baru akan dibuat otomatis setelah Google berhasil diverifikasi.</small>
      </div>
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

async function getBrowserLocation(options: PositionOptions = {}) {
  if (!('geolocation' in navigator)) return null

  try {
    const position = await new Promise<GeolocationPosition>((resolve, reject) => {
      navigator.geolocation.getCurrentPosition(resolve, reject, {
        enableHighAccuracy: options.enableHighAccuracy ?? true,
        timeout: options.timeout ?? 10000,
        maximumAge: options.maximumAge ?? 0,
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

function openCustomerSoundDb(): Promise<IDBDatabase> {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open(CUSTOMER_SOUND_DB, 1)
    request.onupgradeneeded = () => {
      const db = request.result
      if (!db.objectStoreNames.contains(CUSTOMER_SOUND_STORE)) db.createObjectStore(CUSTOMER_SOUND_STORE)
    }
    request.onsuccess = () => resolve(request.result)
    request.onerror = () => reject(request.error ?? new Error('IndexedDB tidak tersedia'))
  })
}

async function customerSoundStore(mode: IDBTransactionMode, work: (store: IDBObjectStore) => IDBRequest) {
  const db = await openCustomerSoundDb()
  return new Promise<unknown>((resolve, reject) => {
    const transaction = db.transaction(CUSTOMER_SOUND_STORE, mode)
    const request = work(transaction.objectStore(CUSTOMER_SOUND_STORE))
    request.onsuccess = () => resolve(request.result)
    request.onerror = () => reject(request.error ?? new Error('Gagal mengakses audio notifikasi'))
    transaction.oncomplete = () => db.close()
    transaction.onerror = () => {
      db.close()
      reject(transaction.error ?? new Error('Gagal menyimpan audio notifikasi'))
    }
  })
}

async function saveCustomCustomerNotificationSound(file: File) {
  if (!file.type.startsWith('audio/')) throw new Error('File harus berupa audio.')
  if (file.size > 2 * 1024 * 1024) throw new Error('Ukuran audio maksimal 2 MB.')

  await customerSoundStore('readwrite', (store) => store.put(file, CUSTOMER_SOUND_KEY))
}

async function clearCustomCustomerNotificationSound() {
  await customerSoundStore('readwrite', (store) => store.delete(CUSTOMER_SOUND_KEY))
}

async function hasCustomCustomerNotificationSound() {
  try {
    const blob = await customerSoundStore('readonly', (store) => store.get(CUSTOMER_SOUND_KEY))

    return blob instanceof Blob
  } catch {
    return false
  }
}

async function customerNotificationSoundUrl() {
  try {
    const blob = await customerSoundStore('readonly', (store) => store.get(CUSTOMER_SOUND_KEY))
    if (blob instanceof Blob) return URL.createObjectURL(blob)
  } catch {
    // fallback ke default audio.
  }

  return DEFAULT_CUSTOMER_NOTIFICATION_SOUND
}

async function playCustomerNotificationSound() {
  if (typeof Audio === 'undefined') return

  const url = await customerNotificationSoundUrl()
  const audio = new Audio(url)
  audio.preload = 'auto'
  audio.volume = 1

  try {
    await audio.play()
  } catch {
    // Browser bisa menolak autoplay sebelum ada interaksi user.
  } finally {
    if (url.startsWith('blob:')) window.setTimeout(() => URL.revokeObjectURL(url), 3000)
  }
}

function formatOrderTime(value?: string) {
  const date = parseJojoDate(value)

  if (!date) return '--:--'

  return new Intl.DateTimeFormat('id-ID', { hour: '2-digit', minute: '2-digit' }).format(date)
}

function formatOrderDate(value?: string) {
  const date = parseJojoDate(value)

  if (!date) return 'Tanggal belum tersedia'

  return new Intl.DateTimeFormat('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }).format(date)
}

function profileText(value: unknown, fallback = '') {
  if (typeof value === 'string') return value
  if (typeof value === 'number') return String(value)

  return fallback
}

function firstProfileText(values: unknown[], fallback = '') {
  for (const value of values) {
    const text = profileText(value).trim()
    if (text) return text
  }

  return fallback
}

function monthKey(value?: string | null) {
  const date = parseJojoDate(value) ?? (!value ? new Date() : null)
  if (!date) return ''

  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`
}

function parseJojoDate(value?: string | null) {
  if (!value) return null

  const normalized = String(value).trim()
  const candidates = [
    normalized,
    normalized.replace(' ', 'T'),
    normalized.replace(' ', 'T').replace(/(\.\d+)?$/, ''),
  ]

  for (const candidate of candidates) {
    const date = new Date(candidate)
    if (!Number.isNaN(date.getTime())) return date
  }

  const match = normalized.match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?/)
  if (!match) return null

  const [, year, month, day, hour = '0', minute = '0', second = '0'] = match
  const date = new Date(Number(year), Number(month) - 1, Number(day), Number(hour), Number(minute), Number(second))

  return Number.isNaN(date.getTime()) ? null : date
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

function isDriverTimeoutCancelledOrder(order: Order) {
  if (!isCancelledStatus(order.status)) return false
  if (order.feedback?.type === 'order_auto_cancelled') {
    return !/multi-crew|helper/i.test(order.feedback.message ?? '')
  }

  const notes = String(order.notes ?? '')
  const reason = String(order.cancel_reason ?? '')

  if (/multi-crew|helper/i.test(notes) || /multi-crew|helper/i.test(reason)) return false

  return /driver timeout|batas waktu mencari driver|batas waktu cari driver/i.test(notes)
    || /driver timeout|batas waktu mencari driver|batas waktu cari driver/i.test(reason)
}

function cancelFeedbackMessage(order: Order) {
  return order.feedback?.message
    ?? order.cancel_reason
    ?? cancelReasonFromNotes(order.notes)
    ?? 'Maaf, order Anda dibatalkan. Silakan buat order ulang atau hubungi CS.'
}

function orderReasonItems(order: Order) {
  const items: Array<{ label: string; text: string }> = []
  const cancelReason = order.feedback?.message ?? order.cancel_reason ?? cancelReasonFromNotes(order.notes)

  if (cancelReason && isCancelledStatus(order.status)) {
    items.push({ label: 'Alasan cancel', text: cancelReason })
  }

  const adjustments = order.adjustments ?? []
  adjustments.forEach((adjustment, index) => {
    if (!adjustment.reason) return
    const amount = Number(adjustment.amount ?? 0)
    const driver = adjustment.driver?.user?.name
    items.push({
      label: `Service charge ${index + 1}`,
      text: `${amount > 0 ? `${formatRupiah(amount)} - ` : ''}${adjustment.reason}${driver ? ` (${driver})` : ''}`,
    })
  })

  return items
}

function cancelReasonFromNotes(notes?: string | null) {
  const lines = String(notes ?? '')
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter(Boolean)

  if (lines.length === 0) return ''

  const lastLine = lines.at(-1) ?? ''
  if (/driver timeout|batas waktu/i.test(lastLine)) return 'Batas waktu mencari driver habis (10 menit).'

  return lastLine.replace(/^cancel(?:led)?(?:\s+reason)?\s*:\s*/i, '').trim()
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
  return Boolean(user && (!user.branch_id || user.area_status === 'outside_branch'))
}

function branchDisplayLabel(branch: Branch) {
  return branch.display_name || [branch.branch_code, branch.name, branch.area].filter(Boolean).join(' - ') || `Area #${branch.id}`
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

function chatParticipantLabel(message: ChatMessage, fallbackDriverName = '') {
  const senderName = message.sender?.name ?? message.sender_name
  const role = String(message.sender_type ?? '').toLowerCase()
  if (senderName) return `${senderName} (${chatRoleLabel(role)})`
  if (role === 'driver' && fallbackDriverName && fallbackDriverName !== '-') return `${fallbackDriverName} (Driver)`

  return chatRoleLabel(role)
}

function chatRoleLabel(role?: string | null) {
  const key = String(role ?? '').toLowerCase()
  if (key === 'driver') return 'Driver'
  if (key === 'customer') return 'Customer'
  if (key === 'operator') return 'Operator'
  if (key === 'eksekutor') return 'Eksekutor'
  if (key === 'manager') return 'Manager'
  if (key === 'spv') return 'SPV'
  if (key === 'admin') return 'Admin'
  if (key === 'bot') return 'JOJOBOT'

  return 'JOJO'
}

async function downloadAsset(url: string, filename: string) {
  try {
    const response = await fetch(url, { mode: 'cors' })
    if (!response.ok) throw new Error(`HTTP ${response.status}`)
    const blob = await response.blob()
    const objectUrl = URL.createObjectURL(blob)
    const anchor = document.createElement('a')
    anchor.href = objectUrl
    anchor.download = filename
    document.body.appendChild(anchor)
    anchor.click()
    anchor.remove()
    window.setTimeout(() => URL.revokeObjectURL(objectUrl), 1000)
  } catch {
    const anchor = document.createElement('a')
    anchor.href = url
    anchor.download = filename
    anchor.target = '_blank'
    anchor.rel = 'noreferrer'
    document.body.appendChild(anchor)
    anchor.click()
    anchor.remove()
  }
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

function redactMapText(message?: string | null) {
  if (!message) return ''

  return message
    .replace(/https?:\/\/(?:www\.)?(?:google\.com\/maps|maps\.google\.com|maps\.app\.goo\.gl)\S*/giu, '[lokasi disembunyikan]')
    .replace(/(-?\d{1,2}(?:\.\d+)?)\s*,\s*(-?\d{1,3}(?:\.\d+)?)/g, '[koordinat disembunyikan]')
}

declare global {
  interface Window {
    SpeechRecognition?: BrowserSpeechRecognitionConstructor
    webkitSpeechRecognition?: BrowserSpeechRecognitionConstructor
  }
}

type BrowserSpeechRecognitionEvent = {
  resultIndex?: number
  results: ArrayLike<ArrayLike<{ transcript: string }> & { isFinal?: boolean }>
}

type BrowserSpeechRecognitionErrorEvent = {
  error: string
}

type BrowserSpeechRecognition = {
  lang: string
  continuous: boolean
  interimResults: boolean
  maxAlternatives?: number
  onresult: ((event: BrowserSpeechRecognitionEvent) => void) | null
  onerror: ((event: BrowserSpeechRecognitionErrorEvent) => void) | null
  onend: (() => void) | null
  start: () => void
  stop: () => void
  abort?: () => void
}

type BrowserSpeechRecognitionConstructor = new () => BrowserSpeechRecognition

export default App
