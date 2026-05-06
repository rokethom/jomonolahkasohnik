export type ServiceType = 'ojek' | 'delivery' | 'kurir' | 'travel' | 'gift' | 'belanja'

export type LatLng = {
  latitude: number
  longitude: number
}

export type Address = LatLng & {
  label: string
}

export type User = {
  id: number
  username?: string
  name: string
  email: string
  phone?: string
  role?: string
}

export type PriceQuote = {
  distance_km: number
  price: number
  base_price?: number
  service_fee?: number
  keyword_charge?: number
  service_charge: number
  total_price: number
}

export type Order = {
  id: number
  order_code?: string
  code?: string
  service_type?: ServiceType | string
  pickup_address?: string
  destination_address?: string
  total_price?: number
  status: string
  created_at?: string
  driver_latitude?: number
  driver_longitude?: number
}

export type ChatMessage = {
  id: string
  from: 'customer' | 'driver' | 'cs' | 'system'
  text: string
  time: string
}

export type RootStackParamList = {
  Auth: undefined
  Main: undefined
}

export type MainTabParamList = {
  Home: undefined
  Order: undefined
  Tracking: undefined
  Chat: undefined
  History: undefined
  Profile: undefined
}
