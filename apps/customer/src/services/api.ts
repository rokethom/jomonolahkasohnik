import axios, { AxiosError } from 'axios'
import type { Address, Branch, ChatConversation, ChatMessage, DynamicService, GeocodeResult, Order, PriceQuote, ServiceType, User, HomeData, PublicSettings } from '../types'

function resolveApiBase() {
  const configured = import.meta.env.VITE_API_BASE_URL ?? import.meta.env.VITE_API_URL ?? 'http://127.0.0.1:8000/api'
  const isBrowser = typeof window !== 'undefined'
  const isPublicHost = isBrowser && !['localhost', '127.0.0.1', '::1'].includes(window.location.hostname)
  const pointsToLocalhost = /^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?\/api\/?$/i.test(configured)

  if (isPublicHost && pointsToLocalhost) {
    return window.location.hostname.endsWith('aplikasijoker.my.id')
      ? 'https://aplikasijoker.my.id/api'
      : `${window.location.origin}/api`
  }

  return configured
}

export const API_BASE = resolveApiBase()

export const api = axios.create({
  baseURL: API_BASE,
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json',
  },
})

api.interceptors.request.use((config) => {
  const token = localStorage.getItem('customer_token') || localStorage.getItem('token')
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

export type LoginPayload = {
  email: string
  password: string
}

export type RegisterPayload = LoginPayload & {
  name: string
  username?: string
  phone?: string
  password_confirmation: string
  lat: number
  lng: number
  location_lat?: number
  location_lng?: number
  location_accuracy?: number
  gps_timestamp?: string
}

export type OrderPayload = {
  service_type: ServiceType
  pickup_address: string
  pickup_lat: number
  pickup_lng: number
  destination_address: string
  destination_lat: number
  destination_lng: number
  stops: number
  branch_id?: number
  destination_text?: string
  notes?: string
  service_payload?: Record<string, unknown>
  items?: Array<{ name: string; quantity?: number; qty?: number; price?: number; notes?: string }>
  points?: Array<{ label?: string; address: string }>
  payment_method?: 'cash' | 'transfer' | string
  preferred_vehicle_type?: 'motor' | 'mobil'
  vehicle_seat_rows?: 2 | 3
  driver_preference?: 'general' | 'ladies'
}

export type GeocodePayload = {
  address: string
  pickup_address?: string
  pickup_lat?: number
  pickup_lng?: number
  service_type?: ServiceType
  branch_id?: number
  stops?: number
  notes?: string
}

export async function login(payload: LoginPayload) {
  const { data } = await api.post<{ user: User; token: string }>('/auth/login', payload)
  return data
}

export async function register(payload: RegisterPayload) {
  const { data } = await api.post<{ user: User; token: string }>('/auth/register', payload)
  return data
}

export function getApiErrorMessage(error: unknown, fallback = 'Request gagal') {
  if (error instanceof AxiosError) {
    const response = error.response?.data as { message?: string; errors?: Record<string, string[]> } | undefined
    const firstError = response?.errors ? Object.values(response.errors).flat()[0] : undefined
    return firstError ?? response?.message ?? fallback
  }

  return fallback
}

export function googleLoginUrl() {
  return `${API_BASE}/auth/google/redirect`
}

export async function fetchMe() {
  const { data } = await api.get<User>('/user')
  return data
}

export async function updateUserLocation(payload: { lat: number; lng: number; accuracy?: number; gps_timestamp?: string }) {
  const { data } = await api.post<{
    data: {
      status: 'inside_branch' | 'outside_branch'
      inside_branch: boolean
      branch: { id: number; name: string; area?: string | null; display_name?: string } | null
      nearest_branch?: { id: number; name: string; area?: string | null; display_name?: string } | null
      distance_meters?: number | null
      nearest_distance_meters?: number | null
      user: User
    }
  }>('/user/location', payload)
  return data.data
}

export async function updateProfile(payload: { name: string; phone: string; address: string; branch_id?: number | null; profile_photo?: File | null }) {
  if (payload.profile_photo) {
    const profilePhoto = await resizeImageFile(payload.profile_photo, {
      maxWidth: 640,
      maxHeight: 640,
      quality: 0.78,
      fileNamePrefix: 'customer-profile',
    })
    const form = new FormData()
    form.append('name', payload.name)
    form.append('phone', payload.phone)
    form.append('address', payload.address)
    if (payload.branch_id) form.append('branch_id', String(payload.branch_id))
    form.append('profile_photo', profilePhoto)
    const { data } = await api.post<User>('/user/profile', form, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    return data
  }

  const { data } = await api.put<User>('/user/profile', payload)
  return data
}

type ResizeImageOptions = {
  maxWidth: number
  maxHeight: number
  quality: number
  fileNamePrefix: string
}

async function resizeImageFile(file: File, options: ResizeImageOptions): Promise<File> {
  if (!file.type.startsWith('image/') || typeof document === 'undefined') return file

  const bitmap = await createImageBitmap(file).catch(() => null)
  if (!bitmap) return file

  const ratio = Math.min(options.maxWidth / bitmap.width, options.maxHeight / bitmap.height, 1)
  const width = Math.max(1, Math.round(bitmap.width * ratio))
  const height = Math.max(1, Math.round(bitmap.height * ratio))
  const canvas = document.createElement('canvas')
  canvas.width = width
  canvas.height = height
  const context = canvas.getContext('2d')
  if (!context) return file

  context.drawImage(bitmap, 0, 0, width, height)
  bitmap.close?.()

  const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/jpeg', options.quality))
  if (!blob || blob.size >= file.size) return file

  return new File([blob], `${options.fileNamePrefix}-${Date.now()}.jpg`, { type: 'image/jpeg' })
}

export async function fetchBranches() {
  const { data } = await api.get<{ data: Branch[] }>('/branches')
  return data.data
}

export async function fetchHome() {
  const { data } = await api.get<HomeData>('/home')
  return data
}

export async function fetchPublicSettings() {
  const { data } = await api.get<{ data: PublicSettings }>('/settings/public')
  return data.data
}

export async function registerDeviceToken(payload: { token: string; platform?: string; app?: string }) {
  const { data } = await api.post<{ data: unknown }>('/push/device-token', payload)
  return data.data
}

export async function fetchServices() {
  const { data } = await api.get<{ data: DynamicService[] }>('/services')
  return data.data
}

export type KeywordParserConfig = {
  id: number
  keyword: string
  service_type: string
  response_template: string
  form_schema?: DynamicFormSchema | null
  parser_type: 'simple' | 'advanced' | string
  priority: number
}

export async function fetchKeywordParsers() {
  const { data } = await api.get<{ data: KeywordParserConfig[] }>('/keyword-parsers')
  return data.data
}

export async function quoteOrder(payload: OrderPayload) {
  const { data } = await api.post<{ data: PriceQuote }>('/orders/quote', payload)
  return data.data
}

export async function calculatePricing(payload: OrderPayload) {
  const { data } = await api.post<{ data: PriceQuote }>('/pricing/calculate', payload)
  return data.data
}

export async function geocodeAddress(payload: GeocodePayload) {
  const { data } = await api.post<{ data: GeocodeResult }>('/geocode', payload)
  return data.data
}

export async function createOrder(payload: OrderPayload) {
  const { data } = await api.post<{ data: Order }>('/orders', payload)
  return data.data
}

export type JojoBotPreview = {
  intent: 'service_menu' | 'service_selected' | 'order_preview' | 'fallback_form'
  services: Array<{ id: number; code: string; name: string; service_type: string; whatsapp_redirect_enabled?: boolean; whatsapp_number?: string | null; whatsapp_message_template?: string | null }>
  selected_service?: string | null
  message?: string | null
  form_schema?: DynamicFormSchema | null
  service_type?: string | null
  parsed?: {
    name?: string | null
    phone?: string | null
    pickup_address?: string | null
    destination_address?: string | null
    notes?: string | null
    items?: Array<{ name: string; quantity?: number }>
    store_location?: string | null
    destination?: string | null
    customer?: { name?: string | null; phone?: string | null }
    smart_parser?: boolean
  }
  quote?: PriceQuote | null
  order_payload?: OrderPayload | null
  actions?: Array<'add_point' | 'preview_order' | string>
  fallback_format?: string
  reply: string
}

export type DynamicFormField = {
  label: string
  name: string
  type: 'text' | 'textarea' | 'number' | 'select' | 'phone' | string
  required?: boolean
  options?: string[]
}

export type DynamicFormSchema = {
  fields?: DynamicFormField[]
}

export async function previewJojoBot(rawText: string) {
  const { data } = await api.post<{ data: JojoBotPreview }>('/jojobot/preview', { raw_text: rawText })
  return data.data
}

export async function findDriver(orderId: number) {
  const { data } = await api.post<{ data: Order; driver?: unknown }>(`/orders/${orderId}/find-driver`)
  return data
}

export async function fetchOrders(perPage = 100, month?: string) {
  const orders: Order[] = []
  let page = 1
  let lastPage = 1

  do {
    const { data } = await api.get<{ data: { data?: Order[]; current_page?: number; last_page?: number } | Order[] }>('/orders', { params: { per_page: perPage, page, month } })
    if (Array.isArray(data.data)) return data.data

    orders.push(...(data.data.data ?? []))
    lastPage = data.data.last_page ?? page
    page = (data.data.current_page ?? page) + 1
  } while (page <= lastPage)

  return orders
}

export async function submitOrderRating(orderId: number, payload: { rating: number; comment?: string }) {
  const { data } = await api.post<{ data: { id: number; rating: number; comment?: string | null } }>(`/orders/${orderId}/rating`, payload)
  return data.data
}

export async function startOperatorChat() {
  const { data } = await api.post<{ data: ChatConversation }>('/chats/operator')
  return data.data
}

export async function startOrderChat(orderId: number) {
  const { data } = await api.post<{ data: ChatConversation }>(`/orders/${orderId}/chat`, { type: 'customer_driver' })
  return data.data
}

export async function fetchChatMessages(conversationId: number, page = 1) {
  const { data } = await api.get<{ data: { data: ChatMessage[] }; conversation?: ChatConversation }>(`/chats/${conversationId}/messages`, { params: { page } })
  return { messages: data.data.data.reverse(), conversation: data.conversation }
}

export async function submitOperatorRating(conversationId: number, payload: { rating: number; comment?: string }) {
  const { data } = await api.post<{ data: ChatConversation }>(`/chats/${conversationId}/operator-rating`, payload)
  return data.data
}

export async function fetchOrderMessages(orderId: number) {
  const { data } = await api.get<{ data: ChatMessage[]; conversation?: ChatConversation }>(`/orders/${orderId}/messages`)
  return data
}

export async function sendChatMessage(conversationId: number, payload: { message?: string; image?: File | null; audio?: Blob | null; audio_duration?: number | null }) {
  const form = new FormData()
  if (payload.message) form.append('message', payload.message)
  if (payload.image) form.append('image', payload.image)
  if (payload.audio) form.append('audio', payload.audio, 'voice-note.webm')
  if (payload.audio_duration) form.append('audio_duration', String(payload.audio_duration))
  const { data } = await api.post<{ data: ChatMessage }>(`/chats/${conversationId}/messages`, form, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
  return data.data
}

export async function sendOrderMessage(orderId: number, payload: { message?: string; image?: File | null; audio?: Blob | null; audio_duration?: number | null }) {
  const form = new FormData()
  form.append('order_id', String(orderId))
  if (payload.message) form.append('message', payload.message)
  if (payload.image) form.append('image', payload.image)
  if (payload.audio) form.append('audio', payload.audio, 'voice-note.webm')
  if (payload.audio_duration) form.append('audio_duration', String(payload.audio_duration))
  const { data } = await api.post<{ data: ChatMessage; conversation?: ChatConversation }>('/messages', form, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
  return data
}

export async function markChatRead(conversationId: number) {
  await api.post(`/chats/${conversationId}/read`)
}

export async function requestCancelOrder(orderId: number, payload: { reason: string; chat_conversation_id?: number | null; image?: File | null }) {
  const form = new FormData()
  form.append('reason', payload.reason)
  if (payload.chat_conversation_id) form.append('chat_conversation_id', String(payload.chat_conversation_id))
  if (payload.image) form.append('image', payload.image)
  const { data } = await api.post<{ data: unknown }>(`/orders/${orderId}/cancel-request`, form, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
  return data.data
}

export async function logout() {
  await api.post('/auth/logout')
}

export async function searchAddress(query: string) {
  if (query.trim().length < 3) return []

  const response = await fetch(
    `https://nominatim.openstreetmap.org/search?format=json&limit=6&addressdetails=1&q=${encodeURIComponent(query)}`,
    { headers: { Accept: 'application/json' } },
  )
  const rows = await response.json() as Array<{ display_name: string; lat: string; lon: string }>

  return rows.map<Address>((row) => ({
    label: row.display_name,
    lat: Number(row.lat),
    lng: Number(row.lon),
  }))
}
