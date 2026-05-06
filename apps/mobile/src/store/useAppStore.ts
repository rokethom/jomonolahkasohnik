import { create } from 'zustand'
import type { Address, ChatMessage, Order, PriceQuote, ServiceType, User } from '@/types'

type AppState = {
  user: User | null
  token: string
  service: ServiceType
  pickup: Address | null
  destination: Address | null
  stops: Address[]
  notes: string
  quote: PriceQuote | null
  orders: Order[]
  activeOrder: Order | null
  messages: ChatMessage[]
  isOffline: boolean
  setSession: (user: User, token: string) => void
  clearSession: () => void
  setService: (service: ServiceType) => void
  setPickup: (address: Address | null) => void
  setDestination: (address: Address | null) => void
  addStop: (address: Address) => void
  removeStop: (index: number) => void
  setNotes: (notes: string) => void
  setQuote: (quote: PriceQuote | null) => void
  setOrders: (orders: Order[]) => void
  addOrder: (order: Order) => void
  setActiveOrder: (order: Order | null) => void
  addMessage: (message: ChatMessage) => void
  setOffline: (isOffline: boolean) => void
}

export const useAppStore = create<AppState>((set, get) => ({
  user: null,
  token: '',
  service: 'ojek',
  pickup: null,
  destination: null,
  stops: [],
  notes: '',
  quote: null,
  orders: [],
  activeOrder: null,
  messages: [
    {
      id: 'welcome',
      from: 'system',
      text: 'Chat Jojo siap. Driver atau CS akan masuk setelah order dibuat.',
      time: 'Now',
    },
  ],
  isOffline: false,
  setSession: (user, token) => set({ user, token }),
  clearSession: () => set({ user: null, token: '', orders: [], activeOrder: null, quote: null }),
  setService: (service) => set({ service }),
  setPickup: (pickup) => set({ pickup }),
  setDestination: (destination) => set({ destination }),
  addStop: (address) => set({ stops: [...get().stops, address] }),
  removeStop: (index) => set({ stops: get().stops.filter((_, itemIndex) => itemIndex !== index) }),
  setNotes: (notes) => set({ notes }),
  setQuote: (quote) => set({ quote }),
  setOrders: (orders) => set({ orders }),
  addOrder: (order) => set({ orders: [order, ...get().orders], activeOrder: order }),
  setActiveOrder: (activeOrder) => set({ activeOrder }),
  addMessage: (message) => set({ messages: [...get().messages, message] }),
  setOffline: (isOffline) => set({ isOffline }),
}))
