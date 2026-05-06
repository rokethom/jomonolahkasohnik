import axios, { AxiosError } from 'axios'
import * as SecureStore from 'expo-secure-store'
import type { Address, Order, PriceQuote, ServiceType, User } from '@/types'

const API_BASE = process.env.EXPO_PUBLIC_API_URL ?? 'http://127.0.0.1:8000/api'
const TOKEN_KEY = 'jojo_customer_token'

export type LoginPayload = {
  email: string
  password: string
}

export type RegisterPayload = LoginPayload & {
  name: string
  username?: string
  phone?: string
  password_confirmation: string
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
  notes?: string
}

export const api = axios.create({
  baseURL: API_BASE,
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json',
  },
  timeout: 15000,
})

export function setAuthToken(token: string | null) {
  if (token) {
    api.defaults.headers.common.Authorization = `Bearer ${token}`
  } else {
    delete api.defaults.headers.common.Authorization
  }
}

export async function saveToken(token: string) {
  await SecureStore.setItemAsync(TOKEN_KEY, token)
  setAuthToken(token)
}

export async function getSavedToken() {
  const token = await SecureStore.getItemAsync(TOKEN_KEY)
  setAuthToken(token)
  return token
}

export async function clearToken() {
  await SecureStore.deleteItemAsync(TOKEN_KEY)
  setAuthToken(null)
}

export async function login(payload: LoginPayload) {
  const { data } = await api.post<{ user: User; token: string }>('/auth/login', payload)
  await saveToken(data.token)
  return data
}

export async function register(payload: RegisterPayload) {
  const { data } = await api.post<{ user: User; token: string }>('/auth/register', payload)
  await saveToken(data.token)
  return data
}

export function googleLoginUrl() {
  return `${API_BASE}/auth/google/redirect`
}

export async function fetchMe() {
  const { data } = await api.get<User>('/user')
  return data
}

export async function quoteOrder(payload: OrderPayload) {
  const { data } = await api.post<{ data: PriceQuote }>('/orders/quote', payload)
  return data.data
}

export async function createOrder(payload: OrderPayload) {
  const { data } = await api.post<{ data: Order }>('/orders', payload)
  return data.data
}

export async function fetchOrders() {
  const { data } = await api.get<{ data: { data?: Order[] } | Order[] }>('/orders')
  return Array.isArray(data.data) ? data.data : data.data.data ?? []
}

export async function logout() {
  try {
    await api.post('/auth/logout')
  } finally {
    await clearToken()
  }
}

export async function searchAddress(query: string) {
  if (query.trim().length < 3) return []

  const response = await fetch(
    `https://nominatim.openstreetmap.org/search?format=json&limit=6&addressdetails=1&q=${encodeURIComponent(query)}`,
    { headers: { Accept: 'application/json' } },
  )
  const rows = (await response.json()) as Array<{ display_name: string; lat: string; lon: string }>

  return rows.map<Address>((row) => ({
    label: row.display_name,
    latitude: Number(row.lat),
    longitude: Number(row.lon),
  }))
}

export function getApiErrorMessage(error: unknown, fallback = 'Request gagal') {
  if (error instanceof AxiosError) {
    const response = error.response?.data as { message?: string; errors?: Record<string, string[]> } | undefined
    const firstError = response?.errors ? Object.values(response.errors).flat()[0] : undefined
    return firstError ?? response?.message ?? fallback
  }

  return fallback
}
