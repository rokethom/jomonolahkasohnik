import { create } from 'zustand'
import type { Address, ChatMessage, FavoriteAddress, Order, PriceQuote, ServiceType, Toast, User, View, HomeData } from '../types'

type CustomerState = {
  view: View
  user: User | null
  token: string
  service: ServiceType
  pickup: Address | null
  destination: Address | null
  stops: FavoriteAddress[]
  notes: string
  quote: PriceQuote | null
  orders: Order[]
  messages: ChatMessage[]
  favorites: FavoriteAddress[]
  toasts: Toast[]
  homeData: HomeData | null
  setView: (view: View) => void
  setAuthToken: (token: string) => void
  setUserSession: (user: User, token: string) => void
  clearSession: () => void
  setService: (service: ServiceType) => void
  setPickup: (address: Address | null) => void
  setDestination: (address: Address | null) => void
  addStop: (address: FavoriteAddress) => void
  removeStop: (id: string) => void
  setNotes: (notes: string) => void
  setQuote: (quote: PriceQuote | null) => void
  setOrders: (orders: Order[]) => void
  addOrder: (order: Order) => void
  addMessage: (message: ChatMessage) => void
  addFavorite: (favorite: FavoriteAddress) => void
  setHomeData: (homeData: HomeData) => void
  showToast: (type: Toast['type'], message: string) => void
  dismissToast: (id: string) => void
}

const savedToken = localStorage.getItem('customer_token') ?? ''
const savedFavorites = JSON.parse(localStorage.getItem('favorite_addresses') ?? '[]') as FavoriteAddress[]

export const useCustomerStore = create<CustomerState>((set, get) => ({
  view: savedToken ? 'home' : 'login',
  user: null,
  token: savedToken,
  service: 'ojek',
  pickup: null,
  destination: null,
  stops: [],
  notes: '',
  quote: null,
  orders: [],
  messages: [],
  favorites: savedFavorites,
  toasts: [],
  homeData: null,
  setView: (view) => set({ view }),
  setAuthToken: (token) => {
    localStorage.setItem('customer_token', token)
    set({ token, view: 'home' })
  },
  setUserSession: (user, token) => {
    localStorage.setItem('customer_token', token)
    set({ user, token, view: 'home' })
  },
  clearSession: () => {
    localStorage.removeItem('customer_token')
    set({ user: null, token: '', view: 'login', orders: [], quote: null })
  },
  setService: (service) => set({ service }),
  setPickup: (pickup) => set({ pickup }),
  setDestination: (destination) => set({ destination }),
  addStop: (address) => set({ stops: [...get().stops, address] }),
  removeStop: (id) => set({ stops: get().stops.filter((stop) => stop.id !== id) }),
  setNotes: (notes) => set({ notes }),
  setQuote: (quote) => set({ quote }),
  setOrders: (orders) => set({ orders }),
  addOrder: (order) => set({ orders: [order, ...get().orders] }),
  addMessage: (message) => set({ messages: [...get().messages, message] }),
  addFavorite: (favorite) => {
    const favorites = [favorite, ...get().favorites].slice(0, 8)
    localStorage.setItem('favorite_addresses', JSON.stringify(favorites))
    set({ favorites })
  },
  setHomeData: (homeData) => set({ homeData }),
  showToast: (type, message) => {
    const id = crypto.randomUUID()
    set({ toasts: [...get().toasts, { id, type, message }] })
    window.setTimeout(() => get().dismissToast(id), 3200)
  },
  dismissToast: (id) => set({ toasts: get().toasts.filter((toast) => toast.id !== id) }),
}))
